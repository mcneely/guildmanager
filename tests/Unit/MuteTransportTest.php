<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\VoiceWait\MuteTransport;
use Module\VoiceWait\WaitLease;
use PHPUnit\Framework\Attributes\CoversClass;
use React\EventLoop\StreamSelectLoop;
use React\Http\Message\Response;
use React\Promise\Deferred;
use RuntimeException;
use Tests\Support\Clock;
use Tests\Support\RecordingBrowser;
use Tests\Support\TestLoop;

use function React\Promise\resolve;

#[CoversClass(MuteTransport::class)]
final class MuteTransportTest extends GuildTestCase
{
    private const string GUILD = '111111111111111111';

    private const string USER = '222222222222222222';

    public function testStaleQueuedUnmuteIsRejectedWithoutTransmission(): void
    {
        $calls = 0;
        $transport = $this->transport(function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"mute":false}'));
        });
        $valid = true;
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, function () use (&$valid) {
            return $valid;
        })->then(null, function () use (&$rejected): void {
            $rejected = true;
        });
        $valid = false;
        $transport->pump();
        self::assertSame(0, $calls);
        self::assertTrue($rejected);
    }

    public function testCancellationAndGenerationRevocationDropQueuedWrites(): void
    {
        $calls = 0;
        $sender = function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, [], '{"mute":false}'));
        };
        $transport = $this->transport($sender);
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->cancelAll();
        $transport->pump();
        self::assertSame(0, $calls);
        self::assertTrue($rejected);

        $lease = WaitLease::begin(self::GUILD, self::USER, '333333333333333333', 's', 'server_mute', 1000, false, ['mute' => false], ['mute' => true]);
        self::assertNotNull($lease);
        $voice = ['guild_id' => self::GUILD, 'user_id' => self::USER, 'channel_id' => '333333333333333333', 'session_id' => 's', 'restriction' => ['mute' => true]];
        $lease->applied(1, $voice);
        $release = $lease->prepareRelease(1300, $voice, true);
        self::assertIsArray($release);
        $transport = $this->transport($sender);
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => $lease->generation() === $release['generation'])
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $lease->hold('Moderator hold after enqueue.');
        $transport->pump();
        self::assertTrue($rejected);

        $transport = $this->transport($sender);
        $revision = 10;
        $captured = $revision;
        $currentMute = true;
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, function () use (&$revision, $captured, &$currentMute): bool {
            return $currentMute && $revision === $captured;
        })->then(null, function () use (&$rejected): void {
            $rejected = true;
        });
        $currentMute = false;
        ++$revision;
        $currentMute = true;
        ++$revision;
        $transport->pump();
        self::assertSame(0, $calls);
        self::assertTrue($rejected);
    }

    public function testAValidWriteSendsOnceAndA429IsNotRetried(): void
    {
        $calls = 0;
        $transport = $this->transport(function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"mute":false}'));
        });
        $success = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(function (array $data) use (&$success): void {
                $success = $data === ['mute' => false];
            });
        $transport->pump();
        self::assertSame(1, $calls);
        self::assertTrue($success);

        $rateCalls = 0;
        $transport = $this->transport(function () use (&$rateCalls) {
            ++$rateCalls;

            return resolve(new Response(429, ['Content-Type' => 'application/json', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset-After' => '2'], '{"retry_after":5}'));
        });
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$rejected): void {
                $rejected = $error->getCode() === 429;
            });
        $transport->pump();
        $transport->pump();
        self::assertSame(1, $rateCalls);
        self::assertTrue($rejected);

        $transport = $this->transport(static fn() => resolve(new Response(429, [], '{"retry_after":{"bad":true}}')));
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->pump();
        self::assertTrue($rejected);

        Clock::$hrtimeVoice = 5_000_000_000;
        $transport = $this->transport(static fn() => resolve(new Response(429, [], '{}')));
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(null, static function (): void {});
        $transport->pump();
        self::assertEqualsWithDelta(6.0, new \ReflectionProperty(MuteTransport::class, 'nextStart')->getValue($transport), 0.001);
        Clock::$hrtimeVoice = null;

        $transport = $this->transport(static fn() => resolve('nope'));
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$rejected): void {
                $rejected = $error->getMessage() === 'Moderation transport returned a non-response.';
            });
        $transport->pump();
        self::assertTrue($rejected);

        $transport = $this->transport(static fn() => resolve(new Response(200, [], '1')));
        $empty = false;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(function (array $data) use (&$empty): void {
                $empty = $data === [];
            });
        $transport->pump();
        self::assertTrue($empty);

        $transport = $this->transport(static fn() => resolve(new Response(200, [], '{}')));
        $deferred = new Deferred();
        $deferred->promise()->then(null, static function (): void {});
        new \ReflectionProperty(MuteTransport::class, 'inFlight')->setValue($transport, [
            self::USER => [
                'id' => 'missing-request',
                'guild' => self::GUILD,
                'user' => self::USER,
                'mute' => false,
                'guard' => static fn(): bool => true,
                'deferred' => $deferred,
            ],
        ]);
        $transport->cancel(self::USER);
        self::assertFalse($transport->hasStarted(self::USER));
    }

    public function testALateResponseCannotReviveACancelledMutation(): void
    {
        $cancelledRequest = false;
        $pending = new Deferred(function () use (&$cancelledRequest): void {
            $cancelledRequest = true;
        });
        $transport = $this->transport(static fn() => $pending->promise());
        $success = false;
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)->then(
            function () use (&$success): void {
                $success = true;
            },
            function () use (&$rejected): void {
                $rejected = true;
            },
        );
        $transport->pump();
        self::assertTrue($transport->hasStarted(self::USER));
        $transport->cancel(self::USER);
        self::assertFalse($transport->hasStarted(self::USER));
        self::assertTrue($cancelledRequest);
        $pending->resolve(new Response(200, [], '{"mute":false}'));
        self::assertTrue($rejected);
        self::assertFalse($success);
        $transport->cancel('333333333333333333');
        $transport->pump();
    }

    public function testInvalidAndDuplicateTargetsAreRejectedBeforeQueueing(): void
    {
        $transport = $this->transport(static fn() => resolve(new Response(200, [], '{}')));
        $reason = null;
        $transport->submit('nope', self::USER, false, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$reason): void {
                $reason = $error->getMessage();
            });
        self::assertSame('Invalid moderation target.', $reason);
        $transport->submit(self::GUILD . 'x', self::USER, false, static fn(): bool => true)
            ->then(null, static function (): void {});
        self::assertArrayNotHasKey(self::USER, new \ReflectionProperty(MuteTransport::class, 'queue')->getValue($transport));
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true);
        self::assertSame(16, strlen(new \ReflectionProperty(MuteTransport::class, 'queue')->getValue($transport)[self::USER]['id']));
        $duplicate = null;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$duplicate): void {
                $duplicate = $error->getMessage();
            });
        self::assertSame('A mutation for this member is already pending.', $duplicate);
    }

    public function testEmptyBodyNonSuccessAndTransportFailureDoNotRetry(): void
    {
        Clock::$hrtimeVoice = 1_000_000_000;
        $transport = $this->transport(static fn() => resolve(new Response(200, ['X-RateLimit-Remaining' => '1'], '')));
        $resolved = null;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(function (array $data) use (&$resolved): void {
                $resolved = $data;
            });
        $transport->pump();
        self::assertSame([], $resolved);

        Clock::$hrtimeVoice = 3_000_000_000;
        $transport = $this->transport(static fn() => resolve(new Response(403, [], '{"message":"no"}')));
        $code = null;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$code): void {
                $code = $error->getMessage();
            });
        $transport->pump();
        self::assertSame('Moderation response was not successful.', $code);

        $pending = new Deferred();
        $transport = $this->transport(static fn() => $pending->promise());
        $message = null;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$message): void {
                $message = $error->getMessage();
            });
        $transport->pump();
        $pending->reject(new RuntimeException('socket down'));
        self::assertSame('Moderation transport failed; outcome needs review.', $message);
    }

    public function testTheThrottleBlocksASecondStartUntilTheClockAdvances(): void
    {
        Clock::$hrtimeVoice = 5_000_000_000;
        $calls = 0;
        $transport = $this->transport(function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, [], '{"ok":true}'));
        });
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true);
        $transport->submit(self::GUILD, '333333333333333333', false, static fn(): bool => true);
        $transport->pump();
        $transport->pump();
        self::assertSame(1, $calls);
        Clock::$hrtimeVoice = 7_000_000_000;
        $transport->pump();
        self::assertSame(2, $calls);
    }

    public function testTheDefaultSenderBuildsOneOfflinePatch(): void
    {
        $browser = new RecordingBrowser();
        $loop = new TestLoop();
        $transport = new MuteTransport($loop, 'token-value', null, $browser);
        $rejected = false;
        $transport->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, function () use (&$rejected): void {
                $rejected = true;
            });
        $transport->pump();
        self::assertSame(15.0, $browser->options['timeout']);
        self::assertFalse($browser->options['followRedirects']);
        self::assertFalse($browser->options['rejectErrorResponse']);
        self::assertSame(1024 * 1024, $browser->options['responseBuffer']);
        self::assertCount(1, $browser->calls);
        self::assertSame('https://discord.com/api/v10/guilds/' . self::GUILD . '/members/' . self::USER, $browser->calls[0]['url']);
        self::assertSame('Bot token-value', $browser->calls[0]['headers']['Authorization']);
        self::assertSame('{"mute":true}', $browser->calls[0]['body']);
        self::assertTrue($rejected);
        self::assertSame(0.1, $loop->periodic[0]['interval'] ?? null);
        $callback = $loop->periodic[0]['callback'];
        $callback();
        unset($transport);
        gc_collect_cycles();
        $callback();
        self::assertCount(1, $loop->cancelled);

        $loop = new TestLoop();
        $fired = 0;
        $live = new MuteTransport($loop, 'unused', function () use (&$fired) {
            ++$fired;

            return resolve(new Response(200, [], '{}'));
        });
        $live->submit(self::GUILD, self::USER, true, static fn(): bool => true)
            ->then(null, static function (): void {});
        $loop->firePeriodic();
        self::assertSame(1, $fired);
    }

    public function testAThrowingSenderIsRejectedAndDoesNotStayInFlight(): void
    {
        $transport = $this->transport(static function (): never {
            throw new RuntimeException('encode failed');
        });
        $message = null;
        $transport->submit(self::GUILD, self::USER, false, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$message): void {
                $message = $error->getMessage();
            });
        $transport->pump();
        self::assertSame('encode failed', $message);
        self::assertFalse($transport->hasStarted(self::USER));
    }

    private function transport(callable $sender): MuteTransport
    {
        return new MuteTransport(new StreamSelectLoop(), 'unused-offline', $sender);
    }
}
