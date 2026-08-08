<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\SchemaBuilder;

/**
 * Reports which migrations from the manifest have run and which have not,
 * per connection. Read-only: it applies nothing and rolls nothing back.
 *
 *   lombokclarion migrate:status
 *   lombokclarion migrate:status --connection=reporting
 *   lombokclarion migrate:status --strict     # exit 1 if anything is pending
 *
 * Why this exists: before Stage 16 the only way to learn whether a database
 * was current was to run `migrate` and read what it did — a question you
 * could not ask without also answering it. A deploy gate needs the question
 * on its own, which is what --strict is for (same convention as
 * audit:security --strict: warnings become a failing exit code when you ask
 * them to).
 *
 * ORPHANS. A row in the `migrations` table whose class is absent from the
 * manifest is reported separately and is ALWAYS an error, with or without
 * --strict. That state is exactly what MigrationRunner::rollback() hard-errors
 * on (AUDIT-TRAIL #45's neighbour: a rollback it cannot run is worse than a
 * rollback it refuses), so the status report is the earliest place it can be
 * seen — typically a migration deleted from the manifest while still applied
 * in some environment.
 *
 * Like MigrateCommand this takes the ConnectionManager, not a pre-built
 * runner, because --connection= can only mean something if the connection is
 * still open to choose at run() time.
 */
final class MigrateStatusCommand implements Command
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
        return 'migrate:status';
    }

    public function run(array $arguments): int
    {
        $connection = null;
        $strict = false;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--connection=')) {
                $connection = substr($argument, strlen('--connection='));

                if ($connection === '') {
                    fwrite(STDERR, "--connection= requires a name, e.g. --connection=reporting\n");
                    return 1;
                }

                continue;
            }

            if ($argument === '--strict') {
                $strict = true;
                continue;
            }

            // Same reasoning as MigrateCommand: a typo'd flag that silently
            // reported on the primary is a bad afternoon.
            fwrite(STDERR, sprintf(
                "Unknown argument \"%s\". Usage: migrate:status [--connection=NAME] [--strict]\n",
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

        // The write side, matching MigrateCommand: the migrations table this
        // report is about is the one migrate writes to. Reading a replica
        // could answer about a lagging copy, which is the wrong answer to
        // "is this database current?".
        $pdo = $this->connections->write($name);
        $driver = $this->connections->driver($name);
        $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, $driver), $driver);

        $applied = $runner->appliedMigrations();

        $ran = [];
        $pending = [];

        foreach ($this->manifest as $migrationClass) {
            if (in_array($migrationClass, $applied, true)) {
                $ran[] = $migrationClass;
                continue;
            }

            $pending[] = $migrationClass;
        }

        $orphans = array_values(array_diff($applied, $this->manifest));

        echo "Connection \"$name\": " . count($ran) . " ran, " . count($pending) . " pending.\n";

        foreach ($this->manifest as $migrationClass) {
            $marker = in_array($migrationClass, $applied, true) ? 'ran     ' : 'PENDING ';
            echo "  $marker $migrationClass\n";
        }

        if ($orphans !== []) {
            fwrite(STDERR, sprintf(
                "\n%d applied migration(s) are not in the manifest — rollback cannot run them:\n",
                count($orphans)
            ));

            foreach ($orphans as $orphan) {
                fwrite(STDERR, "  orphan  $orphan\n");
            }

            fwrite(STDERR, "Restore the class to the manifest, or remove its row deliberately.\n");
            return 1;
        }

        if ($pending !== [] && $strict) {
            fwrite(STDERR, sprintf(
                "\n%d migration(s) pending on \"%s\" (--strict).\n",
                count($pending),
                $name
            ));
            return 1;
        }

        return 0;
    }
}
