<?php

declare(strict_types=1);

namespace App\Command;

/** Long-lived guildmanager process: Gateway connection plus the module handlers. */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'run', description: 'Run the guildmanager: serve the voice-wait, onboarding, and overflow Modules from the Gateway.', help: <<<'TXT'
Long-lived process. Requires DISCORD_BOT_TOKEN, DISCORD_GUILD_ID, and DATABASE_URL in the environment.
TXT)]
class RunBotCommand extends BotCommand
{
    protected function registerOnly(): bool
    {
        return false;
    }
}
