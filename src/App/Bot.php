<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Throwable;

use function React\Promise\all;

/**
 * Host orchestrator. Owns the Gateway connection lifecycle, the single
 * interaction dispatch loop, and command registration. Features live in
 * GuildModule implementations; the host wires them to the context.
 */
final readonly class Bot
{
    /** @var list<GuildModule> */
    private array $modules;

    /**
     * @param list<GuildModule> $modules
     */
    public function __construct(
        private BotContext $context,
        array $modules,
        private bool $registerOnly = false,
    ) {
        $seen = [];
        foreach ($modules as $module) {
            $name = $module->name();
            if (in_array($name, $seen, true)) {
                throw new InvalidArgumentException("Duplicate module name: $name");
            }
            $seen[] = $name;
        }
        $this->modules = $modules;
    }

    public function attach(): void
    {
        // Registered before module handlers so READY identity is set first.
        $this->context->discord->on('raw', function (object $packet): void {
            /** @var object{op: int|string, t: string, d: mixed} $packet */
            if ((int) $packet->op !== 0) {
                return;
            }
            $data = BotContext::normalize($packet->d);
            switch ($packet->t) {
                case 'READY':
                    $this->context->setIdentity($data);
                    break;
                case 'INTERACTION_CREATE':
                    if (!$this->registerOnly) {
                        $this->dispatchInteraction($data);
                    }
                    break;
            }
        });
        $this->context->discord->on('init', function (): void {
            if ($this->registerOnly) {
                $this->registerCommands();
                return;
            }
            $this->context->log('Ready. Loaded Modules: ' . implode(', ', array_map(
                static fn(GuildModule $module): string => $module->name(),
                $this->modules,
            )) . '.');
        });

        foreach ($this->modules as $module) {
            $module->attach($this->context);
        }
    }

    /**
     * First module to claim an interaction owns it; others are skipped.
     *
     * @param array<array-key, mixed> $interaction
     */
    private function dispatchInteraction(array $interaction): void
    {
        foreach ($this->modules as $module) {
            $name = $module->name();
            try {
                $promise = $module->interact($interaction);
            } catch (Throwable $error) {
                $this->context->logError("Module $name interaction dispatch failed", $error);
                continue;
            }
            if ($promise === null) {
                continue;
            }
            $promise->then(null, function (Throwable $error) use ($name): void {
                $this->context->logError("Module $name interaction failed", $error);
            });
            return;
        }
    }

    /**
     * Upsert only the Modules' command names; never bulk-delete unrelated
     * guild commands.
     */
    private function registerCommands(): void
    {
        $requests = [];
        foreach ($this->modules as $module) {
            foreach ($module->commands() as $command) {
                if (!is_array($command)) {
                    continue;
                }
                $requests[] = $this->context->api(
                    'post',
                    'applications/' . $this->context->applicationId . '/guilds/' . $this->context->guildId . '/commands',
                    $command,
                );
            }
        }
        all($requests)->then(
            function (): void {
                $this->context->log('Guild commands registered.');
                $this->context->discord->close();
            },
            function (Throwable $error): void {
                $this->context->logError('Command registration failed', $error);
                $this->context->discord->close();
            },
        );
    }
}
