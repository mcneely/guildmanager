<?php

declare(strict_types=1);

namespace Tests\Support;

/** Controllable stand-ins for unqualified time()/hrtime() in module namespaces. */
final class Clock
{
    public static ?int $voiceWait = null;

    public static ?int $overflow = null;

    public static ?int $hrtimeVoice = null;

    public static ?int $hrtimeOverflow = null;

    public static function reset(): void
    {
        self::$voiceWait = null;
        self::$overflow = null;
        self::$hrtimeVoice = null;
        self::$hrtimeOverflow = null;
    }
}
