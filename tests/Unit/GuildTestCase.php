<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\Clock;
use Tests\Support\Harness;

abstract class GuildTestCase extends TestCase
{
    protected ?Harness $harness = null;

    protected function setUp(): void
    {
        Clock::reset();
        Harness::clearStderr();
    }

    protected function tearDown(): void
    {
        $this->harness?->shutdown();
        $this->harness = null;
        gc_collect_cycles();
        Clock::reset();
        \React\EventLoop\Loop::stop();
    }

    protected function harness(): Harness
    {
        return $this->harness ??= new Harness();
    }
}
