<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\SchemaBuilder;

/**
 * Reads the migration manifest (an explicit ordered list, never a
 * directory scan) and applies whatever hasn't run yet. Per design spec
 * §7, this is meant to run under a separate, higher-privileged DB role
 * than the app runtime — that's a deployment-config concern, not
 * something this command enforces itself.
 *
 *   lombokclarion migrate
 *   lombokclarion migrate --connection=reporting
 *
 * Stage 10 note on why this takes a ConnectionManager and not a
 * MigrationRunner: a runner is bound to one PDO at construction, so a
 * command holding a runner CANNOT honour --connection= — it would have to
 * ignore the flag or lie. The manager is the seam that makes the flag
 * mean something, so the command holds the manager and builds the runner
 * for the connection actually asked for.
 *
 * Each connection tracks its own `migrations` table, because each
 * connection is its own database. That is the intended behaviour and the
 * reason the flag exists: the reporting database's schema history is not
 * the primary's.
 */
final class MigrateCommand implements Command
{
    /**
     * @param list<class-string<\LombokClarion\Persistence\Migration>> $manifest
     */
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly array $manifest,
    ) {
    }

    public static function signature(): string
    {
        return 'migrate';
    }

    public function run(array $arguments): int
    {
        $connection = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--connection=')) {
                $connection = substr($argument, strlen('--connection='));

                if ($connection === '') {
                    fwrite(STDERR, "--connection= requires a name, e.g. --connection=reporting\n");
                    return 1;
                }

                continue;
            }

            // An unrecognised flag is an error, not something to skip. A typo'd
            // `--connnection=reporting` that silently migrated the primary is a
            // bad afternoon.
            fwrite(STDERR, sprintf(
                "Unknown argument \"%s\". Usage: migrate [--connection=NAME]\n",
                $argument
            ));
            return 1;
        }

        if ($connection !== null && !$this->connections->has($connection)) {
            fwrite(STDERR, sprintf(
                "Unknown connection \"%s\". Defined: %s\n",
                $connection,
                implode(', ', $this->connections->names())
            ));
            return 1;
        }

        $name = $connection ?? $this->connections->defaultName();
        $pdo = $this->connections->write($name);
        $driver = $this->connections->driver($name);

        // Migrations are DDL: always the write side, never a read replica,
        // even when one is configured. write() above is not an oversight.
        $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, $driver), $driver);

        $ran = $runner->migrate($this->manifest);

        if ($ran === []) {
            echo "Nothing to migrate on connection \"$name\".\n";
            return 0;
        }

        foreach ($ran as $migrationClass) {
            echo "Migrated: $migrationClass (on \"$name\")\n";
        }

        return 0;
    }
}
