<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\Overflow\MoveTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\Response;
use React\Promise\Deferred;
use RuntimeException;
use Tests\Support\Clock;
use Tests\Support\RecordingBrowser;
use Tests\Support\TestLoop;

use function React\Promise\resolve;

#[CoversClass(MoveTransport::class)]
final class MoveTransportTest extends GuildTestCase
{
    private const string GUILD = '111111111111111111';

    private const string USER = '222222222222222222';

    private const string CHANNEL = '333333333333333333';

    public function testGuardsCancellationSingleDispatchAndNoRetry(): void
    {
        $calls = 0;
        $sender = function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"333333333333333333"}'));
        };
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', $sender);
        $valid = true;
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, function () use (&$valid): bool {
            return $valid;
        })->then(null, function () use (&$rejected): void {
            $rejected = true;
        });
        $valid = false;
        $transport->pump();
        self::assertSame(0, $calls);
        self::assertTrue($rejected);

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', $sender);
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->cancelAll();
        $transport->pump();
        self::assertTrue($rejected);

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', $sender);
        $moved = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(function (array $data) use (&$moved): void {
                $moved = $data['channel_id'] === self::CHANNEL;
            });
        $transport->pump();
        self::assertSame(1, $calls);
        self::assertTrue($moved);

        $rateCalls = 0;
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', function () use (&$rateCalls) {
            ++$rateCalls;

            return resolve(new Response(429, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset-After' => '0'], '{"retry_after":{"bad":true}}'));
        });
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->pump();
        $transport->pump();
        self::assertSame(1, $rateCalls);
        self::assertTrue($rejected);

        $probed = new class {
            public int $statusCalls = 0;

            public function getStatusCode(): int
            {
                ++$this->statusCalls;

                return 200;
            }
        };
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve($probed));
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->pump();
        self::assertTrue($rejected);
        self::assertSame(0, $probed->statusCalls);
        Clock::$hrtimeOverflow = 5_000_000_000;
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve(new Response(429, [], '{}')));
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, static function (): void {});
        $transport->pump();
        self::assertEqualsWithDelta(6.0, new \ReflectionProperty(MoveTransport::class, 'nextStart')->getValue($transport), 0.001);
        Clock::$hrtimeOverflow = null;

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve(new Response(200, [], '1')));
        $empty = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(function (array $data) use (&$empty): void {
                $empty = $data === [];
            });
        $transport->pump();
        self::assertTrue($empty);

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve(new Response(200, [], '{}')));
        $deferred = new Deferred();
        $deferred->promise()->then(null, static function (): void {});
        new \ReflectionProperty(MoveTransport::class, 'inFlight')->setValue($transport, [
            self::USER => [
                'id' => 'missing-request',
                'guild' => self::GUILD,
                'user' => self::USER,
                'channel' => self::CHANNEL,
                'guard' => static fn(): bool => true,
                'deferred' => $deferred,
            ],
        ]);
        $transport->cancel(self::USER);
        self::assertArrayNotHasKey(self::USER, new \ReflectionProperty(MoveTransport::class, 'inFlight')->getValue($transport));
    }

    public function testALateResponseCannotReviveACancelledMove(): void
    {
        $pending = new Deferred();
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => $pending->promise());
        $moved = false;
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)->then(
            function () use (&$moved): void {
                $moved = true;
            },
            function () use (&$rejected): void {
                $rejected = true;
            },
        );
        $transport->pump();
        $transport->cancel(self::USER);
        $pending->resolve(new Response(200, [], '{"ok":true}'));
        self::assertTrue($rejected);
        self::assertFalse($moved);
        $transport->cancel(self::USER);
    }

    public function testInvalidDuplicateEmptyAndFailedMoves(): void
    {
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve(new Response(200, [], '{}')));
        $rejected = false;
        $transport->submit('nope', self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        self::assertTrue($rejected);
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true);
        $duplicate = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$duplicate): void {
                $duplicate = $error->getMessage() === 'A move for this member is already pending.';
            });
        self::assertTrue($duplicate);

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => resolve(new Response(500, [], '')));
        $message = null;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$message): void {
                $message = $error->getMessage();
            });
        $transport->pump();
        self::assertSame('Move response was not successful.', $message);

        $pending = new Deferred();
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static fn() => $pending->promise());
        $message = null;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$message): void {
                $message = $error->getMessage();
            });
        $transport->pump();
        $pending->reject(new RuntimeException('down'));
        self::assertSame('Move transport failed; outcome follows the next voice state.', $message);

        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', static function (): never {
            throw new RuntimeException('boom');
        });
        $message = null;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$message): void {
                $message = $error->getMessage();
            });
        $transport->pump();
        self::assertSame('boom', $message);
        $transport->pump();
    }

    public function testTheThrottleAndDefaultSender(): void
    {
        Clock::$hrtimeOverflow = 9_000_000_000;
        $calls = 0;
        $transport = new MoveTransport(new StreamSelectLoop(), 'unused-offline', function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, [], '{"ok":true}'));
        });
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true);
        $transport->submit(self::GUILD, '444444444444444444', self::CHANNEL, static fn(): bool => true);
        $transport->pump();
        $transport->pump();
        self::assertSame(1, $calls);
        Clock::$hrtimeOverflow = 11_000_000_000;
        $transport->pump();
        self::assertSame(2, $calls);

        $browser = new RecordingBrowser();
        $transport = new MoveTransport(new StreamSelectLoop(), 'move-token', null, $browser);
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->pump();
        self::assertSame(15.0, $browser->options['timeout']);
        self::assertFalse($browser->options['followRedirects']);
        self::assertFalse($browser->options['rejectErrorResponse']);
        self::assertSame(1024 * 1024, $browser->options['responseBuffer']);
        self::assertSame('https://discord.com/api/v10/guilds/' . self::GUILD . '/members/' . self::USER, $browser->calls[0]['url']);
        self::assertSame('{"channel_id":"' . self::CHANNEL . '"}', $browser->calls[0]['body']);
        self::assertSame('Bot move-token', $browser->calls[0]['headers']['Authorization']);
        self::assertTrue($rejected);

        $loop = new TestLoop();
        $fired = 0;
        $live = new MoveTransport($loop, 'unused-offline', function () use (&$fired) {
            ++$fired;

            return resolve(new Response(200, [], '{}'));
        });
        $live->submit(self::GUILD, self::USER, self::CHANNEL, static fn(): bool => true)
            ->then(null, static function (): void {});
        $loop->firePeriodic();
        self::assertSame(1, $fired);

        $loop = new TestLoop();
        $doomed = new MoveTransport($loop, 'move-token', null, new RecordingBrowser());
        $callback = $loop->periodic[0]['callback'];
        $callback();
        unset($doomed);
        gc_collect_cycles();
        $callback();
        self::assertCount(1, $loop->cancelled);
    }
}
