<?php

declare(strict_types=1);

namespace Module\Overflow;

use App\BotContext;
use App\GuildModule;
use App\Permissions;
use Discord\Http\Exceptions\NotFoundException;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Overflow module: when a normal voice channel reaches its user limit, create
 * a voice channel directly under it using the CHANNEL_TEMPLATE. Members
 * who join that overflow are moved into the source channel as soon as a seat
 * is free. Once the source is below its limit and the overflow is empty, the
 * overflow channel is deleted.
 *
 * Discord enforces user_limit on both joins and member moves, so a waiter
 * cannot be placed into a channel that is still full. The move itself is
 * PATCH /guilds/{id}/members/{user} with channel_id (Move Members plus
 * Connect on the destination). Modify User Voice State cannot move members.
 *
 * Only channels this module created are deleted. A crash between the saved
 * intent and the saved channel id is recovered by adopting the one matching
 * voice channel under the same parent; two matches are left alone.
 *
 * Stage channels are not managed. No slash commands: the trigger is the
 * voice state, the same way onboarding watches member events.
 *
 * @phpstan-type Channel array{
 *     id?: int|string,
 *     guild_id?: int|string,
 *     type?: int|string,
 *     user_limit?: int|string,
 *     parent_id?: int|string|null,
 *     flags?: int|string,
 *     position?: int|string,
 *     bitrate?: int|string,
 *     rtc_region?: int|string,
 *     video_quality_mode?: int|string,
 *     nsfw?: mixed,
 *     permission_overwrites?: list<array{id?: int|string, type?: int|string, allow?: int|string, deny?: int|string}>
 * }
 * @phpstan-type VoiceState array{
 *     user_id?: int|string,
 *     channel_id?: int|string|null,
 *     guild_id?: int|string,
 *     member?: array{user?: array{guildmanager?: mixed}}
 * }
 * @phpstan-type GuildSnapshot array{
 *     id?: int|string,
 *     unavailable?: mixed,
 *     channels?: list<Channel>,
 *     voice_states?: list<VoiceState>
 * }
 * @phpstan-type OverflowRecord array{overflow_id: string|null, owned: bool, pending: bool}
 * @phpstan-type Role array{id: int|string, permissions: int|string, name: int|string}
 * @phpstan-type Guild array{id: int|string, owner_id: int|string}
 * @phpstan-type Member array{user?: array{id?: int|string}, roles?: list<int|string>}
 */
final class OverflowModule implements GuildModule
{
    private const OBFUSCATED = 1 << 17;

    private const int MISSING_TYPE = -1;

    /** @var array<string, Channel> */
    private array $channels = [];

    /** @var array<string, string> */
    private array $voices = [];

    /** @var array<string, true> */
    private array $bots = [];

    /** @var array<string, list<string>> */
    private array $order = [];

    /** @var array<string, OverflowRecord> */
    private array $records = [];

    /** @var array<string, true> */
    private array $busy = [];

    /** @var array<string, true> */
    private array $moving = [];

    /** @var array<string, true> */
    private array $renaming = [];

    /** @var array<string, true> */
    private array $noted = [];

    /** @var array<string, int> */
    private array $createRetryAt = [];

    /** @var array<string, int> */
    private array $moveRetryAt = [];

    /** @var array<string, int> */
    private array $deleteRetryAt = [];

    /** @var array<string, int> */
    private array $renameRetryAt = [];
    private bool $online = false;
    private bool $initialized = false;
    private bool $guildAvailable = false;
    private bool $awaitingGuild = false;
    private bool $reconciling = false;
    private int $epoch = 0;

    public function __construct(
        private readonly BotContext $context,
        private readonly MoveTransport $moves,
        private readonly bool $registerOnly = false,
    ) {
        $this->loadRecords();
    }

    public function name(): string
    {
        return 'overflow';
    }

    public function attach(BotContext $context): void
    {
        $this->context->discord->on('voicewait.gateway_gap', fn() => $this->gap('Gateway disconnected.'));
        $this->context->discord->on('raw', function (object $packet): void {
            /** @var object{op: int|string, t: string, d: mixed} $packet */
            if (in_array((int) $packet->op, [7, 9], true)) {
                $this->gap('Gateway requested a reconnect or invalidated the session.');
                /** @infection-ignore-all */
                return;
            }
            if ((int) $packet->op !== 0) {
                return;
            }
            /** @var array{id?: int|string, guild_id?: int|string, unavailable?: mixed, channels?: list<Channel>, voice_states?: list<VoiceState>} $data */
            $data = BotContext::normalize($packet->d);
            switch ($packet->t) {
                case 'GUILD_CREATE':
                    if ((string) ($data['id'] ?? '') !== $this->context->guildId) {
                        // onGuild repeats this id check, so falling through changes nothing.
                        /** @infection-ignore-all */
                        return;
                    }
                    if (empty($data['unavailable'])) {
                        $this->onGuild($data);
                    }
                    break;
                case 'GUILD_DELETE':
                    if ((string) ($data['id'] ?? '') === $this->context->guildId) {
                        $this->guildAvailable = false;
                        $this->gap('Guild became unavailable or the guildmanager was removed.');
                    }
                    break;
                case 'CHANNEL_CREATE':
                case 'CHANNEL_UPDATE':
                    $this->onChannel($data);
                    break;
                case 'CHANNEL_DELETE':
                    $this->onChannelDelete($data);
                    break;
                case 'VOICE_STATE_UPDATE':
                    if ((string) ($data['guild_id'] ?? '') === $this->context->guildId) {
                        $this->onVoice($data);
                    }
                    break;
            }
        });
        $this->context->discord->on('init', function (): void {
            $this->initialized = true;
            if ($this->registerOnly) {
                return;
            }
            $this->online = !$this->awaitingGuild && $this->guildAvailable;
            $this->context->discord->getLoop()->addPeriodicTimer(1.0, fn() => $this->tick());
            if ($this->online) {
                $this->reconcile();
            }
        });
        $this->context->discord->on('reconnected', function (): void {
            if ($this->registerOnly || $this->awaitingGuild) {
                return;
            }
            $this->online = true;
            $this->reconcile();
        });
    }

    public function commands(): array
    {
        return [];
    }

    public function interact(array $interaction): ?PromiseInterface
    {
        return null;
    }

    public function occupants(string $channelId): int
    {
        $count = 0;
        foreach ($this->voices as $channel) {
            if ($channel === $channelId) {
                ++$count;
            }
        }
        return $count;
    }

    /** @return list<string> */
    public function waiting(string $overflowId): array
    {
        return array_values($this->order[$overflowId] ?? []);
    }

    public function trackedOverflow(string $sourceId): ?string
    {
        $id = $this->records[$sourceId]['overflow_id'] ?? null;
        return $id === null || $id === '' ? null : (string) $id;
    }

    private function acting(): bool
    {
        return $this->online && $this->guildAvailable && !$this->registerOnly && $this->context->botId !== '';
    }

    private function gap(string $reason): void
    {
        $wasOnline = $this->online;
        $this->online = false;
        // In-flight work compares epoch with !==, so the direction does not matter.
        /** @infection-ignore-all */
        ++$this->epoch;
        $this->moves->cancelAll();
        if ($wasOnline) {
            $this->context->log($reason . ' Overflow moves are paused.');
        }
    }

    /** @param GuildSnapshot $data */
    private function onGuild(array $data): void
    {
        if ((string) ($data['id'] ?? '') !== $this->context->guildId) {
            return;
        }
        $this->awaitingGuild = false;
        $this->guildAvailable = empty($data['unavailable']);
        if (!$this->guildAvailable) {
            $this->gap('Guild became unavailable.');
            return;
        }
        $this->channels = [];
        foreach ($data['channels'] ?? [] as $channel) {
            $this->rememberChannel($channel);
        }
        $this->voices = [];
        $this->bots = [];
        $this->order = [];
        foreach ($data['voice_states'] ?? [] as $state) {
            if (empty($state['user_id'])) {
                continue;
            }
            $uid = (string) $state['user_id'];
            if (!empty($state['member']['user']['guildmanager'])) {
                $this->bots[$uid] = true;
            }
            $channelId = $state['channel_id'] ?? null;
            $this->setVoice($uid, $channelId === null || $channelId === '' ? null : (string) $channelId);
        }
        $this->online = $this->initialized && !$this->registerOnly;
        if ($this->acting()) {
            $this->reconcile();
        }
    }

    /** @param Channel $data */
    private function onChannel(array $data): void
    {
        if ((string) ($data['guild_id'] ?? '') !== $this->context->guildId) {
            return;
        }
        $this->rememberChannel($data);
        /** @infection-ignore-all */
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            // evaluate('') has no channel and no record, so it returns before any request.
            /** @infection-ignore-all */
            return;
        }
        $this->evaluate($id);
        $source = $this->sourceOf($id);
        if ($source !== null) {
            $this->evaluate($source);
        }
    }

    /** @param Channel $data */
    private function onChannelDelete(array $data): void
    {
        if ((string) ($data['guild_id'] ?? '') !== $this->context->guildId) {
            return;
        }
        /** @infection-ignore-all */
        $id = (string) ($data['id'] ?? '');
        unset($this->channels[$id], $this->order[$id]);
        $source = $this->sourceOf($id);
        if ($source !== null) {
            unset($this->records[$source]);
            $this->save();
            // Still full: create a replacement.
            $this->evaluate($source);
        }
        if (isset($this->records[$id])) {
            $this->evaluate($id);
        }
    }

    /** @param VoiceState $data */
    private function onVoice(array $data): void
    {
        $uid = (string) ($data['user_id'] ?? '');
        if ($uid === '') {
            return;
        }
        if (!empty($data['member']['user']['guildmanager'])) {
            $this->bots[$uid] = true;
        }
        $previous = $this->voices[$uid] ?? null;
        $channelId = $data['channel_id'] ?? null;
        $next = $channelId === null || $channelId === '' ? null : (string) $channelId;
        $this->setVoice($uid, $next);
        if ($previous === $next) {
            return;
        }
        // A seat just opened. A recent \"channel full\" rejection must not
        // delay the waiter who can now be moved.
        if ($previous !== null && isset($this->records[$previous])) {
            unset($this->moveRetryAt[$previous]);
        }
        // Duplicate ids are the same evaluation. A second call is a no-op.
        $ids = [];
        foreach ([$previous, $next, $previous !== null ? $this->sourceOf($previous) : null, $next !== null ? $this->sourceOf($next) : null] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        // The first evaluate sets its in-flight flag before returning, so a repeated id is a no-op.
        /** @infection-ignore-all */
        $unique = array_unique($ids);
        foreach ($unique as $sourceId) {
            $this->evaluate($sourceId);
        }
    }

    private function setVoice(string $uid, ?string $channelId): void
    {
        $previous = $this->voices[$uid] ?? null;
        if ($channelId === null) {
            unset($this->voices[$uid]);
        } else {
            $this->voices[$uid] = $channelId;
        }
        if ($previous !== null && $previous !== $channelId) {
            $this->removeWaiting($previous, $uid);
        }
        if ($channelId !== null && $channelId !== $previous && $this->canWait($uid) && $this->isOverflow($channelId)) {
            $this->addWaiting($channelId, $uid);
        }
    }

    private function tick(): void
    {
        /** @infection-ignore-all */
        if (!$this->acting()) {
            return;
        }
        foreach ($this->managedSourceIds() as $sourceId) {
            $this->evaluate($sourceId, true);
        }
    }

    private function reconcile(): void
    {
        if (!$this->acting() || $this->reconciling) {
            return;
        }
        $this->reconciling = true;
        $this->adoptPending();
        $this->confirmOverflows()->then(function (): void {
            $this->rebuildOrder();
            foreach ($this->managedSourceIds() as $sourceId) {
                $this->evaluate($sourceId);
            }
        })->finally(function (): void {
            $this->reconciling = false;
        });
    }

    /** @return list<string> */
    private function managedSourceIds(): array
    {
        $ids = [];
        foreach (array_keys($this->records) as $id) {
            $ids[] = (string) $id;
        }
        foreach ($this->channels as $id => $channel) {
            if ($this->isManageableSource((string) $id)) {
                $ids[] = (string) $id;
            }
        }
        /** @infection-ignore-all */
        return array_values(array_unique($ids));
    }

    private function evaluate(string $sourceId, bool $fromTick = false): void
    {
        if (!$this->acting() || isset($this->busy[$sourceId]) || $this->isOverflow($sourceId)) {
            return;
        }
        $source = $this->channels[$sourceId] ?? null;
        if ($source === null) {
            $this->retireMissingSource($sourceId);
            /** @infection-ignore-all */
            return;
        }
        if (!$this->isManageableSource($sourceId) && !isset($this->records[$sourceId])) {
            return;
        }
        $this->renameIfNeeded($sourceId);
        // A missing limit is not a cap. -1 and 0 are both unmanaged.
        /** @infection-ignore-all */
        $limit = (int) ($source['user_limit'] ?? 0);
        $occupancy = $this->occupants($sourceId);
        $tracked = isset($this->records[$sourceId]);

        if (OverflowPolicy::shouldCreate($occupancy, $limit, $tracked, false)) {
            $this->create($sourceId);
            // Creation leaves no overflow id yet, so the move and delete checks return.
            /** @infection-ignore-all */
            return;
        }
        $overflowId = $this->trackedOverflow($sourceId);
        if ($overflowId === null) {
            return;
        }
        if (OverflowPolicy::nextWaiting($this->order[$overflowId] ?? [], $this->voices, $overflowId) !== null) {
            $this->moveOne($sourceId, $fromTick);
            // A waiter is still in the overflow, so deletion cannot start.
            /** @infection-ignore-all */
            return;
        }
        $owned = !empty($this->records[$sourceId]['owned']);
        if (OverflowPolicy::shouldDelete($owned, $occupancy, $limit, $this->occupants($overflowId), $this->moveInFlight($overflowId))) {
            $this->deleteOverflow($sourceId);
        }
    }

    private function create(string $sourceId): void
    {
        if (isset($this->busy[$sourceId]) || isset($this->records[$sourceId])) {
            return;
        }
        /** @infection-ignore-all */
        if (($this->createRetryAt[$sourceId] ?? 0) > time()) {
            return;
        }
        $source = $this->channels[$sourceId] ?? null;
        // A missing limit is not a cap. -1 and 0 both refuse creation.
        /** @infection-ignore-all */
        $createLimit = $source === null ? 0 : (int) ($source['user_limit'] ?? 0);
        if ($source === null || !OverflowPolicy::shouldCreate(
            $this->occupants($sourceId),
            $createLimit,
            false,
            false,
        )) {
            return;
        }
        // The pending record is saved on the next lines, so a second create returns before this flag is read.
        /** @infection-ignore-all */
        $this->busy[$sourceId] = true;
        // A missing overflow id is the same as null, and owned is unread until adoption overwrites it.
        $intent = ['pending' => true];
        /** @infection-ignore-all */
        $intent['overflow_id'] = null;
        /** @infection-ignore-all */
        $intent['owned'] = true;
        $this->records[$sourceId] = $intent;
        $this->save();
        $epoch = $this->epoch;
        $guildId = $this->context->guildId;
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        all([
            $this->context->api('get', 'guilds/' . $guildId),
            $this->context->api('get', 'guilds/' . $guildId . '/roles'),
            $this->context->api('get', 'guilds/' . $guildId . '/members/' . $this->context->botId),
            $this->context->api('get', 'channels/' . $sourceId),
        ])->then(function (mixed $ctx) use ($sourceId, $epoch, $template): mixed {
            /** @var array{0: Guild, 1: list<Role>, 2: Member, 3: Channel} $ctx */
            if (!$this->acting() || $epoch !== $this->epoch) {
                return null;
            }
            [$guild, $roles, $botMember, $fresh] = $ctx;
            $this->rememberChannel($fresh);
            // A missing limit is not full. -1 and 0 both abort creation.
            /** @infection-ignore-all */
            $limit = (int) ($fresh['user_limit'] ?? 0);
            if ((int) ($fresh['type'] ?? self::MISSING_TYPE) !== 2 || !OverflowPolicy::isFull($this->occupants($sourceId), $limit)) {
                return null;
            }
            if (!Permissions::has(Permissions::base($guild, $roles, $botMember), Permissions::MANAGE_CHANNELS)) {
                // The pending record blocks another create, so this dedupe key is never read again.
                /** @infection-ignore-all */
                $noteKey = 'create:' . $sourceId;
                $this->note($noteKey, 'Overflow: the guildmanager lacks Manage Channels; no overflow created for channel ' . $sourceId . '.');
                // The pending record blocks another create, so this deadline is never read.
                /** @infection-ignore-all */
                $this->createRetryAt[$sourceId] = time() + 60;
                return null;
            }
            return $this->postChannel($fresh, true, $template)->then(function (mixed $created) use ($sourceId, $epoch) {
                /** @var Channel $created */
                if (!is_array($created) || empty($created['id'])) {
                    $this->abandonPending($sourceId);
                    return null;
                }
                $overflowId = (string) ($created['id'] ?? '');
                $this->rememberChannel($created);
                $this->records[$sourceId] = ['overflow_id' => $overflowId, 'owned' => true, 'pending' => false];
                $this->save();
                // A denied create never reaches here, so this key was not set.
                /** @infection-ignore-all */
                unset($this->noted['create:' . $sourceId]);
                $this->context->log('Overflow: created channel ' . $overflowId . ' under full channel ' . $sourceId . '.');
                $this->rebuildOrder();
                if (!$this->acting() || $epoch !== $this->epoch) {
                    return null;
                }
                return $this->placeUnder($sourceId, $overflowId)->then(null, function (Throwable $error) use ($overflowId) {
                    $this->context->logError('Overflow position update failed for channel ' . $overflowId, $error);
                });
            }, function (Throwable $error) use ($sourceId) {
                $this->abandonPending($sourceId);
                $this->createRetryAt[$sourceId] = time() + 30;
                $this->context->logError('Overflow creation failed for channel ' . $sourceId, $error);
            });
        }, function (Throwable $error) use ($sourceId): void {
            $this->abandonPending($sourceId);
            $this->context->logError('Overflow process failed for channel ' . $sourceId, $error);
        })->finally(function () use ($sourceId): void {
            unset($this->busy[$sourceId]);
        });
    }

    /** @param Channel $source
     *  @return PromiseInterface<mixed>
     */
    private function postChannel(array $source, bool $withOverwrites, string $template): PromiseInterface
    {
        $body = [
            'name' => OverflowPolicy::overflowName((string) ($source['name'] ?? ''), $template),
            'type' => 2,
            'user_limit' => 0,
        ];
        if (!empty($source['parent_id'])) {
            $body['parent_id'] = (string) $source['parent_id'];
        }
        // PHP 8 numeric-casts the string in `>=`, so dropping the cast does not change the cutoff.
        /** @infection-ignore-all */
        $bitrate = (int) ($source['bitrate'] ?? 0);
        if (isset($source['bitrate']) && $bitrate >= 8000) {
            $body['bitrate'] = (int) $source['bitrate'];
        }
        if (!empty($source['rtc_region'])) {
            $body['rtc_region'] = (string) $source['rtc_region'];
        }
        if (isset($source['video_quality_mode'])) {
            $body['video_quality_mode'] = (int) $source['video_quality_mode'];
        }
        if (!empty($source['nsfw'])) {
            $body['nsfw'] = true;
        }
        if ($withOverwrites) {
            $overwrites = OverflowPolicy::overwrites($source);
            if ($overwrites !== []) {
                $body['permission_overwrites'] = $overwrites;
            }
        }
        return $this->context->api('post', 'guilds/' . $this->context->guildId . '/channels', $body);
    }

    /** @return PromiseInterface<mixed> */
    private function placeUnder(string $sourceId, string $overflowId): PromiseInterface
    {
        $source = $this->channels[$sourceId] ?? null;
        if ($source === null) {
            // Continuing still resolves null: positionUpdates finds no source.
            /** @infection-ignore-all */
            return resolve(null);
        }
        $parent = ($source['parent_id'] ?? null) ?: null;
        $siblings = [];
        foreach ($this->channels as $id => $channel) {
            if ((($channel['parent_id'] ?? null) ?: null) !== $parent) {
                continue;
            }
            // Bit 17 hides a channel. A missing flag, or 1, does not set it.
            /** @infection-ignore-all */
            $hidden = ((int) ($channel['flags'] ?? 0)) & self::OBFUSCATED;
            if ($hidden) {
                continue;
            }
            $rawPosition = $channel['position'] ?? 0;
            // Policy casts again, so dropping this cast does not change the update.
            /** @infection-ignore-all */
            $position = (int) $rawPosition;
            // positionUpdates keys the id. A numeric snowflake is the same key either way.
            /** @infection-ignore-all */
            $siblings[] = ['id' => (string) $id, 'position' => $position];
        }
        // A second copy at PHP_INT_MAX does not change the assigned positions.
        /** @infection-ignore-all */
        if (!isset($this->channels[$overflowId])) {
            $siblings[] = ['id' => $overflowId, 'position' => PHP_INT_MAX];
        }
        $updates = OverflowPolicy::positionUpdates($siblings, $sourceId, $overflowId);
        if ($updates === []) {
            return resolve(null);
        }
        return $this->context->api('patch', 'guilds/' . $this->context->guildId . '/channels', $updates)
            ->then(function () use ($updates): void {
                foreach ($updates as $update) {
                    if (isset($this->channels[$update['id']])) {
                        $this->channels[$update['id']]['position'] = $update['position'];
                    }
                }
            });
    }

    private function moveOne(string $sourceId, bool $respectCooldown): void
    {
        /** @infection-ignore-all */
        if ($respectCooldown && ($this->moveRetryAt[$sourceId] ?? 0) > time()) {
            return;
        }
        $overflowId = $this->trackedOverflow($sourceId);
        $source = $this->channels[$sourceId] ?? null;
        if ($overflowId === null || $source === null) {
            return;
        }
        $uid = OverflowPolicy::nextWaiting($this->order[$overflowId] ?? [], $this->voices, $overflowId);
        if ($uid === null || isset($this->moving[$uid]) || $uid === $this->context->botId) {
            return;
        }
        // isset is true for false, so a second move still waits for finally().
        /** @infection-ignore-all */
        $this->moving[$uid] = true;
        $epoch = $this->epoch;
        $guildId = $this->context->guildId;
        $this->context->api('get', 'guilds/' . $guildId . '/voice-states/' . $uid)
            ->then(function (array $voice) use ($uid, $sourceId, $overflowId, $epoch, $guildId) {
                /** @var array{channel_id?: int|string|null} $voice */
                if (!$this->acting() || $epoch !== $this->epoch) {
                    return null;
                }
                if ((string) ($voice['channel_id'] ?? '') !== $overflowId) {
                    return null;
                }
                return all([
                    $this->context->api('get', 'guilds/' . $guildId),
                    $this->context->api('get', 'guilds/' . $guildId . '/roles'),
                    $this->context->api('get', 'guilds/' . $guildId . '/members/' . $this->context->botId),
                    $this->context->api('get', 'channels/' . $sourceId),
                ])->then(function (mixed $ctx) use ($uid, $sourceId, $overflowId, $epoch): mixed {
                    /** @var array{0: Guild, 1: list<Role>, 2: Member, 3: Channel} $ctx */
                    if (!$this->acting() || $epoch !== $this->epoch) {
                        return null;
                    }
                    [$guild, $roles, $botMember, $fresh] = $ctx;
                    $this->rememberChannel($fresh);
                    /** @var array{permission_overwrites?: list<array{id: int|string, type: int|string, allow: int|string, deny: int|string}>} $channel */
                    $channel = $fresh;
                    $permissions = Permissions::effective($guild, $roles, $botMember, $channel);
                    if (!Permissions::has($permissions, Permissions::MOVE_MEMBERS | Permissions::CONNECT | Permissions::VIEW)) {
                        $this->note('move:' . $sourceId, 'Overflow: the guildmanager lacks Move Members, Connect, or View Channel; not moving members into channel ' . $sourceId . '.');
                        $this->moveRetryAt[$sourceId] = time() + 60;
                        return null;
                    }
                    return $this->moves->submit($this->context->guildId, $uid, $sourceId, fn(): bool => $this->acting() && $epoch === $this->epoch
                        && ($this->voices[$uid] ?? null) === $overflowId);
                });
            })
            ->then(function ($moved) use ($uid, $sourceId, $overflowId): void {
                if ($moved === null) {
                    return;
                }
                unset($this->noted['move:' . $sourceId], $this->moveRetryAt[$sourceId]);
                // Optimistic update: member is now in source channel.
                $this->voices[$uid] = $sourceId;
                $this->removeWaiting($overflowId, $uid);
                $this->context->log('Overflow: moved member ' . $uid . ' into channel ' . $sourceId . '.');
            }, function (Throwable $error) use ($uid, $sourceId): void {
                if ($error instanceof NotFoundException) {
                    return; // Left voice before the read. The next event reconciles.
                }
                $this->moveRetryAt[$sourceId] = time() + 5;
                $this->context->logError('Overflow move failed for member ' . $uid, $error);
            })->finally(function () use ($uid): void {
                unset($this->moving[$uid]);
            });
    }

    private function deleteOverflow(string $sourceId): void
    {
        $record = $this->records[$sourceId] ?? null;
        $overflowId = $record['overflow_id'] ?? null;
        if ($overflowId === null || $overflowId === '' || empty($record['owned']) || isset($this->busy[$sourceId])) {
            return;
        }
        /** @infection-ignore-all */
        if (($this->deleteRetryAt[$sourceId] ?? 0) > time()) {
            return;
        }
        $overflowId = (string) $overflowId;
        if ($this->occupants($overflowId) > 0 || $this->moveInFlight($overflowId)) {
            return;
        }
        $source = $this->channels[$sourceId] ?? null;
        // A missing limit is not full. -1 and 0 both allow the delete.
        /** @infection-ignore-all */
        $deleteLimit = $source === null ? 0 : (int) ($source['user_limit'] ?? 0);
        if ($source !== null && !OverflowPolicy::shouldDelete(
            true,
            $this->occupants($sourceId),
            $deleteLimit,
            0,
            false,
        )) {
            return;
        }
        // isset is true for false, so a second delete still waits for finally().
        /** @infection-ignore-all */
        $this->busy[$sourceId] = true;
        $epoch = $this->epoch;
        $confirm
            /**
             * @param array<array-key, mixed> $fresh
             * @return PromiseInterface<array<array-key, mixed>>|null
             */
            = function (array $fresh) use ($sourceId, $overflowId, $epoch) {
                /** @var Channel $fresh */
                if (!$this->acting() || $epoch !== $this->epoch) {
                    return null;
                }
                if ($this->occupants($overflowId) > 0 || $this->moveInFlight($overflowId)) {
                    return null;
                }
                $source = $this->channels[$sourceId] ?? null;
                // A missing limit is not full. -1 and 0 both allow the delete.
                /** @infection-ignore-all */
                $limit = $source === null ? 0 : (int) ($source['user_limit'] ?? 0);
                if ($source !== null && OverflowPolicy::isFull($this->occupants($sourceId), $limit)) {
                    return null;
                }
                if ((string) ($fresh['id'] ?? '') !== $overflowId || (int) ($fresh['type'] ?? self::MISSING_TYPE) !== 2) {
                    return null;
                }
                return $this->context->api('delete', 'channels/' . $overflowId);
            };
        /** @phpstan-ignore argument.type */
        $this->context->api('get', 'channels/' . $overflowId)->then($confirm)->then(function ($deleted) use ($sourceId, $overflowId): void {
            if (!is_array($deleted)) {
                return;
            }
            unset($this->records[$sourceId], $this->channels[$overflowId], $this->order[$overflowId]);
            $this->save();
            $this->context->log('Overflow: removed channel ' . $overflowId . ' after channel ' . $sourceId . ' dropped below its limit.');
        }, function (Throwable $error) use ($sourceId, $overflowId): void {
            if ($error instanceof NotFoundException) {
                unset($this->records[$sourceId], $this->channels[$overflowId], $this->order[$overflowId]);
                $this->save();
                return;
            }
            $this->deleteRetryAt[$sourceId] = time() + 30;
            $this->context->logError('Overflow delete failed for channel ' . $overflowId, $error);
        })->finally(function () use ($sourceId): void {
            unset($this->busy[$sourceId]);
        });
    }

    private function renameIfNeeded(string $sourceId): void
    {
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $overflowId = $this->trackedOverflow($sourceId);
        if ($overflowId === null || empty($this->records[$sourceId]['owned']) || isset($this->renaming[$overflowId])) {
            return;
        }
        $source = $this->channels[$sourceId] ?? null;
        $overflow = $this->channels[$overflowId] ?? null;
        if ($source === null || $overflow === null) {
            return;
        }
        $expected = OverflowPolicy::overflowName((string) ($source['name'] ?? ''), $template);
        if ((string) ($overflow['name'] ?? '') === $expected) {
            return;
        }
        /** @infection-ignore-all */
        if (($this->renameRetryAt[$overflowId] ?? 0) > time()) {
            return;
        }
        // isset is true for false, so a second rename still waits for finally().
        /** @infection-ignore-all */
        $this->renaming[$overflowId] = true;
        $this->context->api('patch', 'channels/' . $overflowId, ['name' => $expected])
            ->then(function (mixed $updated): void {
                /** @var Channel $updated */
                $this->rememberChannel($updated);
            }, function (Throwable $error) use ($overflowId): void {
                $this->renameRetryAt[$overflowId] = time() + 60;
                $this->context->logError('Overflow rename failed for channel ' . $overflowId, $error);
            })->finally(function () use ($overflowId): void {
                unset($this->renaming[$overflowId]);
            });
    }

    private function retireMissingSource(string $sourceId): void
    {
        $record = $this->records[$sourceId] ?? null;
        if ($record === null) {
            return;
        }
        $overflowId = $record['overflow_id'] ?? null;
        if ($overflowId === null || $overflowId === '') {
            unset($this->records[$sourceId]);
            $this->save();
            // The record is already gone, so the occupied and delete checks below no-op.
            /** @infection-ignore-all */
            return;
        }
        $overflowId = (string) $overflowId;
        if ($this->occupants($overflowId) > 0) {
            // The key is only a dedupe token. Nothing else reads it, and both calls use this same expression.
            /** @infection-ignore-all */
            $this->note('orphan:' . $sourceId, 'Overflow: source channel ' . $sourceId . ' is gone; leaving occupied overflow ' . $overflowId . ' in place.');
            // deleteOverflow returns immediately while the overflow is occupied.
            /** @infection-ignore-all */
            return;
        }
        if (!empty($record['owned'])) {
            $this->deleteOverflow($sourceId);
        }
    }

    private function adoptPending(): void
    {
        $template = getenv('CHANNEL_TEMPLATE') ?: '--- <channel> Overflow';
        $changed = false;
        foreach ($this->records as $sourceId => $record) {
            if (empty($record['pending']) || !empty($record['overflow_id'])) {
                continue;
            }
            $source = $this->channels[$sourceId] ?? null;
            if ($source === null) {
                unset($this->records[$sourceId]);
                $changed = true;
                continue;
            }
            // matchPending only reads channel values, so the list index is irrelevant.
            /** @infection-ignore-all */
            $candidates = array_values($this->channels);
            $match = OverflowPolicy::matchPending(
                $candidates,
                OverflowPolicy::overflowName((string) ($source['name'] ?? ''), $template),
                ($source['parent_id'] ?? null) ?: null,
            );
            if ($match === null) {
                unset($this->records[$sourceId]);
            } else {
                // pending is unread once an overflow id is saved.
                /** @infection-ignore-all */
                $this->records[$sourceId] = ['overflow_id' => $match, 'owned' => true, 'pending' => false];
                $this->context->log('Overflow: adopted channel ' . $match . ' for channel ' . $sourceId . ' after a restart.');
            }
            $changed = true;
        }
        if ($changed) {
            $this->save();
        }
    }

    /** A missing overflow id is confirmed with one GET before the record is dropped, so an omitted channel list cannot cause a duplicate. */
    /** @return PromiseInterface<mixed> */
    private function confirmOverflows(): PromiseInterface
    {
        $checks = [];
        foreach ($this->records as $sourceId => $record) {
            // A numeric id is the same array key and the same URL either way.
            /** @infection-ignore-all */
            $id = (string) ($record['overflow_id'] ?? '');
            if ($id === '' || isset($this->channels[$id])) {
                continue;
            }
            $checks[] = $this->context->api('get', 'channels/' . $id)->then(function (mixed $channel): void {
                /** @var Channel $channel */
                $this->rememberChannel($channel);
            }, function (Throwable $error) use ($sourceId, $id): void {
                if ($error instanceof NotFoundException) {
                    unset($this->records[$sourceId], $this->order[$id]);
                    $this->save();
                }
            });
        }
        return $checks === [] ? resolve(null) : all($checks);
    }

    private function rebuildOrder(): void
    {
        $this->order = [];
        foreach ($this->voices as $uid => $channelId) {
            if ($this->canWait((string) $uid) && $this->isOverflow((string) $channelId)) {
                $this->addWaiting((string) $channelId, (string) $uid);
            }
        }
    }

    private function abandonPending(string $sourceId): void
    {
        $record = $this->records[$sourceId] ?? null;
        if ($record !== null && !empty($record['pending']) && empty($record['overflow_id'])) {
            unset($this->records[$sourceId]);
            $this->save();
        }
    }

    /** @param Channel $channel */
    private function rememberChannel(array $channel): void
    {
        // A numeric id used as a key is cast to int either way.
        /** @infection-ignore-all */
        $id = (string) ($channel['id'] ?? '');
        // A missing flag is not bit 17, and 1 does not set it.
        /** @infection-ignore-all */
        if ($id === '' || ((int) ($channel['flags'] ?? 0) & self::OBFUSCATED)) {
            return;
        }
        $this->channels[$id] = $channel;
    }

    private function isManageableSource(string $channelId): bool
    {
        $channel = $this->channels[$channelId] ?? null;
        if ($channel === null || $this->isOverflow($channelId)) {
            return false;
        }
        if ((int) ($channel['type'] ?? self::MISSING_TYPE) !== 2) {
            return false;
        }
        // A missing limit is not a cap. -1 and 0 are both unmanaged.
        /** @infection-ignore-all */
        return (int) ($channel['user_limit'] ?? 0) > 0;
    }

    private function isOverflow(string $channelId): bool
    {
        return array_any($this->records, fn($record) => (string) ($record['overflow_id'] ?? '') === $channelId);
    }

    private function sourceOf(string $overflowId): ?string
    {
        foreach ($this->records as $sourceId => $record) {
            if ((string) ($record['overflow_id'] ?? '') === $overflowId) {
                return (string) $sourceId;
            }
        }
        return null;
    }

    private function canWait(string $uid): bool
    {
        return $uid !== $this->context->botId && empty($this->bots[$uid]);
    }

    private function addWaiting(string $overflowId, string $uid): void
    {
        $order = $this->order[$overflowId] ?? [];
        if (!in_array($uid, $order, true)) {
            $order[] = $uid;
        }
        $this->order[$overflowId] = $order;
    }

    private function removeWaiting(string $overflowId, string $uid): void
    {
        if (!isset($this->order[$overflowId])) {
            return;
        }
        $remaining = array_filter(
            $this->order[$overflowId],
            static fn(string $waiting): bool => $waiting !== $uid,
        );
        // nextWaiting and waiting() read values, not keys.
        /** @infection-ignore-all */
        $this->order[$overflowId] = array_values($remaining);
    }

    private function moveInFlight(string $overflowId): bool
    {
        foreach (array_keys($this->moving) as $uid) {
            // Numeric ids share one array key either way.
            /** @infection-ignore-all */
            $channelId = $this->voices[(string) $uid] ?? null;
            if ($channelId === $overflowId) {
                return true;
            }
        }
        return false;
    }

    private function note(string $key, string $text): void
    {
        if (isset($this->noted[$key])) {
            return;
        }
        // isset is true for false, so a second note still returns.
        /** @infection-ignore-all */
        $this->noted[$key] = true;
        $this->context->log($text);
    }

    private function loadRecords(): void
    {
        $saved = $this->context->store->get('overflows:' . $this->context->guildId);
        if (!is_array($saved)) {
            return;
        }
        foreach ($saved as $sourceId => $record) {
            $sourceId = (string) $sourceId;
            if (!preg_match('/^\d{17,20}$/', $sourceId) || !is_array($record)) {
                continue;
            }
            $raw = $record['overflow_id'] ?? null;
            $overflowId = is_int($raw) || is_string($raw) ? $raw : null;
            if ($overflowId !== null && $overflowId !== '' && !preg_match('/^\d{17,20}$/', (string) $overflowId)) {
                continue;
            }
            $this->records[$sourceId] = [
                // Every reader casts this id before comparing or storing it again.
                /** @infection-ignore-all */
                'overflow_id' => $overflowId === null || $overflowId === '' ? null : (string) $overflowId,
                'owned' => !empty($record['owned']),
                'pending' => !empty($record['pending']),
            ];
        }
    }

    private function save(): void
    {
        /** @var array<string, mixed> $records */
        $records = $this->records;
        $this->context->store->put('overflows:' . $this->context->guildId, $records);
    }
}
