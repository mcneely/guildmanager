<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\VoiceWait\MuteTransport;
use Module\VoiceWait\VoiceWaitModule;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\Harness;

use function React\Promise\resolve;

#[CoversClass(VoiceWaitModule::class)]
final class VoiceWaitModuleTest extends GuildTestCase
{
    public function testTheModuleFeedsCommandsAndInteractionsToTheEngine(): void
    {
        $harness = $this->harness();
        $module = new VoiceWaitModule($harness->context, new MuteTransport($harness->loop, 'offline', static fn() => resolve(null)));
        self::assertSame('voice_wait', $module->name());
        $commands = $module->commands();
        self::assertContains('mywait', array_column($commands, 'name'));
        self::assertNull($module->interact(['guild_id' => 'other']));
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        self::assertStringContainsString('Ready.', Harness::stderr());
    }
}
