<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Factory;
use LombokClarion\Persistence\SeedContext;
use LombokClarion\Persistence\SeederRunner;

/**
 * Runs the seeder manifest against a connection.
 *
 *   lombokclarion seed
 *   lombokclarion seed --connection=reporting
 *   lombokclarion seed --seed=12345                 # reproduce a prior run
 *   lombokclarion seed --only=DemoWidgetsSeeder
 *   lombokclarion seed --only=DemoWidgetsSeeder --force
 *
 * Pending-only by default (see SeederRunner's docblock for why seeders are
 * tracked at all), so this is safe to put in a deploy pipeline next to
 * `migrate`.
 *
 * --force REQUIRES --only, and that is deliberate. Re-running a seeder
 * duplicates its rows unless the seeder itself is idempotent, and nothing in
 * the type system can promise that it is. Requiring the name makes the
 * operator state which duplication they are choosing; a bare `seed --force`
 * that re-ran the whole manifest would make the destructive case the shortest
 * thing to type.
 *
 * --seed=N sets the Factory seed for this run and is echoed back on every run
 * whether or not it was passed, because a run whose seed was not printed
 * cannot be reproduced. Like MigrateCommand this takes the ConnectionManager
 * rather than a pre-built runner, so --connection= is still free to choose at
 * run() time.
 */
final class SeedCommand implements Command
{
    /**
     * @param list<class-string<\LombokClarion\Persistence\Seeder>> $manifest
     */
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly array $manifest,
        private readonly int $defaultSeed = 1,
    ) {
    }

    public static function signature(): string
    {
        return 'seed';
    }

    public function run(array $arguments): int
    {
        $connection = null;
        $seedValue = $this->defaultSeed;
        $force = false;

        /** @var list<string> $only */
        $only = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--connection=')) {
                $connection = substr($argument, strlen('--connection='));

                if ($connection === '') {
                    fwrite(STDERR, "--connection= requires a name, e.g. --connection=reporting\n");
                    return 1;
                }

                continue;
            }

            if (str_starts_with($argument, '--only=')) {
                $name = substr($argument, strlen('--only='));

                if ($name === '') {
                    fwrite(STDERR, "--only= requires a seeder class name.\n");
                    return 1;
                }

                $only[] = $name;
                continue;
            }

            if (str_starts_with($argument, '--seed=')) {
                $raw = substr($argument, strlen('--seed='));

                // A seed that silently became 0 because it was not a number
                // would reproduce nothing and say nothing.
                if (preg_match('/^-?[0-9]+$/', $raw) !== 1) {
                    fwrite(STDERR, sprintf("--seed= requires an integer, got \"%s\".\n", $raw));
                    return 1;
                }

                $seedValue = (int) $raw;
                continue;
            }

            if ($argument === '--force') {
                $force = true;
                continue;
            }

            fwrite(STDERR, sprintf(
                "Unknown argument \"%s\". Usage: seed [--connection=NAME] [--only=Class] [--force] [--seed=N]\n",
                $argument
            ));
            return 1;
        }

        if ($force && $only === []) {
            fwrite(STDERR, "--force requires --only=ClassName: re-running the whole manifest would duplicate every seeder's rows.\n");
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

        // The write side, matching MigrateCommand: seeding a read replica is
        // not a thing that can succeed, and failing at the first INSERT is a
        // worse message than never offering the option.
        $pdo = $this->connections->write($name);
        $runner = new SeederRunner($pdo, $this->connections->driver($name));

        $context = new SeedContext(
            $this->connections,
            new Factory($seedValue),
            $connection
        );

        // Resolve short names against the manifest so an operator can type
        // --only=DemoWidgetsSeeder without the namespace.
        $resolved = [];

        foreach ($only as $requested) {
            $match = $this->resolve($requested);

            if ($match === null) {
                fwrite(STDERR, sprintf(
                    "Unknown seeder \"%s\". Manifest: %s\n",
                    $requested,
                    $this->manifest === [] ? '(empty)' : implode(', ', $this->manifest)
                ));
                return 1;
            }

            $resolved[] = $match;
        }

        try {
            $ran = $resolved === []
                ? $runner->seed($this->manifest, $context)
                : $runner->seedOnly($this->manifest, $resolved, $context, $force);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Seeding failed on "' . $name . '": ' . $e->getMessage() . "\n");

            // A forced re-run at the SAME seed regenerates the SAME values, so
            // any unique column the seeder fills collides and the transaction
            // rolls back — nothing was duplicated. That is the deterministic
            // Factory working, not a bug, but the driver's message says only
            // "constraint violation" and leaves the operator to infer it.
            if ($force) {
                fwrite(
                    STDERR,
                    "This was a --force re-run at seed=$seedValue. A re-run at the same seed regenerates "
                    . "identical values, so a unique column will collide and the whole seeder rolls back — "
                    . "no rows were duplicated. Pass a different --seed=N to add a second, distinct set of "
                    . "rows, or delete the existing rows first if you meant to replace them.\n"
                );
            }

            return 1;
        }

        if ($ran === []) {
            echo "Nothing to seed on \"$name\" (seed=$seedValue).\n";
            return 0;
        }

        foreach ($ran as $seederClass) {
            echo "Seeded: $seederClass (on \"$name\")\n";
        }

        // Printed unconditionally: a run whose seed was never shown cannot be
        // reproduced, and the run that turns out to need reproducing is never
        // the one you thought to record.
        echo "Factory seed: $seedValue — pass --seed=$seedValue to reproduce this data.\n";

        return 0;
    }

    /**
     * @return class-string<\LombokClarion\Persistence\Seeder>|null
     */
    private function resolve(string $requested): ?string
    {
        foreach ($this->manifest as $seederClass) {
            if ($seederClass === $requested) {
                return $seederClass;
            }

            $short = substr((string) strrchr('\\' . $seederClass, '\\'), 1);

            if ($short === $requested) {
                return $seederClass;
            }
        }

        return null;
    }
}
