<?php

declare(strict_types=1);

namespace App\Command;

use App\BotFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Shared startup sequence for the guildmanager's console commands: resolve the
 * environment configuration, wire the host to its Modules, then hand
 * control to the ReactPHP event loop.
 */
abstract class BotCommand extends Command
{
    abstract protected function registerOnly(): bool;

    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $factory = $this->factory();
            $factory->build($this->registerOnly())->attach();
        } catch (Throwable $error) {
            $this->logger->error($error->getMessage(), [
                'exception' => $error,
            ]);

            return Command::FAILURE;
        }
        try {
            $this->start($factory);
        } catch (Throwable $error) {
            $this->logger->error($error->getMessage(), [
                'exception' => $error,
            ]);
            return Command::FAILURE;
        } finally {
            $factory->store()->close();
        }

        return Command::SUCCESS;
    }

    protected function factory(): BotFactory
    {
        BotFactory::setLogger($this->logger);
        return BotFactory::fromEnvironment();
    }

    protected function start(BotFactory $factory): void
    {
        $factory->discord()->run();
    }
}
