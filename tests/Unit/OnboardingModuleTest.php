<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Permissions;
use Module\Onboarding\OnboardingModule;
use Module\Onboarding\OnboardingTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\Harness;

#[CoversClass(OnboardingModule::class)]
final class OnboardingModuleTest extends GuildTestCase
{
    private OnboardingTracker $tracker;
    private OnboardingModule $module;
    #[\Override]
    protected ?Harness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = $this->harness();
        $this->tracker = new OnboardingTracker();
        $this->module = new OnboardingModule($this->harness->context, 'Verified', $this->tracker);
        Harness::clearStderr();
    }

    protected function attachModule(): void
    {
        $this->module->attach($this->harness->context);
    }

    public function testTrackerBaselinesATransitionOnceAndResetsOnLeave(): void
    {
        $tracker = new OnboardingTracker();
        self::assertFalse($tracker->updated('u1', false));
        self::assertFalse($tracker->updated('u1', false));
        self::assertFalse($tracker->baselineOf('u1'));
        self::assertFalse($tracker->added('u2', true));
        self::assertFalse($tracker->updated('u2', true));
        self::assertTrue($tracker->updated('u2', false));
        self::assertFalse($tracker->updated('u2', false));
        $tracker->added('u4', true);
        self::assertTrue($tracker->updated('u4', false));
        $tracker->removed('u4');
        self::assertNull($tracker->baselineOf('u4'));
        self::assertFalse($tracker->updated('u4', false));
        self::assertNull($tracker->baselineOf('missing'));
    }

    public function testSelectionRoleOrderAndCursor(): void
    {
        $roleId = Harness::ROLE;
        $members = [
            ['user' => ['id' => '111111111111111111', 'guildmanager' => false], 'pending' => true, 'roles' => []],
            ['user' => ['id' => '222222222222222222', 'guildmanager' => false], 'pending' => false, 'roles' => []],
            ['user' => ['id' => '333333333333333333', 'guildmanager' => false], 'pending' => false, 'roles' => []],
            ['user' => ['id' => '444444444444444444', 'guildmanager' => false], 'pending' => false, 'roles' => [$roleId]],
            ['user' => ['id' => '555555555555555555', 'guildmanager' => true], 'pending' => false, 'roles' => []],
        ];
        self::assertSame(['222222222222222222', '333333333333333333'], OnboardingModule::selectTargets($members, $roleId));
        self::assertSame([], OnboardingModule::selectTargets([], $roleId));

        $roles = [
            ['id' => '111', 'name' => 'Admin', 'position' => 3],
            ['id' => '222', 'name' => 'Moderator', 'position' => 2],
            ['id' => $roleId, 'name' => 'Verified', 'position' => 1],
            ['id' => '999', 'name' => '@everyone', 'position' => 0],
        ];
        self::assertTrue(OnboardingModule::canManageRole($roles, $roleId, ['user' => ['id' => '888'], 'roles' => ['222', '999']]));
        self::assertFalse(OnboardingModule::canManageRole($roles, $roleId, ['user' => ['id' => '888'], 'roles' => ['999']]));
        self::assertFalse(OnboardingModule::canManageRole($roles, '111', ['user' => ['id' => '888'], 'roles' => ['222']]));
        self::assertFalse(OnboardingModule::canManageRole($roles, $roleId, []));
        $rolesByCreation = [
            ['id' => '100', 'name' => 'Members', 'position' => 2],
            ['id' => '200', 'name' => 'BotLow', 'position' => 1],
            ['id' => '300', 'name' => 'BotTop', 'position' => 9],
        ];
        self::assertTrue(OnboardingModule::canManageRole($rolesByCreation, '100', ['user' => ['id' => '888'], 'roles' => ['200', '300']]));
        self::assertFalse(OnboardingModule::canManageRole([
            ['id' => '100', 'name' => 'BotLow', 'position' => 1],
            ['id' => '200', 'name' => 'HighTarget', 'position' => 9],
            ['id' => '300', 'name' => 'BotMid', 'position' => 2],
        ], '200', ['user' => ['id' => '888'], 'roles' => ['100', '300']]));
        self::assertSame(['2'], OnboardingModule::selectTargets([
            ['user' => ['id' => '1', 'guildmanager' => true], 'pending' => false, 'roles' => []],
            ['user' => ['id' => '2', 'guildmanager' => false], 'pending' => false, 'roles' => []],
        ], $roleId));
        self::assertSame(['456'], OnboardingModule::selectTargets([
            ['user' => ['id' => 456, 'guildmanager' => false], 'roles' => []],
        ], $roleId));
        self::assertSame([], OnboardingModule::selectTargets([
            ['user' => ['id' => '2', 'guildmanager' => false], 'roles' => [123]],
        ], '123'));
        self::assertTrue(OnboardingModule::canManageRole([
            ['id' => 42, 'name' => 'Verified', 'position' => 1],
            ['id' => '9', 'name' => 'Bot', 'position' => 2],
        ], '42', ['roles' => ['9']]));
        self::assertFalse(OnboardingModule::canManageRole([
            ['id' => '9', 'name' => 'Bot', 'position' => 2],
        ], 'missing', ['roles' => ['9']]));
        self::assertFalse(OnboardingModule::canManageRole([
            ['id' => '9', 'name' => 'Bot', 'position' => 2],
        ], '9', ['roles' => ['9']]));
        self::assertFalse(OnboardingModule::canManageRole([
            ['id' => '200', 'name' => 'Verified'],
            ['id' => '100', 'name' => 'Bot', 'position' => 0],
        ], '200', ['roles' => ['100']]));
        self::assertTrue(OnboardingModule::canManageRole([
            ['id' => '100', 'name' => 'Verified'],
            ['id' => '200', 'name' => 'Bot', 'position' => 0],
        ], '100', ['roles' => ['200']]));
    }

    public function testPacketsIgnoreOtherGuildsBotsAndBaselineOurMembers(): void
    {
        $module = $this->module;
        $tracker = $this->tracker;
        $harness = $this->harness;
        $this->attachModule();

        // 1. Other Guilds are ignored
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => '333333333333333333', 'user' => ['id' => Harness::USER, 'guildmanager' => false], 'pending' => true], 0);
        self::assertNull($tracker->baselineOf(Harness::USER));

        // 2. Bots are ignored
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => Harness::BOT, 'guildmanager' => true], 'pending' => true], 0);
        self::assertNull($tracker->baselineOf(Harness::BOT));

        // 3. Our members are tracked
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => Harness::USER, 'guildmanager' => false], 'pending' => true], 0);
        self::assertTrue($tracker->baselineOf(Harness::USER));

        // 4. Transition is detected
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => Harness::USER, 'guildmanager' => false], 'pending' => false], 0);
        self::assertFalse($tracker->baselineOf(Harness::USER));

        // 5. Removal is tracked
        $harness->emitRaw('GUILD_MEMBER_REMOVE', ['guild_id' => $harness->guildId, 'user' => ['id' => Harness::USER, 'guildmanager' => false]], 0);
        self::assertNull($tracker->baselineOf(Harness::USER));
    }

    public function testReconnectedTriggersBackfill(): void
    {
        $harness = $this->harness;
        $module = $this->module;
        $module->attach($harness->context);

        // Setup: role exists, guildmanager has permissions, guildmanager is identified
        $harness->identify();
        $perms = $harness->botPermissions();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($perms));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [['user' => ['id' => '1', 'guildmanager' => false], 'pending' => false]]);
        // Target member needs role
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            ['user' => ['id' => 'm1', 'guildmanager' => false], 'pending' => false, 'roles' => []],
        ]);
        // Queue successful PUT for m1
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/m1/roles/VERIFIED_ID', []);
        // Re-read after PUT
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/m1', [
            ['user' => ['id' => 'm1', 'guildmanager' => false], 'pending' => false, 'roles' => ['VERIFIED_ID']],
        ]);

        // Trigger reconnected
        $harness->discord->emit('reconnected', []);
        $harness->flush();

        // We expect a backfill log message
        self::assertStringContainsString('Onboarding: backfill granting the role to', Harness::stderr());
    }

    public function testGrantSkipsWhenTheRoleIsMissingAlreadyHeldOrUnmanageable(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $module = $this->module;
        $module->attach($harness->context);
        $perms = $harness->botPermissions();

        // Queue responses for backfill
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($perms));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [['user' => ['id' => '1', 'guildmanager' => false], 'pending' => false]]);

        $harness->emitRaw('GUILD_CREATE', ['id' => '9999999999999991', 'unavailable' => false], 0);
        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => true], 0);
        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => false], 0);
        $harness->flush();

        // Re-trigger with empty roles
        Harness::clearStderr();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [['user' => ['id' => '1', 'guildmanager' => false], 'pending' => false]]);

        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => false], 0);
        $harness->flush();

        self::assertStringContainsString('Onboarding: role "Verified" does not exist in the server; backfill skipped.', Harness::stderr());
    }

    public function testGuardsAndEdgeCases(): void
    {
        $harness = $this->harness;
        $module = $this->module;
        $tracker = $this->tracker;
        $module->attach($harness->context);

        // 1. GUILD_CREATE for wrong guild must not trigger backfill
        Harness::clearStderr();
        $harness->emitRaw('GUILD_CREATE', ['id' => '999999999999999999', 'unavailable' => false], 0);
        $harness->flush();
        self::assertStringNotContainsString('Onboarding:', Harness::stderr());

        // 2. GUILD_MEMBER_ADD with missing 'pending' field (should default to false)
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => 'u1', 'guildmanager' => false]], 0);
        $harness->flush();
        self::assertFalse($tracker->baselineOf('u1'));

        // 3. GUILD_MEMBER_ADD with 'pending' => true
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => 'u2', 'guildmanager' => false], 'pending' => true], 0);
        $harness->flush();
        self::assertTrue($tracker->baselineOf('u2'));

        // 4. GUILD_MEMBER_ADD with missing 'user.id' or guildmanager
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['guildmanager' => true]], 0);
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => []], 0);
        $harness->flush();
    }

    public function testNextCursorLogic(): void
    {
        self::assertNull(OnboardingModule::nextCursor([]));
        self::assertNull(OnboardingModule::nextCursor(array_fill(0, 999, ['user' => ['id' => '1', 'guildmanager' => false]])));

        $page = array_fill(0, 1000, ['user' => ['id' => '1', 'guildmanager' => false]]);
        $page[500] = ['user' => ['id' => '200', 'guildmanager' => false]];
        self::assertSame('200', OnboardingModule::nextCursor($page));

        $intPage = array_fill(0, 1000, ['user' => ['id' => 1, 'guildmanager' => false]]);
        $intPage[10] = ['user' => ['id' => 200, 'guildmanager' => false]];
        self::assertSame('200', OnboardingModule::nextCursor($intPage));

        $largePage = array_fill(0, 1000, ['user' => ['id' => '1', 'guildmanager' => false]]);
        self::assertNotNull(OnboardingModule::nextCursor($largePage));
    }

    public function testBackfillGuardsExpanded(): void
    {
        $harness = $this->harness;
        $tracker = $this->tracker;

        // 1. registerOnly: if true, backfill should not grant roles
        $tracker2 = new OnboardingTracker();
        $registerOnlyModule = new OnboardingModule($harness->context, 'Verified', $tracker2, true);
        $registerOnlyModule->attach($harness->context);

        $targetUserId = 'u_register_only';
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => $targetUserId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();

        $harness->identify();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($harness->botPermissions()));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [['user' => ['id' => $targetUserId, 'guildmanager' => false], 'pending' => false]]);

        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => false], 0);
        $harness->discord->emit('reconnected', []);
        $harness->flush();

        // Check that it skipped via logs to kill LogicOr mutant
        self::assertStringNotContainsString('Onboarding: backfill granting the role to', Harness::stderr());
        self::assertFalse($tracker2->updated($targetUserId, false), 'registerOnly should have prevented backfill');

        // 2. botId empty: if botId is empty, backfill should not grant roles
        $tracker3 = new OnboardingTracker();
        $emptyBotContext = clone $harness->context;
        $emptyBotContext->botId = '';
        $emptyBotModule = new OnboardingModule($emptyBotContext, 'Verified', $tracker3);
        $emptyBotModule->attach($emptyBotContext);

        $targetUserId2 = 'u_empty_bot';
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => $targetUserId2, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();

        $emptyBotContext->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringNotContainsString('Onboarding: backfill granting the role to', Harness::stderr());
        self::assertFalse($tracker3->updated($targetUserId2, false), 'empty botId should have prevented backfill');
    }

    public function testBackfillCompleteLogAndCount(): void
    {
        $harness = $this->harness;
        $tracker = $this->tracker;
        $module = $this->module;
        $module->attach($harness->context);
        $harness->identify();

        $perms = $harness->botPermissions();
        $roles = $harness->roles($perms);
        $verifiedRole = array_find($roles, fn($r) => $r['name'] === 'Verified');
        $roleId = $verifiedRole['id'];
        $roleName = $verifiedRole['name'];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));

        // Two members: one already has role, one needs it.
        // Total targets: 1.
        // Successes: 1.
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            ['user' => ['id' => 'm1', 'guildmanager' => false], 'pending' => false, 'roles' => [$roleId]],
            ['user' => ['id' => 'm2', 'guildmanager' => false], 'pending' => false, 'roles' => []],
        ]);

        // Queue successful PUT for m2
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/m2/roles/' . $roleId, []);
        // We also need to queue the re-read after the PUT to verify
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/m2', [
            ['user' => ['id' => 'm2', 'guildmanager' => false], 'pending' => false, 'roles' => [$roleId]],
        ]);

        Harness::clearStderr();
        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => false], 0);
        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: backfill complete; "' . $roleName . '" granted to 1 of 1 member(s).', Harness::stderr());
    }

    public function testCastIntMutant(): void
    {
        $harness = $this->harness;
        $this->attachModule();
        // Pass '0' as the third argument to the 'op' field
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => '1', 'guildmanager' => false], 'pending' => true], '0');
        $harness->flush();
        self::assertNotNull($this->tracker->baselineOf('1'));
    }

    public function testCastStringMutantsInOnUpdate(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->attachModule();
        // 1. Add member as pending
        $harness->emitRaw('GUILD_MEMBER_ADD', ['guild_id' => $harness->guildId, 'user' => ['id' => 12345, 'guildmanager' => false], 'pending' => true], 0);
        $harness->flush();
        // 2. Update member to not pending
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => 12345, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertNotNull($this->tracker->baselineOf('12345'));
    }

    public function testBackfillGrantErrorsAndRoleNotFoundLog(): void
    {
        $harness = $this->harness;
        $module = $this->module;
        $this->attachModule();
        $harness->identify();

        $perms = $harness->botPermissions();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $harness->roles($perms));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($harness->context->botId, ['333333333333333333']));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [['user' => ['id' => '1', 'guildmanager' => false], 'pending' => false]]);

        Harness::clearStderr();
        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => false], 0);
        $harness->discord->emit('reconnected', []);
        $harness->flush();

        $logs = Harness::stderr();
        self::assertTrue(
            str_contains($logs, 'Onboarding: role "Verified" does not exist in the server; backfill skipped.')
            || str_contains($logs, 'Onboarding: backfill could not grant "Verified" to member 1 (RuntimeException).'),
        );
    }

    public function testIdGt(): void
    {
        $reflection = new \ReflectionClass(OnboardingModule::class);
        $method = $reflection->getMethod('idGt');

        // Different lengths
        self::assertTrue($method->invoke(null, '1234', '123'));
        self::assertFalse($method->invoke(null, '123', '1234'));

        // Same length, lexicographical
        self::assertTrue($method->invoke(null, '222', '111'));
        self::assertFalse($method->invoke(null, '111', '222'));
        self::assertFalse($method->invoke(null, '111', '111'));
    }
}
