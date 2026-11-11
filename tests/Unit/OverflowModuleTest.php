<?php

declare(strict_types=1);

namespace Tests\Unit;

use Discord\Http\Exceptions\NotFoundException;
use Module\Overflow\MoveTransport;
use Module\Overflow\OverflowModule;
use Module\Overflow\OverflowPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use React\Http\Message\Response;
use React\Promise\Deferred;
use RuntimeException;
use Tests\Support\Clock;
use Tests\Support\Harness;

use function React\Promise\resolve;

#[CoversClass(OverflowModule::class)]
final class OverflowModuleTest extends GuildTestCase
{
    public function testPacketsUpdateOccupancyAndDoNotCreateBeforeReady(): void
    {
        $harness = $this->harness();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        self::assertSame('overflow', $module->name());
        self::assertSame([], $module->commands());
        self::assertNull($module->interact(['guild_id' => $harness->guildId, 'data' => ['name' => 'mywait']]));
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => '999999999999999991',
            'unavailable' => false,
            'channels' => [['id' => Harness::CHANNEL, 'type' => 2, 'name' => 'Raid', 'user_limit' => 1, 'position' => 0]],
            'voice_states' => [['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL]],
        ], 1);
        self::assertSame(0, $module->occupants(Harness::CHANNEL));
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                [
                    'id' => Harness::CHANNEL, 'type' => 2, 'name' => 'Raid', 'user_limit' => 1,
                    'position' => 2, 'parent_id' => '444444444444444444',
                ],
            ],
            'voice_states' => [
                ['user_id' => '', 'channel_id' => Harness::CHANNEL],
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ['user_id' => '444444444444444444', 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => true]]],
            ],
        ]);
        self::assertSame(2, $module->occupants(Harness::CHANNEL));
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        $harness->emitRaw('VOICE_STATE_UPDATE', ['guild_id' => $harness->guildId, 'user_id' => Harness::USER, 'channel_id' => null]);
        self::assertSame(1, $module->occupants(Harness::CHANNEL));
        $harness->emitRaw('VOICE_STATE_UPDATE', ['guild_id' => '999999999999999991', 'user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL]);
        self::assertSame(1, $module->occupants(Harness::CHANNEL));
        $harness->emitRaw('VOICE_STATE_UPDATE', ['guild_id' => $harness->guildId, 'user_id' => '']);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $ref = new \ReflectionProperty($module, 'online');
        $ref->setValue($module, true);
        Harness::clearStderr();
        $harness->emitRaw('RECONNECT', [], 7);
        self::assertStringContainsString('Gateway requested a reconnect or invalidated the session. Overflow moves are paused.', Harness::stderr());
        Harness::clearStderr();
        $ref->setValue($module, true);
        $harness->emitRaw('INVALID_SESSION', [], 9);
        self::assertStringContainsString('Gateway requested a reconnect or invalidated the session. Overflow moves are paused.', Harness::stderr());
        Harness::clearStderr();
        $ref->setValue($module, true);
        $harness->discord->emit('voicewait.gateway_gap', []);
        self::assertStringContainsString('Gateway disconnected. Overflow moves are paused.', Harness::stderr());
    }

    public function testAFullChannelCreatesAnOverflowAfterTheGatewayIsReady(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 2);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => true,
        ]);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $created = $source;
        $created['id'] = '666666666666666666';
        $created['name'] = 'Raid Overflow';
        $created['user_limit'] = 0;
        $this->scriptManage($harness);
        $harness->discord->emit('init', []);
        $harness->flush();

        $second = '444444444444444444';
        $fresh = $harness->voiceChannel($second, 'Full', 1, 3);
        $harness->emitRaw('CHANNEL_CREATE', $fresh);

        $denied = $harness->botPermissions() & ~\App\Permissions::MANAGE_CHANNELS;

        // The create() method performs these GETs.
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($denied));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . $second, $fresh);

        $harness->emitRaw('VOICE_STATE_UPDATE', ['guild_id' => $harness->guildId, 'user_id' => '444444444444444444', 'channel_id' => $second, 'member' => ['user' => ['guildmanager' => false]]]);
        $harness->flush();

        self::assertStringContainsString('Overflow: the guildmanager lacks Manage Channels; no overflow created for channel ' . $second . '.', Harness::stderr());
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        self::assertStringNotContainsString('Overflow creation failed for channel ' . $second, Harness::stderr());
    }

    public function testSavedRecordsAreFilteredAndAMissingOverflowIsDropped(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            'bad' => ['overflow_id' => Harness::CHANNEL, 'owned' => true],
            Harness::CHANNEL => ['overflow_id' => 'not-a-snowflake', 'owned' => true],
            '444444444444444444' => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
            '555555555555555555' => ['overflow_id' => '', 'owned' => true, 'pending' => true],
            '343434343434343434' => ['overflow_id' => null, 'owned' => true, 'pending' => true],
            '343434343434343435' => ['overflow_id' => null, 'owned' => true, 'pending' => true],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $kept = $harness->voiceChannel('343434343434343435', 'Kept', 1, 1);
        $adopted = $harness->voiceChannel('343434343434343436', \Module\Overflow\OverflowPolicy::overflowName('Kept', $template), 0, 2);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel('555555555555555555', 'Gone', 2), $kept, $adopted],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => '343434343434343435', 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->http->queue('GET', 'channels/666666666666666666', new NotFoundException('missing'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertNull($module->trackedOverflow('444444444444444444'));
        self::assertNull($module->trackedOverflow('343434343434343434'));
        self::assertSame('343434343434343436', $module->trackedOverflow('343434343434343435'));
        self::assertStringContainsString('Overflow: adopted channel 343434343434343436 for channel 343434343434343435 after a restart.', Harness::stderr());
    }

    public function testAWaiterIsMovedWhenASeatOpensAndAnEmptyOverflowIsDeleted(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $source = Harness::CHANNEL;
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';

        $harness->store->put('overflows:' . $harness->guildId, [
            $source => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);

        $calls = 0;
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', function () use (&$calls, $source) {
            ++$calls;
            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"' . $source . '"}'));
        }));
        $module->attach($harness->context);

        $sourceChannel = $harness->voiceChannel($source, 'Raid', 1, 1);
        $expectedName = \Module\Overflow\OverflowPolicy::overflowName('Raid', $template);
        $overflowChannel = $harness->voiceChannel($overflow, $expectedName, 0, 2);

        // Queue what moveOne and confirmOverflows need before init/reconcile
        $harness->http->queue('GET', 'channels/' . $overflow, $overflowChannel);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => $overflow]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . $source, $sourceChannel);

        $harness->discord->emit('init', []);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$sourceChannel, $overflowChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->loop->firePeriodic();
        $harness->flush();

        self::assertStringContainsString('Overflow: moved member ' . Harness::USER . ' into channel ' . $source . '.', Harness::stderr());
        self::assertNotEmpty($calls);
        self::assertStringNotContainsString('move failed', Harness::stderr());
        self::assertStringNotContainsString('rename failed', Harness::stderr());
        self::assertSame($overflow, $module->trackedOverflow($source));

        // Now leave and delete overflow
        $harness->http->queue('GET', 'channels/' . $overflow, $overflowChannel);
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);

        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->flush();

        self::assertNull($module->trackedOverflow($source));
        self::assertStringContainsString('Overflow: removed channel ' . $overflow . ' after channel ' . $source . ' dropped below its limit.', Harness::stderr());
    }

    public function testRegisterOnlyDoesNotReconcileAndACreateFailureIsLogged(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', static fn() => resolve(new Response(200, [], '{}'))), true);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $harness->discord->emit('reconnected', []);
        $harness->emitRaw('GUILD_DELETE', ['id' => $harness->guildId]);
        $harness->emitRaw('CHANNEL_UPDATE', ['guild_id' => '999999999999999991', 'id' => Harness::CHANNEL]);
        self::assertSame(0, $module->occupants(Harness::CHANNEL));
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL]],
        ]);
        $harness->http->queue('GET', 'guilds/', new RuntimeException('down', 1));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow process failed for channel ' . Harness::CHANNEL, Harness::stderr());
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
    }

    public function testWaitingListsTheMemberStillInTheOverflow(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        self::assertSame([Harness::USER], $module->waiting($overflow));
    }

    public function testTickDoesNothingBeforeTheBotIsIdentified(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $harness->loop->firePeriodic();
        self::assertSame(0, $module->occupants(Harness::CHANNEL));
    }

    public function testATextChannelIsNotManaged(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $harness->emitRaw('CHANNEL_UPDATE', [
            'id' => '121212121212121212',
            'guild_id' => $harness->guildId,
            'type' => 0,
            'name' => 'general',
            'user_limit' => 1,
        ]);
        self::assertSame(0, $module->occupants('121212121212121212'));
        self::assertNull($module->trackedOverflow('121212121212121212'));
    }

    public function testUpdatingAnOverflowReevaluatesItsSource(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel($overflow, $name, 0, 2));
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testAMissingOverflowIsFetchedAndRenamed(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $expected = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $fetched = $harness->voiceChannel($overflow, 'Wrong', 0, 9);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->http->queue('GET', 'channels/' . $overflow, $fetched);
        $harness->http->queue('PATCH', 'channels/' . $overflow, ['id' => $overflow, 'name' => $expected, 'type' => 2]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $patched = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'PATCH' && str_contains($call['url'], $overflow),
        ));
        self::assertNotEmpty($patched);
        self::assertSame($expected, $patched[0]['body']['name'] ?? null);
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testAFailedRenameIsNotRetriedUntilTheWindowPasses(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $expected = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $wrong = $harness->voiceChannel($overflow, 'Wrong', 0, 2);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $wrong],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->http->queue('PATCH', 'channels/' . $overflow, new RuntimeException('rename down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow rename failed for channel ' . $overflow, Harness::stderr());
        $patches = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($patches, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        Clock::$overflow = 1_700_000_070;
        $harness->http->queue('PATCH', 'channels/' . $overflow, ['id' => $overflow, 'name' => $expected, 'type' => 2]);
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertGreaterThan($patches, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        unset($module);
    }

    public function testCreateCopiesSettingsAndPlacesTheOverflowUnderTheSource(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $parent = '444444444444444440';
        $otherParent = '444444444444444441';
        $sibling = '444444444444444442';
        $between = '444444444444444443';
        $createdId = '777777777777777777';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $source['bitrate'] = 64000;
        $source['rtc_region'] = 'us-east';
        $source['video_quality_mode'] = 2;
        $source['nsfw'] = true;
        $source['permission_overwrites'] = [
            ['id' => Harness::ROLE, 'type' => 0, 'allow' => '1024', 'deny' => '0'],
        ];
        $other = $harness->voiceChannel($sibling, 'Elsewhere', 0, 3);
        $other['parent_id'] = $otherParent;
        $betweenChannel = $harness->voiceChannel($between, 'Between', 0, 2);
        $betweenChannel['parent_id'] = $parent;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $other, $betweenChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $fresh = $source;
        $fresh['parent_id'] = (int) $parent;
        $fresh['bitrate'] = '8000';
        $fresh['rtc_region'] = 1;
        $fresh['video_quality_mode'] = '2';
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $fresh);
        $created = $harness->voiceChannel($createdId, 'created', 0, 9);
        $created['parent_id'] = $parent;
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $created);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow: created channel ' . $createdId . ' under full channel ' . Harness::CHANNEL . '.', Harness::stderr());
        self::assertSame($createdId, $module->trackedOverflow(Harness::CHANNEL));
        self::assertFalse($harness->store->get('overflows:' . $harness->guildId)[Harness::CHANNEL]['pending'] ?? true);
        $remembered = new \ReflectionProperty(OverflowModule::class, 'channels')->getValue($module);
        self::assertSame('8000', $remembered[Harness::CHANNEL]['bitrate'] ?? null);
        self::assertSame($createdId, $harness->store->get('overflows:' . $harness->guildId)[Harness::CHANNEL]['overflow_id'] ?? null);
        $posts = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        self::assertNotEmpty($posts);
        $body = $posts[0]['body'];
        self::assertSame($parent, $body['parent_id']);
        self::assertSame(2, $body['type']);
        self::assertSame(0, $body['user_limit']);
        self::assertSame(8000, $body['bitrate']);
        self::assertSame('1', $body['rtc_region']);
        self::assertSame(2, $body['video_quality_mode']);
        self::assertTrue($body['nsfw']);
        self::assertSame(Harness::ROLE, $body['permission_overwrites'][0]['id']);
        $patches = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'PATCH' && str_contains($call['url'], '/channels'),
        ));
        self::assertNotEmpty($patches);
        $patchedIds = array_map(static fn(mixed $id): string => (string) $id, array_column($patches[0]['body'], 'id'));
        self::assertContains($createdId, $patchedIds);
        self::assertNotContains($sibling, $patchedIds);
    }

    public function testCreateDropsAPendingRecordWhenThePostFailsOrReturnsNoChannel(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', []);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('created channel', Harness::stderr());
        self::assertArrayNotHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);

        Harness::clearStderr();
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', new RuntimeException('create down'));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => '444444444444444444',
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertStringContainsString('Overflow creation failed for channel ' . Harness::CHANNEL, Harness::stderr());
        self::assertArrayNotHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        $retry = new \ReflectionProperty(OverflowModule::class, 'createRetryAt')->getValue($module);
        self::assertSame(1_700_000_030, $retry[Harness::CHANNEL] ?? null);
        $posts = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($posts, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
    }

    public function testCreateAbortsWhenTheFreshChannelIsNotAFullVoiceChannel(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $stale = $source;
        $stale['type'] = 0;
        $stale['user_limit'] = 0;
        $this->queueCreateReads($harness, $stale);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringNotContainsString('created channel', Harness::stderr());
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
    }

    public function testCreateAbortsWhenTheSessionIsInvalidated(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $source);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $source);
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('INVALID_SESSION', [], 9);
        $deferred->resolve($harness->guild());
        $harness->flush();
        self::assertStringContainsString('Gateway requested a reconnect or invalidated the session.', Harness::stderr());
        self::assertStringNotContainsString('created channel', Harness::stderr());
    }

    public function testAMoveIsNotRetriedWhileCoolingDown(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, new RuntimeException('voice down'));
        $this->openWait($harness);
        $harness->flush();
        self::assertStringContainsString('Overflow move failed for member ' . Harness::USER, Harness::stderr());
        $reads = count(array_filter($harness->http->calls, static fn(array $call): bool => str_contains($call['url'], 'voice-states')));
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($reads, count(array_filter($harness->http->calls, static fn(array $call): bool => str_contains($call['url'], 'voice-states'))));
        self::assertSame('666666666666666666', $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testAMoveIsSkippedWhenTheMemberAlreadyLeftOrTheBotCannotMove(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, new NotFoundException('gone'));
        $this->openWait($harness);
        $harness->flush();
        self::assertStringNotContainsString('moved member', Harness::stderr());
        self::assertStringNotContainsString('move failed', Harness::stderr());

        Harness::clearStderr();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => Harness::CHANNEL]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => '666666666666666666',
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->flush();
        self::assertStringNotContainsString('moved member', Harness::stderr());
        self::assertStringNotContainsString('move failed', Harness::stderr());

        Harness::clearStderr();
        $denied = $harness->botPermissions() & ~\App\Permissions::MOVE_MEMBERS;
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($denied));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertStringContainsString('Overflow: the guildmanager lacks Move Members, Connect, or View Channel; not moving members into channel ' . Harness::CHANNEL . '.', Harness::stderr());
        unset($module);
    }

    public function testAMoveIsAbandonedWhenTheSessionIsInvalidated(): void
    {
        $harness = $this->harness();
        $this->bootWait($harness);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, $deferred->promise());
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('RECONNECT', [], 7);
        $deferred->resolve(['channel_id' => '666666666666666666']);
        $harness->flush();
        self::assertStringNotContainsString('moved member', Harness::stderr());
        self::assertStringContainsString('Gateway requested a reconnect or invalidated the session.', Harness::stderr());
    }

    public function testAnEmptyOverflowIsDeletedAndAFailedDeleteIsRetriedLater(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1);
        $overflowChannel = $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $overflowChannel],
            'voice_states' => [],
        ]);
        $harness->http->queue('GET', 'channels/' . $overflow, new RuntimeException('delete down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow delete failed for channel ' . $overflow, Harness::stderr());
        $gets = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'GET' && str_contains($call['url'], $overflow)));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($gets, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'GET' && str_contains($call['url'], $overflow))));

        Clock::$overflow = 1_700_000_040;
        $harness->http->queue('GET', 'channels/' . $overflow, $overflowChannel);
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        self::assertStringContainsString('Overflow: removed channel ' . $overflow . ' after channel ' . Harness::CHANNEL . ' dropped below its limit.', Harness::stderr());
    }

    public function testDeleteIsDroppedWhenTheOverflowIsGoneOrNoLongerAVoiceChannel(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $harness->http->queue('GET', 'channels/' . $overflow, new NotFoundException('gone'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('removed channel', Harness::stderr());

        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $wrong = $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2);
        $wrong['type'] = 0;
        $harness->http->queue('GET', 'channels/' . $overflow, $wrong);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('removed channel', Harness::stderr());
    }

    public function testDeleteIsAbandonedWhenTheChannelFillsBeforeTheConfirm(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness, 1);
        $overflow = '666666666666666666';
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('removed channel', Harness::stderr());
    }

    public function testAnOccupiedOrphanOverflowIsLeftUntilItEmpties(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel($overflow, 'Raid Overflow', 0, 2)],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow: source channel ' . Harness::CHANNEL . ' is gone; leaving occupied overflow ' . $overflow . ' in place.', Harness::stderr());
        Harness::clearStderr();
        $harness->loop->firePeriodic();
        self::assertStringNotContainsString('leaving occupied overflow', Harness::stderr());
        $harness->http->queue('GET', 'channels/' . $overflow, $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->flush();
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        self::assertStringContainsString('Overflow: removed channel ' . $overflow, Harness::stderr());
    }

    public function testAPositionUpdateFailureIsLoggedAndAStaleCreateDoesNotPlaceTheChannel(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $parent = '444444444444444440';
        $between = '444444444444444443';
        $createdId = '777777777777777777';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $betweenChannel = $harness->voiceChannel($between, 'Between', 0, 2);
        $betweenChannel['parent_id'] = $parent;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $betweenChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $this->queueCreateReads($harness, $source);
        $created = $harness->voiceChannel($createdId, 'created', 0, 9);
        $created['parent_id'] = $parent;
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $created);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', new RuntimeException('position down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow position update failed for channel ' . $createdId, Harness::stderr());
        self::assertSame($createdId, $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testCreateDoesNotPlaceAnOverflowAfterTheSessionIsInvalidated(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $this->queueCreateReads($harness, $source);
        $created = $harness->voiceChannel('777777777777777777', 'created', 0, 9);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('INVALID_SESSION', [], 9);
        $deferred->resolve($created);
        $harness->flush();
        self::assertStringContainsString('created channel 777777777777777777', Harness::stderr());
        $patches = array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH');
        self::assertCount(0, $patches);
        unset($module);
    }

    public function testAMoveStopsWhenTheSessionIsInvalidatedDuringThePermissionCheck(): void
    {
        $harness = $this->harness();
        $this->bootWait($harness);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('RECONNECT', [], 7);
        $deferred->resolve($harness->guild());
        $harness->flush();
        self::assertStringNotContainsString('moved member', Harness::stderr());
        self::assertStringNotContainsString('lacks Move Members', Harness::stderr());
    }

    public function testDeleteStopsWhenTheSessionChangesOrSomeoneEntersTheOverflow(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('RECONNECT', [], 7);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));

        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('removed channel', Harness::stderr());
    }

    public function testCallerGuardsAreNotRepeatedByThePrivateHelpers(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $overflow = '666666666666666666';
        $parent = '444444444444444440';
        $hidden = '444444444444444449';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $hiddenChannel = $harness->voiceChannel($hidden, 'Hidden', 0, 3);
        $hiddenChannel['parent_id'] = $parent;
        $hiddenChannel['flags'] = 1 << 17;
        $text = $harness->voiceChannel('121212121212121212', 'general', 1, 4);
        $text['type'] = 0;

        $online = new \ReflectionProperty(OverflowModule::class, 'online');
        $online->setValue($module, true);
        $guildAvailable = new \ReflectionProperty(OverflowModule::class, 'guildAvailable');
        $guildAvailable->setValue($module, true);
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels');
        $channels->setValue($module, [
            Harness::CHANNEL => $source,
            $hidden => $hiddenChannel,
            '121212121212121212' => $text,
        ]);
        $records = new \ReflectionProperty(OverflowModule::class, 'records');
        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => false, 'pending' => false],
        ]);

        $this->call($module, 'onGuild', [['id' => '999999999999999991']]);
        self::assertTrue($online->getValue($module));
        $this->call($module, 'onGuild', [['id' => $harness->guildId, 'unavailable' => true]]);
        self::assertStringContainsString('Guild became unavailable.', Harness::stderr());

        $guildAvailable->setValue($module, true);
        $online->setValue($module, true);
        self::assertFalse($this->call($module, 'isManageableSource', ['121212121212121212']));
        $before = count($harness->http->calls);
        $this->call($module, 'evaluate', ['121212121212121212']);
        $this->call($module, 'create', [Harness::CHANNEL]);
        $this->call($module, 'create', ['121212121212121212']);
        $this->call($module, 'moveOne', ['121212121212121212', false]);
        $this->call($module, 'deleteOverflow', [Harness::CHANNEL]);
        $this->call($module, 'renameIfNeeded', [Harness::CHANNEL]);
        $this->call($module, 'retireMissingSource', ['343434343434343434']);
        self::assertSame($before, count($harness->http->calls));

        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $channels->setValue($module, [Harness::CHANNEL => $source]);
        $this->call($module, 'renameIfNeeded', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));

        $voices = new \ReflectionProperty(OverflowModule::class, 'voices');
        $voices->setValue($module, [Harness::USER => $overflow]);
        $this->call($module, 'deleteOverflow', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));

        $voices->setValue($module, [Harness::USER => Harness::CHANNEL]);
        $full = $source;
        $full['user_limit'] = 1;
        $channels->setValue($module, [Harness::CHANNEL => $full]);
        $this->call($module, 'deleteOverflow', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));

        $order = new \ReflectionProperty(OverflowModule::class, 'order');
        $order->setValue($module, [$overflow => [Harness::BOT]]);
        $voices->setValue($module, [Harness::BOT => $overflow]);
        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $channels->setValue($module, [Harness::CHANNEL => $source, $overflow => $harness->voiceChannel($overflow, 'Overflow', 0, 2)]);
        $this->call($module, 'moveOne', [Harness::CHANNEL, false]);
        self::assertSame($before, count($harness->http->calls));

        $moving = new \ReflectionProperty(OverflowModule::class, 'moving');
        $moving->setValue($module, [Harness::USER => true]);
        $voices->setValue($module, [Harness::USER => $overflow]);
        self::assertTrue($this->call($module, 'moveInFlight', [$overflow]));

        $beforePlace = count($harness->http->calls);
        $missing = $this->call($module, 'placeUnder', ['999999999999999991', $overflow]);
        self::assertInstanceOf(\React\Promise\PromiseInterface::class, $missing);
        self::assertSame($beforePlace, count($harness->http->calls));
        $adjacent = $harness->voiceChannel($overflow, 'Overflow', 0, 2);
        $adjacent['parent_id'] = $parent;
        $channels->setValue($module, [Harness::CHANNEL => $source, $overflow => $adjacent]);
        $alreadyPlaced = $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        self::assertInstanceOf(\React\Promise\PromiseInterface::class, $alreadyPlaced);
        self::assertSame($beforePlace, count($harness->http->calls));

        $between = $harness->voiceChannel('444444444444444443', 'Between', 0, 2);
        $between['parent_id'] = $parent;
        $channels->setValue($module, [
            Harness::CHANNEL => $source,
            $hidden => $hiddenChannel,
            '444444444444444443' => $between,
        ]);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        $harness->flush();
        $patches = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        self::assertNotEmpty($patches);
        $ids = array_map(static fn(mixed $id): string => (string) $id, array_column($patches[0]['body'], 'id'));
        self::assertContains($overflow, $ids);
        self::assertNotContains($hidden, $ids);
    }

    public function testAPermissionFailureNoteIsClearedByALaterMove(): void
    {
        $harness = $this->harness();
        $this->bootWait($harness);
        $denied = $harness->botPermissions() & ~\App\Permissions::MOVE_MEMBERS;
        $phrase = 'Overflow: the guildmanager lacks Move Members, Connect, or View Channel; not moving members into channel ' . Harness::CHANNEL . '.';
        $this->queueMovePermission($harness, $denied);
        $this->openWait($harness);
        $harness->flush();
        self::assertSame(1, substr_count(Harness::stderr(), $phrase));

        $this->queueMovePermission($harness, $harness->botPermissions());
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertStringContainsString('Overflow: moved member ' . Harness::USER . ' into channel ' . Harness::CHANNEL . '.', Harness::stderr());

        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => '666666666666666666',
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $this->queueMovePermission($harness, $denied);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertSame(2, substr_count(Harness::stderr(), $phrase));
    }

    public function testMovePermissionRetryLastsSixtySecondsAndDoesNotDispatch(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $calls = 0;
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
        ]);
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"' . Harness::CHANNEL . '"}'));
        }));
        $module->attach($harness->context);
        $denied = $harness->botPermissions() & ~\App\Permissions::MOVE_MEMBERS;
        $this->queueMovePermission($harness, $denied);
        $this->openWait($harness);
        $harness->flush();
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame(0, $calls);
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());

        $reads = $this->voiceReads($harness);
        Clock::$overflow = 1_700_000_059;
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($reads, $this->voiceReads($harness));
        Clock::$overflow = 1_700_000_060;
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($reads + 1, $this->voiceReads($harness));
        unset($module);
    }

    public function testAMoveGuardRejectsAStaleSessionEvenIfTheMemberIsStillWaiting(): void
    {
        $harness = $this->harness();
        $calls = 0;
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
        ]);
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', function () use (&$calls) {
            ++$calls;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"' . Harness::CHANNEL . '"}'));
        }));
        $module->attach($harness->context);
        $this->queueMovePermission($harness, $harness->botPermissions());
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('RECONNECT', [], 7);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame(0, $calls);
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());
        unset($module);
    }

    public function testAnInFlightDeleteIsNotStartedAgain(): void
    {
        $harness = $this->harness();
        $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $gets = $this->overflowGets($harness, $overflow);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertSame($gets, $this->overflowGets($harness, $overflow));
        self::assertStringNotContainsString('Overflow delete failed', Harness::stderr());
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->flush();
    }

    public function testDeleteConfirmAbortsWhenTheEpochChangesWhileTheBotIsStillOnline(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $epoch = new \ReflectionProperty(OverflowModule::class, 'epoch');
        $epoch->setValue($module, $epoch->getValue($module) + 1);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame(0, $this->deletes($harness, $overflow));
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('Overflow delete failed', Harness::stderr());
    }

    public function testDeleteConfirmRefusesAFullSourceAndANonVoiceChannel(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness, 1);
        $overflow = '666666666666666666';
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame(0, $this->deletes($harness, $overflow));
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testDeleteConfirmRefusesAChannelThatIsNotVoice(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $wrong = $harness->voiceChannel($overflow, $name, 0, 2);
        $wrong['type'] = 0;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [],
        ]);
        $harness->http->queue('GET', 'channels/' . $overflow, $wrong);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame(0, $this->deletes($harness, $overflow));
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringNotContainsString('Overflow delete failed for channel ' . $overflow, Harness::stderr());
    }

    public function testAMissingOverflowIsDroppedFromTheStoreWithoutADeleteFailure(): void
    {
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $harness->http->queue('GET', 'channels/' . $overflow, new NotFoundException('gone'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        $saved = $harness->store->get('overflows:' . $harness->guildId);
        self::assertIsArray($saved);
        self::assertArrayNotHasKey(Harness::CHANNEL, $saved);
        self::assertStringNotContainsString('Overflow delete failed for channel ' . $overflow, Harness::stderr());
    }

    public function testAStringDispatchOpcodeStillAppliesTheGuildSnapshot(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1)],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ], '0');
        self::assertSame(1, $module->occupants(Harness::CHANNEL));
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1)],
            'voice_states' => [
                ['user_id' => '444444444444444444', 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ], 1);
        self::assertSame(1, $module->occupants(Harness::CHANNEL));
    }

    public function testGuildDeleteMatchesAnIntegerIdAndIgnoresAnotherGuild(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->emitRaw('GUILD_DELETE', ['id' => '999999999999999991']);
        self::assertStringNotContainsString('Guild became unavailable or the guildmanager was removed.', Harness::stderr());
        $harness->emitRaw('GUILD_DELETE', ['id' => (int) $harness->guildId]);
        self::assertStringContainsString('Guild became unavailable or the guildmanager was removed. Overflow moves are paused.', Harness::stderr());
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        unset($module);
    }

    public function testRegisterOnlyDoesNotReconcileOnInitOrReconnect(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', static fn() => resolve(new Response(200, [], '{}'))), true);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1)],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        new \ReflectionProperty(OverflowModule::class, 'awaitingGuild')->setValue($module, true);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        self::assertStringNotContainsString('created channel', Harness::stderr());
    }

    public function testInitStaysOfflineWhileTheGuildSnapshotIsStillMissing(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1)],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'awaitingGuild')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        self::assertStringNotContainsString('created channel', Harness::stderr());
    }

    public function testReconnectMovesAWaiterAndAGapStopsTheNextMove(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->emitRaw('RECONNECT', [], 7);
        $this->queueMovePermission($harness, $harness->botPermissions());
        $harness->discord->emit('reconnected', []);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertStringContainsString('Overflow: moved member ' . Harness::USER . ' into channel ' . Harness::CHANNEL . '.', Harness::stderr());
        Harness::clearStderr();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->emitRaw('RECONNECT', [], 7);
        $this->queueMovePermission($harness, $harness->botPermissions());
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());
        unset($module);
    }

    public function testWaitingReindexesAndAForeignGuildDoesNotWipeOccupancy(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel($overflow, 'Raid Overflow', 0, 2)],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $order = new \ReflectionProperty(OverflowModule::class, 'order');
        $order->setValue($module, [$overflow => [1 => Harness::USER]]);
        self::assertSame([Harness::USER], $module->waiting($overflow));
        $this->call($module, 'onGuild', [['id' => '999999999999999991', 'unavailable' => false, 'channels' => [], 'voice_states' => []]]);
        self::assertSame(1, $module->occupants($overflow));
        $this->call($module, 'onGuild', [['id' => $harness->guildId, 'unavailable' => true, 'channels' => [], 'voice_states' => []]]);
        self::assertSame(1, $module->occupants($overflow));
    }

    public function testDeleteRetryOpensOnTheThirtiethSecond(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $module = $this->bootEmptyOverflow($harness);
        $overflow = '666666666666666666';
        $harness->http->queue('GET', 'channels/' . $overflow, new RuntimeException('delete down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        $gets = $this->overflowGets($harness, $overflow);
        Clock::$overflow = 1_700_000_029;
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertSame($gets, $this->overflowGets($harness, $overflow));
        Clock::$overflow = 1_700_000_030;
        $harness->http->queue('GET', 'channels/' . $overflow, $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $harness->flush();
        self::assertSame($gets + 1, $this->overflowGets($harness, $overflow));
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
    }

    public function testRenameUsesTheConfiguredTemplateAndSkipsAnUnownedChannel(): void
    {
        $previous = getenv('CHANNEL_TEMPLATE');
        putenv('CHANNEL_TEMPLATE=Wait <channel>');
        try {
            $harness = $this->harness();
            $harness->identify();
            $overflow = '666666666666666666';
            $expected = OverflowPolicy::overflowName('Raid', 'Wait <channel>');
            $harness->store->put('overflows:' . $harness->guildId, [
                Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => false, 'pending' => false],
            ]);
            $module = $this->module($harness);
            $module->attach($harness->context);
            $harness->emitRaw('GUILD_CREATE', [
                'id' => $harness->guildId,
                'unavailable' => false,
                'channels' => [
                    $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                    $harness->voiceChannel($overflow, 'Wrong', 0, 2),
                ],
                'voice_states' => [
                    ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ],
            ]);
            $harness->discord->emit('init', []);
            $harness->flush();
            self::assertSame(0, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));

            $harness->store->put('overflows:' . $harness->guildId, [
                Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
            ]);
            $owned = $this->module($harness);
            $owned->attach($harness->context);
            $harness->emitRaw('GUILD_CREATE', [
                'id' => $harness->guildId,
                'unavailable' => false,
                'channels' => [
                    $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                    $harness->voiceChannel($overflow, 'Wrong', 0, 2),
                ],
                'voice_states' => [
                    ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ],
            ]);
            $harness->http->queue('PATCH', 'channels/' . $overflow, ['id' => $overflow, 'name' => $expected, 'type' => 2]);
            $harness->discord->emit('init', []);
            $harness->flush();
            $patches = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
            self::assertNotEmpty($patches);
            self::assertSame($expected, $patches[0]['body']['name'] ?? null);
            $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1));
            $harness->flush();
            self::assertCount(1, array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        } finally {
            if ($previous === false) {
                putenv('CHANNEL_TEMPLATE');
            } else {
                putenv('CHANNEL_TEMPLATE=' . $previous);
            }
        }
    }

    public function testAFailedRenameRetriesAfterSixtySeconds(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, 'Wrong', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->http->queue('PATCH', 'channels/' . $overflow, new RuntimeException('rename down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        $patches = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        Clock::$overflow = 1_700_000_059;
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1));
        $harness->flush();
        self::assertSame($patches, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        Clock::$overflow = 1_700_000_060;
        $harness->http->queue('PATCH', 'channels/' . $overflow, ['id' => $overflow, 'name' => 'fixed', 'type' => 2]);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1));
        $harness->flush();
        self::assertSame($patches + 1, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        unset($module);
    }

    public function testAdoptionIsSavedAndAMissingSourceIsRemovedFromTheStore(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => true],
            '444444444444444444' => ['overflow_id' => null, 'owned' => true, 'pending' => true],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $saved = $harness->store->get('overflows:' . $harness->guildId);
        self::assertSame($overflow, $saved[Harness::CHANNEL]['overflow_id'] ?? null);
        self::assertArrayNotHasKey('444444444444444444', $saved);
        unset($module);
    }

    public function testConfirmChecksEverySavedOverflowAndDropsAMissingOneFromTheStore(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $known = '666666666666666666';
        $missing = '777777777777777777';
        $harness->store->put('overflows:' . $harness->guildId, [
            '444444444444444444' => ['overflow_id' => $known, 'owned' => true, 'pending' => false],
            Harness::CHANNEL => ['overflow_id' => $missing, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel('444444444444444444', 'Known', 1, 1),
                $harness->voiceChannel($known, 'Known Overflow', 0, 2),
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
            ],
            'voice_states' => [],
        ]);
        $harness->http->queue('GET', 'channels/' . $missing, new NotFoundException('gone'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame($known, $module->trackedOverflow('444444444444444444'));
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
        $saved = $harness->store->get('overflows:' . $harness->guildId);
        self::assertArrayNotHasKey(Harness::CHANNEL, $saved);
        self::assertSame(1, $this->overflowGets($harness, $missing));
    }

    public function testAUserInTheSourceIsNotWaitingAndABotIsNotAWaiter(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ['user_id' => Harness::BOT, 'channel_id' => $overflow],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame([], $module->waiting(Harness::CHANNEL));
        self::assertSame([], $module->waiting($overflow));
    }

    public function testJoinOrderKeepsEarlierWaitersWhenSomeoneLeaves(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $second = '444444444444444444';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $second,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        self::assertSame([Harness::USER, $second], $module->waiting($overflow));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        self::assertSame([$second], $module->waiting($overflow));
    }

    public function testSavedOverflowIdsMustBeBareSnowflakes(): void
    {
        $harness = $this->harness();
        $good = '444444444444444444';
        $badSource = 'x' . Harness::CHANNEL;
        $harness->store->put('overflows:' . $harness->guildId, [
            $badSource => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
            '555555555555555551' => ['overflow_id' => 'not-a-snowflake', 'owned' => true, 'pending' => false],
            $good => ['overflow_id' => ['nested'], 'owned' => true, 'pending' => false],
            Harness::CHANNEL => ['overflow_id' => '777777777777777777', 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        self::assertNull($module->trackedOverflow($badSource));
        self::assertNull($module->trackedOverflow('555555555555555551'));
        self::assertNull($module->trackedOverflow($good));
        self::assertSame('777777777777777777', $module->trackedOverflow(Harness::CHANNEL));
    }

    public function testAnIntegerOverflowIdStillPointsAtItsSource(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => (int) $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [],
        ]);
        $harness->emitRaw('CHANNEL_DELETE', [
            'guild_id' => $harness->guildId,
            'id' => $overflow,
        ]);
        self::assertNull($module->trackedOverflow(Harness::CHANNEL));
    }

    public function testPlaceSkipsAForeignParentAndStillMovesTheNextSibling(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $overflow = '666666666666666666';
        $parent = '444444444444444440';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $foreign = $harness->voiceChannel('444444444444444441', 'Other', 0, 2);
        $foreign['parent_id'] = '444444444444444449';
        $sibling = $harness->voiceChannel('444444444444444442', 'Next', 0, 3);
        $sibling['parent_id'] = $parent;
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels');
        $channels->setValue($module, [
            Harness::CHANNEL => $source,
            '444444444444444441' => $foreign,
            '444444444444444442' => $sibling,
        ]);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        $harness->flush();
        $patches = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        self::assertNotEmpty($patches, json_encode($harness->http->calls));
        $ids = array_map(static fn(mixed $id): string => (string) $id, array_column($patches[0]['body'], 'id'));
        self::assertContains('444444444444444442', $ids, json_encode($patches[0]['body']));
        unset($module);
    }

    public function testAMissingPositionSortsAsZero(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $overflow = '666666666666666666';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 0);
        $earlier = $harness->voiceChannel('100000000000000001', 'Earlier', 0, 0);
        $later = $harness->voiceChannel('999999999999999999', 'Later', 0, 0);
        unset($earlier['position'], $later['position']);
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels');
        $channels->setValue($module, [
            Harness::CHANNEL => $source,
            '100000000000000001' => $earlier,
            '999999999999999999' => $later,
        ]);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        $harness->flush();
        $patches = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        self::assertNotEmpty($patches, json_encode($harness->http->calls));
        $ids = array_map(static fn(mixed $id): string => (string) $id, array_column($patches[0]['body'], 'id'));
        self::assertContains('999999999999999999', $ids, json_encode($patches[0]['body']));
        self::assertNotContains('100000000000000001', $ids, json_encode($patches[0]['body']));
        unset($module);
    }

    public function testAPositionUpdateIsRememberedSoTheNextPlaceIsQuiet(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $overflow = '666666666666666666';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $between = $harness->voiceChannel('444444444444444443', 'Between', 0, 2);
        $created = $harness->voiceChannel($overflow, 'Overflow', 0, 9);
        $parent = '444444444444444440';
        $source['parent_id'] = $parent;
        $between['parent_id'] = $parent;
        $created['parent_id'] = $parent;
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels');
        $channels->setValue($module, [
            Harness::CHANNEL => $source,
            '444444444444444443' => $between,
            $overflow => $created,
        ]);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        $harness->flush();
        $patches = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH'));
        self::assertSame(1, $patches);
        $this->call($module, 'placeUnder', [Harness::CHANNEL, $overflow]);
        $harness->flush();
        self::assertSame(1, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        unset($module);
    }

    public function testAnInFlightMoveIsNotStartedAgain(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, $deferred->promise());
        $this->openWait($harness);
        $started = $this->voiceReads($harness);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        self::assertSame($started, $this->voiceReads($harness));
        $deferred->resolve(['channel_id' => '666666666666666666']);
        $harness->flush();
        unset($module);
    }

    public function testAMoveStopsWhenOnlyTheEpochChanges(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $voice = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, $voice->promise());
        $this->openWait($harness);
        $harness->flush();
        $epoch = new \ReflectionProperty(OverflowModule::class, 'epoch');
        $epoch->setValue($module, $epoch->getValue($module) + 1);
        $before = count($harness->http->calls);
        $voice->resolve(['channel_id' => '666666666666666666']);
        $harness->flush();
        self::assertSame($before, count($harness->http->calls));
        self::assertStringNotContainsString('moved member', Harness::stderr());
        unset($module);
    }

    public function testAMoveStopsWhenTheEpochChangesDuringThePermissionCheck(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $guild = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $guild->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Renamed', 2, 1));
        $this->openWait($harness);
        $harness->flush();
        $epoch = new \ReflectionProperty(OverflowModule::class, 'epoch');
        $epoch->setValue($module, $epoch->getValue($module) + 1);
        $guild->resolve($harness->guild());
        $harness->flush();
        self::assertStringNotContainsString('moved member', Harness::stderr());
        self::assertStringNotContainsString('lacks Move Members', Harness::stderr());
        unset($module);
    }

    public function testAMoveRequiresViewAsWellAsMoveAndConnect(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $permissions = \App\Permissions::MOVE_MEMBERS | \App\Permissions::CONNECT;
        $this->queueMovePermission($harness, $permissions);
        $this->openWait($harness);
        $harness->flush();
        self::assertStringContainsString(
            'Overflow: the guildmanager lacks Move Members, Connect, or View Channel; not moving members into channel ' . Harness::CHANNEL . '.',
            Harness::stderr(),
        );
        self::assertStringNotContainsString('moved member', Harness::stderr());
        unset($module);
    }

    public function testAMoveKeepsTheFreshSourceName(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $fresh = $harness->voiceChannel(Harness::CHANNEL, 'Renamed', 2, 1);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $fresh);
        $this->openWait($harness);
        $harness->flush();
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels')->getValue($module);
        self::assertSame('Renamed', $channels[Harness::CHANNEL]['name'] ?? null);
        unset($module);
    }

    public function testASecondReconcileWaitsUntilTheFirstFinishes(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $missing = '777777777777777777';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $missing, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1)],
            'voice_states' => [],
        ]);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $missing, $deferred->promise());
        $harness->discord->emit('init', []);
        $started = $this->overflowGets($harness, $missing);
        $harness->discord->emit('reconnected', []);
        self::assertSame(1, $started);
        self::assertSame($started, $this->overflowGets($harness, $missing));
        $deferred->resolve($harness->voiceChannel($missing, 'Raid Overflow', 0, 2));
        $harness->flush();
        unset($module);
    }

    public function testReconcileRebuildsTheWaitForAnAdoptedChannel(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => true],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame([Harness::USER], $module->waiting($overflow));
        unset($module);
    }

    public function testAChannelUpdateMovesDespiteAMoveRetry(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel('666666666666666666', 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => '444444444444444444', 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ['user_id' => Harness::USER, 'channel_id' => '666666666666666666', 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->setValue($module, [Harness::CHANNEL => time() + 60]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $opened = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1);
        $harness->emitRaw('CHANNEL_UPDATE', $opened);
        $harness->flush();
        self::assertGreaterThan(0, $this->voiceReads($harness));
        unset($module);
    }

    public function testAnOverflowUpdateMovesDespiteAMoveRetry(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->setValue($module, [Harness::CHANNEL => time() + 60]);
        $before = $this->voiceReads($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => $overflow]);
        $updated = $harness->voiceChannel($overflow, $name, 0, 2);
        $harness->emitRaw('CHANNEL_UPDATE', $updated);
        $harness->flush();
        self::assertGreaterThan($before, $this->voiceReads($harness));
        unset($module);
    }

    public function testANonDispatchOpcodeDoesNotMove(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $this->openWait($harness);
        $harness->flush();
        $before = $this->voiceReads($harness);
        $harness->emitRaw('CHANNEL_UPDATE', $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1), 1);
        $harness->flush();
        self::assertSame($before, $this->voiceReads($harness));
        unset($module);
    }

    public function testAnEmptyChannelUpdateDoesNotEvaluate(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $this->openWait($harness);
        $harness->flush();
        $before = count($harness->http->calls);
        $harness->emitRaw('CHANNEL_UPDATE', ['guild_id' => $harness->guildId, 'id' => '', 'type' => 2]);
        $harness->flush();
        self::assertSame($before, count($harness->http->calls));
        unset($module);
    }

    public function testAnIntegerChannelIdStillTracksTheMember(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootWait($harness);
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => 42,
            'channel_id' => (int) $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        self::assertContains('42', $module->waiting($overflow));
        unset($module);
    }

    public function testAFullTextChannelDoesNotCreate(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $text = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $text['type'] = 0;
        $harness->emitRaw('CHANNEL_UPDATE', $text);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        self::assertNull($harness->store->get('overflows:' . $harness->guildId));
        unset($module);
    }

    public function testACreatedRecordBlocksAnotherCreate(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $harness->voiceChannel('777777777777777777', 'created', 0, 9));
        $harness->discord->emit('init', []);
        $harness->flush();
        $posts = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($posts, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testAStaleFullChannelIsNotCreatedAfterTheFreshReadSaysItIsNotFull(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $stale = $source;
        $stale['user_limit'] = 0;
        $this->queueCreateReads($harness, $stale);
        $harness->discord->emit('init', []);
        $harness->flush();
        $posts = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($posts, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testAMemberWhoLeavesBeforeTheMoveIsNotMoved(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $overflow = '666666666666666666';
        $guild = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => $overflow]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $guild->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $guild->resolve($harness->guild());
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());
        unset($module);
    }

    public function testAFailedMoveRetriesOnTheFifthSecond(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, new RuntimeException('voice down'));
        $this->openWait($harness);
        $harness->flush();
        $before = $this->voiceReads($harness);
        Clock::$overflow = 1_700_000_004;
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($before, $this->voiceReads($harness));
        Clock::$overflow = 1_700_000_005;
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertGreaterThan($before, $this->voiceReads($harness));
        unset($module);
    }

    public function testAnOccupiedOverflowIsNotDeletedWhenConfirmRuns(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->http->queue('GET', 'channels/' . $overflow, $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame(0, $this->deletes($harness, $overflow));
        self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
        unset($module);
    }

    public function testRetiringAMissingRecordDoesNothing(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $harness->store->delete('overflows:' . $harness->guildId);
        $before = count($harness->http->calls);
        $this->call($module, 'retireMissingSource', ['444444444444444444']);
        self::assertSame($before, count($harness->http->calls));
        self::assertNull($harness->store->get('overflows:' . $harness->guildId));
        unset($module);
    }

    public function testAnIntegerOverflowIdStillCountsAsOccupied(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness);
        $records = new \ReflectionProperty(OverflowModule::class, 'records');
        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => (int) $overflow, 'owned' => true, 'pending' => false],
        ]);
        $voices = new \ReflectionProperty(OverflowModule::class, 'voices');
        $voices->setValue($module, [Harness::USER => $overflow]);
        $before = $this->deletes($harness, $overflow);
        $this->call($module, 'retireMissingSource', [Harness::CHANNEL]);
        $harness->flush();
        self::assertSame($before, $this->deletes($harness, $overflow));
        unset($module);
    }

    public function testAdoptionUsesTheConfiguredTemplateAndParent(): void
    {
        $previous = getenv('CHANNEL_TEMPLATE');
        putenv('CHANNEL_TEMPLATE=Wait <channel>');
        try {
            $harness = $this->harness();
            $harness->identify();
            $overflow = '666666666666666666';
            $parent = '444444444444444440';
            $name = OverflowPolicy::overflowName('Raid', 'Wait <channel>');
            $harness->store->put('overflows:' . $harness->guildId, [
                Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => true],
            ]);
            $module = $this->module($harness);
            $module->attach($harness->context);
            $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
            $source['parent_id'] = $parent;
            $match = $harness->voiceChannel($overflow, $name, 0, 2);
            $match['parent_id'] = $parent;
            $harness->emitRaw('GUILD_CREATE', [
                'id' => $harness->guildId,
                'unavailable' => false,
                'channels' => [$source, $match],
                'voice_states' => [],
            ]);
            $harness->discord->emit('init', []);
            $harness->flush();
            self::assertSame($overflow, $module->trackedOverflow(Harness::CHANNEL));
            self::assertSame($overflow, $harness->store->get('overflows:' . $harness->guildId)[Harness::CHANNEL]['overflow_id'] ?? null);
            unset($module);
        } finally {
            if ($previous === false) {
                putenv('CHANNEL_TEMPLATE');
            } else {
                putenv('CHANNEL_TEMPLATE=' . $previous);
            }
        }
    }

    public function testATrailingSourceIdIsNotLoaded(): void
    {
        $harness = $this->harness();
        $junk = Harness::CHANNEL . 'x';
        $harness->store->put('overflows:' . $harness->guildId, [
            $junk => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
            Harness::CHANNEL => ['overflow_id' => '777777777777777777', 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        self::assertNull($module->trackedOverflow($junk));
        self::assertSame('777777777777777777', $module->trackedOverflow(Harness::CHANNEL));
        unset($module);
    }

    public function testTrackedOverflowCastsAnIntegerId(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $records = new \ReflectionProperty(OverflowModule::class, 'records');
        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => 666666666666666666, 'owned' => true, 'pending' => false],
        ]);
        self::assertSame('666666666666666666', $module->trackedOverflow(Harness::CHANNEL));
        unset($module);
    }

    public function testRegisterOnlyReconnectDoesNotReconcile(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', static fn() => resolve(new Response(200, [], '{}'))), true);
        $module->attach($harness->context);
        new \ReflectionProperty(OverflowModule::class, 'awaitingGuild')->setValue($module, false);
        $harness->discord->emit('reconnected', []);
        $harness->discord->emit('voicewait.gateway_gap', []);
        self::assertStringNotContainsString('Overflow moves are paused', Harness::stderr());
        unset($module);
    }

    public function testANotFullFreshChannelDoesNotCreate(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $open = $source;
        $open['user_limit'] = 5;
        $this->queueCreateReads($harness, $open);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testCreateDoesNotPostAfterTheBotGoesOfflineWithoutAnEpochChange(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $source);
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, false);
        $deferred->resolve($harness->guild());
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testDeletingAnOverflowIsSaved(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness);
        $harness->http->queue('GET', 'channels/' . $overflow, $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertArrayNotHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        unset($module);
    }

    public function testAMoveRemovesTheMemberFromTheWaitingList(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $this->queueMovePermission($harness, $harness->botPermissions());
        $this->openWait($harness);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame([], $module->waiting('666666666666666666'));
        self::assertStringContainsString('Overflow: moved member ' . Harness::USER . ' into channel ' . Harness::CHANNEL . '.', Harness::stderr());
        unset($module);
    }

    public function testReconcileDoesNotSaveWhenNothingIsPending(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1)],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertNull($harness->store->get('overflows:' . $harness->guildId));
        unset($module);
    }

    public function testAnOverflowIsNotAManageableSource(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $overflow = '666666666666666666';
        $channel = $harness->voiceChannel($overflow, 'Overflow', 4, 2);
        $records = new \ReflectionProperty(OverflowModule::class, 'records');
        $records->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels');
        $channels->setValue($module, [$overflow => $channel]);
        self::assertFalse($this->call($module, 'isManageableSource', [$overflow]));
        unset($module);
    }

    public function testAGapStopsALaterCreate(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->discord->emit('voicewait.gateway_gap', []);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $harness->voiceChannel('777777777777777777', 'created', 0, 9));
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testAGapCancelsAnInFlightMove(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
        ]);
        $pending = new Deferred();
        $sent = 0;
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', function () use (&$sent, $pending) {
            ++$sent;

            return $pending->promise();
        }));
        $module->attach($harness->context);
        $this->queueMovePermission($harness, $harness->botPermissions());
        $this->openWait($harness);
        $harness->flush();
        $harness->loop->firePeriodic();
        self::assertSame(1, $sent);
        $harness->discord->emit('voicewait.gateway_gap', []);
        $pending->resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"' . Harness::CHANNEL . '"}'));
        $harness->flush();
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());
        unset($module);
    }

    public function testDeletingTheOverflowChannelDropsTheSavedRecord(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness);
        $harness->emitRaw('CHANNEL_DELETE', [
            'id' => $overflow,
            'guild_id' => $harness->guildId,
            'type' => 2,
        ]);
        self::assertArrayNotHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        unset($module);
    }

    public function testDeletingTheSourceRetiresItsOverflow(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness, 1);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $before = $this->overflowGets($harness, $overflow);
        $harness->http->queue('GET', 'channels/' . $overflow, $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $harness->emitRaw('CHANNEL_DELETE', [
            'id' => Harness::CHANNEL,
            'guild_id' => $harness->guildId,
            'type' => 2,
        ]);
        $harness->flush();
        self::assertGreaterThan($before, $this->overflowGets($harness, $overflow));
        unset($module);
    }

    public function testADeletedGuildDoesNotReconcileOnReconnect(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $harness->emitRaw('GUILD_DELETE', ['id' => $harness->guildId]);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertSame(0, $this->overflowGets($harness, $overflow));
        unset($module);
    }

    public function testAGapStaysOffline(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => Harness::CHANNEL]);
        $harness->discord->emit('voicewait.gateway_gap', []);
        $before = count($harness->http->calls);
        $this->call($module, 'evaluate', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));
        unset($module);
    }

    public function testABusyMissingSourceIsNotRetired(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => true],
        ]);
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => true],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'busy')->setValue($module, [Harness::CHANNEL => true]);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $this->call($module, 'evaluate', [Harness::CHANNEL]);
        self::assertArrayHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        unset($module);
    }

    public function testConfirmChecksTheOverflowAfterAnEmptyId(): void
    {
        $harness = $this->harness();
        $missing = '777777777777777777';
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            '444444444444444444' => ['overflow_id' => '', 'owned' => true, 'pending' => false],
            Harness::CHANNEL => ['overflow_id' => $missing, 'owned' => true, 'pending' => false],
        ]);
        $harness->http->queue('GET', 'channels/' . $missing, $harness->voiceChannel($missing, 'Overflow', 0, 2));
        $this->call($module, 'confirmOverflows', []);
        $harness->flush();
        self::assertSame(1, $this->overflowGets($harness, $missing));
        unset($module);
    }

    public function testReconcileRebuildsTheWaitFromCurrentVoices(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
            $overflow => $harness->voiceChannel($overflow, 'Overflow', 0, 2),
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => $overflow]);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $this->call($module, 'reconcile', []);
        $harness->flush();
        self::assertSame([Harness::USER], $module->waiting($overflow));
        unset($module);
    }

    public function testAbandonPendingLeavesAFinishedRecordAlone(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => true],
        ]);
        $module = $this->module($harness);
        $this->call($module, 'abandonPending', [Harness::CHANNEL]);
        self::assertSame($overflow, $harness->store->get('overflows:' . $harness->guildId)[Harness::CHANNEL]['overflow_id'] ?? null);
        unset($module);
    }

    public function testAbandonPendingLeavesANonPendingRecordAlone(): void
    {
        $harness = $this->harness();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => null, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $this->call($module, 'abandonPending', [Harness::CHANNEL]);
        self::assertArrayHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        unset($module);
    }

    public function testAMoveDoesNotStartAfterTheBotGoesOfflineWithoutAnEpochChange(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
            $overflow => $harness->voiceChannel($overflow, 'Overflow', 0, 2),
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => $overflow]);
        new \ReflectionProperty(OverflowModule::class, 'order')->setValue($module, [$overflow => [Harness::USER]]);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $guild = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => $overflow]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $guild->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $this->call($module, 'moveOne', [Harness::CHANNEL, false]);
        $harness->flush();
        self::assertNotEmpty(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'GET' && $call['url'] === 'guilds/' . $harness->guildId,
        ));
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, false);
        $guild->resolve($harness->guild());
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertStringNotContainsString('Overflow: moved member ' . Harness::USER, Harness::stderr());
        self::assertStringNotContainsString('lacks Move Members', Harness::stderr());
        self::assertStringNotContainsString('Overflow move failed', Harness::stderr());
        unset($module);
    }

    public function testRegisterOnlyInitDoesNotReconcile(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', static fn() => resolve(new Response(200, [], '{}'))), true);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1)],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->discord->emit('voicewait.gateway_gap', []);
        self::assertStringNotContainsString('Overflow moves are paused', Harness::stderr());
        unset($module);
    }

    public function testAnEmptyUserIdDoesNotJoinTheWait(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootWait($harness);
        $this->openWait($harness);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => '',
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        self::assertSame([Harness::USER], $module->waiting($overflow));
        unset($module);
    }

    public function testATextChannelIsNotEvaluatedAsASource(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $text = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $text['type'] = 0;
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [Harness::CHANNEL => $text]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => Harness::CHANNEL]);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $before = count($harness->http->calls);
        $this->call($module, 'evaluate', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));
        self::assertNull($harness->store->get('overflows:' . $harness->guildId));
        unset($module);
    }

    public function testAnIntegerOverflowIdIsNotDeletedWhileOccupied(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => (int) $overflow, 'owned' => true, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => $overflow]);
        $before = $this->deletes($harness, $overflow);
        $this->call($module, 'deleteOverflow', [Harness::CHANNEL]);
        $harness->flush();
        self::assertSame($before, $this->deletes($harness, $overflow));
        unset($module);
    }

    public function testALowBitrateIsNotCopied(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['bitrate'] = 1000;
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $harness->voiceChannel('777777777777777777', 'created', 0, 9));
        $this->call($module, 'postChannel', [$source, false, '--- <channel> Overflow']);
        $harness->flush();
        $posts = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        self::assertArrayNotHasKey('bitrate', $posts[0]['body'] ?? []);
        unset($module);
    }

    public function testCreateDoesNotPlaceAfterTheBotGoesOffline(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $parent = '444444444444444440';
        $between = '444444444444444443';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $betweenChannel = $harness->voiceChannel($between, 'Between', 0, 2);
        $betweenChannel['parent_id'] = $parent;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $betweenChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $created = new Deferred();
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $created->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, false);
        $placed = $harness->voiceChannel('777777777777777777', 'created', 0, 9);
        $placed['parent_id'] = $parent;
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $created->resolve($placed);
        $harness->flush();
        self::assertStringContainsString('Overflow: created channel 777777777777777777 under full channel ' . Harness::CHANNEL . '.', Harness::stderr());
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        unset($module);
    }

    public function testAMoveGuardRefusesAfterTheBotGoesOffline(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $sent = 0;
        $module = new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', function () use (&$sent) {
            ++$sent;

            return resolve(new Response(200, ['Content-Type' => 'application/json'], '{"channel_id":"' . Harness::CHANNEL . '"}'));
        }));
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
            $overflow => $harness->voiceChannel($overflow, 'Overflow', 0, 2),
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => $overflow]);
        new \ReflectionProperty(OverflowModule::class, 'order')->setValue($module, [$overflow => [Harness::USER]]);
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => $overflow]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
        $this->call($module, 'moveOne', [Harness::CHANNEL, false]);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, false);
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame(0, $sent);
        unset($module);
    }

    public function testAnUnownedOverflowIsNotRenamed(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => false, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
            $overflow => $harness->voiceChannel($overflow, 'Wrong Name', 0, 2),
        ]);
        $before = count($harness->http->calls);
        $this->call($module, 'renameIfNeeded', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));
        unset($module);
    }

    public function testCreateReturnsWhenARecordAlreadyExists(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        new \ReflectionProperty(OverflowModule::class, 'records')->setValue($module, [
            Harness::CHANNEL => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
        ]);
        new \ReflectionProperty(OverflowModule::class, 'channels')->setValue($module, [
            Harness::CHANNEL => $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
        ]);
        new \ReflectionProperty(OverflowModule::class, 'voices')->setValue($module, [Harness::USER => Harness::CHANNEL]);
        $before = count($harness->http->calls);
        $this->call($module, 'create', [Harness::CHANNEL]);
        self::assertSame($before, count($harness->http->calls));
        unset($module);
    }

    public function testAnOverflowThatFillsDuringConfirmIsNotDeleted(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness, 5);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'channels/' . $overflow, $deferred->promise());
        new \ReflectionProperty(OverflowModule::class, 'online')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'guildAvailable')->setValue($module, true);
        new \ReflectionProperty(OverflowModule::class, 'initialized')->setValue($module, true);
        $this->call($module, 'deleteOverflow', [Harness::CHANNEL]);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->http->queue('DELETE', 'channels/' . $overflow, []);
        $deferred->resolve($harness->voiceChannel($overflow, 'Raid Overflow', 0, 2));
        $harness->flush();
        self::assertSame(0, $this->deletes($harness, $overflow));
        unset($module);
    }

    public function testAnOverflowChannelIsNotManagedAsASource(): void
    {
        $harness = $this->harness();
        $overflow = '666666666666666666';
        $module = $this->bootEmptyOverflow($harness, 1);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $posts = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $harness->loop->firePeriodic();
        $harness->flush();
        self::assertSame($posts, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testAnInFlightCreateIsNotStartedAgain(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $source);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->discord->emit('init', []);
        $reads = static fn() => count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'GET' && $call['url'] === 'guilds/' . $harness->guildId,
        ));
        $started = $reads();
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        self::assertSame($started, $reads());
        $deferred->resolve($harness->guild());
        $harness->flush();
        unset($module);
    }

    public function testATextChannelUpdateDoesNotCreate(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [],
            'voice_states' => [],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $text = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $text['type'] = 0;
        $harness->emitRaw('CHANNEL_UPDATE', $text);
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        unset($module);
    }

    public function testCreateSavesThePendingIntentBeforeThePost(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $source);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->discord->emit('init', []);
        $saved = $harness->store->get('overflows:' . $harness->guildId);
        self::assertTrue($saved[Harness::CHANNEL]['pending'] ?? false);
        self::assertArrayHasKey('overflow_id', $saved[Harness::CHANNEL]);
        $deferred->resolve($harness->guild());
        $harness->flush();
        unset($module);
    }

    public function testCreateUsesTheConfiguredTemplate(): void
    {
        $previous = getenv('CHANNEL_TEMPLATE');
        putenv('CHANNEL_TEMPLATE=Wait <channel>');
        try {
            $harness = $this->harness();
            $harness->identify();
            $harness->store->delete('overflows:' . $harness->guildId);
            $module = $this->module($harness);
            $module->attach($harness->context);
            $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
            $harness->emitRaw('GUILD_CREATE', [
                'id' => $harness->guildId,
                'unavailable' => false,
                'channels' => [$source],
                'voice_states' => [
                    ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
                ],
            ]);
            $this->queueCreateReads($harness, $source);
            $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $harness->voiceChannel('777777777777777777', 'created', 0, 9));
            $harness->discord->emit('init', []);
            $harness->flush();
            $posts = array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
            self::assertNotEmpty($posts);
            self::assertSame(OverflowPolicy::overflowName('Raid', 'Wait <channel>'), $posts[0]['body']['name'] ?? null);
            unset($module);
        } finally {
            if ($previous === false) {
                putenv('CHANNEL_TEMPLATE');
            } else {
                putenv('CHANNEL_TEMPLATE=' . $previous);
            }
        }
    }

    private function queueMovePermission(Harness $harness, int $permissions): void
    {
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/voice-states/' . Harness::USER, ['channel_id' => '666666666666666666']);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($permissions));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1));
    }

    private function voiceReads(Harness $harness): int
    {
        return count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], 'voice-states'),
        ));
    }

    private function overflowGets(Harness $harness, string $overflow): int
    {
        return count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'GET' && str_contains($call['url'], 'channels/' . $overflow),
        ));
    }

    private function deletes(Harness $harness, string $overflow): int
    {
        return count(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'DELETE' && str_contains($call['url'], $overflow),
        ));
    }

    private function call(OverflowModule $module, string $method, array $args): mixed
    {
        return new \ReflectionMethod(OverflowModule::class, $method)->invokeArgs($module, $args);
    }

    public function testOnChannelDeleteUnsetsRecordForSource(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $sourceId = '444444444444444444';
        $harness->store->put('overflows:' . $harness->guildId, [
            $sourceId => ['overflow_id' => null, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->discord->emit('init', []);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$harness->voiceChannel($sourceId, 'Raid', 2)],
            'voice_states' => [],
        ]);
        self::assertNull($module->trackedOverflow($sourceId));
        self::assertArrayHasKey($sourceId, $harness->store->get('overflows:' . $harness->guildId));
        $harness->emitRaw('CHANNEL_DELETE', [
            'guild_id' => '999999999999999991',
            'id' => $sourceId,
        ]);
        self::assertArrayHasKey($sourceId, $harness->store->get('overflows:' . $harness->guildId));
        $harness->emitRaw('CHANNEL_DELETE', [
            'guild_id' => $harness->guildId,
            'id' => $sourceId,
        ]);
        self::assertArrayNotHasKey($sourceId, $harness->store->get('overflows:' . $harness->guildId));
    }

    public function testOnChannelDeleteHandlesOverflowDeletion(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $sourceId = '444444444444444444';
        $overflowId = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            $sourceId => ['overflow_id' => $overflowId, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel($sourceId, 'Raid', 2, 1),
                $harness->voiceChannel($overflowId, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [],
        ]);
        self::assertSame($overflowId, $module->trackedOverflow($sourceId));
        $harness->emitRaw('CHANNEL_DELETE', [
            'guild_id' => $harness->guildId,
            'id' => $overflowId,
        ]);
        self::assertNull($module->trackedOverflow($sourceId));
    }

    public function testReadyAwaitingGuildAndReconnectedPaths(): void
    {
        $harness = $this->harness();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('READY', ['user' => ['id' => Harness::BOT]], 0);
        $harness->discord->emit('reconnected', []);
        self::assertSame(0, $module->occupants(Harness::CHANNEL));
        $harness2 = $this->harness();
        $harness2->identify();
        $module2 = $this->module($harness2);
        $module2->attach($harness2->context);
        $source = $harness2->voiceChannel(Harness::CHANNEL, 'Raid', 1, 2);
        $harness2->emitRaw('GUILD_CREATE', [
            'id' => $harness2->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]]],
        ]);
        $harness2->discord->emit('init', []);
        $harness2->flush();
        $harness2->emitRaw('GUILD_CREATE', [
            'id' => '999999999999999991',
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [],
        ]);
        self::assertSame(1, $module2->occupants(Harness::CHANNEL));
    }

    public function testChannelAndVoiceStateGuards(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('CHANNEL_UPDATE', ['guild_id' => $harness->guildId, 'id' => '', 'type' => 2], 0);
        $harness->emitRaw('CHANNEL_CREATE', [
            'id' => '111111111111111110',
            'guild_id' => $harness->guildId,
            'type' => 2,
            'name' => 'Hidden',
            'user_limit' => 1,
            'flags' => 1 << 17,
        ], 0);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::BOT,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => true]],
        ], 0);
        self::assertSame(1, $module->occupants(Harness::CHANNEL));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ], 0);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ], 0);
        self::assertSame(2, $module->occupants(Harness::CHANNEL));
        $harness->discord->emit('voicewait.gateway_gap', []);
        $harness->loop->firePeriodic();
    }

    public function testLoadRecordsRejectsMalformedOverflowId(): void
    {
        $harness = $this->harness();
        $harness->store->put('overflows:' . $harness->guildId, [
            '444444444444444444' => ['overflow_id' => 'bad', 'owned' => true],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        self::assertNull($module->trackedOverflow('444444444444444444'));
    }

    protected function module(Harness $harness): OverflowModule
    {
        return new OverflowModule($harness->context, new MoveTransport($harness->loop, 'offline', static fn() => resolve(new Response(200, [], '{"ok":true}'))));
    }

    /** @param array<string, mixed> $fresh */
    public function testAFailedCreateRetriesOnTheThirtiethSecond(): void
    {
        Clock::$overflow = 1_700_000_000;
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', new RuntimeException('create down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        $retry = new \ReflectionProperty(OverflowModule::class, 'createRetryAt')->getValue($module);
        self::assertSame(1_700_000_030, $retry[Harness::CHANNEL] ?? null);
        $posts = static fn() => count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $afterFail = $posts();
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($afterFail, $posts());
        Clock::$overflow = 1_700_000_029;
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($afterFail, $posts());
        Clock::$overflow = 1_700_000_030;
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $harness->voiceChannel('777777777777777777', 'created', 0, 9));
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        self::assertSame($afterFail + 1, $posts());
        unset($module);
    }

    public function testAFailedCreateReadDropsThePendingRecord(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, new RuntimeException('guild down'));
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertStringContainsString('Overflow process failed for channel ' . Harness::CHANNEL, Harness::stderr());
        self::assertArrayNotHasKey(Harness::CHANNEL, $harness->store->get('overflows:' . $harness->guildId) ?? []);
        unset($module);
    }

    public function testAnIntegerCreatedIdIsPlacedAndAWrongNameIsRenamed(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $parent = '444444444444444440';
        $between = '444444444444444443';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $betweenChannel = $harness->voiceChannel($between, 'Between', 0, 2);
        $betweenChannel['parent_id'] = $parent;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $betweenChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $createdId = 777777777777777777;
        $created = $harness->voiceChannel((string) $createdId, 'Wrong', 0, 9);
        $created['id'] = $createdId;
        $created['parent_id'] = $parent;
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $created);
        $harness->http->queue('PATCH', 'guilds/' . $harness->guildId . '/channels', []);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame((string) $createdId, $module->trackedOverflow(Harness::CHANNEL));
        self::assertStringContainsString('Overflow: created channel ' . $createdId . ' under full channel ' . Harness::CHANNEL . '.', Harness::stderr());
        $place = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'PATCH' && str_contains($call['url'], 'guilds/'),
        ));
        self::assertNotEmpty($place);
        $expected = OverflowPolicy::overflowName('Raid', getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow');
        $harness->http->queue('PATCH', 'channels/' . $createdId, ['id' => (string) $createdId, 'name' => $expected, 'type' => 2]);
        $harness->emitRaw('CHANNEL_UPDATE', $source);
        $harness->flush();
        $renames = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => $call['method'] === 'PATCH' && str_contains($call['url'], 'channels/' . $createdId),
        ));
        self::assertNotEmpty($renames);
        self::assertSame($expected, $renames[0]['body']['name'] ?? null);
        unset($module);
    }

    public function testCreateDoesNotPlaceAfterOnlyTheEpochChanges(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $parent = '444444444444444440';
        $between = '444444444444444443';
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $source['parent_id'] = $parent;
        $betweenChannel = $harness->voiceChannel($between, 'Between', 0, 2);
        $betweenChannel['parent_id'] = $parent;
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source, $betweenChannel],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $this->queueCreateReads($harness, $source);
        $created = $harness->voiceChannel('777777777777777777', 'created', 0, 9);
        $created['parent_id'] = $parent;
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $epoch = new \ReflectionProperty(OverflowModule::class, 'epoch');
        $epoch->setValue($module, $epoch->getValue($module) + 1);
        $deferred->resolve($created);
        $harness->flush();
        self::assertStringNotContainsString('Overflow position update failed', Harness::stderr());
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PATCH')));
        unset($module);
    }

    public function testCreateRebuildsTheWaitForSomeoneAlreadyInTheNewChannel(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $harness->store->delete('overflows:' . $harness->guildId);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $source = $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [$source],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $deferred = new Deferred();
        $createdId = '777777777777777777';
        $waiter = '444444444444444444';
        $this->queueCreateReads($harness, $source);
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', $deferred->promise());
        $harness->discord->emit('init', []);
        $harness->flush();
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => $waiter,
            'channel_id' => $createdId,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $deferred->resolve($harness->voiceChannel($createdId, 'created', 0, 9));
        $harness->flush();
        self::assertSame([$waiter], $module->waiting($createdId));
        unset($module);
    }

    private function queueCreateReads(Harness $harness, array $fresh): void
    {
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $fresh);
    }

    private function bootWait(Harness $harness): OverflowModule
    {
        $harness->identify();
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => '666666666666666666', 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);

        return $module;
    }

    public function testABotIsNotWaitingAndAnIntegerVoiceIdStillIs(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => 42, 'channel_id' => (int) $overflow, 'member' => ['user' => ['guildmanager' => false]]],
                ['user_id' => '555555555555555555', 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => true]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        self::assertSame(['42'], $module->waiting($overflow));
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => '555555555555555556',
            'channel_id' => $overflow,
            'member' => ['user' => ['guildmanager' => true]],
        ]);
        self::assertSame(['42'], $module->waiting($overflow));
    }

    public function testAForeignChannelUpdateDoesNotCreate(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->emitRaw('CHANNEL_UPDATE', [
            'guild_id' => '999999999999999999',
            'id' => '888888888888888888',
            'type' => 2,
            'name' => 'Other',
            'user_limit' => 1,
        ]);
        $harness->flush();
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        $channels = new \ReflectionProperty(OverflowModule::class, 'channels')->getValue($module);
        self::assertArrayNotHasKey('888888888888888888', $channels);
        unset($module);
    }

    public function testDeletingAFullSourcesOverflowCreatesAReplacement(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        $this->scriptManage($harness);
        $harness->http->queue('GET', 'channels/' . Harness::CHANNEL, $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1));
        $harness->http->queue('POST', 'guilds/' . $harness->guildId . '/channels', [
            'id' => '777777777777777777',
            'type' => 2,
            'name' => 'Raid Overflow',
        ]);
        $before = count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST'));
        $harness->emitRaw('CHANNEL_DELETE', ['guild_id' => $harness->guildId, 'id' => $overflow]);
        $harness->flush();
        self::assertSame($before + 1, count(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'POST')));
        unset($module);
    }

    public function testARepeatedVoiceStateDoesNotClearAMoveRetry(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel('666666666666666666', 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->setValue($module, [Harness::CHANNEL => time() + 60]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => Harness::CHANNEL,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $retry = new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->getValue($module);
        self::assertArrayHasKey(Harness::CHANNEL, $retry);
        unset($module);
    }

    public function testLeavingATrackedSourceClearsTheMoveRetry(): void
    {
        $harness = $this->harness();
        $module = $this->bootWait($harness);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 1, 1),
                $harness->voiceChannel('666666666666666666', 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => Harness::CHANNEL, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
        $harness->flush();
        new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->setValue($module, [Harness::CHANNEL => time() + 60]);
        $harness->emitRaw('VOICE_STATE_UPDATE', [
            'guild_id' => $harness->guildId,
            'user_id' => Harness::USER,
            'channel_id' => null,
            'member' => ['user' => ['guildmanager' => false]],
        ]);
        $retry = new \ReflectionProperty(OverflowModule::class, 'moveRetryAt')->getValue($module);
        self::assertArrayNotHasKey(Harness::CHANNEL, $retry);
        unset($module);
    }

    public function testReconcileDoesNotRunBeforeTheGuildIsAvailable(): void
    {
        $harness = $this->harness();
        $harness->identify();
        $module = $this->module($harness);
        $module->attach($harness->context);
        new \ReflectionProperty(OverflowModule::class, 'awaitingGuild')->setValue($module, false);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertSame([], $harness->http->calls);
        self::assertStringNotContainsString('Overflow process failed', Harness::stderr());
        unset($module);
    }

    private function openWait(Harness $harness): void
    {
        $overflow = '666666666666666666';
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $name = OverflowPolicy::overflowName('Raid', $template);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', 2, 1),
                $harness->voiceChannel($overflow, $name, 0, 2),
            ],
            'voice_states' => [
                ['user_id' => Harness::USER, 'channel_id' => $overflow, 'member' => ['user' => ['guildmanager' => false]]],
            ],
        ]);
        $harness->discord->emit('init', []);
    }

    private function bootEmptyOverflow(Harness $harness, int $limit = 2): OverflowModule
    {
        $harness->identify();
        $overflow = '666666666666666666';
        $harness->store->put('overflows:' . $harness->guildId, [
            Harness::CHANNEL => ['overflow_id' => $overflow, 'owned' => true, 'pending' => false],
        ]);
        $module = $this->module($harness);
        $module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', [
            'id' => $harness->guildId,
            'unavailable' => false,
            'channels' => [
                $harness->voiceChannel(Harness::CHANNEL, 'Raid', $limit, 1),
                $harness->voiceChannel($overflow, 'Raid Overflow', 0, 2),
            ],
            'voice_states' => [],
        ]);

        return $module;
    }

    protected function scriptManage(Harness $harness): void
    {
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::BOT, $harness->member(Harness::BOT, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
    }
}
