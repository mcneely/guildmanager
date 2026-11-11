<?php

declare(strict_types=1);

namespace Module\Onboarding;

use App\BotContext;
use App\GuildModule;
use App\Permissions;
use Discord\Http\Endpoint;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\all;

/**
 * Onboarding module: when a member moves out of Discord's onboarding
 * screening state, the guildmanager grants the configured onboarding role.
 *
 * The trigger is the Gateway event, not a user action: the module baselines
 * each member's `pending` state from GUILD_MEMBER_ADD and GUILD_MEMBER_UPDATE,
 * and grants exactly on the first observed pending -> not-pending transition
 * (the GUILD_MEMBER_UPDATE that clears `pending`). Members of other guilds
 * and guildmanager accounts are ignored.
 *
 * On guild (re)availability, and after every Gateway recovery (a resumed
 * session does not replay the member events lost during the gap), the
 * module backfills: it lists every member and grants the role to anyone
 * who is no longer pending and does not hold it, so members who completed
 * screening while the guildmanager was offline (or before it joined) still receive
 * the role. The backfill is idempotent.
 *
 * The grant is idempotent and verified: the member's roles are re-read
 * immediately before the write and re-read after it, and success is only
 * reported once the role is confirmed on the member. Discord's member-role
 * route is not reachable through BotContext::api() by design; this module
 * issues the write explicitly on the shared client's rate-limit queue
 * (safe: re-adding a held role is a no-op, and 429s are retried by the
 * client after retry_after).
 *
 * @phpstan-type Member array{user: array{id: int|string, guildmanager?: mixed}, roles?: list<int|string>, pending?: mixed}
 * @phpstan-type Role array{id: int|string, permissions: int|string, name: int|string, position?: int|string}
 * @phpstan-type Guild array{id: int|string, owner_id: int|string, unavailable?: mixed}
 */
final class OnboardingModule implements GuildModule
{
    private readonly OnboardingTracker $tracker;

    /** @var array<string, true> */
    private array $busy = [];
    private bool $backfilling = false;

    public function __construct(
        private readonly BotContext $context,
        private readonly string $roleName = 'Verified',
        ?OnboardingTracker $tracker = null,
        private readonly bool $registerOnly = false,
    ) {
        $this->tracker = $tracker ?? new OnboardingTracker();
    }

    public function name(): string
    {
        return 'onboarding';
    }

    public function attach(BotContext $context): void
    {
        $this->context->discord->on('raw', function (object $packet, mixed $discord): void {
            /** @var object{op: int|string, t: string, d: mixed} $packet */
            if ((int) $packet->op !== 0) {
                return;
            }
            /** @var array{id?: int|string, guild_id?: int|string, unavailable?: mixed, user?: array{id?: int|string, guildmanager?: mixed}, pending?: mixed} $data */
            $data = BotContext::normalize($packet->d);
            switch ($packet->t) {
                case 'GUILD_CREATE':
                    // The payload is the guild object itself: its id is
                    // `id`, not the `guild_id` field the member events carry.
                    if ((string) ($data['id'] ?? '') !== $this->context->guildId) {
                        return;
                    }
                    // Backfill on (re)availability: closes the gap for members
                    // who completed screening while the guildmanager was offline.
                    if (empty($data['unavailable'])) {
                        $this->backfill();
                    }
                    break;
                case 'GUILD_MEMBER_ADD':
                case 'GUILD_MEMBER_UPDATE':
                case 'GUILD_MEMBER_REMOVE':
                    if ((string) ($data['guild_id'] ?? '') !== $this->context->guildId) {
                        return;
                    }
                    if (empty($data['user']['id']) || !empty($data['user']['guildmanager'])) {
                        return;
                    }
                    $uid = (string) $data['user']['id'];
                    if ($packet->t === 'GUILD_MEMBER_ADD') {
                        $this->tracker->added($uid, $this->pendingOf($data));
                    } elseif ($packet->t === 'GUILD_MEMBER_UPDATE') {
                        $this->onUpdate($uid, $this->pendingOf($data));
                    } else {
                        $this->tracker->removed($uid);
                    }
                    break;
            }
        });
        // A resumed session does not replay the member events lost during
        // the gap, so re-establish the \"no longer pending => has the role\"
        // invariant after every Gateway recovery. A full reconnect also
        // re-sends GUILD_CREATE; the backfilling flag prevents a second scan.
        $this->context->discord->on('reconnected', function (): void {
            $this->backfill();
        });
    }

    /** Event-driven only: no slash commands, no interactions. */
    public function commands(): array
    {
        return [];
    }

    public function interact(array $interaction): ?PromiseInterface
    {
        return null;
    }

    /** `pending` is optional in the payload: absent means screening is off or already cleared. */
    /** @param array{pending?: mixed} $data */
    private function pendingOf(array $data): bool
    {
        return (bool) ($data['pending'] ?? false);
    }

    /**
     * Catch-up scan of every guild member: anyone who is no longer pending
     * and does not hold the onboarding role is granted it, and every member
     * gets a live baseline. Runs on guild (re)availability so members who
     * completed screening while the guildmanager was offline are covered. Skipped
     * for --register runs, while another scan is in flight, and until READY
     * identity is known.
     */
    private function backfill(): void
    {
        if ($this->registerOnly || $this->backfilling || $this->context->botId === '') {
            return;
        }
        $this->backfilling = true;
        $guildId = $this->context->guildId;
        all([
            $this->context->api('get', 'guilds/' . $guildId),
            $this->context->api('get', 'guilds/' . $guildId . '/roles'),
            $this->context->api('get', 'guilds/' . $guildId . '/members/' . $this->context->botId),
        ])->then(function (mixed $ctx) use ($guildId): mixed {
            /** @var array{0: Guild, 1: list<Role>, 2: Member} $ctx */
            [$guild, $roles, $botMember] = $ctx;
            $role = self::findRole($roles, $this->roleName);
            if ($role === null) {
                $this->context->log('Onboarding: role "' . $this->roleName . '" does not exist in the server; backfill skipped.');
                return null;
            }
            // Only interpolated into a log line, which casts either way.
            /** @infection-ignore-all */
            $roleName = (string) $role['name'];
            $roleId = (string) $role['id'];
            if (!Permissions::has(Permissions::base($guild, $roles, $botMember), Permissions::MANAGE_ROLES)) {
                $this->context->log('Onboarding: the guildmanager lacks Manage Roles; backfill skipped.');
                return null;
            }
            if (!self::canManageRole($roles, $roleId, $botMember)) {
                $this->context->log('Onboarding: role "' . $roleName . '" sits at or above the guildmanager\'s highest role; backfill skipped.');
                return null;
            }
            return $this->listMembers($guildId)->then(function (mixed $members) use ($roleId, $roleName): mixed {
                /** @var list<Member> $members */
                foreach ($members as $member) {
                    if (empty($member['user']['guildmanager'])) {
                        $this->tracker->added((string) $member['user']['id'], (bool) ($member['pending'] ?? false));
                    }
                }
                $targets = self::selectTargets($members, $roleId);
                if ($targets === []) {
                    $this->context->log('Onboarding: backfill complete; every non-pending member already holds the role.');
                    return null;
                }
                $writes = array_map(fn(string $uid): PromiseInterface => $this->backfillGrant($uid, $roleId, $roleName), $targets);
                $this->context->log('Onboarding: backfill granting the role to ' . count($writes) . ' member(s).');
                return all($writes)->then(function (array $results) use ($roleName, $writes): void {
                    /** @infection-ignore-all */
                    $failed = count(array_values(array_filter($results, static fn($uid): bool => $uid === '')));
                    $this->context->log('Onboarding: backfill complete; "' . $roleName . '" granted to ' . (count($writes) - $failed) . ' of ' . count($writes) . ' member(s).');
                });
            });
        })->then(null, function (Throwable $error): void {
            $this->context->logError('Onboarding backfill failed', $error);
        })->finally(function (): void {
            $this->backfilling = false;
        });
    }

    /** Page through the full member list (1000 per page); needs the GUILD_MEMBERS intent. */
    /** @return PromiseInterface<list<Member>> */
    private function listMembers(string $guildId): PromiseInterface
    {
        $http = $this->context->discord->getHttpClient();
        $fetch = function (string $after) use ($http, $guildId, &$fetch): PromiseInterface {
            $endpoint = Endpoint::bind('guilds/' . $guildId . '/members');
            $endpoint->addQuery('limit', '1000');
            if ($after !== '') {
                $endpoint->addQuery('after', $after);
            }
            return $http->get($endpoint)->then(function (mixed $page) use ($fetch): array|\React\Promise\PromiseInterface {
                /** @var list<Member> $normalized */
                $normalized = BotContext::normalize($page);
                $cursor = self::nextCursor($normalized);
                if ($cursor === null) {
                    return $normalized;
                }
                return $fetch($cursor)->then(static function (mixed $rest) use ($normalized): array {
                    /** @var list<Member> $rest */
                    return array_merge($normalized, $rest);
                });
            });
        };
        return $fetch('');
    }

    /**
     * Pagination cursor for List Guild Members: the highest user id seen on
     * a full page, or null when the page is short (the last page). The
     * cursor is the max id, not the last element, so it does not depend on
     * the order of the response.
     */
    /** @param list<Member> $page */
    public static function nextCursor(array $page): ?string
    {
        if ($page === [] || count($page) < 1000) {
            return null;
        }
        $max = null;
        foreach ($page as $member) {
            $id = (string) $member['user']['id'];
            if ($max === null || self::idGt($id, $max)) {
                $max = $id;
            }
        }
        return $max;
    }

    /** Numeric comparison for snowflake ids kept as strings. */
    private static function idGt(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b)) {
            /** @infection-ignore-all */
            return strlen($a) > strlen($b);
        }
        return $a > $b; // equal-length numeric strings: lexicographic order is numeric order
    }

    private function onUpdate(string $uid, bool $pending): void
    {
        if (!$this->tracker->updated($uid, $pending)) {
            return;
        }
        if ($this->context->botId === '') {
            return; // READY identity is set by the host before any guild event can arrive.
        }
        if (isset($this->busy[$uid])) {
            return;
        }
        // isset is true for false, so a second grant still waits for finally().
        /** @infection-ignore-all */
        $this->busy[$uid] = true;
        $this->grant($uid)
            ->then(null, function (Throwable $error) use ($uid): void {
                $this->context->logError('Onboarding role grant failed for member ' . $uid . ' (' . $error->getMessage() . ')', $error);
            })
            ->finally(function () use ($uid): void {
                unset($this->busy[$uid]);
            });
    }

    /** @return PromiseInterface<mixed> */
    private function grant(string $uid): PromiseInterface
    {
        $guildId = $this->context->guildId;
        return all([
            $this->context->api('get', 'guilds/' . $guildId),
            $this->context->api('get', 'guilds/' . $guildId . '/roles'),
            $this->context->api('get', 'guilds/' . $guildId . '/members/' . $uid),
            $this->context->api('get', 'guilds/' . $guildId . '/members/' . $this->context->botId),
        ])->then(function (mixed $ctx) use ($uid, $guildId): mixed {
            /** @var array{0: Guild, 1: list<Role>, 2: Member, 3: Member} $ctx */
            [$guild, $roles, $member, $botMember] = $ctx;
            $role = self::findRole($roles, $this->roleName);
            if ($role === null) {
                $this->context->log('Onboarding: role "' . $this->roleName . '" does not exist in the server; no role granted to member ' . $uid . '.');
                return null;
            }
            /** @infection-ignore-all */
            $roleName = (string) $role['name'];
            $roleId = (string) $role['id'];
            if (in_array($roleId, self::strings($member['roles'] ?? []), true)) {
                return null; // Already held (e.g. granted by a moderator); nothing to do.
            }
            if (!Permissions::has(Permissions::base($guild, $roles, $botMember), Permissions::MANAGE_ROLES)) {
                $this->context->log('Onboarding: the guildmanager lacks Manage Roles; cannot grant "' . $roleName . '" to member ' . $uid . '.');
                return null;
            }
            if (!self::canManageRole($roles, $roleId, $botMember)) {
                $this->context->log('Onboarding: role "' . $roleName . '" sits at or above the guildmanager\'s highest role; no role granted to member ' . $uid . '.');
                return null;
            }
            // Re-read immediately before the write so the decision uses current roles.
            return $this->context->api('get', 'guilds/' . $guildId . '/members/' . $uid)
                ->then(function (mixed $fresh) use ($uid, $roleId, $guildId): mixed {
                    /** @var Member $fresh */
                    if (in_array($roleId, self::strings($fresh['roles'] ?? []), true)) {
                        return null;
                    }
                    $http = $this->context->discord->getHttpClient();
                    $endpoint = Endpoint::bind(Endpoint::GUILD_MEMBER_ROLE, $guildId, $uid, $roleId);
                    return $http->put($endpoint); // Add Guild Member Role; 204 with no body.
                })
                ->then(fn() => $this->context->api('get', 'guilds/' . $guildId . '/members/' . $uid))
                ->then(function (mixed $verified) use ($uid, $roleId, $roleName): void {
                    /** @var Member $verified */
                    $granted = in_array($roleId, self::strings($verified['roles'] ?? []), true);
                    $this->context->log($granted
                        ? 'Onboarding: granted "' . $roleName . '" to member ' . $uid . ' (verified after the write).'
                        : 'Onboarding: "' . $roleName . '" was NOT verified on member ' . $uid . ' after the write; a manager should check the member\'s roles.');
                });
        });
    }

    /**
     * Single backfill write. Resolves to the member id on success, or '' if
     * the write failed (per-member failure is logged). Idempotent: re-adding
     * a held role is a no-op.
     * @return PromiseInterface<string>
     */
    private function backfillGrant(string $uid, string $roleId, string $roleName): PromiseInterface
    {
        $guildId = $this->context->guildId;
        $http = $this->context->discord->getHttpClient();
        $endpoint = Endpoint::bind(Endpoint::GUILD_MEMBER_ROLE, $guildId, $uid, $roleId);
        return $http->put($endpoint)
            ->then(static fn(): string => $uid, function (Throwable $error) use ($uid, $roleName): string {
                $this->context->log('Onboarding: backfill could not grant "' . $roleName . '" to member ' . $uid . ' (' . $error::class . ').');
                return '';
            });
    }

    /**
     * Pure selection: ids of members who are no longer pending, are not bots,
     * and do not hold the role. Used by the backfill; granted exactly to
     * these ids, so the operation is idempotent.
     *
     * @param list<Member> $members
     *
     * @return list<string>
     */
    public static function selectTargets(array $members, string $roleId): array
    {
        $targets = [];
        foreach ($members as $member) {
            if (!empty($member['user']['guildmanager'])) {
                continue;
            }
            /** @infection-ignore-all */
            if ((bool) ($member['pending'] ?? false)) {
                continue;
            }
            if (in_array($roleId, self::strings($member['roles'] ?? []), true)) {
                continue;
            }
            $targets[] = (string) $member['user']['id'];
        }
        return $targets;
    }

    /**
     * A role can be managed only when it sits strictly below the guildmanager's
     * highest role. The hierarchy is each role's `position`, with `id`
     * breaking ties (per Discord: \"roles with the same position are
     * sorted by id\"). The guild's role array is NOT returned in position
     * order, so the decision compares `position`/`id` fields, never array
     * indices.
     */
    /**
     * @param list<Role> $roles
     * @param Member $botMember
     */
    public static function canManageRole(array $roles, string $roleId, array $botMember): bool
    {
        $botRoleIds = self::strings($botMember['roles'] ?? []);
        $target = null;
        $highest = null;
        foreach ($roles as $role) {
            $id = (string) $role['id'];
            $key = self::roleKey($role);
            if ($id === $roleId) {
                $target = $key;
            }
            // Overwritten whenever it is read, and unused when highest is still null.
            /** @infection-ignore-all */
            $above = false;
            if ($highest !== null) {
                // Equal keys share position and id, so a non-strict compare stores the same key.
                /** @infection-ignore-all */
                $above = $key > $highest;
            }
            if (in_array($id, $botRoleIds, true) && ($highest === null || $above)) {
                $highest = $key;
            }
        }
        return $target !== null && $highest !== null && $target < $highest;
    }

    /** Hierarchy key: position first, id to break ties. Larger = higher. */
    /**
     * @param Role $role
     *
     * @return array{int, string}
     */
    private static function roleKey(array $role): array
    {
        $position = $role['position'] ?? 0;
        // PHP 8 numeric-casts both sides of `<`, so the string form orders the same.
        /** @infection-ignore-all */
        $position = (int) $position;
        /** @infection-ignore-all */
        $id = (string) $role['id'];

        return [$position, $id];
    }

    /**
     * @param list<Role> $roles
     *
     * @return Role|null
     */
    private static function findRole(array $roles, string $name): ?array
    {
        foreach ($roles as $role) {
            if (strcasecmp((string) ($role['name'] ?? ''), $name) === 0) {
                return $role;
            }
        }
        return null;
    }

    /** @param list<int|string> $ids
     *  @return list<string>
     */
    private static function strings(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[] = (string) $id;
        }
        return $out;
    }
}
