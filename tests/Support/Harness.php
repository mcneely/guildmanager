<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BotContext;
use App\Permissions;
use App\StateStore;
use Discord\Discord;
use Discord\WebSockets\Intents;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Tests\Support\TestLogger;

/** Boots an offline guild context with a scripted HTTP client and a real test database. */
final class Harness
{
    public const string GUILD = '111111111111111111';

    public const string BOT = '999999999999999999';

    public const string APP = '888888888888888888';

    public const string USER = '222222222222222222';

    public const string CHANNEL = '555555555555555555';

    public const string ROLE = '777777777777777777';

    public SilentDiscord $discord;

    public FakeHttp $http;

    public StateStore $store;

    public BotContext $context;

    public TestLoop $loop;

    private static string $stderr = '';

    private static bool $filterRegistered = false;

    public function __construct(public string $guildId = self::GUILD)
    {
        $this->loop = new TestLoop();
        $this->discord = new SilentDiscord([
            'token' => 'offline-test-token',
            'loop' => $this->loop,
            'intents' => Intents::GUILDS | Intents::GUILD_VOICE_STATES | Intents::GUILD_MEMBERS,
            'loadAllMembers' => false,
            'storeMessages' => false,
            'disableVoiceClient' => true,
            'logger' => new NullLogger(),
        ]);
        $this->http = new FakeHttp($this->loop);
        new ReflectionProperty(Discord::class, 'http')->setValue($this->discord, $this->http);
        $side = Database::side();
        $side->prepare('DELETE FROM state WHERE key = ? OR key LIKE ?')->execute([
            'config:' . $guildId,
            'lease:' . $guildId . ':%',
        ]);
        $side->prepare('DELETE FROM state WHERE key = ? OR key = ? OR key LIKE ? OR key LIKE ?')->execute([
            'overflows:' . $guildId,
            $guildId,
            $guildId . ':%',
            $guildId . 'lease:%',
        ]);
        $side->prepare('DELETE FROM state WHERE key LIKE ? OR key = ?')->execute([
            $guildId . 'overflows:%',
            'overflows:',
        ]);
        $this->store = new StateStore(Database::url());
        $this->context = new BotContext($this->discord, $this->store, $guildId, new TestLogger());
        self::captureStderr();
    }

    public function identify(): void
    {
        $this->context->setIdentity([
            'user' => ['id' => self::BOT],
            'application' => ['id' => self::APP],
        ]);
    }

    public function packet(string $type, array $data, int|string $op = 0): object
    {
        return (object) ['op' => $op, 't' => $type, 'd' => $data];
    }

    public function emitRaw(string $type, array $data, int|string $op = 0): void
    {
        fwrite(STDERR, "DEBUG: Emitting raw event: $type\n");
        $this->discord->emit('raw', [$this->packet($type, $data, $op), $this->discord]);
    }

    public function flush(int $ticks = 30): void
    {
        $remaining = $ticks;
        $step = function () use (&$step, &$remaining): void {
            if ($remaining-- <= 0) {
                $this->loop->stop();

                return;
            }
            $this->loop->futureTick($step);
        };
        $this->loop->futureTick($step);
        $this->loop->run();
    }

    /** @return array<string, mixed> */
    public function guild(string $owner = '100000000000000001'): array
    {
        return ['id' => $this->guildId, 'owner_id' => $owner];
    }

    /** @return list<array<string, mixed>> */
    public function roles(int $botPermissions): array
    {
        return [
            ['id' => $this->guildId, 'name' => '@everyone', 'position' => 0, 'permissions' => '0'],
            ['id' => self::ROLE, 'name' => 'Verified', 'position' => 1, 'permissions' => '0'],
            ['id' => '333333333333333333', 'name' => 'Bot', 'position' => 10, 'permissions' => (string) $botPermissions],
        ];
    }

    /** @return array<string, mixed> */
    public function member(string $uid, array $roles = [], bool $bot = false, bool $pending = false): array
    {
        return [
            'user' => ['id' => $uid, 'guildmanager' => $bot],
            'roles' => $roles,
            'pending' => $pending,
        ];
    }

    public function botPermissions(): int
    {
        return Permissions::VIEW | Permissions::CONNECT | Permissions::MUTE | Permissions::SEND
            | Permissions::MOVE_MEMBERS | Permissions::MANAGE_CHANNELS | Permissions::MANAGE_ROLES
            | Permissions::MANAGE_GUILD;
    }

    /** @return array<string, mixed> */
    public function voiceChannel(string $id, string $name = 'Raid', int $limit = 4, int $position = 1): array
    {
        return [
            'id' => $id,
            'guild_id' => $this->guildId,
            'type' => 2,
            'name' => $name,
            'user_limit' => $limit,
            'position' => $position,
            'parent_id' => null,
            'permission_overwrites' => [],
        ];
    }

    public function shutdown(): void
    {
        $this->loop->stop();
        $this->store->close();
        unset($this->context, $this->discord, $this->http, $this->store);
        gc_collect_cycles();
        \React\EventLoop\Loop::stop();
    }

    public static function captureStderr(): void
    {
        if (self::$filterRegistered) {
            return;
        }
        stream_filter_register('guildmanager.stderr', StderrFilter::class);
        stream_filter_append(STDERR, 'guildmanager.stderr');
        self::$filterRegistered = true;
    }

    public static function stderr(): string
    {
        return self::$stderr;
    }

    public static function clearStderr(): void
    {
        self::$stderr = '';
    }

    public static function appendStderr(string $chunk): void
    {
        self::$stderr .= $chunk;
    }
}
