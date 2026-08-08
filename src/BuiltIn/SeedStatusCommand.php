<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\SeederRunner;

/**
 * Reports which seeders from the manifest have run, per connection. Read-only:
 * it seeds nothing.
 *
 *   lombokclarion seed:status
 *   lombokclarion seed:status --connection=reporting
 *   lombokclarion seed:status --strict     # exit 1 if anything is pending
 *
 * Deliberately the same shape as migrate:status, down to --strict and the
 * orphan rule, because the two answer the same question about different
 * halves of a database's state and an operator should not have to learn two
 * vocabularies.
 *
 * ORPHANS. A row in `seeders` whose class is absent from the manifest is
 * reported separately and is ALWAYS an error, with or without --strict — but
 * for a different reason than a migration orphan. A migration orphan blocks
 * rollback; a seeder orphan blocks nothing, because data has no down(). It is
 * an error here because it is the only signal that rows exist which no
 * manifest entry accounts for, and an unaccounted-for seeder is data whose
 * provenance nobody can establish. Clear it with SeederRunner::forget() once
 * the rows have been dealt with deliberately.
 */
final class SeedStatusCommand implements Command
{
    /**
     * @param list<class-string<\LombokClarion\Persistence\Seeder>> $manifest
     */
    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly array $manifest,
    ) {
    }

    public static function signature(): string
    {
        return 'seed:status';
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

            fwrite(STDERR, sprintf(
                "Unknown argument \"%s\". Usage: seed:status [--connection=NAME] [--strict]\n",
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
        $runner = new SeederRunner($pdo, $this->connections->driver($name));

        $applied = $runner->appliedSeeders();

        $pending = [];

        foreach ($this->manifest as $seederClass) {
            if (!in_array($seederClass, $applied, true)) {
                $pending[] = $seederClass;
            }
        }

        $orphans = array_values(array_diff($applied, $this->manifest));

        echo "Connection \"$name\": "
            . (count($this->manifest) - count($pending)) . ' seeded, '
            . count($pending) . " pending.\n";

        foreach ($this->manifest as $seederClass) {
            $marker = in_array($seederClass, $applied, true) ? 'seeded  ' : 'PENDING ';
            echo "  $marker $seederClass\n";
        }

        if ($orphans !== []) {
            fwrite(STDERR, sprintf(
                "\n%d recorded seeder(s) are not in the manifest — rows exist that nothing accounts for:\n",
                count($orphans)
            ));

            foreach ($orphans as $orphan) {
                fwrite(STDERR, "  orphan  $orphan\n");
            }

            fwrite(STDERR, "Restore the class to the manifest, or forget the record deliberately.\n");
            return 1;
        }

        if ($pending !== [] && $strict) {
            fwrite(STDERR, sprintf(
                "\n%d seeder(s) pending on \"%s\" (--strict).\n",
                count($pending),
                $name
            ));
            return 1;
        }

        return 0;
    }
}
