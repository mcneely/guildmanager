<?php

declare(strict_types=1);

namespace Tests\Unit;

use Module\Onboarding\OnboardingModule;
use Module\Onboarding\OnboardingTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Support\Harness;
use App\Permissions;

#[CoversClass(OnboardingModule::class)]
final class OnboardingModuleCoverageTest extends GuildTestCase
{
    private OnboardingModule $module;
    private OnboardingTracker $tracker;
    #[\Override]
    protected ?Harness $harness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->harness = $this->harness();
        $this->tracker = new OnboardingTracker();
        $this->module = new OnboardingModule($this->harness->context, 'Verified', $this->tracker);
    }

    public function testCommandsAndInteractReturnNull(): void
    {
        self::assertSame('onboarding', $this->module->name());
        self::assertSame([], $this->module->commands());
        self::assertNull($this->module->interact([]));
    }

    public function testBackfillSkipsIfNoManageRoles(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member(Harness::BOT, [], true));

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: the guildmanager lacks Manage Roles; backfill skipped.', Harness::stderr());
        self::assertStringNotContainsString('Onboarding backfill failed', Harness::stderr());
        self::assertSame([], array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], '/members?') || str_ends_with($call['url'], '/members'),
        )));
    }

    public function testBackfillDoesNotListMembersWhenManageRolesIsMissingButTheRoleIsBelowTheBot(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 1, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => '0'],
        ];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringContainsString('Onboarding: the guildmanager lacks Manage Roles; backfill skipped.', Harness::stderr());
        self::assertSame([], array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], '/members?') || str_ends_with($call['url'], '/members'),
        )));
    }

    public function testBackfillSkipsIfRoleTooHigh(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 5, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: role "Verified" sits at or above the guildmanager\'s highest role; backfill skipped.', Harness::stderr());
        self::assertStringNotContainsString('Onboarding backfill failed', Harness::stderr());
        self::assertSame([], array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], '/members?') || str_ends_with($call['url'], '/members'),
        )));
    }

    public function testBackfillCompleteWhenNoTargets(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member(Harness::USER, [$roleId], false),
        ]);

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: backfill complete; every non-pending member already holds the role.', Harness::stderr());
        self::assertStringNotContainsString('Onboarding: backfill granting the role to', Harness::stderr());
        self::assertFalse($this->tracker->baselineOf(Harness::USER));
    }

    public function testBackfillSuccessPath(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member(Harness::USER, [], false),
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . Harness::USER . '/roles/' . $roleId, []);

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 1 of 1 member(s).', Harness::stderr());
        self::assertStringContainsString('Onboarding: backfill granting the role to 1 member(s).', Harness::stderr());
        $lists = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], '/members?'),
        ));
        self::assertNotEmpty($lists);
        self::assertStringContainsString('limit=1000', $lists[0]['url']);
        self::assertStringNotContainsString('after=', $lists[0]['url']);
    }

    public function testBackfillFailurePath(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, new \RuntimeException('API Down'));

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding backfill failed', Harness::stderr());
    }

    public function testGrantSuccessfulVerification(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $userId = Harness::USER;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $this->tracker->added($userId, true);

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $userId . '/roles/' . $roleId, []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [$roleId], false));

        $harness->emitRaw('GUILD_MEMBER_UPDATE', [
            'guild_id' => $harness->guildId,
            'user' => ['id' => $userId, 'guildmanager' => false],
            'pending' => false,
        ], 0);

        $harness->flush();

        self::assertStringContainsString('Onboarding: granted "Verified" to member ' . $userId . ' (verified after the write).', Harness::stderr());
    }

    public function testGrantFailedVerification(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $userId = Harness::USER;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $this->tracker->added($userId, true);

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $userId . '/roles/' . $roleId, []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));

        $harness->emitRaw('GUILD_MEMBER_UPDATE', [
            'guild_id' => $harness->guildId,
            'user' => ['id' => $userId, 'guildmanager' => false],
            'pending' => false,
        ], 0);

        $harness->flush();

        self::assertStringContainsString('Onboarding: "Verified" was NOT verified on member ' . $userId . ' after the write; a manager should check the member\'s roles.', Harness::stderr());
    }

    public function testGuildCreateTriggersBackfill(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member(Harness::USER, [$roleId], false),
        ]);

        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId], 0);
        $harness->flush();

        self::assertStringContainsString('Onboarding: backfill complete; every non-pending member already holds the role.', Harness::stderr());
    }

    public function testGuildCreateWithUnavailableTriggersNothing(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId, 'unavailable' => true], 0);
        $harness->flush();

        self::assertStringNotContainsString('Onboarding: backfill', Harness::stderr());
    }

    public function testGuildCreateWithDifferentGuildIdTriggersNothing(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $harness->emitRaw('GUILD_CREATE', ['id' => '999999999999999999'], 0);
        $harness->flush();

        self::assertStringNotContainsString('Onboarding: backfill', Harness::stderr());
        self::assertStringNotContainsString('Onboarding backfill failed', Harness::stderr());
        self::assertSame([], $harness->http->calls);
    }

    public function testOnUpdateAlreadyUpdated(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $userId = Harness::USER;
        $this->tracker->added($userId, true);

        $harness->emitRaw('GUILD_MEMBER_UPDATE', [
            'guild_id' => $harness->guildId,
            'user' => ['id' => $userId, 'guildmanager' => false],
            'pending' => true,
        ], 0);

        $harness->flush();
        self::assertStringNotContainsString('Onboarding: granted', Harness::stderr());
        self::assertStringNotContainsString('Onboarding role grant failed', Harness::stderr());
        self::assertSame([], $harness->http->calls);
    }

    public function testOnUpdateBusy(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $userId = Harness::USER;
        $roles = [
            ['id' => Harness::ROLE, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => Harness::BOT, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        // Setup: user is pending
        $this->tracker->added($userId, true);

        // 1. Hold the first grant open so a second transition is still in flight.
        $deferred = new \React\Promise\Deferred();
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member(Harness::BOT, [Harness::BOT], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $userId . '/roles/' . Harness::ROLE, []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [Harness::ROLE], false));

        // 2. Trigger first update (starts grant, sets busy)
        $harness->emitRaw('GUILD_MEMBER_UPDATE', [
            'guild_id' => $harness->guildId,
            'user' => ['id' => $userId, 'guildmanager' => false],
            'pending' => false,
        ], 0);

        $started = count($harness->http->calls);
        // A second pending->clear transition while that grant is in flight is ignored.
        $this->tracker->added($userId, true);
        $harness->emitRaw('GUILD_MEMBER_UPDATE', [
            'guild_id' => $harness->guildId,
            'user' => ['id' => $userId, 'guildmanager' => false],
            'pending' => false,
        ], 0);
        self::assertSame($started, count($harness->http->calls));

        $deferred->resolve($harness->guild());
        $harness->flush();

        $puts = array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PUT');
        self::assertCount(1, $puts);
        self::assertStringContainsString('Onboarding: granted "Verified" to member ' . $userId . ' (verified after the write).', Harness::stderr());
    }

    public function testNonDispatchOpcodeDoesNotBackfill(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $harness->emitRaw('GUILD_CREATE', ['id' => $harness->guildId], 1);
        $harness->flush();
        self::assertStringNotContainsString('Onboarding backfill failed', Harness::stderr());
        self::assertStringNotContainsString('backfill complete', Harness::stderr());
    }

    public function testListMembersMergesASecondPage(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $page = [];
        for ($i = 1; $i <= 1000; $i++) {
            $page[] = ['user' => ['id' => sprintf('%017d', $i), 'guildmanager' => false], 'pending' => false, 'roles' => [$roleId]];
        }
        $second = '888888888888888888';
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', $page);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member($second, [], false),
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $second . '/roles/' . $roleId, []);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 1 of 1 member(s).', Harness::stderr());
        $lists = array_values(array_filter(
            $harness->http->calls,
            static fn(array $call): bool => str_contains($call['url'], '/members?'),
        ));
        self::assertCount(2, $lists);
        self::assertStringContainsString('limit=1000', $lists[0]['url']);
        self::assertStringNotContainsString('after=', $lists[0]['url']);
        self::assertStringContainsString('after=' . sprintf('%017d', 1000), $lists[1]['url']);
    }

    public function testGrantSkipsWhenTheRoleCannotBeGiven(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $userId = Harness::USER;
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;

        $this->tracker->added($userId, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', [
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertStringContainsString('Onboarding: role "Verified" does not exist in the server; no role granted to member ' . $userId . '.', Harness::stderr());

        Harness::clearStderr();
        $this->tracker->added($userId, true);
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [$roleId], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $before = count($harness->http->calls);
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        $puts = array_filter(array_slice($harness->http->calls, $before), static fn(array $call): bool => $call['method'] === 'PUT');
        self::assertCount(0, $puts);
        self::assertStringNotContainsString('granted', Harness::stderr());
        self::assertStringNotContainsString('Onboarding role grant failed', Harness::stderr());

        Harness::clearStderr();
        $this->tracker->added($userId, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => '0'],
        ]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertStringContainsString('Onboarding: the guildmanager lacks Manage Roles; cannot grant "Verified" to member ' . $userId . '.', Harness::stderr());
        self::assertStringNotContainsString('Onboarding role grant failed', Harness::stderr());
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PUT')));

        Harness::clearStderr();
        $this->tracker->added($userId, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 30, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 10, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ]);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertStringContainsString('Onboarding: role "Verified" sits at or above the guildmanager\'s highest role; no role granted to member ' . $userId . '.', Harness::stderr());
        self::assertStringNotContainsString('Onboarding role grant failed', Harness::stderr());
        self::assertSame([], array_values(array_filter($harness->http->calls, static fn(array $call): bool => $call['method'] === 'PUT')));

        Harness::clearStderr();
        $this->tracker->added($userId, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [$roleId], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [$roleId], false));
        $before = count($harness->http->calls);
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        $puts = array_filter(array_slice($harness->http->calls, $before), static fn(array $call): bool => $call['method'] === 'PUT');
        self::assertCount(0, $puts);
        self::assertStringNotContainsString('role grant failed', Harness::stderr());
    }

    public function testBackfillGrantFailure(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);

        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $harness->context->botId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member(Harness::USER, [], false),
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . Harness::USER . '/roles/' . $roleId, new \RuntimeException('Write failed'));

        $harness->discord->emit('reconnected', []);
        $harness->flush();

        self::assertStringContainsString('Onboarding: backfill could not grant "Verified" to member ' . Harness::USER . ' (RuntimeException).', Harness::stderr());
        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 0 of 1 member(s).', Harness::stderr());
    }

    public function testASecondBackfillWaitsUntilTheFirstFinishes(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $deferred = new \React\Promise\Deferred();
        $botRoleId = Harness::BOT;
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $deferred->promise());
        $harness->discord->emit('reconnected', []);
        $guildGets = static fn(array $call): bool => $call['url'] === 'guilds/' . $harness->guildId;
        $started = count(array_filter($harness->http->calls, $guildGets));
        $harness->discord->emit('reconnected', []);
        self::assertSame(1, $started);
        self::assertSame($started, count(array_filter($harness->http->calls, $guildGets)));
        $deferred->resolve($harness->guild());
        $harness->flush();
    }

    public function testAMemberWithNoPendingFieldIsGranted(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            ['user' => ['id' => Harness::USER, 'guildmanager' => false], 'roles' => []],
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . Harness::USER . '/roles/' . $roleId, []);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 1 of 1 member(s).', Harness::stderr());
        self::assertFalse($this->tracker->baselineOf(Harness::USER));
    }

    public function testAPendingMemberIsRememberedAndGrantedOnTheClear(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $userId = Harness::USER;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member($userId, [], false, true),
        ]);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertTrue($this->tracker->baselineOf($userId));

        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [], false));
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $userId . '/roles/' . $roleId, []);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $userId, $harness->member($userId, [$roleId], false));
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertStringContainsString('Onboarding: granted "Verified" to member ' . $userId . ' (verified after the write).', Harness::stderr());
    }

    public function testBothPagesAreGranted(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = Harness::ROLE;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $first = '111111111111111111';
        $page = [];
        for ($i = 1; $i <= 999; $i++) {
            $page[] = ['user' => ['id' => sprintf('%017d', $i), 'guildmanager' => false], 'pending' => false, 'roles' => [$roleId]];
        }
        $page[] = ['user' => ['id' => $first, 'guildmanager' => false], 'pending' => false, 'roles' => []];
        $second = '888888888888888888';
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', $page);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member($second, [], false),
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $first . '/roles/' . $roleId, []);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . $second . '/roles/' . $roleId, []);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringContainsString('Onboarding: backfill granting the role to 2 member(s).', Harness::stderr());
        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 2 of 2 member(s).', Harness::stderr());
    }

    public function testAnIntegerRoleIdIsStillGrantedAndAHeldIntegerIdIsNotWrittenAgain(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $roleId = 42;
        $botRoleId = Harness::BOT;
        $roles = [
            ['id' => $roleId, 'name' => 'Verified', 'position' => 10, 'permissions' => '0'],
            ['id' => $botRoleId, 'name' => 'Bot', 'position' => 20, 'permissions' => (string) Permissions::MANAGE_ROLES],
        ];
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members', [
            $harness->member(Harness::USER, [], false),
        ]);
        $harness->http->queue('PUT', 'guilds/' . $harness->guildId . '/members/' . Harness::USER . '/roles/42', []);
        $harness->discord->emit('reconnected', []);
        $harness->flush();
        self::assertStringContainsString('Onboarding: backfill complete; "Verified" granted to 1 of 1 member(s).', Harness::stderr());

        Harness::clearStderr();
        $this->tracker->added(Harness::USER, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, $harness->guild());
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/roles', $roles);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . Harness::USER, $harness->member(Harness::USER, ['42'], false));
        $harness->http->queue('GET', 'guilds/' . $harness->guildId . '/members/' . $botRoleId, $harness->member($botRoleId, [$botRoleId], true));
        $before = count($harness->http->calls);
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => Harness::USER, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        $puts = array_filter(array_slice($harness->http->calls, $before), static fn(array $call): bool => $call['method'] === 'PUT');
        self::assertCount(0, $puts);
        self::assertStringNotContainsString('Onboarding role grant failed', Harness::stderr());
    }

    public function testGrantFailureNamesTheMemberAndTheError(): void
    {
        $harness = $this->harness;
        $harness->identify();
        $this->module->attach($harness->context);
        $userId = Harness::USER;
        $this->tracker->added($userId, true);
        $harness->http->queue('GET', 'guilds/' . $harness->guildId, new \RuntimeException('API Down'));
        $harness->emitRaw('GUILD_MEMBER_UPDATE', ['guild_id' => $harness->guildId, 'user' => ['id' => $userId, 'guildmanager' => false], 'pending' => false], 0);
        $harness->flush();
        self::assertStringContainsString(
            'Onboarding role grant failed for member ' . $userId . ' (API Down)',
            Harness::stderr(),
        );
    }
}
