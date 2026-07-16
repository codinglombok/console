<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\MigrationRunner;

/**
 * Reads the migration manifest (an explicit ordered list, never a
 * directory scan) and applies whatever hasn't run yet. Per master prompt
 * §7, this is meant to run under a separate, higher-privileged DB role
 * than the app runtime — that's a deployment-config concern, not
 * something this command enforces itself.
 */
final class MigrateCommand implements Command
{
    /**
     * @param list<class-string<\LombokClarion\Persistence\Migration>> $manifest
     */
    public function __construct(
        private readonly MigrationRunner $runner,
        private readonly array $manifest,
    ) {
    }

    public static function signature(): string
    {
        return 'migrate';
    }

    public function run(array $arguments): int
    {
        $ran = $this->runner->migrate($this->manifest);

        if ($ran === []) {
            echo "Nothing to migrate.\n";
            return 0;
        }

        foreach ($ran as $migrationClass) {
            echo "Migrated: $migrationClass\n";
        }

        return 0;
    }
}
