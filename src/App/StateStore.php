<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

final class StateStore
{
    private ?PDO $db = null;

    public function __construct(?string $url = null)
    {
        $raw = $url ?: (getenv('DATABASE_URL') ?: '');
        if ($raw === '') {
            throw new RuntimeException('DATABASE_URL is not set.');
        }
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['host']) || !isset($parts['scheme'])
            || !in_array($parts['scheme'], ['postgresql', 'postgres'], true)) {
            throw new RuntimeException('DATABASE_URL is not a valid PostgreSQL URL.');
        }
        $dbname = ltrim($parts['path'] ?? '', '/');
        if ($dbname === '') {
            throw new RuntimeException('DATABASE_URL has no database name.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5;sslmode=disable',
            $parts['host'],
            (int) ($parts['port'] ?? 5432),
            $dbname,
        );

        // User and password go through PDO arguments, never the DSN, so they
        // cannot surface in exception text.
        $this->db = new PDO($dsn, $parts['user'] ?? '', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $this->db->exec('CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, body TEXT NOT NULL)');

        $statement = $this->db->query('SELECT pg_try_advisory_lock(872634519, ' . self::lockKey() . ')::int');
        /** @var \PDOStatement $statement */
        $locked = $statement->fetchColumn();
        if ((string) $locked !== '1') {
            $this->db = null;
            throw new RuntimeException('Another process owns this state database.');
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    public function put(string $key, array $value): void
    {
        $q = $this->connection()->prepare('INSERT INTO state (key, body) VALUES (?, ?) '
            . 'ON CONFLICT (key) DO UPDATE SET body = EXCLUDED.body');
        $q->execute([$key, json_encode($value, JSON_THROW_ON_ERROR)]);
    }

    /** @return array<array-key, mixed>|null */
    public function get(string $key): ?array
    {
        $q = $this->connection()->prepare('SELECT body FROM state WHERE key = ?');
        $q->execute([$key]);
        $body = $q->fetchColumn();
        if (!is_string($body)) {
            return null;
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<array<array-key, mixed>> */
    public function leases(string $guildId): array
    {
        $q = $this->connection()->prepare('SELECT body FROM state WHERE key LIKE ?');
        $q->execute(['lease:' . $guildId . ':%']);
        $rows = [];
        /** @var list<string> $bodies */
        $bodies = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($bodies as $body) {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    public function delete(string $key): void
    {
        $q = $this->connection()->prepare('DELETE FROM state WHERE key = ?');
        $q->execute([$key]);
    }

    private function connection(): PDO
    {
        if (!$this->db instanceof PDO) {
            throw new RuntimeException('State store is closed.');
        }
        return $this->db;
    }

    /**
     * Optional int4 lock id. Unset (or non-numeric) keeps the per-database
     * key so a second process is still refused. Tests set a per-process id
     * so parallel runners do not share one cluster-wide lock.
     */
    private static function lockKey(): string
    {
        $raw = getenv('STATE_LOCK_KEY') ?: ($_ENV['STATE_LOCK_KEY'] ?? null);
        if (is_string($raw) && preg_match('/^-?[0-9]+$/', $raw) === 1) {
            $key = (int) $raw;
            if ($key >= -2147483648 && $key <= 2147483647) {
                return (string) $key;
            }
        }

        return 'hashtext(current_database())';
    }

    public function close(): void
    {
        if ($this->db instanceof PDO) {
            try {
                /** @infection-ignore-all */
                $this->db->query('SELECT pg_advisory_unlock_all()');
            } catch (Throwable) {
                // Connection already lost; nothing left to unlock.
            }
            $this->db = null;
        }
    }

    public function __destruct()
    {
        /** @infection-ignore-all */
        $this->close();
    }
}
