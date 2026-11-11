<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\Overflow\MoveTransport;
use Module\VoiceWait\MuteTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\Http\Message\Response;
use React\Promise\Deferred;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\Clock;
use Tests\Support\Harness;
use Tests\Support\TestLoop;

use function React\Promise\resolve;

#[CoversClass(MuteTransport::class)]
#[CoversClass(MoveTransport::class)]
final class TransportBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::reset();
    }

    public function testEveryIdMustMatchTheWholeSnowflake(): void
    {
        $mute = new MuteTransport(new TestLoop(), 't', static fn() => resolve(new Response(200, [], '{}')));
        $this->assertRejected($mute->submit('x' . Harness::USER, Harness::USER, false, static fn(): bool => true));
        $this->assertRejected($mute->submit(Harness::GUILD, 'x' . Harness::USER, false, static fn(): bool => true));
        $this->assertRejected($mute->submit(Harness::GUILD, Harness::USER . 'x', false, static fn(): bool => true));

        $move = new MoveTransport(new TestLoop(), 't', static fn() => resolve(new Response(200, [], '{}')));
        $this->assertRejected($move->submit('x' . Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true));
        $this->assertRejected($move->submit(Harness::GUILD . 'x', Harness::USER, Harness::CHANNEL, static fn(): bool => true));
        $this->assertRejected($move->submit(Harness::GUILD, 'x' . Harness::USER, Harness::CHANNEL, static fn(): bool => true));
        $this->assertRejected($move->submit(Harness::GUILD, Harness::USER . 'x', Harness::CHANNEL, static fn(): bool => true));
        $this->assertRejected($move->submit(Harness::GUILD, Harness::USER, 'x' . Harness::CHANNEL, static fn(): bool => true));
        $this->assertRejected($move->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL . 'x', static fn(): bool => true));
    }

    public function testCancelAllReachesAnInFlightWriteAndACancelledBodyDoesNotExtendTheThrottle(): void
    {
        Clock::$hrtimeVoice = 0;
        $calls = 0;
        $gate = new Deferred();
        $mute = new MuteTransport(new TestLoop(), 't', function () use (&$calls, $gate) {
            ++$calls;

            return $calls === 1
                ? $gate->promise()
                : resolve(new Response(200, ['X-RateLimit-Remaining' => '5'], '{}'));
        });
        $mute->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true)->then(null, static fn() => null);
        $mute->pump();
        self::assertTrue($mute->hasStarted(Harness::USER));
        $mute->cancelAll();
        self::assertFalse($mute->hasStarted(Harness::USER));
        $gate->resolve(new Response(200, [
            'X-RateLimit-Remaining' => '0',
            'X-RateLimit-Reset-After' => '10',
        ], '{}'));
        Clock::$hrtimeVoice = 1_500_000_000;
        $mute->submit(Harness::GUILD, '333333333333333333', false, static fn(): bool => true);
        $mute->pump();
        self::assertSame(2, $calls);
    }

    public function testTheSecondWriteStartsExactlyOneSecondLaterAndAResetExtendsThat(): void
    {
        Clock::$hrtimeVoice = 0;
        $started = 0;
        $mute = new MuteTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(200, ['X-RateLimit-Remaining' => '5', 'X-RateLimit-Reset-After' => '0'], '{}'));
        });
        $mute->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true);
        $mute->pump();
        Clock::$hrtimeVoice = 999_999_999;
        $mute->submit(Harness::GUILD, '333333333333333333', false, static fn(): bool => true);
        $mute->pump();
        self::assertSame(1, $started);
        Clock::$hrtimeVoice = 1_000_000_000;
        $mute->pump();
        self::assertSame(2, $started);

        Clock::$hrtimeVoice = 0;
        $started = 0;
        $limited = new MuteTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(200, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset-After' => '3',
            ], '{}'));
        });
        $limited->submit(Harness::GUILD, Harness::USER, true, static fn(): bool => true);
        $limited->pump();
        Clock::$hrtimeVoice = 1_500_000_000;
        $limited->submit(Harness::GUILD, '333333333333333333', true, static fn(): bool => true);
        $limited->pump();
        self::assertSame(1, $started);

        Clock::$hrtimeOverflow = 0;
        $started = 0;
        $move = new MoveTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(429, ['X-RateLimit-Remaining' => '1'], '{"retry_after":5}'));
        });
        $move->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $move->pump();
        Clock::$hrtimeOverflow = 1_500_000_000;
        $move->submit(Harness::GUILD, '333333333333333333', Harness::CHANNEL, static fn(): bool => true);
        $move->pump();
        self::assertSame(1, $started);
    }

    public function testASuccessfulBodyAtTheDecoderLimitIsKeptAndOnePastItIsRejected(): void
    {
        $accepted = null;
        $mute = new MuteTransport(new TestLoop(), 't', fn() => resolve(new Response(200, [], json_encode($this->wrapped(510)))));
        $mute->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true)->then(function (array $body) use (&$accepted): void {
            $accepted = $body;
        });
        $mute->pump();
        self::assertIsArray($accepted);
        self::assertSame(['ok' => true], $this->leaf($accepted));

        $rejected = false;
        $deep = new MuteTransport(new TestLoop(), 't', fn() => resolve(new Response(200, [], json_encode($this->wrapped(511)))));
        $deep->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true)->then(null, function () use (&$rejected): void {
            $rejected = true;
        });
        $deep->pump();
        self::assertTrue($rejected);
    }

    public function testAnInFlightMoveIsCancelledEvenWhenNothingIsQueued(): void
    {
        $cancelled = false;
        $pending = new Deferred(static function () use (&$cancelled): void {
            $cancelled = true;
        });
        $move = new MoveTransport(new TestLoop(), 't', static fn() => $pending->promise());
        $move->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $move->pump();
        $queued = new ReflectionProperty(MoveTransport::class, 'queue')->getValue($move);
        self::assertSame([], $queued);
        $move->cancelAll();
        self::assertTrue($cancelled);
    }

    public function testMoveBoundariesAndAFailedTransportAreObservable(): void
    {
        Clock::$hrtimeOverflow = 0;
        $started = 0;
        $move = new MoveTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(200, ['X-RateLimit-Remaining' => '5'], '{}'));
        });
        $move->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $queued = new ReflectionProperty(MoveTransport::class, 'queue')->getValue($move);
        self::assertSame(16, strlen($queued[Harness::USER]['id']));
        $move->pump();
        Clock::$hrtimeOverflow = 999_999_999;
        $move->submit(Harness::GUILD, '333333333333333333', Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $move->pump();
        self::assertSame(1, $started);
        Clock::$hrtimeOverflow = 1_000_000_000;
        $move->pump();
        self::assertSame(2, $started);

        Clock::$hrtimeOverflow = 0;
        $started = 0;
        $limited = new MoveTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(200, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset-After' => '3',
            ], '{}'));
        });
        $limited->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $limited->pump();
        Clock::$hrtimeOverflow = 1_500_000_000;
        $limited->submit(Harness::GUILD, '333333333333333333', Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $limited->pump();
        self::assertSame(1, $started);

        Clock::$hrtimeVoice = 0;
        $started = 0;
        $muted = new MuteTransport(new TestLoop(), 't', function () use (&$started) {
            ++$started;

            return resolve(new Response(429, [], '{"retry_after":5}'));
        });
        $muted->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true)->then(null, static fn() => null);
        $muted->pump();
        Clock::$hrtimeVoice = 1_500_000_000;
        $muted->submit(Harness::GUILD, '333333333333333333', false, static fn(): bool => true)->then(null, static fn() => null);
        $muted->pump();
        self::assertSame(1, $started);

        $accepted = null;
        $deep = new MoveTransport(new TestLoop(), 't', fn() => resolve(new Response(200, [], json_encode($this->wrapped(510)))));
        $deep->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(function (array $body) use (&$accepted): void {
            $accepted = $body;
        });
        $deep->pump();
        self::assertSame(['ok' => true], $this->leaf($accepted ?? []));
        $tooDeep = false;
        $deeper = new MoveTransport(new TestLoop(), 't', fn() => resolve(new Response(200, [], json_encode($this->wrapped(511)))));
        $deeper->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, function () use (&$tooDeep): void {
            $tooDeep = true;
        });
        $deeper->pump();
        self::assertTrue($tooDeep);

        $rejected = false;
        $failing = new MoveTransport(new TestLoop(), 't', static fn() => \React\Promise\reject(new RuntimeException('down')));
        $failing->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$rejected): void {
                $rejected = $error->getMessage() === 'Move transport failed; outcome follows the next voice state.';
            });
        $failing->pump();
        self::assertTrue($rejected);

        $rejected = false;
        $failingMute = new MuteTransport(new TestLoop(), 't', static fn() => \React\Promise\reject(new RuntimeException('down')));
        $failingMute->submit(Harness::GUILD, Harness::USER, true, static fn(): bool => true)
            ->then(null, function (RuntimeException $error) use (&$rejected): void {
                $rejected = $error->getMessage() === 'Moderation transport failed; outcome needs review.';
            });
        $failingMute->pump();
        self::assertTrue($rejected);

        $message = '';
        $odd = new MuteTransport(new TestLoop(), 't', static fn() => resolve(new Response(429, [], '{"retry_after":"2foo"}')));
        $odd->submit(Harness::GUILD, Harness::USER, false, static fn(): bool => true)->then(null, function (RuntimeException $error) use (&$message): void {
            $message = $error->getMessage();
        });
        $odd->pump();
        self::assertSame('Moderation response was not successful.', $message);

        $message = '';
        $oddMove = new MoveTransport(new TestLoop(), 't', static fn() => resolve(new Response(429, [], '{"retry_after":"2foo"}')));
        $oddMove->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, function (RuntimeException $error) use (&$message): void {
            $message = $error->getMessage();
        });
        $oddMove->pump();
        self::assertSame('Move response was not successful.', $message);

        Clock::$hrtimeOverflow = 0;
        $calls = 0;
        $gate = new Deferred();
        $revived = new MoveTransport(new TestLoop(), 't', function () use (&$calls, $gate) {
            ++$calls;

            return $calls === 1
                ? $gate->promise()
                : resolve(new Response(200, ['X-RateLimit-Remaining' => '5'], '{}'));
        });
        $revived->submit(Harness::GUILD, Harness::USER, Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $revived->pump();
        $revived->cancelAll();
        $gate->resolve(new Response(200, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset-After' => '10'], '{}'));
        Clock::$hrtimeOverflow = 1_500_000_000;
        $revived->submit(Harness::GUILD, '333333333333333333', Harness::CHANNEL, static fn(): bool => true)->then(null, static fn() => null);
        $revived->pump();
        self::assertSame(2, $calls);
    }

    private function assertRejected(mixed $promise): void
    {
        $rejected = false;
        $promise->then(null, function (RuntimeException $error) use (&$rejected): void {
            $rejected = $error->getMessage() !== '';
        });
        self::assertTrue($rejected);
    }

    /** @return array<mixed> */
    private function wrapped(int $wraps): array
    {
        $value = ['ok' => true];
        for ($i = 0; $i < $wraps; ++$i) {
            $value = [$value];
        }

        return $value;
    }

    /** @param array<mixed> $value */
    private function leaf(array $value): array
    {
        while (isset($value[0]) && is_array($value[0])) {
            $value = $value[0];
        }

        return $value;
    }
}
