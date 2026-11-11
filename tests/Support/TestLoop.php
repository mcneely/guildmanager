<?php

declare(strict_types=1);

namespace Tests\Support;

use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;

/** Delegates to a real loop and records periodic callbacks so tests can fire them. */
final class TestLoop implements LoopInterface
{
    private readonly StreamSelectLoop $inner;

    /** @var list<array{interval: float, callback: callable}> */
    public array $periodic = [];

    public function __construct()
    {
        $this->inner = new StreamSelectLoop();
    }

    public function addReadStream($stream, $listener): void
    {
        $this->inner->addReadStream($stream, $listener);
    }

    public function addWriteStream($stream, $listener): void
    {
        $this->inner->addWriteStream($stream, $listener);
    }

    public function removeReadStream($stream): void
    {
        $this->inner->removeReadStream($stream);
    }

    public function removeWriteStream($stream): void
    {
        $this->inner->removeWriteStream($stream);
    }

    /** @var list<array{interval: float, callback: callable}> */
    public array $timers = [];

    public function addTimer($interval, $callback): TimerInterface
    {
        $this->timers[] = ['interval' => (float) $interval, 'callback' => $callback];

        return $this->inner->addTimer($interval, $callback);
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $this->periodic[] = ['interval' => (float) $interval, 'callback' => $callback];

        return $this->inner->addPeriodicTimer($interval, $callback);
    }

    public array $cancelled = [];

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->cancelled[] = $timer;
        $this->inner->cancelTimer($timer);
    }

    public function futureTick($listener): void
    {
        $this->inner->futureTick($listener);
    }

    public function addSignal($signal, $listener): void
    {
        $this->inner->addSignal($signal, $listener);
    }

    public function removeSignal($signal, $listener): void
    {
        $this->inner->removeSignal($signal, $listener);
    }

    public function run(): void
    {
        $this->inner->run();
    }

    public function stop(): void
    {
        $this->inner->stop();
    }

    public function firePeriodic(): void
    {
        foreach ($this->periodic as $timer) {
            ($timer['callback'])();
        }
    }
}
