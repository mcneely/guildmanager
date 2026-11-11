<?php

declare(strict_types=1);

namespace Tests\Support;

use App\ObservedDiscord;

/** Gateway client that never opens a socket. Tests emit packets themselves. */
final class SilentDiscord extends ObservedDiscord
{
    public function connectWs(): void {}
}
