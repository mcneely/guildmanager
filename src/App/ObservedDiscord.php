<?php

declare(strict_types=1);

namespace App;

use Discord\Discord;

/** Invalidate ownership at the close callback, before reconnection is attempted. */
class ObservedDiscord extends Discord
{
    public function handleWsClose(int $op, string $reason): void
    {
        $this->emit('voicewait.gateway_gap');
        parent::handleWsClose($op, $reason);
    }
}
