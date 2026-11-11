<?php

declare(strict_types=1);

namespace Module\VoiceWait {
    function time(): int
    {
        return \Tests\Support\Clock::$voiceWait ?? \time();
    }

    function hrtime(bool $asNumber = false): int
    {
        unset($asNumber);

        return \Tests\Support\Clock::$hrtimeVoice ?? \hrtime(true);
    }
}

namespace Module\Overflow {
    function time(): int
    {
        return \Tests\Support\Clock::$overflow ?? \time();
    }

    function hrtime(bool $asNumber = false): int
    {
        unset($asNumber);

        return \Tests\Support\Clock::$hrtimeOverflow ?? \hrtime(true);
    }
}
