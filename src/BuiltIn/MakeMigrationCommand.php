<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;

/**
 * Writes a migration class file from a stub.
 *
 *   lombokclarion make:migration CreateInvoicesTable
 *   lombokclarion make:migration CreateInvoicesTable --table=invoices
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: it does not touch
 * bootstrap/migrations.php. Master prompt §2.5 makes registration explicit —
 * the manifest is a hand-written ordered list, not a directory scan — and a
 * generator that silently appended to it would reintroduce exactly the
 * "where did this come from?" property the manifest exists to prevent.
 * Migration ORDER is a decision (an invoices table that references customers
 * must run after it), and a generator cannot make that decision. So the
 * command prints the line to paste and where to paste it, and stops.
 *
 * The target directory and namespace are constructor arguments rather than
 * anything discovered at runtime: this command has no opinion about where an
 * application keeps its migrations, and no way to guess wrong.
 */
final class MakeMigrationCommand implements Command
{
    public function __construct(
        private readonly string $targetDirectory,
        private readonly string $namespace,
        private readonly string $manifestPathForMessage = 'bootstrap/migrations.php',
    ) {
    }

    public static function signature(): string
    {
        return 'make:migration';
    }

    public function run(array $arguments): int
    {
        $className = null;
        $table = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--table=')) {
                $table = substr($argument, strlen('--table='));

                if ($table === '') {
                    fwrite(STDERR, "--table= requires a name, e.g. --table=invoices\n");
                    return 1;
                }

                continue;
            }

            if (str_starts_with($argument, '--')) {
                fwrite(STDERR, sprintf(
                    "Unknown argument \"%s\". Usage: make:migration ClassName [--table=NAME]\n",
                    $argument
                ));
                return 1;
            }

            if ($className !== null) {
                fwrite(STDERR, "make:migration takes exactly one class name.\n");
                return 1;
            }

            $className = $argument;
        }

        if ($className === null) {
            fwrite(STDERR, "Usage: make:migration ClassName [--table=NAME]\n");
            return 1;
        }

        // A class name, not a path and not a namespace: the namespace is this
        // command's configuration, so accepting a separator here would let the
        // caller write a file whose declared namespace and location disagree.
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $className) !== 1) {
            fwrite(STDERR, sprintf(
                "\"%s\" is not a valid migration class name (PascalCase, letters and digits only).\n",
                $className
            ));
            return 1;
        }

        if ($table !== null && preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
            fwrite(STDERR, sprintf(
                "\"%s\" is not a valid table name (lowercase, digits and underscores).\n",
                $table
            ));
            return 1;
        }

        if (!is_dir($this->targetDirectory)) {
            fwrite(STDERR, sprintf(
                "Target directory does not exist: %s\n",
                $this->targetDirectory
            ));
            return 1;
        }

        $path = rtrim($this->targetDirectory, '/') . '/' . $className . '.php';

        // Never clobber. A generator that overwrote an authored migration
        // would destroy applied history with no undo.
        if (file_exists($path)) {
            fwrite(STDERR, sprintf("Refusing to overwrite existing file: %s\n", $path));
            return 1;
        }

        $written = file_put_contents($path, $this->stub($className, $table));

        if ($written === false) {
            fwrite(STDERR, sprintf("Could not write %s\n", $path));
            return 1;
        }

        $fqcn = $this->namespace . '\\' . $className;

        echo "Created: $path\n";
        echo "\nRegister it yourself in {$this->manifestPathForMessage} — order matters:\n";
        echo "    use $fqcn;\n";
        echo "    // ... then add to the returned list, in the position it must run:\n";
        echo "    $className::class,\n";

        if ($table === null) {
            echo "\nThe stub's up()/down() are empty: fill them in before migrating.\n";
        }

        return 0;
    }

    private function stub(string $className, ?string $table): string
    {
        $up = $table === null
            ? "        // TODO: describe the schema change, e.g.\n"
                . "        // \$schema->createTable('example', ['id' => 'VARCHAR(32) PRIMARY KEY']);"
            : "        \$schema->createTable('$table', [\n"
                . "            'id' => 'VARCHAR(32) PRIMARY KEY',\n"
                . "        ]);";

        $down = $table === null
            ? "        // TODO: undo up() exactly. A migration whose down() is a\n"
                . "        // no-op is a migration that cannot be rolled back."
            : "        \$schema->dropTable('$table');";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->namespace};

        use LombokClarion\\Persistence\\Migration;
        use LombokClarion\\Persistence\\SchemaBuilder;

        final class $className implements Migration
        {
            public function up(SchemaBuilder \$schema): void
            {
        $up
            }

            public function down(SchemaBuilder \$schema): void
            {
        $down
            }

            public function runsInTransaction(SchemaBuilder \$schema): bool
            {
                return \$schema->migrationsAreTransactionalByDefault();
            }
        }

        PHP;
    }
}
