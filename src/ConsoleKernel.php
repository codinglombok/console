<?php

declare(strict_types=1);

namespace LombokClarion\Console;

use LombokClarion\Container\ContainerInterface;
use RuntimeException;

/**
 * `bootstrap/console.php` registers every command by hand:
 *
 *   $console->register(MigrateCommand::class);
 *   $console->register(AuditSqlCommand::class);
 *
 * There is no scanning of app/Console/Commands for classes implementing
 * Command — the list you can read in console.php IS the list of commands
 * that exist.
 */
final class ConsoleKernel
{
    /** @var array<string, class-string<Command>> */
    private array $commands = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @param class-string<Command> $commandClass
     */
    public function register(string $commandClass): void
    {
        $signature = $commandClass::signature();
        $this->commands[$signature] = $commandClass;
    }

    /**
     * @param list<string> $argv typically $argv without the script name
     */
    public function handle(array $argv): int
    {
        if ($argv === []) {
            fwrite(STDERR, "Usage: lombokclarion <command> [arguments]\n");
            fwrite(STDERR, "Available commands: " . implode(', ', array_keys($this->commands)) . "\n");
            return 1;
        }

        [$name, $arguments] = [$argv[0], array_slice($argv, 1)];

        if (!isset($this->commands[$name])) {
            fwrite(STDERR, "Unknown command \"$name\". Available: " . implode(', ', array_keys($this->commands)) . "\n");
            return 1;
        }

        /** @var Command $command */
        $command = $this->container->get($this->commands[$name]);
        if (!$command instanceof Command) {
            throw new RuntimeException("{$this->commands[$name]} must implement " . Command::class);
        }

        return $command->run($arguments);
    }

    /** @return array<string, class-string<Command>> */
    public function commands(): array
    {
        return $this->commands;
    }
}
