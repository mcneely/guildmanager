<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\StateStore;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use JsonException;
use Tests\Support\Database;
use Throwable;

#[CoversClass(StateStore::class)]
final class StateStoreTest extends GuildTestCase
{
    private ?PDO $side = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->side = Database::side();
        $this->side->exec('CREATE TABLE IF NOT EXISTS state (key TEXT PRIMARY KEY, body TEXT NOT NULL)');
        $this->side->exec('TRUNCATE state');
    }

    protected function tearDown(): void
    {
        $this->side?->exec('TRUNCATE state');
        $this->side = null;
        parent::tearDown();
    }

    public function testRoundTripRestartAndDelete(): void
    {
        $store = new StateStore(Database::url());
        $prefix = 'lease:server:' . bin2hex(random_bytes(4)) . ':';
        $key = $prefix . '123456789012345678';
        $other = $prefix . '987654321098765432';
        $item = ['user_id' => '123456789012345678', 'expires_at' => 300, 'state' => 'review'];
        $store->put($key, $item);
        $store->put($other, ['user_id' => '987654321098765432']);
        self::assertSame($item, $store->get($key));
        self::assertNull($store->get($prefix . 'missing'));
        $found = $store->leases('server');
        self::assertCount(2, $found);
        $store->close();
        unset($store);
        $store = new StateStore(Database::url());
        self::assertSame($item, $store->get($key));
        $store->delete($key);
        self::assertNull($store->get($key));
        $store->close();
        unset($store);
    }

    public function testASecondWriterOnTheSameKeyIsBlocked(): void
    {
        $first = new StateStore(Database::url());
        $blocked = false;
        try {
            new StateStore(Database::url());
        } catch (RuntimeException $error) {
            $blocked = str_contains($error->getMessage(), 'Another process');
        }
        self::assertTrue($blocked);
        $first->close();
        unset($first);
    }

    public function testDistinctLockKeysDoNotBlockEachOtherAndInvalidKeysFallBack(): void
    {
        $previous = getenv('STATE_LOCK_KEY');
        try {
            putenv('STATE_LOCK_KEY=1000001');
            $first = new StateStore(Database::url());
            putenv('STATE_LOCK_KEY=1000002');
            $second = new StateStore(Database::url());
            self::assertNotSame($first, $second);
            putenv('STATE_LOCK_KEY=1000001');
            $blocked = false;
            try {
                new StateStore(Database::url());
            } catch (RuntimeException) {
                $blocked = true;
            }
            self::assertTrue($blocked);
            $first->close();
            $second->close();
            unset($first, $second);

            putenv('STATE_LOCK_KEY=not-a-number');
            $fallback = new StateStore(Database::url());
            $blocked = false;
            try {
                new StateStore(Database::url());
            } catch (RuntimeException) {
                $blocked = true;
            }
            self::assertTrue($blocked);
            $fallback->close();
            unset($fallback);

            putenv('STATE_LOCK_KEY=999999999999');
            $overflow = new StateStore(Database::url());
            self::assertInstanceOf(StateStore::class, $overflow);
            $overflow->close();
            unset($overflow);
        } finally {
            if ($previous === false) {
                putenv('STATE_LOCK_KEY');
            } else {
                putenv('STATE_LOCK_KEY=' . $previous);
            }
        }
    }

    public function testConstructorRejectsAMissingOrNonPostgresUrl(): void
    {
        $previous = getenv('DATABASE_URL') ?: '';
        putenv('DATABASE_URL');
        unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
        try {
            new StateStore(null);
            self::fail('missing env was accepted');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('not set', $error->getMessage());
        }
        foreach (['http://example.test/db' => 'not a valid PostgreSQL URL', 'postgres://localhost' => 'no database name', 'not a url' => 'not a valid PostgreSQL URL'] as $url => $fragment) {
            try {
                new StateStore($url);
                self::fail($url . ' was accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString($fragment, $error->getMessage());
            }
        }
        putenv('DATABASE_URL=' . $previous);
    }

    public function testInvalidJsonAndALostConnectionAreHandled(): void
    {
        $store = new StateStore(Database::url());
        $this->side?->exec("INSERT INTO state (key, body) VALUES ('bad', '{')");
        try {
            $store->get('bad');
            self::fail('invalid json was decoded');
        } catch (Throwable) {
            self::assertTrue(true);
        }
        $pid = $this->side?->query('SELECT pg_backend_pid()')->fetchColumn();
        self::assertNotFalse($pid);
        $store->close();
        unset($store);

        $store = new StateStore(Database::url());
        $backend = Database::side();
        $ownPid = $backend->query('SELECT pg_backend_pid()')->fetchColumn();
        $killer = Database::side();
        $killer->exec('SELECT pg_terminate_backend(' . (int) $ownPid . ')');
        $store->close();
        unset($store);
        self::assertTrue(true);
    }

    public function testACustomPortIsNotReplacedWithTheDefault(): void
    {
        $url = Database::url();
        $bad = preg_replace('#^(postgres(?:ql)?://[^/]+?)(?::\\d+)?(/)#', '$1:59999$2', $url, 1);
        self::assertIsString($bad);
        self::assertStringContainsString(':59999/', $bad);
        $this->expectException(PDOException::class);
        new StateStore($bad);
    }

    public function testLeasesRejectABodyNestedPastTheDecodeLimit(): void
    {
        $nested = '0';
        for ($i = 0; $i < 512; ++$i) {
            $nested = '[' . $nested . ']';
        }
        $this->side?->prepare('INSERT INTO state (key, body) VALUES (?, ?)')->execute([
            'lease:111111111111111111:222222222222222222',
            $nested,
        ]);
        $store = new StateStore(Database::url());
        try {
            $store->leases('111111111111111111');
            self::fail('over-deep lease was decoded');
        } catch (JsonException) {
            self::assertTrue(true);
        }
        $store->close();
        unset($store);
    }

    public function testCloseHandlesLostConnection(): void
    {
        $store = new StateStore(Database::url());

        $reflection = new \ReflectionClass(StateStore::class);
        $property = $reflection->getProperty('db');

        $pdo = $property->getValue($store);
        self::assertInstanceOf(PDO::class, $pdo);

        $pid = $pdo->query('SELECT pg_backend_pid()')->fetchColumn();
        self::assertNotFalse($pid);

        $killer = Database::side();
        $killer->exec('SELECT pg_terminate_backend(' . (int) $pid . ')');

        $store->close();

        self::assertNull($property->getValue($store));
        $this->expectException(RuntimeException::class);
        $store->get('closed');
    }
}
