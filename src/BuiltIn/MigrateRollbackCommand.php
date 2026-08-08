<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\MigrationRunner;
use LombokClarion\Persistence\SchemaBuilder;

/**
 * The other half of `migrate` — the one the deploy checklist found
 * missing (AUDIT-TRAIL #45).
 *
 *   lombokclarion migrate:rollback                      # last 1
 *   lombokclarion migrate:rollback --steps=2
 *   lombokclarion migrate:rollback --connection=reporting
 *
 * Defaults to ONE step because "undo the deploy that just went wrong" is
 * the case rollback exists for; unwinding a whole schema is `--steps=N`,
 * typed deliberately. Schema only — down() that drops a table drops its
 * rows; the runbook's backup-before-migrate is what protects data.
 * Argument discipline identical to migrate: unknown flags are exit 1,
 * never a shrug.
 */
final class MigrateRollbackCommand implements Command
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
        return 'migrate:rollback';
    }

    public function run(array $arguments): int
    {
        $connection = null;
        $steps = 1;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--connection=')) {
                $connection = substr($argument, strlen('--connection='));
                if ($connection === '') {
                    fwrite(STDERR, "--connection= requires a name.\n");
                    return 1;
                }
                continue;
            }

            if (str_starts_with($argument, '--steps=')) {
                $raw = substr($argument, strlen('--steps='));
                if (filter_var($raw, FILTER_VALIDATE_INT) === false || (int) $raw < 1) {
                    fwrite(STDERR, "--steps= must be an integer >= 1, got \"$raw\".\n");
                    return 1;
                }
                $steps = (int) $raw;
                continue;
            }

            fwrite(STDERR, "Unknown argument \"$argument\". Usage: migrate:rollback [--steps=N] [--connection=NAME]\n");
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
        $runner = new MigrationRunner($pdo, new SchemaBuilder($pdo, $driver), $driver);

        $rolledBack = $runner->rollback($this->manifest, $steps);

        if ($rolledBack === []) {
            echo "Nothing to roll back on connection \"$name\".\n";
            return 0;
        }

        foreach ($rolledBack as $migrationClass) {
            echo "Rolled back: $migrationClass (on \"$name\")\n";
        }

        return 0;
    }
}
