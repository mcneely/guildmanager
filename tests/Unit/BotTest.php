<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Bot;
use App\GuildModule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use React\Promise\PromiseInterface;
use RuntimeException;
use Tests\Support\Harness;

use function React\Promise\reject;
use function React\Promise\resolve;

#[CoversClass(Bot::class)]
final class BotTest extends GuildTestCase
{
    public function testDuplicateModuleNamesAreRejected(): void
    {
        $harness = $this->harness();
        $this->expectException(InvalidArgumentException::class);
        new Bot($harness->context, [$this->module('same'), $this->module('same')]);
    }

    public function testNonArrayCommandsAreSkippedWithoutStoppingTheLoop(): void
    {
        $harness = $this->harness();
        $harness->identify();

        $harness->http->queue('POST', 'applications/' . Harness::APP . '/guilds/' . Harness::GUILD . '/commands', ['name' => 'valid']);

        $module = $this->module('voice_wait', null, ['not-an-array', ['name' => 'valid']]);
        $bot = new Bot($harness->context, [$module], true);
        $bot->attach();
        $harness->discord->emit('init', []);
        $harness->flush();

        self::assertCount(1, $harness->http->calls);
        self::assertSame('POST', $harness->http->calls[0]['method']);
        self::assertSame('applications/' . Harness::APP . '/guilds/' . Harness::GUILD . '/commands', $harness->http->calls[0]['url']);
    }

    public function testDispatchClaimsTheFirstModuleAndLogsFailures(): void
    {
        $harness = $this->harness();
        $claimed = false;
        $skipped = $this->module('skip', static fn(): null => null);
        $throwing = $this->module('boom', static function (): never {
            throw new RuntimeException('dispatch');
        });
        $owner = $this->module('owner', static function () use (&$claimed): PromiseInterface {
            $claimed = true;

            return reject(new RuntimeException('later', 7));
        });
        $after = false;
        $later = $this->module('later', static function () use (&$after): PromiseInterface {
            $after = true;

            return resolve(null);
        });
        $bot = new Bot($harness->context, [$throwing, $skipped, $owner, $later]);
        $bot->attach();
        $harness->emitRaw('READY', ['user' => ['id' => Harness::BOT], 'application' => ['id' => Harness::APP]], 1);
        self::assertSame('', $harness->context->botId);
        $harness->emitRaw('READY', ['user' => ['id' => Harness::BOT], 'application' => ['id' => Harness::APP]], '0');
        $harness->emitRaw('INTERACTION_CREATE', ['id' => '1']);
        $harness->flush();
        self::assertSame(Harness::BOT, $harness->context->botId);
        self::assertTrue($claimed);
        self::assertFalse($after);
        self::assertStringContainsString('boom interaction dispatch failed', Harness::stderr());
        self::assertStringContainsString('owner interaction failed', Harness::stderr());
    }

    public function testRegisterOnlySkipsInteractionsAndClosesAfterCommands(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->http->queue('POST', 'applications/', ['id' => '1']);
        $module = $this->module('voice_wait', null, [['name' => 'mywait', 'description' => 'check'], 'skip-me']);
        $bot = new Bot($harness->context, [$module], true);
        $bot->attach();
        $harness->emitRaw('INTERACTION_CREATE', ['id' => '1']);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Guild commands registered.', Harness::stderr());
        self::assertStringNotContainsString('Loaded Modules', Harness::stderr());
        self::assertNotSame([], $harness->http->calls);
        self::assertTrue(new \ReflectionProperty(\Discord\Discord::class, 'closing')->getValue($harness->discord));
    }

    public function testRegistrationFailureIsLoggedAndTheGatewayCloses(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->http->queue('POST', 'applications/', new RuntimeException('nope', 500));
        $bot = new Bot($harness->context, [$this->module('voice_wait', null, [['name' => 'mywait']])], true);
        $bot->attach();
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Command registration failed', Harness::stderr());
        self::assertTrue(new \ReflectionProperty(\Discord\Discord::class, 'closing')->getValue($harness->discord));
    }

    public function testANormalInitLogsTheLoadedModules(): void
    {
        $harness = $this->harness();
        $attached = new \ArrayObject();
        $bot = new Bot($harness->context, [
            $this->module('voice_wait', null, [], $attached),
            $this->module('overflow', null, [], $attached),
        ]);
        $bot->attach();
        $harness->discord->emit('init', []);
        self::assertSame(['voice_wait', 'overflow'], $attached->getArrayCopy());
        self::assertStringContainsString('Ready. Loaded Modules: voice_wait, overflow.', Harness::stderr());
    }

    private function module(string $name, ?callable $interact = null, array $commands = [], ?\ArrayObject $attached = null): GuildModule
    {
        return new readonly class ($name, $interact, $commands, $attached) implements GuildModule {
            public function __construct(private string $id, private mixed $interact, private array $commands, private ?\ArrayObject $attached) {}

            public function name(): string
            {
                return $this->id;
            }

            public function attach(\App\BotContext $context): void
            {
                unset($context);
                $this->attached?->append($this->id);
            }

            public function commands(): array
            {
                return $this->commands;
            }

            public function interact(array $interaction): ?PromiseInterface
            {
                unset($interaction);
                if ($this->interact === null) {
                    return resolve(null);
                }

                return ($this->interact)();
            }
        };
    }
}
