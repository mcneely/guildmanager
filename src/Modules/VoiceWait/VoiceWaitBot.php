<?php

declare(strict_types=1);

namespace Module\VoiceWait;

use App\BotContext;
use App\ObservedDiscord;
use App\Permissions;
use App\StateStore;
use InvalidArgumentException;
use React\Promise\PromiseInterface;
use Throwable;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Engine of the voice wait module. Single-guild, single-process; it never
 * joins or records audio. Uses the shared context for the Gateway,
 * asynchronous HTTP and Discord rate limits. Interaction dispatch and
 * command registration belong to the host (Bot).
 *
 * @phpstan-type VoiceState array{
 *     user_id?: int|string,
 *     guild_id?: int|string,
 *     channel_id?: int|string|null,
 *     session_id?: int|string,
 *     mute?: bool|null,
 *     member?: array{user?: array{id?: int|string, guildmanager?: mixed}}
 * }
 * @phpstan-type Config array{enabled?: bool, channel_id?: int|string, panel_id?: int|string}
 * @phpstan-type Watcher array{token: string, application_id: int|string, until: int}
 * @phpstan-type Guild array{id: int|string, owner_id: int|string}
 * @phpstan-type Role array{id: int|string, permissions: int|string}
 * @phpstan-type Member array{user?: array{id?: int|string, guildmanager?: mixed}, roles?: list<int|string>}
 * @phpstan-type Channel array{
 *     id?: int|string,
 *     guild_id?: int|string,
 *     type?: int|string,
 *     permission_overwrites?: list<array{id: int|string, type: int|string, allow: int|string, deny: int|string}>
 * }
 * @phpstan-type Context array{0: Guild, 1: list<Role>, 2: Member, 3: Channel, 4: Member}
 */
final class VoiceWaitBot
{
    /** @var array<string, WaitLease> */
    private array $leases = [];

    /** @var array<string, VoiceState> */
    private array $states = [];

    /** @var array<string, int> */
    private array $revisions = [];

    /** @var array<string, true> */
    private array $busy = [];

    /** @var array<string, string> */
    private array $hints = [];

    /** @var array<string, Watcher> */
    private array $watchers = [];

    /** @var Config */
    private array $config;
    private bool $online = false;
    private bool $initialized = false;
    private bool $guildAvailable = false;
    private bool $configBusy = false;
    private int $epoch = 0;
    private string $botId = '';
    private readonly ObservedDiscord $discord;
    private readonly StateStore $store;
    private readonly string $guildId;

    public function __construct(
        private readonly BotContext $context,
        private readonly MuteTransport $mutations,
        private readonly bool $registerOnly = false,
    ) {
        $this->discord = $context->discord;
        $this->store = $context->store;
        $this->guildId = $context->guildId;
        $savedConfig = $this->store->get('config:' . $this->guildId);
        /** @var Config $config */
        $config = is_array($savedConfig) ? $savedConfig : ['enabled' => false];
        $this->config = $config;
        foreach ($this->store->leases($this->guildId) as $saved) {
            /** @var array{user_id: int|string} $saved */
            $lease = WaitLease::recover($saved);
            $uid = (string) $saved['user_id'];
            $this->leases[$uid] = $lease;
            $this->save($uid);
        }
    }

    public function attach(): void
    {
        $this->discord->on('voicewait.gateway_gap', fn() => $this->gap('Gateway disconnected.'));
        $this->discord->on('raw', function (object $packet): void {
            /** @var object{op: int|string, t: string, d: mixed} $packet */
            if (in_array((int) $packet->op, [7, 9], true)) {
                $this->gap('Gateway requested a reconnect or invalidated the session.');
            }
            if ((int) $packet->op !== 0) {
                return;
            }
            /** @var array{id?: int|string, guild_id?: int|string, unavailable?: mixed, user?: array{id?: int|string}, voice_states?: list<VoiceState>} $data */
            $data = BotContext::normalize($packet->d);
            switch ($packet->t) {
                case 'READY':
                    $this->botId = (string) ($data['user']['id'] ?? '');
                    break;
                case 'GUILD_CREATE':
                    if ((string) ($data['id'] ?? '') === $this->guildId) {
                        $this->guildAvailable = empty($data['unavailable']);
                        $this->online = $this->initialized;
                        $this->states = [];
                        foreach ($data['voice_states'] ?? [] as $state) {
                            $state['guild_id'] = $this->guildId;
                            $this->states[(string) ($state['user_id'] ?? '')] = $state;
                        }
                    }
                    break;
                case 'GUILD_DELETE':
                    if ((string) ($data['id'] ?? '') === $this->guildId) {
                        $this->guildAvailable = false;
                        $this->gap('Guild became unavailable or the guildmanager was removed.');
                    }
                    break;
                case 'VOICE_STATE_UPDATE':
                    if ((string) ($data['guild_id'] ?? '') === $this->guildId) {
                        $this->voice($data);
                    }
                    break;
            }
        });
        $this->discord->on('init', function (): void {
            $this->initialized = true;
            $this->online = true;
            if ($this->registerOnly) {
                return; // The host (Bot) performs command registration.
            }
            $this->log('Ready. Existing/recovered uncertain waits require moderator review.');
            $this->discord->getLoop()->addPeriodicTimer(1.0, fn() => $this->tick());
        });
        $this->discord->on('reconnected', function (): void {
            $this->online = true;
            $this->log('Reconnected. Earlier waits remain on review; new joins can be handled.');
        });
    }

    private function healthy(): bool
    {
        return $this->online && $this->guildAvailable && !$this->registerOnly;
    }

    private function gap(string $reason): void
    {
        $this->online = false;
        ++$this->epoch;
        foreach ($this->leases as $uid => $lease) {
            if (in_array($lease->snapshot()['state'], ['applying', 'active', 'releasing'], true)) {
                $lease->hold($reason);
                $this->save((string) $uid);
                $this->notifyStatus((string) $uid);
            }
        }
        $this->mutations->cancelAll();
        $this->log($reason . ' Automatic release is paused for existing waits.');
    }

    /** @param VoiceState $next */
    private function voice(array $next): void
    {
        $uid = (string) ($next['user_id'] ?? '');
        $previous = $this->states[$uid] ?? null;
        $this->states[$uid] = $next;
        $changed = $previous === null
            || ($previous['channel_id'] ?? null) !== ($next['channel_id'] ?? null)
            || ($previous['session_id'] ?? '') !== ($next['session_id'] ?? '')
            || ($previous['mute'] ?? null) !== ($next['mute'] ?? null);
        if ($changed) {
            $this->revisions[$uid] = ($this->revisions[$uid] ?? 0) + 1;
        }
        if (!$this->healthy() || $uid === $this->botId) {
            return;
        }
        $lease = $this->leases[$uid] ?? null;
        if ($lease !== null) {
            $saved = $lease->snapshot();
            if (in_array($saved['state'], ['applying', 'active', 'releasing'], true)) {
                if (empty($next['channel_id']) || ($next['session_id'] ?? '') !== $saved['session_id']) {
                    $this->hold($uid, 'Member disconnected or rejoined; mute ownership needs review.');
                    return;
                }
                if (($previous['mute'] ?? false) && !($next['mute'] ?? true)
                    && $saved['state'] !== 'releasing') {
                    if ($saved['state'] === 'applying') {
                        $this->hold($uid, 'Mute was removed while application was still in flight.');
                        return;
                    }
                    $lease->externalRemovalObserved();
                    $this->save($uid);
                    $this->mutations->cancel($uid);
                    $this->notifyStatus($uid);
                    return;
                }
                if (($previous['channel_id'] ?? null) !== $next['channel_id']) {
                    if ($saved['state'] !== 'active') {
                        $this->hold($uid, 'Member moved during an in-flight mute operation.');
                    } elseif ($next['channel_id'] !== $saved['channel_id']) {
                        $this->release($uid, true);
                    }
                    // The next statement is the same return. Falling through does not restart a wait.
                    /** @infection-ignore-all */
                    return;
                }
                return; // Self-mute/camera updates and our own mute events do not restart the timer.
            }
        }
        $entered = !empty($next['channel_id'])
            && ($previous['channel_id'] ?? null) !== $next['channel_id'];
        if ($entered && !empty($this->config['enabled'])
            && $next['channel_id'] === ($this->config['channel_id'] ?? null)) {
            if ($lease !== null && $lease->snapshot()['state'] === 'review') {
                $this->hints[$uid] = 'A moderator must review your earlier wait first.';
                return;
            }
            if ($lease !== null && !isset($this->busy[$uid])) {
                $this->store->delete('lease:' . $this->guildId . ':' . $uid);
                unset($this->leases[$uid]);
            }
            $this->begin($uid, $next, time());
        }
    }

    /** @param VoiceState $entry */
    private function begin(string $uid, array $entry, int $enteredAt): void
    {
        if (isset($this->busy[$uid])) {
            return;
        }
        if (!array_key_exists('mute', $entry) || $entry['mute'] !== false) {
            $this->hints[$uid] = 'You already have a server mute. This guildmanager will not remove it.';
            return;
        }
        if (!empty($entry['member']['user']['guildmanager'])) {
            $this->hints[$uid] = 'Bot accounts are exempt from this wait.';
            return;
        }
        unset($this->hints[$uid]);
        $this->busy[$uid] = true;
        $epoch = $this->epoch;
        $revision = $this->revisions[$uid] ?? 0;
        $channel = (string) ($entry['channel_id'] ?? '');
        $this->context($channel, $uid)->then(function (mixed $context) use ($uid, $entry, $enteredAt, $epoch, $revision, $channel): mixed {
            /** @var Context $context */
            $permissions = Permissions::effective($context[0], $context[1], $context[2], $context[3]);
            $botPermissions = Permissions::effective($context[0], $context[1], $context[4], $context[3]);
            if (!empty($context[2]['user']['guildmanager']) || Permissions::has($permissions, Permissions::MUTE)) {
                $this->hints[$uid] = 'Server owners, administrators, voice moderators, and bots are exempt.';
                return null;
            }
            if (!Permissions::has($botPermissions, Permissions::VIEW | Permissions::CONNECT | Permissions::MUTE)) {
                $this->hints[$uid] = 'The guildmanager is missing a required channel permission; your wait was not applied.';
                return null;
            }
            return $this->freshVoice($uid)->then(function (mixed $voice) use ($uid, $entry, $enteredAt, $epoch, $revision, $channel): mixed {
                /** @var VoiceState $voice */
                if (!$this->healthy() || $epoch !== $this->epoch
                    || ($this->revisions[$uid] ?? 0) !== $revision
                    || empty($this->config['enabled'])
                    || ($this->config['channel_id'] ?? null) !== $channel
                    || ($voice['channel_id'] ?? null) !== $channel
                    || ($voice['session_id'] ?? null) !== ($entry['session_id'] ?? null)
                    || ($voice['mute'] ?? true) !== false) {
                    $this->hints[$uid] = 'Your voice state changed before a wait could be applied.';
                    return null;
                }
                if (time() >= $enteredAt + 300) {
                    $this->hints[$uid] = 'The join wait elapsed before a mute could be applied. No mute was added.';
                    return null;
                }
                $lease = WaitLease::begin(
                    $this->guildId,
                    $uid,
                    $channel,
                    (string) ($voice['session_id'] ?? ''),
                    'server_mute',
                    $enteredAt,
                    false,
                    ['mute' => false],
                    ['mute' => true],
                );
                /** @var WaitLease $lease */
                $this->leases[$uid] = $lease;
                $this->save($uid); // Durable intent precedes the external write.
                $generation = $lease->generation();
                return $this->mutations->submit(
                    $this->guildId,
                    $uid,
                    true,
                    function () use ($uid, $lease, $generation, $epoch, $entry, $channel): bool {
                        $current = $this->states[$uid] ?? [];
                        return $this->healthy() && $epoch === $this->epoch
                            && $lease->generation() === $generation
                            && $lease->snapshot()['state'] === 'applying'
                            && time() < $lease->snapshot()['expires_at']
                            && !empty($this->config['enabled'])
                            && ($this->config['channel_id'] ?? null) === $channel
                            && ($current['channel_id'] ?? null) === $channel
                            && ($current['session_id'] ?? null) === ($entry['session_id'] ?? null)
                            && ($current['mute'] ?? true) === false;
                    },
                )
                    ->then(fn() => $this->freshVoice($uid))
                    ->then(function (mixed $fresh) use ($uid, $lease, $generation, $epoch): void {
                        /** @var VoiceState $fresh */
                        if (!$this->healthy() || $epoch !== $this->epoch) {
                            $lease->hold('Connection changed during mute application.');
                        } else {
                            $lease->applied($generation, $this->observation($uid, $fresh));
                        }
                        $this->save($uid);
                        $this->notifyStatus($uid);
                    });
            });
        })->then(null, function (Throwable $error) use ($uid): void {
            $lease = $this->leases[$uid] ?? null;
            if ($lease !== null && $lease->snapshot()['state'] === 'applying') {
                $this->hold($uid, 'Mute application failed or its result is uncertain.');
            } elseif ($lease === null) {
                $this->hints[$uid] = 'The wait could not be applied. Ask a server manager to check the guildmanager.';
            }
            $this->logError('Apply failed', $error);
        })->finally(function () use ($uid): void {
            unset($this->busy[$uid]);
        });
    }

    private function release(string $uid, bool $leftSelectedChannel = false): void
    {
        if (!$this->healthy() || isset($this->busy[$uid]) || !isset($this->leases[$uid])) {
            return;
        }
        $lease = $this->leases[$uid];
        if ($lease->snapshot()['state'] !== 'active') {
            return;
        }
        $this->busy[$uid] = true;
        $epoch = $this->epoch;
        $generation = $lease->generation();
        $revision = $this->revisions[$uid] ?? 0;
        $this->freshVoice($uid)->then(function (mixed $voice) use ($uid, $lease, $epoch, $generation, $revision, $leftSelectedChannel): mixed {
            /** @var VoiceState $voice */
            if (!$this->healthy() || $epoch !== $this->epoch || $generation !== $lease->generation()) {
                return null;
            }
            if (($this->revisions[$uid] ?? 0) !== $revision) {
                $this->hold($uid, 'Voice state changed while checking release.');
                return null;
            }
            $plan = $lease->prepareRelease(time(), $this->observation($uid, $voice), true, $leftSelectedChannel);
            $this->save($uid);
            if ($plan === null) {
                $this->notifyStatus($uid);
                return null;
            }
            // Residual API race: Discord offers no atomic mute-owner/compare-and-set.
            return $this->mutations->submit(
                $this->guildId,
                $uid,
                false,
                function () use ($uid, $lease, $plan, $epoch, $voice, $revision): bool {
                    $current = $this->states[$uid] ?? [];
                    return $this->healthy() && $epoch === $this->epoch
                        && ($this->revisions[$uid] ?? 0) === $revision
                        && $lease->generation() === $plan['generation']
                        && $lease->snapshot()['state'] === 'releasing'
                        && ($current['channel_id'] ?? null) === ($voice['channel_id'] ?? null)
                        && !empty($current['channel_id'])
                        && ($current['session_id'] ?? null) === ($voice['session_id'] ?? null)
                        && ($current['mute'] ?? false) === true;
                },
            )
                ->then(fn() => $this->freshVoice($uid))
                ->then(function (mixed $fresh) use ($uid, $lease, $plan, $epoch): void {
                    /** @var VoiceState $fresh */
                    $saved = $lease->snapshot();
                    $verified = $this->healthy() && $epoch === $this->epoch
                        && ($fresh['mute'] ?? true) === false
                        && !empty($fresh['channel_id'])
                        && ($fresh['session_id'] ?? '') === $saved['session_id'];
                    $lease->released($plan['generation'], $verified);
                    $this->save($uid);
                    $this->notifyStatus($uid);
                });
        })->then(null, function (Throwable $error) use ($uid): void {
            $this->hold($uid, 'Automatic release failed or its result is uncertain; ask a moderator.');
            $this->logError('Release failed', $error);
        })->finally(function () use ($uid): void {
            unset($this->busy[$uid]);
        });
    }

    private function tick(): void
    {
        $now = time();
        foreach ($this->leases as $uid => $lease) {
            $saved = $lease->snapshot();
            if ($saved['state'] === 'active' && $saved['expires_at'] <= $now) {
                $this->release((string) $uid);
            } elseif (in_array($saved['state'], ['complete', 'cancelled'], true)
                && $saved['expires_at'] < $now - 86400 && !isset($this->busy[$uid])) {
                $this->store->delete('lease:' . $this->guildId . ':' . $uid);
                unset($this->leases[$uid]);
            }
        }
        foreach ($this->watchers as $uid => $watcher) {
            if ($watcher['until'] < $now) {
                unset($this->watchers[$uid]);
            }
        }
    }

    /**
     * Claim one interaction, returning its handling promise, or null if it
     * belongs to another module (unknown command/button, other guild).
     */
    /**
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<mixed>|null
     */
    public function interact(array $interaction): ?PromiseInterface
    {
        /** @var array{id?: int|string, token?: string, application_id?: int|string, guild_id?: int|string, type?: int|string, member?: array{user?: array{id?: int|string}}, data?: array{name?: int|string, custom_id?: int|string, options?: list<array{name?: int|string, value?: mixed}>}} $interaction */
        if ((string) ($interaction['guild_id'] ?? '') !== $this->guildId
            || empty($interaction['member']['user']['id'])) {
            return null;
        }
        /** @infection-ignore-all */
        $type = (int) ($interaction['type'] ?? 0);
        $name = (string) ($interaction['data']['name'] ?? '');
        /** @infection-ignore-all */
        $uid = (string) $interaction['member']['user']['id'];
        if (($type === 3 && ($interaction['data']['custom_id'] ?? '') === 'voicewait:check:v1')
            || ($type === 2 && $name === 'mywait')) {
            return $this->reply($interaction, $this->status($uid), true)->then(function () use ($uid, $interaction): void {
                $this->watchers[$uid] = [
                    'token' => (string) ($interaction['token'] ?? ''),
                    'application_id' => is_int($interaction['application_id'] ?? null) || is_string($interaction['application_id'] ?? null)
                        ? $interaction['application_id']
                        : '',
                    'until' => time() + 840,
                ];
            }, fn(Throwable $error) => $this->logError('Private reply failed', $error));
        }
        $commands = ['cooldown', 'waitpanel', 'waitdisable', 'waithold', 'waitclear', 'waitreview'];
        if ($type !== 2 || !in_array($name, $commands, true)) {
            return null;
        }
        // Acknowledge before any network reads or message creation.
        $authorizationRead = false;
        return $this->defer($interaction)->then(function () use ($uid, &$authorizationRead) {
            $authorizationRead = true;
            return all([
                $this->api('get', 'guilds/' . $this->guildId),
                $this->api('get', 'guilds/' . $this->guildId . '/roles'),
                $this->api('get', 'guilds/' . $this->guildId . '/members/' . $uid),
            ]);
        })->then(function (mixed $context) use ($interaction, $name, &$authorizationRead): mixed {
            /** @var array{0: Guild, 1: list<Role>, 2: Member} $context */
            $authorizationRead = false;
            $permissions = Permissions::base($context[0], $context[1], $context[2]);
            if (!Permissions::has($permissions, Permissions::MANAGE_GUILD)) {
                return $this->editReply($interaction, 'You need the guild-level Manage Server permission to use this command.');
            }
            $options = [];
            foreach ($interaction['data']['options'] ?? [] as $option) {
                $options[(string) ($option['name'] ?? '')] = $option['value'] ?? null;
            }
            return $this->admin($name, $options)->then(
                fn(mixed $text): PromiseInterface => $this->editReply($interaction, is_string($text) ? $text : ''),
                function (Throwable $error) use ($interaction) {
                    $this->logError('Command failed', $error);
                    return $this->editReply(
                        $interaction,
                        'That operation could not be confirmed. Check the guildmanager permissions and server log before retrying.',
                    );
                },
            );
        })->then(null, function (Throwable $error) use ($interaction, &$authorizationRead) {
            $this->logError('Command reply or authorization read failed', $error);
            if ($authorizationRead) {
                return $this->editReply($interaction, 'I could not verify your server permissions. No command action was taken.')
                    ->then(null, fn(Throwable $failure) => $this->logError('Permission failure reply failed', $failure));
            }
            return null;
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return PromiseInterface<mixed>
     */
    private function admin(string $name, array $options): PromiseInterface
    {
        if ($name === 'waitreview') {
            $lines = [];
            foreach ($this->leases as $uid => $lease) {
                $saved = $lease->snapshot();
                if (!in_array($saved['state'], ['complete', 'cancelled'], true)) {
                    $lines[] = $uid . ': ' . $saved['state'] . '; ' . ($saved['reason'] ?? 'wait ends ' . $saved['expires_at']);
                }
            }
            return resolve($lines ? substr(implode("\n", $lines), 0, 1900) : 'No active or review-needed waits.');
        }
        if (in_array($name, ['waithold', 'waitclear'], true)) {
            $uid = self::snowflake($options['member'] ?? '');
            if (!isset($this->leases[$uid])) {
                return resolve('There is no recorded wait for that member.');
            }
            if ($name === 'waithold') {
                $started = $this->mutations->hasStarted($uid);
                $this->hold($uid, 'A server manager requested a moderation hold.');
                return resolve($started
                    ? 'Automatic release was paused and the in-flight request was cancelled, but Discord may already have received it. Verify the member state before applying moderation.'
                    : 'Automatic release is paused for that member. You can now manage their server mute manually.');
            }
            if (isset($this->busy[$uid])) {
                return resolve('An operation is still in flight. Wait for it to finish before clearing the record.');
            }
            if (in_array($this->leases[$uid]->snapshot()['state'], ['applying', 'active', 'releasing'], true)) {
                return resolve('Use /waithold first, then review and manually unmute the member in Discord if appropriate.');
            }
            $this->busy[$uid] = true;
            return $this->freshVoice($uid)->then(function (mixed $voice) use ($uid): string {
                /** @var VoiceState $voice */
                if (empty($voice['channel_id']) || ($voice['mute'] ?? true) !== false) {
                    return 'Reconnect the member and resolve their server mute in Discord first. No mute was changed.';
                }
                $this->store->delete('lease:' . $this->guildId . ':' . $uid);
                unset($this->leases[$uid], $this->hints[$uid]);
                return 'The wait record was cleared after confirming the member is not server-muted. A future join starts a fresh wait.';
            })->finally(function () use ($uid): void {
                unset($this->busy[$uid]);
            });
        }
        if ($this->configBusy) {
            return resolve('Another channel setup operation is in progress. Please wait.');
        }
        if ($name === 'waitdisable') {
            $this->config['enabled'] = false;
            $this->store->put('config:' . $this->guildId, $this->config);
            return resolve('New waits are disabled. Already-active waits still finish; review-needed mutes remain for a moderator to resolve.');
        }
        $channel = $name === 'cooldown'
            ? self::snowflake($options['channel'] ?? '')
            // waitpanel only concatenates this id. A numeric value stringifies the same way.
            /** @infection-ignore-all */
            : (string) ($this->config['channel_id'] ?? '');
        if ($channel === '') {
            return resolve('Run /cooldown first.');
        }
        if ($name === 'cooldown' && $channel !== ($this->config['channel_id'] ?? null)) {
            foreach ($this->leases as $lease) {
                if (!in_array($lease->snapshot()['state'], ['complete', 'cancelled'], true)) {
                    return resolve('Resolve active or review-needed waits before changing channels. Use /waitreview.');
                }
            }
        }
        $this->configBusy = true;
        return $this->context($channel, $this->botId)->then(function (mixed $context) use ($name, $channel): mixed {
            /** @var Context $context */
            $voiceChannel = $context[3];
            if ((string) ($voiceChannel['guild_id'] ?? '') !== $this->guildId || (int) ($voiceChannel['type'] ?? -1) !== 2) {
                return resolve('Choose a normal voice channel in this server. Stage channels are not supported.');
            }
            $permissions = Permissions::effective($context[0], $context[1], $context[4], $voiceChannel);
            if (!Permissions::has($permissions, Permissions::VIEW | Permissions::CONNECT | Permissions::MUTE | Permissions::SEND)) {
                return resolve('The guildmanager needs View Channel, Connect, Mute Members, and Send Messages in that voice channel.');
            }
            if ($name === 'cooldown' && $channel === ($this->config['channel_id'] ?? null)
                && !empty($this->config['panel_id'])) {
                $this->config['enabled'] = true;
                $this->store->put('config:' . $this->guildId, $this->config);
                return resolve('Five-minute waits are enabled for <#' . $channel . '>. Use /waitpanel if the old panel was removed.');
            }
            return $this->api('post', 'channels/' . $channel . '/messages', [
                'content' => 'New eligible members wait five minutes before speaking in this voice channel. '
                    . 'Click below to privately check your own wait. Existing moderation mutes remain separate.',
                'components' => self::button(),
                'allowed_mentions' => ['parse' => []],
            ])->then(function (mixed $message) use ($name, $channel): string {
                /** @var array{id?: int|string} $message */
                $this->config['channel_id'] = $channel;
                $this->config['panel_id'] = (string) ($message['id'] ?? '');
                if ($name === 'cooldown') {
                    $this->config['enabled'] = true;
                }
                $this->store->put('config:' . $this->guildId, $this->config);
                return ($name === 'cooldown' ? 'Configured five-minute waits for new eligible joins. ' : 'Posted a fresh status panel. ')
                    . 'The private Check my wait button is in <#' . $channel . '>.';
            });
        })->finally(function (): void {
            $this->configBusy = false;
        });
    }

    /** @return PromiseInterface<mixed> */
    private function context(string $channel, string $uid): PromiseInterface
    {
        return all([
            $this->api('get', 'guilds/' . $this->guildId),
            $this->api('get', 'guilds/' . $this->guildId . '/roles'),
            $this->api('get', 'guilds/' . $this->guildId . '/members/' . $uid),
            $this->api('get', 'channels/' . $channel),
            $this->api('get', 'guilds/' . $this->guildId . '/members/' . $this->botId),
        ]);
    }

    /** @return PromiseInterface<mixed> */
    private function freshVoice(string $uid): PromiseInterface
    {
        return $this->api('get', 'guilds/' . $this->guildId . '/voice-states/' . $uid);
    }

    /**
     * @param VoiceState $voice
     * @return array<string, mixed>
     */
    private function observation(string $uid, array $voice): array
    {
        return [
            'guild_id' => $this->guildId, 'user_id' => $uid,
            'channel_id' => $voice['channel_id'] ?? null,
            'session_id' => $voice['session_id'] ?? '',
            'restriction' => ['mute' => $voice['mute'] ?? null],
        ];
    }

    private function status(string $uid): string
    {
        if (isset($this->leases[$uid])) {
            return $this->leases[$uid]->status(time());
        }
        return $this->hints[$uid] ?? 'You have no guildmanager-managed wait. Your own mute or another server restriction may still apply.';
    }

    private function hold(string $uid, string $reason): void
    {
        if (isset($this->leases[$uid])) {
            $this->leases[$uid]->hold($reason);
            $this->save($uid);
            $this->mutations->cancel($uid);
            $this->notifyStatus($uid);
            $this->log('Review needed for member ' . $uid . ': ' . $reason);
        }
    }

    private function save(string $uid): void
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $this->leases[$uid]->snapshot();
        $this->store->put('lease:' . $this->guildId . ':' . $uid, $snapshot);
    }

    private function notifyStatus(string $uid): void
    {
        $watcher = $this->watchers[$uid] ?? null;
        if ($watcher === null || $watcher['until'] < time()) {
            return;
        }
        $this->editReply($watcher, $this->status($uid), true)
            ->then(null, fn(Throwable $error) => $this->logError('Private status refresh failed', $error));
    }

    /**
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<array<array-key, mixed>>
     */
    private function reply(array $interaction, string $text, bool $button = false): PromiseInterface
    {
        /** @var array{id: int|string, token: string} $interaction */
        return $this->context->reply($interaction, $text, $button ? self::button() : []);
    }

    /**
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<array<array-key, mixed>>
     */
    private function defer(array $interaction): PromiseInterface
    {
        /** @var array{id: int|string, token: string} $interaction */
        return $this->context->defer($interaction);
    }

    /**
     * @param array<array-key, mixed> $interaction
     * @return PromiseInterface<array<array-key, mixed>>
     */
    private function editReply(array $interaction, string $text, bool $button = false): PromiseInterface
    {
        /** @var array{application_id: int|string, token: string} $interaction */
        return $this->context->editReply($interaction, $text, $button ? self::button() : []);
    }

    /** @return list<array<string, mixed>> */
    private static function button(): array
    {
        return [['type' => 1, 'components' => [[
            'type' => 2, 'style' => 1, 'label' => 'Check my wait',
            'custom_id' => 'voicewait:check:v1',
        ]]]];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return PromiseInterface<array<array-key, mixed>>
     */
    private function api(string $method, string $route, ?array $body = null): PromiseInterface
    {
        // Member PATCHes throw in the context: moderation writes must go
        // through MuteTransport, never the shared client.
        return $this->context->api($method, $route, $body);
    }

    private static function snowflake(mixed $id): string
    {
        /** @infection-ignore-all */
        $id = is_int($id) || is_string($id) ? (string) $id : '';
        if (!preg_match('/^\d{17,20}$/', $id)) {
            throw new InvalidArgumentException('Invalid Discord ID.');
        }
        return $id;
    }

    private function log(string $text): void
    {
        $this->context->log($text);
    }

    private function logError(string $context, Throwable $error): void
    {
        // Do not log exception messages, URLs, raw packets, or tokens.
        $this->context->logError($context, $error);
    }
}
