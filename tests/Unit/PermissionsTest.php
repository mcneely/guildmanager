<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Permissions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permissions::class)]
final class PermissionsTest extends TestCase
{
    public function testRoleAllowBeatsRoleDenyAndMemberDenyBeatsRoleAllow(): void
    {
        $guild = ['id' => 123, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1', 'r2']];
        $viewBit = Permissions::VIEW;
        $roles = [
            ['id' => '123', 'permissions' => 7],
            ['id' => 'r1', 'permissions' => (string) $viewBit],
            ['id' => 'r2', 'permissions' => 0],
            ['id' => 'ignored', 'permissions' => '16'],
        ];

        // 1. Role Allow beats Role Deny (when Allow is applied later)
        // Overwrites: [Deny $viewBit, Allow $viewBit]
        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => '0', 'deny' => (string) $viewBit],
            ['id' => 'r2', 'type' => 0, 'allow' => (string) $viewBit, 'deny' => '0'],
        ]];
        $effective = Permissions::effective($guild, $roles, $member, $channel);
        self::assertTrue(Permissions::has($effective, $viewBit), 'Role Allow (applied last) should beat Role Deny (applied first)');

        // 2. Member Deny beats Role Allow (Type 1 beats Type 0)
        // Overwrites: [Role Allow $viewBit, Member Deny $viewBit]
        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => (string) $viewBit, 'deny' => '0'],
            ['id' => '456', 'type' => 1, 'allow' => '0', 'deny' => (string) $viewBit],
        ]];
        $effective = Permissions::effective($guild, $roles, $member, $channel);
        self::assertFalse(Permissions::has($effective, $viewBit), 'Member Deny (type 1) should beat Role Allow (type 0)');
    }

    public function testAdministratorAndOwnerBypassChannelDenies(): void
    {
        $guild = ['id' => 1, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => 'u'], 'roles' => ['r1']];
        $roles = [
            ['id' => '1', 'permissions' => '0'],
            ['id' => 'r1', 'permissions' => (string) Permissions::ADMIN],
        ];
        $channel = ['permission_overwrites' => [
            ['id' => '1', 'type' => 0, 'allow' => '0', 'deny' => '8'],
            ['id' => 'u', 'type' => 1, 'allow' => '0', 'deny' => '8'],
        ]];
        self::assertSame(PHP_INT_MAX, Permissions::base($guild, $roles, $member));
        self::assertSame(PHP_INT_MAX, Permissions::effective($guild, $roles, $member, $channel));
        $owner = ['user' => ['id' => 'owner'], 'roles' => []];
        self::assertTrue(Permissions::has(Permissions::effective($guild, $roles, $owner, $channel), Permissions::MANAGE_GUILD));
        self::assertSame(PHP_INT_MAX, Permissions::base($guild, $roles, $owner));
    }

    public function testHasRequiresEveryRequestedBit(): void
    {
        self::assertFalse(Permissions::has(Permissions::VIEW, Permissions::VIEW | Permissions::MUTE));
        self::assertTrue(Permissions::has(Permissions::ADMIN, Permissions::MUTE));
    }

    public function testMalformedDataHandling(): void
    {
        $guild = ['id' => 'g', 'owner_id' => 'owner'];
        $roles = [['id' => 'g', 'permissions' => (string) Permissions::VIEW]];

        // Test non-numeric permission strings
        $roles = [['id' => 'g', 'permissions' => '2abc']];
        self::assertSame(2, Permissions::base($guild, $roles, []));

        // Test invalid guild/role ID for base
        $roles = [['id' => '1', 'permissions' => '1']];
        self::assertSame(1, Permissions::base(['id' => '1', 'owner_id' => '2'], $roles, []));
    }

    public function testEffectivePermissionsWithVariousOverwrites(): void
    {
        $guild = ['id' => 1, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '123'], 'roles' => ['r1', 'r2']];
        $viewBit = Permissions::VIEW;
        $roles = [
            ['id' => '1', 'permissions' => 7],
            ['id' => 'r1', 'permissions' => (string) $viewBit],
            ['id' => 'r2', 'permissions' => 0],
        ];

        // Overwrites: [Role Allow $viewBit, Member Deny $viewBit, Role Allow 16, Member Allow 32]
        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => (string) $viewBit, 'deny' => '0'],
            ['id' => '123', 'type' => 1, 'allow' => '0', 'deny' => (string) $viewBit],
            ['id' => 'r2', 'type' => 0, 'allow' => '16', 'deny' => '0'],
            ['id' => '123', 'type' => 1, 'allow' => '32', 'deny' => '0'],
        ]];

        $effective = Permissions::effective($guild, $roles, $member, $channel);

        self::assertTrue(Permissions::has($effective, 32));
        self::assertTrue(Permissions::has($effective, 16));
        self::assertFalse(Permissions::has($effective, $viewBit));
        self::assertTrue(Permissions::has($effective, 4));
        self::assertTrue(Permissions::has($effective, 2));
        self::assertTrue(Permissions::has($effective, 1));
    }

    public function testOwnerIdAsIntInBase(): void
    {
        // Kills mutant 1: if ((string) $uid === (string) $guild['owner_id'] ...
        $guild = ['id' => '123', 'owner_id' => '456'];
        $member = ['user' => ['id' => 456], 'roles' => []];

        self::assertSame(PHP_INT_MAX, Permissions::base($guild, [], $member), 'Should detect owner even if member id is int and owner_id is string');
    }

    public function testIntegerIdsAreHandledAsStrings(): void
    {
        // Kills CastString mutants in base() and effective()

        // 1. base() owner check
        $guild = ['id' => '123', 'owner_id' => 456];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        self::assertSame(PHP_INT_MAX, Permissions::base($guild, [], $member), 'Should detect owner even if member id is string and owner_id is int');

        $guild = ['id' => 123, 'owner_id' => 456];
        $member = ['user' => ['id' => 456], 'roles' => []];
        self::assertSame(PHP_INT_MAX, Permissions::base($guild, [], $member), 'Should detect owner when both ids are ints');

        // 2. base() role ID check
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => [123]];
        $roles = [['id' => 123, 'permissions' => 1]];
        self::assertSame(1, Permissions::base($guild, $roles, $member), 'Should detect role even if role id is int and guild id is string');

        // 3. effective() member ID check
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $channel = ['permission_overwrites' => [
            ['id' => 456, 'type' => 1, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, [], $member, $channel), 'Should detect member even if member id is string and overwrite id is int');

        // 4. effective() overwrite ID check
        $guild = ['id' => 123, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $channel = ['permission_overwrites' => [
            ['id' => 123, 'type' => 0, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, [], $member, $channel), 'Should detect guild even if guild id is int and overwrite id is string');
    }

    public function testUnwrapArrayMapAndRoleIdCasting(): void
    {
        $guild = ['id' => 'g', 'owner_id' => 'o'];
        $member = ['user' => ['id' => 'u'], 'roles' => [123, '456']];
        $roles = [
            ['id' => 123, 'permissions' => 1],
            ['id' => '456', 'permissions' => 2],
        ];

        self::assertSame(3, Permissions::base($guild, $roles, $member));

        $channel = ['permission_overwrites' => [
            ['id' => 123, 'type' => 0, 'allow' => '4', 'deny' => '0'],
        ]];
        self::assertSame(7, Permissions::effective($guild, $roles, $member, $channel));

        $channel = ['permission_overwrites' => [
            ['id' => 'u', 'type' => 1, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(11, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testRoleAccumulationKillsAssignmentMutants(): void
    {
        // Kills mutants where |= is replaced by = (Mutants 13, 14, 15, 16, 18)
        $guild = ['id' => 123, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1', 'r2']];
        $roles = [
            ['id' => 'r1', 'permissions' => '1'],
            ['id' => 'r2', 'permissions' => '2'],
        ];

        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => '1', 'deny' => '0'],
            ['id' => 'r2', 'type' => 0, 'allow' => '2', 'deny' => '0'],
        ]];

        $effective = Permissions::effective($guild, $roles, $member, $channel);
        self::assertSame(3, $effective, 'Role permissions must accumulate using |=');
    }

    public function testMemberOverwriteLogicKillsLogicalAndMutants(): void
    {
        // Kills mutants where && is replaced by || (Mutant 21)
        $guild = ['id' => 123, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1']];
        $roles = [['id' => 'r1', 'permissions' => '1']];

        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '2', 'deny' => '0'],
        ]];
        self::assertSame(3, Permissions::effective($guild, $roles, $member, $channel));

        $channel = ['permission_overwrites' => [
            ['id' => 'wrong', 'type' => 1, 'allow' => '2', 'deny' => '0'],
        ]];
        self::assertSame(1, Permissions::effective($guild, $roles, $member, $channel));

        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 0, 'allow' => '2', 'deny' => '0'],
        ]];
        $roles = [['id' => 'r1', 'permissions' => '0']];
        self::assertSame(0, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testRoleIdCastingInBase(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['123']];
        $roles = [['id' => 123, 'permissions' => 1]];
        self::assertSame(1, Permissions::base($guild, $roles, $member));
    }

    public function testRolePermissionsCastingInBase(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1']];
        $roles = [['id' => 'r1', 'permissions' => '1.5']];
        self::assertSame(1, Permissions::base($guild, $roles, $member));
    }

    public function testMemberUidCastingInEffective(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => 456], 'roles' => []];
        $roles = [];
        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testOverwriteIdCastingInEffective(): void
    {
        $guild = ['id' => 123, 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $roles = [];
        $channel = ['permission_overwrites' => [
            ['id' => 123, 'type' => 0, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testOverwriteTypeCastingInEffective(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $roles = [];
        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => '1', 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testOverwriteAllowDenyCastingInEffective(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $roles = [];
        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '8.5', 'deny' => '0.5'],
        ]];
        self::assertSame(8, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testForeachEmptyMutant(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []];
        $roles = [];
        $channel = ['permission_overwrites' => [
            ['id' => '123', 'type' => 0, 'allow' => '8', 'deny' => '0'],
        ]];
        self::assertSame(8, Permissions::effective($guild, $roles, $member, $channel));
    }

    public function testGuildOverwriteBitwiseNot(): void
    {
        // Kills mutant 3: $permissions & ~(int) $overwrite['deny'] -> $permissions & (int) $overwrite['deny']
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1']];
        $viewBit = Permissions::VIEW;
        $roles = [['id' => 'r1', 'permissions' => (string) $viewBit]];

        // Base is $viewBit.
        // Guild overwrite: Deny $viewBit.
        // Result should be 0.
        // Mutant: $viewBit & $viewBit | 0 = $viewBit.
        $channel = ['permission_overwrites' => [
            ['id' => '123', 'type' => 0, 'allow' => '0', 'deny' => (string) $viewBit],
        ]];
        self::assertSame(0, Permissions::effective($guild, $roles, $member, $channel), 'Guild overwrite should clear the denied bit');
    }

    public function testRoleAccumulationDenyAndAllow(): void
    {
        // Kills mutants 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 22 (Accumulation and Casting)
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1', 'r2']];
        $roles = [
            ['id' => 'r1', 'permissions' => '1'],
            ['id' => 'r2', 'permissions' => '2'],
        ];

        // Role 1: Allow 1, Deny 0
        // Role 2: Allow 2, Deny 4
        // Expected: (1 | 2) & ~(0 | 4) = 3 & ~4 = 3
        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => '1', 'deny' => '0'],
            ['id' => 'r2', 'type' => 0, 'allow' => '2', 'deny' => '4'],
        ]];

        $effective = Permissions::effective($guild, $roles, $member, $channel);
        self::assertSame(3, $effective, 'Role permissions (allow and deny) must accumulate correctly');

        $bare = ['id' => '123', 'owner_id' => 'owner'];
        $noBase = ['user' => ['id' => '456'], 'roles' => ['r1', 'r2']];
        self::assertSame(3, Permissions::effective($bare, [], $noBase, ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => 1, 'deny' => 1],
            ['id' => 'r2', 'type' => 0, 'allow' => 2, 'deny' => 2],
        ]]));
        self::assertSame(4, Permissions::effective($bare, [['id' => 'r1', 'permissions' => '7']], $noBase, ['permission_overwrites' => [
            ['id' => 'r1', 'type' => 0, 'allow' => 0, 'deny' => 1],
            ['id' => 'r2', 'type' => 0, 'allow' => 0, 'deny' => 2],
        ]]));
    }

    public function testSameIdOverwritesAccumulate(): void
    {
        // Kills mutants 4 and 6 (Assignment instead of accumulation for same ID)
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1']];
        $roles = [['id' => 'r1', 'permissions' => '1']];

        // Two overwrites for the SAME member ID
        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '2', 'deny' => '0'],
            ['id' => '456', 'type' => 1, 'allow' => '4', 'deny' => '0'],
        ]];

        // Base 1.
        // First overwrite: (1 & ~0) | 2 = 3.
        // Second overwrite: (3 & ~0) | 4 = 7.
        // If Assignment is used:
        // First: 3.
        // Second: (3 & ~0) | 4 = 7. Wait, that's also 7.

        // Let's try Deny.
        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '0', 'deny' => '2'],
            ['id' => '456', 'type' => 1, 'allow' => '0', 'deny' => '4'],
        ]];
        // Base 7.
        // First: (7 & ~2) | 0 = 5.
        // Second: (5 & ~4) | 0 = 1.
        // If Assignment is used:
        // First: 5.
        // Second: (5 & ~4) | 0 = 1. Still 1.

        // Ah, the mutation is on the $allow and $deny variables *inside* the loop.
        // Let's look at line 77-78.
        // $allow |= (int) $overwrite['allow'];
        // $deny |= (int) $overwrite['deny'];

        // If we have two overwrites for the same ID:
        // Iteration 1: $allow = 2, $deny = 0.
        // Iteration 2: $allow = 4, $deny = 0.
        // If |= is used: $allow = 6, $deny = 0.
        // If = is used: $allow = 4, $deny = 0.

        $channel = ['permission_overwrites' => [
            ['id' => '456', 'type' => 1, 'allow' => '2', 'deny' => '0'],
            ['id' => '456', 'type' => 1, 'allow' => '4', 'deny' => '0'],
        ]];
        $effective = Permissions::effective($guild, $roles, $member, $channel);
        // Base 1.
        // Accumulation: (1 & ~0) | 6 = 7.
        // Assignment: (1 & ~0) | 4 = 5.
        self::assertSame(7, $effective, 'Overwrites for the same ID must accumulate allow and deny bits');
    }

    public function testGuildRoleMatchesGuildIdWithoutRoleInMember(): void
    {
        // Kills mutant 1: if ((string) $role['id'] === (string) $guild['id'] ...
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => []]; // No roles
        $roles = [['id' => 123, 'permissions' => 1]]; // Role ID is int 123

        $effective = Permissions::effective($guild, $roles, $member, []);
        self::assertSame(1, $effective, 'Should match guild role even if role ID is int and not in member roles');
    }

    public function testRoleIdAsIntInEffective(): void
    {
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => [123]];
        $roles = [['id' => 123, 'permissions' => 1]];

        $effective = Permissions::effective($guild, $roles, $member, []);
        self::assertSame(1, $effective);
    }

    public function testTypeAsStringInOverwrites(): void
    {
        // Kills mutant 4: if ((int) $overwrite['type'] === 0 ...
        $guild = ['id' => '123', 'owner_id' => 'owner'];
        $member = ['user' => ['id' => '456'], 'roles' => ['r1']];
        $roles = [['id' => 'r1', 'permissions' => 1]];
        $channel = ['permission_overwrites' => [
            ['id' => 'r1', 'type' => '0', 'allow' => '8', 'deny' => '0'],
        ]];

        $effective = Permissions::effective($guild, $roles, $member, $channel);
        self::assertSame(9, $effective); // 1 (base) | 8 (allow) = 9
    }
}
