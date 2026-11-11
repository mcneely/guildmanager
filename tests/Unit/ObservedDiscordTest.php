<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\ObservedDiscord;
use Discord\WebSockets\Op;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ObservedDiscord::class)]
final class ObservedDiscordTest extends GuildTestCase
{
    public function testACriticalCloseEmitsTheGatewayGapBeforeTheParentHandler(): void
    {
        $harness = $this->harness();
        $seen = false;
        $discord = $harness->discord;
        $connected = new \ReflectionProperty($discord, 'connected');
        $connected->setValue($discord, true);
        $discord->on('voicewait.gateway_gap', static function () use (&$seen): void {
            $seen = true;
        });
        $discord->handleWsClose(Op::CLOSE_INVALID_TOKEN, 'bad token');
        self::assertTrue($seen);
        self::assertFalse($connected->getValue($discord));
    }
}
