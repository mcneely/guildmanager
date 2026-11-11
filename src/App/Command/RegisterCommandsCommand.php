<?php

declare(strict_types=1);

namespace App\Command;

/** Upserts the Modules' guild command definitions, then exits. */
#[\Symfony\Component\Console\Attribute\AsCommand(name: 'register', description: "Upsert the Modules' guild command definitions, then close the Gateway connection.", help: <<<'TXT'
Do not run this while another instance owns the state database.
TXT)]
class RegisterCommandsCommand extends BotCommand
{
    protected function registerOnly(): bool
    {
        return true;
    }
}
