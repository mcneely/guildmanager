<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/** Rewrites DATABASE_URL onto guildmanager_test and refuses any other database. */
final class Database
{
    public static function url(): string
    {
        $raw = getenv('DATABASE_URL') ?: '';
        $parts = parse_url($raw);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || ($parts['path'] ?? '') === '') {
            throw new RuntimeException('Tests require a valid DATABASE_URL.');
        }
        $rewritten = preg_replace(
            '~^((?:[a-z][a-z0-9+.-]*://)?(?:[^/@]*@)?[^/]+)(/.*)$~i',
            '$1/guildmanager_test',
            $raw,
            1,
        );
        if (!is_string($rewritten)) {
            throw new RuntimeException('Could not derive the test database URL from DATABASE_URL.');
        }
        $check = parse_url($rewritten);
        $name = $check === false ? '' : ltrim($check['path'] ?? '', '/');
        if ($name !== 'guildmanager_test') {
            throw new RuntimeException("Refusing to use database '$name' for tests; expected guildmanager_test.");
        }

        return $rewritten;
    }

    public static function side(): PDO
    {
        $parts = parse_url(self::url());
        if ($parts === false) {
            throw new RuntimeException('Test database URL is not parseable.');
        }
        $dbname = ltrim($parts['path'] ?? '', '/');
        $host = $parts['host'] ?? 'localhost';
        $port = (int) ($parts['port'] ?? 5432);

        return new PDO(sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5;sslmode=disable',
            $host,
            $port,
            $dbname,
        ), $parts['user'] ?? '', $parts['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private static function replaceHost(string $url, string $host): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }
        $auth = '';
        if (isset($parts['user'])) {
            $auth = $parts['user'];
            if (isset($parts['pass'])) {
                $auth .= ':' . $parts['pass'];
            }
            $auth .= '@';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $auth . $host . $port . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
