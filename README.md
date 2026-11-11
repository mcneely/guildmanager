# Guild manager bot, in PHP

A single-server, modular Discord bot. Features live in self-contained modules under `src/Modules`, wired together by a small console host (`bin/guildmanager` + `src/App`):

- **Voice wait** (`src/Modules`): automatically server-mutes eligible new voice-channel arrivals for five minutes. A **Check my wait** button in the voice channel's text chat opens the member's own private countdown.
- **Onboarding** (`src/Modules`): grants the configured onboarding role the moment a member moves out of Discord's onboarding screening, detected from the `GUILD_MEMBER_UPDATE` Gateway event that clears the member's `pending` state. On server (re)availability it also runs a catch-up scan so members who completed screening while the bot was offline still receive the role.
- **Overflow** (`src/Modules`): when a normal voice channel with a user limit fills, creates a voice channel directly under it named `<Channel Name> Overflow`. Members who join the overflow are moved into the source channel as soon as a seat is free. The overflow is deleted once the source is below its limit and empty.

The voice-channel interpretation is confirmed. This package contains the implementation, tests, and setup instructions. It has **not been installed or connected to Discord**. PHP/Composer and live-Discord verification remain outstanding;

## What it does

- `/cooldown <voice channel>` enables the rule and posts the status button.
- A genuine transition into that channel starts a 300-second deadline. Self-mute, camera, and deafen events do not reset it.
- Clicking **Check my wait**, or using `/mywait`, returns a private ephemeral message with a relative finish time and exact local finish time. Discord renders the relative timestamp; it is not a second-by-second stopwatch. The latest private response is updated when the bot finishes or needs review.
- After the wait, the bot checks its recorded ownership and current voice state, then removes its mute. It never changes the member's local/self-mute.
- A future return to the channel starts a new wait after the earlier lease is resolved. People already present when setup occurs or the bot starts are not retroactively muted.
- Existing server mutes are not claimed or automatically removed. Owners, administrators, members with effective Mute Members permission, and bot accounts are exempt.

### Onboarding

- The grant is event-driven, not a user action: when a member moves out of onboarding screening, Discord sends a `GUILD_MEMBER_UPDATE` that clears the member's `pending` flag, and the bot grants the onboarding role (default `Verified`, configurable via `ONBOARDING_ROLE_NAME`).
- The bot baselines each member's `pending` state from join and update events, and grants exactly once on the first observed pending -> not-pending transition. Bot accounts and members of other servers are ignored.
- On server (re)availability and after every Gateway recovery (resumed sessions do not replay the member events lost during the gap) the bot runs a catch-up scan: it lists every member (paginated) and grants the role to each member who is no longer pending and does not hold it. This covers members who completed screening while the bot was offline or before it joined. The scan is idempotent and skips when the role is missing, the bot lacks Manage Roles, or the role sits at or above the bot's highest role.
- The grant is idempotent and verified: the member's roles are re-read immediately before the write and again after it, and success is only reported once the role is confirmed on the member.
- The bot needs **Manage Roles**, and the role must sit below the bot's highest role.

### Overflow

- Applies to every normal voice channel that has a user limit. Stage channels are not managed. There is no command to turn it on: filling the channel is the trigger.
- The overflow is created in the same category, placed directly under the source, and named `<Channel Name> Overflow` (truncated to Discord's 100-character limit). It copies the source channel's permission overwrites, bitrate, and voice region, and it has no user limit of its own, so it does not grow a second overflow.
- Discord rejects a move into a voice channel that is already at its user limit. A member who joins the overflow is moved into the source as soon as a seat opens, including immediately if a seat is already free. The oldest waiter is moved first.
- When the source drops below its limit and the overflow is empty, the bot deletes the overflow. It only deletes a channel it created. If the source channel itself is deleted while people are still in the overflow, that overflow is left in place so nobody is disconnected.
- The bot needs **Manage Channels** to create, place, and delete the overflow, and **Move Members**, **View Channel**, and **Connect** on the source channel to move a waiter in.

## The important safety limitation

Discord exposes server mute as **one shared boolean**. There is no atomic “unmute only if this bot still owns this mute” operation. A moderator's simultaneous mute can be indistinguishable from the bot's mute. No implementation using this flag can honestly guarantee that race away.

This implementation addresses the avoidable cases:

- It preserves known preexisting mutes and respects observed manual overrides.
- Every queued moderation write checks the current lease generation, connection epoch, session, channel, and mute state **immediately before starting HTTP**.
- It uses its own guarded, cancellable moderation queue. Mute/unmute writes never enter DiscordPHP's opaque retry queue. Only one moderation request is in flight, and at most one starts per second.
- It does not automatically retry failed moderation writes. Rate limits, timeouts, permission failures, disconnected members, ambiguous results, and bot/Gateway restarts retain a review-needed record instead of blindly unmuting.
- A manager can run `/waithold member:<member>` before separate moderation. Queued work is cancelled. An already-started request may have reached Discord even if cancelled, so the manager must verify the member state before applying their own mute.

These protections intentionally mean **some members need manual moderator cleanup after an outage or error**. A five-minute deadline is not a guaranteed release time during failure. There is also a short join-to-mute API delay and possible queue/rate-limit delay. This is not a zero-audio admission barrier.

## Requirements

- 64-bit PHP 8.4 or newer, below 9.0, running as a long-lived CLI process
- Composer and the JSON, zlib, PDO, and PDO PostgreSQL extensions
- A server-installed Discord application/bot you control
- A dedicated bot token stored in your hosting provider's secret manager or secure process environment
- One running instance per state database/server

Composer resolves DiscordPHP and its dependencies. The bot explicitly disables the audio/voice client. It does not join voice or record audio. It requests the Guilds, Guild Voice States, and Server Members intents; it does not request Message Content or Presence.

Keep the Postgres data volume available across restarts (named volume `guildmanager-db`). Do not run this through PHP-FPM, Apache request handlers, or a short-lived cron job.

## Setup

1. In the [Discord Developer Portal](https://discord.com/developers/applications), create or select your application and bot. Install it to your server with the `bot` and `applications.commands` scopes. Leave the Interactions Endpoint URL unset: this implementation receives interactions through the Gateway. Enable the **Server Members Intent** in the bot settings — the onboarding module reads member screening events, and Discord closes the Gateway connection with code `4014` if a privileged intent is sent without being enabled there (apps in 100+ guilds also need verification approval for it).
2. Give it **View Channel, Connect, Mute Members, and Send Messages** in the designated voice channel. Administrator is unnecessary. Cleanup after a move also needs applicable access in the destination voice channel; otherwise the wait goes to review. For onboarding, give it **Manage Roles** and create the onboarding role (default `Verified`) below the bot's highest role. For overflow, give it **Manage Channels**, plus **Move Members**, **View Channel**, and **Connect** on any voice channel that has a user limit.
3. Set these process environment variables through your host's secret management:
   - `DISCORD_BOT_TOKEN`: your bot token; never paste it into chat, source control, or logs
   - `DISCORD_GUILD_ID`: your server ID as a string
   - `DATABASE_URL`: the Postgres state database, e.g. `postgresql://user:password@postgres:5432/guildmanager`; the bot refuses to start without it
   - Optional `ONBOARDING_ROLE_NAME`: the onboarding module's role name; defaults to `Verified`
4. Start the state database (compose builds the PHP image and waits for Postgres before starting anything else):

```sh
docker compose up -d postgres
```

5. Install dependencies and perform local validation (the suite runs in the built container because the host PHP lacks `pdo_pgsql`):

```sh
docker compose run --rm php composer install
docker compose run --rm php composer lint
docker compose run --rm php composer test
```

6. Register the commands in your server, then start the bot — both through Compose,
because the host PHP has no `pdo_pgsql` and cannot resolve the `postgres` hostname:

```sh
docker compose run --rm --no-deps php php bin/guildmanager register
docker compose up -d php
```

Registration upserts only this package's seven voice-wait command names. It does not bulk-overwrite unrelated commands. Do not run the registration process while another instance owns the same state database.

7. In Discord, run `/cooldown channel:<your voice channel>`. Setup requires **guild-level Manage Server**, which is checked again against fresh server roles/member data. It validates the bot's effective permissions before enabling the rule.

8. Test with an ordinary non-moderator account in a dedicated test channel, before broader use.

## Stack

```sh
make start     # docker compose up -d
make stop      # docker compose down (named volume guildmanager-db is kept)
make restart   # stop, then start
```

`make` lists these. The compose command is `docker compose` when the `docker-compose` binary is absent.

## Commands

- `/cooldown <channel>`: configure the normal voice channel and enable new waits
- `/mywait`: privately view your own wait
- `/waitpanel`: post a fresh button in the configured voice channel
- `/waitdisable`: stop new waits; existing active waits finish and review records remain
- `/waitreview`: privately show active/review-needed member IDs and states to a manager
- `/waithold member:<member>`: revoke automatic release before separate moderation
- `/waitclear member:<member>`: clear a reviewed record only after fresh confirmation that the connected member is not server-muted; it does not unmute anyone

Every command except `/mywait` requires guild-level Manage Server. A button always looks up the clicking member, never a supplied target member. The onboarding and overflow modules have no commands and no buttons.

## If someone stays muted

1. Use `/waitreview` and `/waithold member:<member>`.
2. If a request had already started, wait for its result and inspect the member in Discord. Do not assume cancellation rolled back a server action.
3. When appropriate, have a moderator manually remove the server mute while the member is connected to voice.
4. Run `/waitclear member:<member>`. The next genuine channel entry can start a new wait.


