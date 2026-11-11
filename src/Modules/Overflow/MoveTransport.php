<?php

declare(strict_types=1);

namespace Module\Overflow;

use Closure;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\reject;

/**
 * Guarded queue for moving a connected member between voice channels.
 *
 * The move is PATCH /guilds/{guild}/members/{user} with channel_id. That
 * write is forbidden on BotContext::api() by design, and it must not enter
 * DiscordPHP's retry queue: a retried move can land after the member has
 * already left. Modify User Voice State cannot do this — that route only
 * suppresses a speaker in a stage channel.
 *
 * Every queued move is checked again immediately before the HTTP request
 * starts. An already-started request cannot be rolled back.
 *
 * @phpstan-type MoveJob array{
 *     id: string,
 *     guild: string,
 *     user: string,
 *     channel: string,
 *     guard: Closure,
 *     deferred: Deferred<mixed>,
 *     request?: PromiseInterface<mixed>
 * }
 */
final class MoveTransport
{
    /** @var array<string, MoveJob> */
    private array $queue = [];

    /** @var array<string, MoveJob> */
    private array $inFlight = [];
    private float $nextStart = 0.0;
    /** @var Closure(string, string, string): PromiseInterface<mixed> */
    private Closure $sender;

    public function __construct(LoopInterface $loop, string $token, ?callable $sender = null, ?Browser $browser = null)
    {
        if ($sender !== null) {
            $callable = Closure::fromCallable($sender);
            /** @var Closure(string, string, string): PromiseInterface<mixed> $callable */
            $this->sender = $callable;
        } else {
            $client = ($browser ?? new Browser(null, $loop))
                ->withTimeout(15.0)
                ->withFollowRedirects(false)
                ->withRejectErrorResponse(false)
                ->withResponseBuffer(1024 * 1024);
            $this->sender = static fn(string $guild, string $user, string $channel): PromiseInterface
                => $client->patch('https://discord.com/api/v10/guilds/' . $guild . '/members/' . $user, [
                    'Authorization' => 'Bot ' . $token,
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'DiscordBot (https://discord-php.github.io/DiscordPHP.org/, 1.0)',
                ], json_encode(['channel_id' => $channel], JSON_THROW_ON_ERROR));
        }
        $ref = \WeakReference::create($this);
        $timer = $loop->addPeriodicTimer(0.1, static function () use ($ref, $loop, &$timer): void {
            $self = $ref->get();
            if ($self === null) {
                /** @var \React\EventLoop\TimerInterface $timer */
                $loop->cancelTimer($timer);
                return;
            }
            $self->pump();
        });
    }

    /** @return PromiseInterface<mixed> */
    public function submit(string $guild, string $user, string $channel, callable $guard): PromiseInterface
    {
        if (!preg_match('/^\d{17,20}$/', $guild) || !preg_match('/^\d{17,20}$/', $user)
            || !preg_match('/^\d{17,20}$/', $channel)) {
            return reject(new RuntimeException('Invalid move target.'));
        }
        if (isset($this->queue[$user]) || isset($this->inFlight[$user])) {
            return reject(new RuntimeException('A move for this member is already pending.'));
        }
        $deferred = new Deferred();
        $this->queue[$user] = [
            'id' => bin2hex(random_bytes(8)),
            'guild' => $guild, 'user' => $user, 'channel' => $channel,
            'guard' => Closure::fromCallable($guard), 'deferred' => $deferred,
        ];
        return $deferred->promise();
    }

    /** Cancels pending work; cannot roll back a request Discord already received. */
    public function cancel(string $user): void
    {
        if (isset($this->queue[$user])) {
            $job = $this->queue[$user];
            unset($this->queue[$user]);
            $job['deferred']->reject(new RuntimeException('Move cancelled before dispatch.'));
        }
        if (isset($this->inFlight[$user])) {
            $job = $this->inFlight[$user];
            unset($this->inFlight[$user]);
            $job['deferred']->reject(new RuntimeException('In-flight move cancelled; outcome follows the next voice state.'));
            if (isset($job['request'])) {
                $job['request']->cancel();
            }
        }
    }

    public function cancelAll(): void
    {
        $users = array_merge(array_keys($this->queue), array_keys($this->inFlight));
        /** @infection-ignore-all */
        foreach (array_unique($users) as $user) {
            $this->cancel((string) $user);
        }
    }

    /** Public for deterministic offline tests; production calls it on the loop. */
    public function pump(): void
    {
        if ($this->inFlight || !$this->queue || self::now() < $this->nextStart) {
            return;
        }
        /** @infection-ignore-all */
        $user = (string) array_key_first($this->queue);
        $job = $this->queue[$user];
        unset($this->queue[$user]);
        try {
            if (!($job['guard'])()) {
                $job['deferred']->reject(new RuntimeException('Stale move rejected before dispatch.'));
                return;
            }
            $this->nextStart = self::now() + 1.0; // At most one move starts per second.
            $request = ($this->sender)($job['guild'], $job['user'], $job['channel']);
            $job['request'] = $request;
            $this->inFlight[$user] = $job;
            $request->then(function (mixed $response) use ($job, $user): void {
                if (!$response instanceof ResponseInterface) {
                    unset($this->inFlight[$user]);
                    $job['deferred']->reject(new RuntimeException('Move transport returned a non-response.'));
                    // inFlight is already unset, so falling through hits the same cancelled return.
                    /** @infection-ignore-all */
                    return;
                }
                $current = $this->inFlight[$user]['id'] ?? null;
                if ($current !== $job['id']) {
                    return; // Cancelled: never revive or retry it.
                }
                unset($this->inFlight[$user]);
                try {
                    $status = $response->getStatusCode();
                    $body = (string) $response->getBody();
                    $data = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($data)) {
                        $data = [];
                    }
                    if ($response->getHeaderLine('X-RateLimit-Remaining') === '0') {
                        /** @infection-ignore-all */
                        $reset = max(0.0, (float) $response->getHeaderLine('X-RateLimit-Reset-After'));
                        $this->nextStart = max($this->nextStart, self::now() + $reset);
                    }
                    if ($status === 429) {
                        // A missing retry_after is 1; 0 collapses to the same delay under max(1.0, ...).
                        /** @infection-ignore-all */
                        $raw = $data['retry_after'] ?? 1;
                        /** @infection-ignore-all */
                        $requested = is_numeric($raw) ? (float) $raw : 1.0;
                        /** @infection-ignore-all */
                        $delay = max(1.0, $requested);
                        $this->nextStart = max($this->nextStart, self::now() + $delay);
                    }
                    if ($status !== 200) {
                        // No retries. The module asks again only after state changes.
                        throw new RuntimeException('Move response was not successful.', $status);
                    }
                    $job['deferred']->resolve($data);
                } catch (Throwable $error) {
                    $job['deferred']->reject($error);
                }
            }, function () use ($job, $user): void {
                $current = $this->inFlight[$user]['id'] ?? null;
                if ($current === $job['id']) {
                    unset($this->inFlight[$user]);
                    $job['deferred']->reject(new RuntimeException('Move transport failed; outcome follows the next voice state.'));
                }
            });
        } catch (Throwable $error) {
            unset($this->inFlight[$user]);
            $job['deferred']->reject($error);
        }
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
