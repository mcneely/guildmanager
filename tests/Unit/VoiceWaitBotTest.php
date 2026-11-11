<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\VoiceWait\MuteTransport;
use Module\VoiceWait\VoiceWaitBot;
use Module\VoiceWait\WaitLease;
use PHPUnit\Framework\Attributes\CoversClass;
use React\Http\Message\Response;
use React\Promise\Deferred;
use RuntimeException;
use Tests\Support\Clock;
use Tests\Support\Harness;

use function React\Promise\resolve;

#[CoversClass(VoiceWaitBot::class)]
final class VoiceWaitBotTest extends GuildTestCase
{
    public function testRecoveredLeasesStayOnReviewAndAGapPausesRelease(): void
    {
        $harness = $this->harness();
        $lease = WaitLease::begin($harness->guildId, Harness::USER, Harness::CHANNEL, 's', 'server_mute', 1000, false, ['mute' => false], ['mute' => true]);
        self::assertNotNull($lease);
        $lease->applied(1, ['guild_id' => $harness->guildId, 'user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'session_id' => 's', 'restriction' => ['mute' => true]]);
        $harness->store->put('lease:' . $harness->guildId . ':' . Harness::USER, $lease->snapshot());
        $bot = new VoiceWaitBot($harness->context, $this->transport($harness));
        $saved = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($saved);
        self::assertSame('review', $saved['state']);
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($this->interaction('waitreview')));
        $harness->flush();
        self::assertStringContainsString(Harness::USER . ': review; Recovered after restart', $this->lastBody($harness));
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($this->interaction('waitpanel')));
        $harness->flush();
        self::assertStringContainsString('cooldown first', $this->lastBody($harness));
        $change = $this->interaction('cooldown');
        $change['data']['options'] = [['name' => 'channel', 'value' => '666666666666666666']];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($change));
        $harness->flush();
        self::assertStringContainsString('before changing channels', $this->lastBody($harness));
        $missing = $this->interaction('waithold');
        $missing['data']['options'] = [['name' => 'member', 'value' => '222222222222222223']];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($missing));
        $harness->flush();
        self::assertStringContainsString('no recorded wait', $this->lastBody($harness));
        $hold = $this->interaction('waithold');
        $hold['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($hold));
        $harness->flush();
        self::assertStringContainsString('Automatic release is paused for that member', $this->lastBody($harness));
        $voice = new Deferred();
        $clear = $this->interaction('waitclear');
        $clear['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $voice->promise());
        self::assertNotNull($bot->interact($clear));
        $harness->flush();
        $blocked = $this->interaction('waitclear');
        $blocked['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($blocked));
        $harness->flush();
        self::assertStringContainsString('still in flight', $this->lastBody($harness));
        $voice->resolve(['channel_id' => '', 'mute' => false]);
        $harness->flush();
        self::assertStringContainsString('Reconnect the member', $this->lastBody($harness));
        self::assertNotNull($harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER));
        $cleared = $this->interaction('waitclear');
        $cleared['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, ['channel_id' => Harness::CHANNEL, 'mute' => false]);
        self::assertNotNull($bot->interact($cleared));
        $harness->flush();
        self::assertStringContainsString('wait record was cleared', $this->lastBody($harness));
        self::assertNull($harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER));
        $bot->attach();
        $harness->discord->emit('voicewait.gateway_gap', []);
        $harness->emitRaw('RESUMED', [], 7);
        $harness->emitRaw('INVALID', [], 9);
        $harness->emitRaw('IGNORED', [], 1);
        $harness->emitRaw('GUILD_DELETE', ['id' => '999999999999999991']);
        $harness->emitRaw('GUILD_DELETE', ['id' => $harness->guildId]);
        $harness->emitRaw('VOICE_STATE_UPDATE', ['guild_id' => '999999999999999991', 'user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL]);
        self::assertStringContainsString('Automatic release is paused', Harness::stderr());
    }

    public function testAnEligibleJoinIsMutedAndALaterExpiryReleasesIt(): void
    {
        Clock::$voiceWait = 1_700_000_000;
        Clock::$hrtimeVoice = 1_000_000_000;
        $harness = $this->harness();
        $this->enable($harness);
        $transport = $this->transport($harness, static fn() => resolve(new Response(200, [], '{"mute":true}')));
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $this->scriptReads($harness, false, $harness->botPermissions());
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(false));
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(true));
        $this->join($harness);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        $saved = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($saved);
        self::assertSame('active', $saved['state']);
        $blocked = $this->interaction('waitclear');
        $blocked['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($blocked));
        $harness->flush();
        self::assertStringContainsString('manually unmute the member', $this->lastBody($harness));

        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => true,
        ]);
        Clock::$voiceWait = 1_700_000_300;
        Clock::$hrtimeVoice = 4_000_000_000;
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(true));
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(false));
        $harness->loop->firePeriodic();
        $harness->flush();
        $transport->pump();
        $harness->flush();
        $saved = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($saved);
        self::assertSame('complete', $saved['state']);

        $other = '222222222222222223';
        Clock::$hrtimeVoice = 9_000_000_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $other);
        $harness->http->queue('GET', 'voice-states/' . $other, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess2', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $other, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess2', 'mute' => true]);
        $this->join($harness, ['user_id' => $other, 'session_id' => 'sess2']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        $held = $harness->store->get('lease:' . $harness->guildId . ':' . $other);
        self::assertIsArray($held);
        self::assertSame('active', $held['state']);
        $calls = count($harness->http->calls);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::BOT,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'bot2',
            'mute' => false,
        ]);
        self::assertSame($calls, count($harness->http->calls));
        $harness->discord->emit('voicewait.gateway_gap', []);
        $held = $harness->store->get('lease:' . $harness->guildId . ':' . $other);
        self::assertIsArray($held);
        self::assertSame('review', $held['state']);
        self::assertStringContainsString('Gateway disconnected', (string) $held['reason']);
        $harness->discord->emit('reconnected', []);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $other,
            'channel_id' => null,
            'session_id' => 'sess2',
            'mute' => true,
        ]);
        $calls = count($harness->http->calls);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $other,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess2',
            'mute' => true,
        ]);
        $harness->flush();
        self::assertSame($calls, count($harness->http->calls));
        self::assertSame('review', $harness->store->get('lease:' . $harness->guildId . ':' . $other)['state']);

        $removed = '222222222222222225';
        $this->scriptReads($harness, false, $harness->botPermissions(), $removed);
        $harness->http->queue('GET', 'voice-states/' . $removed, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess3', 'mute' => false]);
        $this->join($harness, ['user_id' => $removed, 'session_id' => 'sess3']);
        $harness->flush();
        self::assertSame('applying', $harness->store->get('lease:' . $harness->guildId . ':' . $removed)['state']);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $removed,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess3',
            'mute' => true,
        ]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $removed,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess3',
            'mute' => false,
        ]);
        self::assertStringContainsString('removed while application was still in flight', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $removed)['reason']);

        $moved = '222222222222222226';
        $this->scriptReads($harness, false, $harness->botPermissions(), $moved);
        $harness->http->queue('GET', 'voice-states/' . $moved, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess4', 'mute' => false]);
        $this->join($harness, ['user_id' => $moved, 'session_id' => 'sess4']);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $moved,
            'channel_id' => '555555555555555556',
            'session_id' => 'sess4',
            'mute' => false,
        ]);
        self::assertStringContainsString('moved during an in-flight mute operation', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $moved)['reason']);

        $unmuted = '222222222222222227';
        Clock::$hrtimeVoice += 2_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $unmuted);
        $harness->http->queue('GET', 'voice-states/' . $unmuted, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess5', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $unmuted, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess5', 'mute' => true]);
        $this->join($harness, ['user_id' => $unmuted, 'session_id' => 'sess5']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertSame('active', $harness->store->get('lease:' . $harness->guildId . ':' . $unmuted)['state']);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $unmuted,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess5',
            'mute' => true,
        ]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $unmuted,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess5',
            'mute' => false,
        ]);
        self::assertSame('cancelled', $harness->store->get('lease:' . $harness->guildId . ':' . $unmuted)['state']);
        self::assertStringContainsString('Restriction removed outside the automatic release', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $unmuted)['reason']);

        $changed = '222222222222222228';
        $this->scriptReads($harness, false, $harness->botPermissions(), $changed);
        $harness->http->queue('GET', 'voice-states/' . $changed, ['channel_id' => '555555555555555556', 'session_id' => 'other', 'mute' => false]);
        $this->join($harness, ['user_id' => $changed, 'session_id' => 'sess6']);
        $harness->flush();
        self::assertStringContainsString('voice state changed before a wait could be applied', $this->privateStatus($harness, $bot, $changed));

        $elapsed = '222222222222222229';
        $pendingVoice = new Deferred();
        $this->scriptReads($harness, false, $harness->botPermissions(), $elapsed);
        $harness->http->queue('GET', 'voice-states/' . $elapsed, $pendingVoice->promise());
        $enteredAt = Clock::$voiceWait ?? time();
        $this->join($harness, ['user_id' => $elapsed, 'session_id' => 'sess7']);
        $harness->flush();
        Clock::$voiceWait = $enteredAt + 301;
        $pendingVoice->resolve(['channel_id' => Harness::CHANNEL, 'session_id' => 'sess7', 'mute' => false]);
        $harness->flush();
        self::assertStringContainsString('elapsed before a mute could be applied', $this->privateStatus($harness, $bot, $elapsed));

        $busyUser = '222222222222222230';
        $pendingGuild = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $pendingGuild->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $busyUser, $harness->member($busyUser));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $this->join($harness, ['user_id' => $busyUser, 'session_id' => 'sess8']);
        $harness->flush();
        $calls = count($harness->http->calls);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $busyUser,
            'channel_id' => null,
            'session_id' => 'sess8',
            'mute' => false,
        ]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $busyUser,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess8',
            'mute' => false,
            'member' => ['user' => ['id' => $busyUser, 'guildmanager' => false]],
        ]);
        self::assertSame($calls, count($harness->http->calls));
        $pendingGuild->reject(new RuntimeException('down', 1));
        $harness->flush();
        self::assertStringContainsString('could not be applied', $this->privateStatus($harness, $bot, $busyUser));

        $disconnected = '222222222222222231';
        Clock::$hrtimeVoice = (Clock::$hrtimeVoice ?? 0) + 2_000_000_000;
        $afterMute = new Deferred();
        $this->scriptReads($harness, false, $harness->botPermissions(), $disconnected);
        $harness->http->queue('GET', 'voice-states/' . $disconnected, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess9', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $disconnected, $afterMute->promise());
        $this->join($harness, ['user_id' => $disconnected, 'session_id' => 'sess9']);
        $harness->flush();
        $transport->pump();
        $harness->discord->emit('voicewait.gateway_gap', []);
        $afterMute->resolve(['channel_id' => Harness::CHANNEL, 'session_id' => 'sess9', 'mute' => true]);
        $harness->flush();
        self::assertStringContainsString('Connection changed during mute application', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $disconnected)['reason']);

        $harness->discord->emit('reconnected', []);
        $failedApply = '222222222222222232';
        Clock::$hrtimeVoice += 2_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $failedApply);
        $harness->http->queue('GET', 'voice-states/' . $failedApply, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess10', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $failedApply, new RuntimeException('verify down', 1));
        $this->join($harness, ['user_id' => $failedApply, 'session_id' => 'sess10']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertSame('review', $harness->store->get('lease:' . $harness->guildId . ':' . $failedApply)['state']);
        self::assertStringContainsString('Mute application failed', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $failedApply)['reason']);

        $releasing = '222222222222222233';
        Clock::$hrtimeVoice += 2_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $releasing);
        $harness->http->queue('GET', 'voice-states/' . $releasing, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess11', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $releasing, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess11', 'mute' => true]);
        $this->join($harness, ['user_id' => $releasing, 'session_id' => 'sess11']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertSame('active', $harness->store->get('lease:' . $harness->guildId . ':' . $releasing)['state']);
        $pendingRelease = new Deferred();
        $harness->http->queue('GET', 'voice-states/' . $releasing, $pendingRelease->promise());
        Clock::$voiceWait = (Clock::$voiceWait ?? time()) + 301;
        $harness->loop->firePeriodic();
        $harness->flush();
        $reads = count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . $releasing),
        ));
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($reads, count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . $releasing),
        )));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $releasing,
            'channel_id' => '555555555555555556',
            'session_id' => 'sess11',
            'mute' => true,
        ]);
        $pendingRelease->resolve(['channel_id' => Harness::CHANNEL, 'session_id' => 'sess11', 'mute' => true]);
        $harness->flush();
        self::assertStringContainsString('Voice state changed while checking release', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $releasing)['reason']);

        $stale = '222222222222222234';
        Clock::$hrtimeVoice += 2_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $stale);
        $harness->http->queue('GET', 'voice-states/' . $stale, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess12', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $stale, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess12', 'mute' => true]);
        $this->join($harness, ['user_id' => $stale, 'session_id' => 'sess12']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        $harness->http->queue('GET', 'voice-states/' . $stale, ['channel_id' => '', 'session_id' => 'sess12', 'mute' => true]);
        Clock::$voiceWait += 301;
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertStringContainsString('disconnected or changed session', (string) $harness->store->get('lease:' . $harness->guildId . ':' . $stale)['reason']);

        $watched = '222222222222222235';
        Clock::$hrtimeVoice += 2_000_000_000;
        $this->scriptReads($harness, false, $harness->botPermissions(), $watched);
        $harness->http->queue('GET', 'voice-states/' . $watched, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess13', 'mute' => false]);
        $harness->http->queue('GET', 'voice-states/' . $watched, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess13', 'mute' => true]);
        $this->join($harness, ['user_id' => $watched, 'session_id' => 'sess13']);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertStringContainsString('Your wait ends', $this->privateStatus($harness, $bot, $watched));
        $harness->http->queue('PATCH', 'webhooks/', new RuntimeException('refresh down', 1));
        $harness->discord->emit('voicewait.gateway_gap', []);
        $harness->flush();
        self::assertStringContainsString('Private status refresh failed', Harness::stderr());

        $harness->discord->emit('reconnected', []);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'session_id' => 'sess',
            'mute' => false,
        ]);
        $this->scriptReads($harness, false, $harness->botPermissions(), Harness::USER);
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess', 'mute' => false]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => false,
            'member' => ['user' => ['id' => Harness::USER, 'guildmanager' => false]],
        ]);
        $harness->flush();
        $fresh = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($fresh);
        self::assertSame('applying', $fresh['state']);
        self::assertSame(1, $fresh['generation']);
        $reads = count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . Harness::USER),
        ));
        new \ReflectionMethod(VoiceWaitBot::class, 'release')->invoke($bot, Harness::USER);
        self::assertSame('applying', $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER)['state']);
        self::assertSame($reads, count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . Harness::USER),
        )));

        Clock::$hrtimeVoice += 2_000_000_000;
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess', 'mute' => true]);
        $transport->pump();
        $harness->flush();
        self::assertSame('active', $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER)['state']);
        $pendingRelease = new Deferred();
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $pendingRelease->promise());
        Clock::$voiceWait = (Clock::$voiceWait ?? time()) + 301;
        $harness->loop->firePeriodic();
        $harness->flush();
        $harness->discord->emit('voicewait.gateway_gap', []);
        Harness::clearStderr();
        $pendingRelease->resolve(['channel_id' => Harness::CHANNEL, 'session_id' => 'sess', 'mute' => true]);
        $harness->flush();
        $held = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($held);
        self::assertSame('review', $held['state']);
        self::assertStringContainsString('Gateway disconnected', (string) $held['reason']);
        self::assertStringNotContainsString('Release failed', Harness::stderr());
        $harness->discord->emit('reconnected', []);
        $reads = count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . Harness::USER),
        ));
        new \ReflectionMethod(VoiceWaitBot::class, 'release')->invoke($bot, Harness::USER);
        self::assertSame('review', $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER)['state']);
        self::assertSame($reads, count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states/' . Harness::USER),
        )));
    }

    public function testExemptionsAndAChangedVoiceStateDoNotApplyAMute(): void
    {
        $harness = $this->harness();
        $this->enable($harness);
        $bot = new VoiceWaitBot($harness->context, $this->transport($harness));
        $this->online($harness, $bot);
        $this->join($harness, ['mute' => true]);
        self::assertStringContainsString('already have a server mute', $this->privateStatus($harness, $bot));

        $this->join($harness, ['member' => ['user' => ['id' => '444444444444444444', 'guildmanager' => true]], 'user_id' => '444444444444444444']);
        self::assertStringContainsString('Bot accounts are exempt', $this->privateStatus($harness, $bot, '444444444444444444'));

        $this->scriptReads($harness, true, $harness->botPermissions(), '555555555555555555');
        $this->join($harness, ['user_id' => '555555555555555555']);
        $harness->flush();
        self::assertStringContainsString('exempt', $this->privateStatus($harness, $bot, '555555555555555555'));

        $this->scriptReads($harness, false, 0, '666666666666666666');
        $this->join($harness, ['user_id' => '666666666666666666']);
        $harness->flush();
        self::assertStringContainsString('missing a required channel permission', $this->privateStatus($harness, $bot, '666666666666666666'));
    }

    public function testADisconnectOrExternalUnmuteWhileApplyingHoldsTheLease(): void
    {
        Clock::$voiceWait = 1_800_000_000;
        $harness = $this->harness();
        $this->enable($harness);
        $pending = new Deferred();
        $transport = $this->transport($harness, static fn() => $pending->promise());
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $this->scriptReads($harness, false, $harness->botPermissions());
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(false));
        $this->join($harness);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'session_id' => 'sess',
            'mute' => false,
        ]);
        $saved = $harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER);
        self::assertIsArray($saved);
        self::assertSame('review', $saved['state']);
        self::assertStringContainsString('disconnected or rejoined', (string) $saved['reason']);
        Clock::$hrtimeVoice = 9_000_000_000_000_000;
        $transport->submit($harness->guildId, Harness::USER, true, static fn(): bool => true)
            ->then(null, static function (): void {});
        $transport->pump();
        $inflight = $this->interaction('waithold');
        $inflight['data']['options'] = [['name' => 'member', 'value' => Harness::USER]];
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($inflight));
        $harness->flush();
        self::assertStringContainsString('in-flight request was cancelled', $this->lastBody($harness));
        self::assertFalse($transport->hasStarted(Harness::USER));
    }

    public function testCommandsReplyPrivatelyAndManagersCanHoldOrClear(): void
    {
        $harness = $this->harness();
        $this->enable($harness);
        $transport = $this->transport($harness);
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $interaction = $this->interaction('mywait');
        $harness->http->queue('POST', 'interactions/', []);
        self::assertNotNull($bot->interact($interaction));
        $harness->flush();
        self::assertStringContainsString('no guildmanager-managed wait', $this->lastBody($harness));

        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::USER, $harness->member(Harness::USER, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('PATCH', 'webhooks/', []);
        self::assertNotNull($bot->interact($this->interaction('waitdisable')));
        $harness->flush();
        self::assertFalse($harness->store->get('config:' . $harness->guildId)['enabled'] ?? true);
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($this->interaction('waitreview')));
        $harness->flush();
        self::assertStringContainsString('No active or review-needed waits.', $this->lastBody($harness));

        $stage = $harness->voiceChannel(Harness::CHANNEL);
        $stage['type'] = 13;
        $rejected = $this->interaction('cooldown');
        $rejected['data']['options'] = [['name' => 'channel', 'value' => Harness::CHANNEL]];
        $this->scriptChannelSetup($harness, $stage);
        self::assertNotNull($bot->interact($rejected));
        $harness->flush();
        self::assertStringContainsString('Stage channels are not supported', $this->lastBody($harness));

        $denied = $this->interaction('cooldown');
        $denied['data']['options'] = [['name' => 'channel', 'value' => Harness::CHANNEL]];
        $this->scriptChannelSetup($harness, $harness->voiceChannel(Harness::CHANNEL), null, 0);
        self::assertNotNull($bot->interact($denied));
        $harness->flush();
        self::assertStringContainsString('Mute Members', $this->lastBody($harness));

        $pending = new Deferred();
        $cooldown = $this->interaction('cooldown');
        $cooldown['data']['options'] = [['name' => 'channel', 'value' => Harness::CHANNEL]];
        $this->scriptManager($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $pending->promise());
        $harness->http->queue('POST', 'channels/' . Harness::CHANNEL . '/messages', ['id' => '777777777777777777']);
        self::assertNotNull($bot->interact($cooldown));
        $harness->flush();
        $this->scriptManager($harness);
        self::assertNotNull($bot->interact($this->interaction('waitpanel')));
        $harness->flush();
        self::assertStringContainsString('Another channel setup operation is in progress', $this->lastBody($harness));
        $pending->resolve($harness->voiceChannel(Harness::CHANNEL));
        $harness->flush();
        self::assertStringContainsString('Configured five-minute waits for new eligible joins. The private Check my wait button is in <#' . Harness::CHANNEL . '>.', $this->lastBody($harness));
        $panel = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'POST' && str_contains($call['url'], '/messages'),
        ));
        self::assertSame(1, $panel[0]['body']['components'][0]['type'] ?? null);
        self::assertSame(2, $panel[0]['body']['components'][0]['components'][0]['type'] ?? null);
        self::assertSame(1, $panel[0]['body']['components'][0]['components'][0]['style'] ?? null);
        self::assertSame('777777777777777777', $harness->store->get('config:' . $harness->guildId)['panel_id'] ?? null);

        $again = $this->interaction('cooldown');
        $again['data']['options'] = [['name' => 'channel', 'value' => Harness::CHANNEL]];
        $this->scriptChannelSetup($harness, $harness->voiceChannel(Harness::CHANNEL));
        self::assertNotNull($bot->interact($again));
        $harness->flush();
        self::assertStringContainsString('Five-minute waits are enabled', $this->lastBody($harness));

        self::assertNull($bot->interact(['guild_id' => 'other', 'member' => ['user' => ['id' => Harness::USER]]]));
        self::assertNull($bot->interact($this->interaction('unknown')));

        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles(0));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::USER, $harness->member(Harness::USER));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('PATCH', 'webhooks/', []);
        self::assertNotNull($bot->interact($this->interaction('waitdisable')));
        $harness->flush();
        self::assertStringContainsString('Manage Server', $this->lastBody($harness));

        $failed = $this->interaction('cooldown');
        $failed['data']['options'] = [['name' => 'channel', 'value' => Harness::CHANNEL]];
        $this->scriptManager($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, new RuntimeException('context down', 1));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL));
        self::assertNotNull($bot->interact($failed));
        $harness->flush();
        self::assertStringContainsString('could not be confirmed', $this->lastBody($harness));

        $harness->http->queue('POST', 'interactions/', new RuntimeException('defer down', 1));
        self::assertNotNull($bot->interact($this->interaction('waitreview')));
        $harness->flush();
        self::assertStringContainsString('Command reply or authorization read failed', Harness::stderr());
    }

    public function testAManagerWithoutPermissionGetsAPrivateError(): void
    {
        $harness = $this->harness();
        $bot = new VoiceWaitBot($harness->context, $this->transport($harness), true);
        $bot->attach();
        $harness->discord->emit('init', []);
        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, new RuntimeException('down'));
        $harness->http->queue('PATCH', 'webhooks/', []);
        self::assertNotNull($bot->interact($this->interaction('waitreview')));
        $harness->flush();
        self::assertStringContainsString('could not verify your server permissions', $this->lastBody($harness));
    }

    public function testANonDispatchOpcodeDoesNotMute(): void
    {
        $harness = $this->harness();
        $this->enable($harness);
        $sent = 0;
        $transport = $this->transport($harness, function () use (&$sent) {
            ++$sent;

            return resolve(new Response(200, [], '{"mute":true}'));
        });
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $this->scriptReads($harness, false, $harness->botPermissions());
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(false));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => false,
            'member' => ['user' => ['id' => Harness::USER, 'guildmanager' => false]],
        ], 1);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertSame(0, $sent);
        self::assertNull($harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER));
        unset($bot);
    }

    public function testAFreshMuteStopsTheWait(): void
    {
        $harness = $this->harness();
        $this->enable($harness);
        $sent = 0;
        $transport = $this->transport($harness, function () use (&$sent) {
            ++$sent;

            return resolve(new Response(200, [], '{"mute":true}'));
        });
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $this->scriptReads($harness, false, $harness->botPermissions());
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, $this->voice(true));
        $this->join($harness);
        $harness->flush();
        $transport->pump();
        $harness->flush();
        self::assertSame(0, $sent);
        self::assertNull($harness->store->get('lease:' . $harness->guildId . ':' . Harness::USER));
        unset($bot);
    }

    private function transport(Harness $harness, ?callable $sender = null): MuteTransport
    {
        return new MuteTransport($harness->loop, 'offline', $sender ?? static fn() => resolve(new Response(200, [], '{"mute":true}')));
    }

    private function enable(Harness $harness): void
    {
        $harness->store->put('config:' . $harness->guildId, ['enabled' => true, 'channel_id' => Harness::CHANNEL]);
    }

    private function online(Harness $harness, VoiceWaitBot $bot): void
    {
        $bot->attach();
        $harness->emitRaw('READY', ['user' => ['id' => Harness::BOT], 'application' => ['id' => Harness::APP]]);
        $harness->emitRaw('GUILD_CREATE', ['id' => '999999999999999991', 'unavailable' => false]);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'voice_states' => [['user_id' => Harness::BOT, 'channel_id' => Harness::CHANNEL, 'session_id' => 'guildmanager', 'mute' => false]],
        ]);
        $harness->discord->emit('init', []);
        $harness->discord->emit('reconnected', []);
    }

    /** @param array<string, mixed> $overrides */
    private function join(Harness $harness, array $overrides = []): void
    {
        $harness->emitRaw('VOICE_STATE_UPDATE', $overrides + [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => false,
            'member' => ['user' => ['id' => Harness::USER, 'guildmanager' => false]],
        ]);
    }

    private function scriptReads(Harness $harness, bool $memberCanMute, int $botPermissions, ?string $uid = null): void
    {
        $uid ??= Harness::USER;
        $memberPerms = $memberCanMute ? (string) \App\Permissions::MUTE : '0';
        $roles = $harness->roles($botPermissions);
        $roles[] = ['id' => '444444444444444440', 'name' => 'Member', 'position' => 1, 'permissions' => $memberPerms];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $uid, $harness->member($uid, $memberCanMute ? ['444444444444444440'] : []));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL));
    }

    private function scriptManager(Harness $harness): void
    {
        $harness->http->queue('POST', 'interactions/', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::USER, $harness->member(Harness::USER, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('PATCH', 'webhooks/', []);
    }

    /** @param array<string, mixed> $channel */
    private function scriptChannelSetup(Harness $harness, array $channel, ?array $message = null, ?int $botPermissions = null): void
    {
        $this->scriptManager($harness);
        $id = (string) $channel['id'];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($botPermissions ?? $harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'channels/' . $id, $channel);
        if ($message !== null) {
            $harness->http->queue('POST', 'channels/' . $id . '/messages', $message);
        }
    }

    /** @return array<string, mixed> */
    private function voice(bool $mute): array
    {
        return ['channel_id' => Harness::CHANNEL, 'session_id' => 'sess', 'mute' => $mute];
    }

    /** @return array<string, mixed> */
    private function interaction(string $name, int $type = 2): array
    {
        return [
            'guild_id' => Harness::GUILD,
            'type' => $type,
            'data' => ['name' => $name, 'custom_id' => $type === 3 ? 'voicewait:check:v1' : ''],
            'member' => ['user' => ['id' => Harness::USER]],
            'id' => '999999999999999999',
            'token' => 'offline-token',
            'application_id' => Harness::APP,
        ];
    }

    private function privateStatus(Harness $harness, VoiceWaitBot $bot, string $uid = Harness::USER): string
    {
        $text = '';
        $harness->http->queue('POST', 'interactions/', []);
        $bot->interact([
            'guild_id' => $harness->guildId,
            'type' => 2,
            'data' => ['name' => 'mywait'],
            'member' => ['user' => ['id' => $uid]],
            'id' => '999999999999999998',
            'token' => 'status-token',
            'application_id' => Harness::APP,
        ])?->then(function () use (&$text, $harness): void {
            $text = $this->lastBody($harness);
        });
        $harness->flush();

        return $text;
    }

    public function testSnowflakeRejectsAPrefixedId(): void
    {
        $method = new \ReflectionMethod(VoiceWaitBot::class, 'snowflake');
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, 'x' . Harness::USER);
    }

    public function testSnowflakeRejectsATrailingCharacter(): void
    {
        $method = new \ReflectionMethod(VoiceWaitBot::class, 'snowflake');
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke(null, Harness::USER . 'x');
    }

    public function testReleaseRefusesAMissingMuteFlag(): void
    {
        Clock::$voiceWait = 1_700_000_000;
        Clock::$hrtimeVoice = 1_000_000_000;
        $harness = $this->harness();
        $sent = [];
        $transport = $this->transport($harness, function () use (&$sent) {
            $sent[] = true;

            return resolve(new Response(200, [], '{"mute":false}'));
        });
        $bot = new VoiceWaitBot($harness->context, $transport);
        $this->online($harness, $bot);
        $lease = WaitLease::begin(
            $harness->guildId,
            Harness::USER,
            Harness::CHANNEL,
            'sess',
            'server_mute',
            1_700_000_000,
            false,
            ['mute' => false],
            ['mute' => true],
        );
        self::assertNotNull($lease);
        $record = new \ReflectionProperty(WaitLease::class, 'record');
        $saved = $record->getValue($lease);
        $saved['state'] = 'active';
        $saved['expires_at'] = 1_700_000_000;
        $record->setValue($lease, $saved);
        new \ReflectionProperty(VoiceWaitBot::class, 'leases')->setValue($bot, [Harness::USER => $lease]);
        new \ReflectionProperty(VoiceWaitBot::class, 'states')->setValue($bot, [Harness::USER => [
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => true,
        ]]);
        $harness->http->queue('GET', 'voice-states/' . Harness::USER, [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'session_id' => 'sess',
            'mute' => true,
        ]);
        Clock::$voiceWait = 1_700_000_300;
        new \ReflectionMethod(VoiceWaitBot::class, 'release')->invoke($bot, Harness::USER);
        $harness->flush();
        $states = new \ReflectionProperty(VoiceWaitBot::class, 'states')->getValue($bot);
        unset($states[Harness::USER]['mute']);
        new \ReflectionProperty(VoiceWaitBot::class, 'states')->setValue($bot, $states);
        $transport->pump();
        $harness->flush();
        self::assertSame([], $sent);
        self::assertSame('Automatic release failed or its result is uncertain; ask a moderator.', $lease->snapshot()['reason']);
    }

    public function testAnExpiredLeaseDeletesOnlyThatMembersRow(): void
    {
        Clock::$voiceWait = 1_700_000_000 + 86401;
        $harness = $this->harness();
        $bot = new VoiceWaitBot($harness->context, $this->transport($harness));
        $this->online($harness, $bot);
        $done = WaitLease::begin(
            $harness->guildId,
            Harness::USER,
            Harness::CHANNEL,
            'sess',
            'server_mute',
            1_700_000_000,
            false,
            ['mute' => false],
            ['mute' => true],
        );
        self::assertNotNull($done);
        $record = new \ReflectionProperty(WaitLease::class, 'record');
        $saved = $record->getValue($done);
        $saved['state'] = 'complete';
        $saved['expires_at'] = 1_700_000_000;
        $record->setValue($done, $saved);
        $key = 'lease:' . $harness->guildId . ':' . Harness::USER;
        $other = 'lease:' . $harness->guildId . ':' . '222222222222222223';
        $harness->store->put($key, $done->snapshot());
        $harness->store->put($other, ['user_id' => '222222222222222223']);
        new \ReflectionProperty(VoiceWaitBot::class, 'leases')->setValue($bot, [Harness::USER => $done]);
        $harness->loop->firePeriodic();
        self::assertNull($harness->store->get($key));
        self::assertSame(['user_id' => '222222222222222223'], $harness->store->get($other));
        self::assertArrayNotHasKey(Harness::USER, new \ReflectionProperty(VoiceWaitBot::class, 'leases')->getValue($bot));
    }

    public function testGuildCreateCastsANonIntegerUserId(): void
    {
        $harness = $this->harness();
        $bot = new VoiceWaitBot($harness->context, $this->transport($harness));
        $this->online($harness, $bot);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'voice_states' => [[
                'user_id' => 1.5,
                'channel_id' => Harness::CHANNEL,
                'session_id' => 'sess',
                'mute' => false,
            ]],
        ]);
        $states = new \ReflectionProperty(VoiceWaitBot::class, 'states')->getValue($bot);
        self::assertArrayHasKey('1.5', $states);
        self::assertArrayNotHasKey(1, $states);
    }

    private function lastBody(Harness $harness): string
    {
        $last = $harness->http->calls[array_key_last($harness->http->calls)] ?? null;
        $body = $last['body'] ?? [];

        return is_array($body) ? (string) json_encode($body) : '';
    }
}
