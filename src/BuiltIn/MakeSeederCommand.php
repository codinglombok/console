<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;

/**
 * Writes a seeder class file from a stub.
 *
 *   lombokclarion make:seeder DemoWidgetsSeeder
 *   lombokclarion make:seeder DemoWidgetsSeeder --table=widgets
 *
 * WHAT THIS DELIBERATELY DOES NOT DO — the same rule as make:migration, and
 * for the same reason: it does not touch bootstrap/seeders.php. Registration
 * is explicit (§2.5) and seeder ORDER is a decision the generator cannot make
 * (rows referencing a parent table must be seeded after it). It prints the
 * lines to paste and stops.
 *
 * Target directory and namespace are constructor arguments, not runtime
 * discovery: this command has no opinion about where an application keeps its
 * seeders and no way to guess wrong.
 */
final class MakeSeederCommand implements Command
{
    public function __construct(
        private readonly string $targetDirectory,
        private readonly string $namespace,
        private readonly string $manifestPathForMessage = 'bootstrap/seeders.php',
    ) {
    }

    public static function signature(): string
    {
        return 'make:seeder';
    }

    public function run(array $arguments): int
    {
        $className = null;
        $table = null;

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--table=')) {
                $table = substr($argument, strlen('--table='));

                if ($table === '') {
                    fwrite(STDERR, "--table= requires a name, e.g. --table=widgets\n");
                    return 1;
                }

                continue;
            }

            if (str_starts_with($argument, '--')) {
                fwrite(STDERR, sprintf(
                    "Unknown argument \"%s\". Usage: make:seeder ClassName [--table=NAME]\n",
                    $argument
                ));
                return 1;
            }

            if ($className !== null) {
                fwrite(STDERR, "make:seeder takes exactly one class name.\n");
                return 1;
            }

            $className = $argument;
        }

        if ($className === null) {
            fwrite(STDERR, "Usage: make:seeder ClassName [--table=NAME]\n");
            return 1;
        }

        // A class name, not a path and not a namespace — so the declared
        // namespace and the file's location cannot disagree, and no path
        // escape is expressible.
        if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $className) !== 1) {
            fwrite(STDERR, sprintf(
                "\"%s\" is not a valid seeder class name (PascalCase, letters and digits only).\n",
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

        // Never clobber: an authored seeder that has already been applied
        // somewhere cannot be recovered from a stub.
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
            echo "\nThe stub's seed() is empty: fill it in before seeding.\n";
        }

        return 0;
    }

    private function stub(string $className, ?string $table): string
    {
        $body = $table === null
            ? "        // TODO: insert this seeder's rows, e.g.\n"
                . "        // \$context->table('example')->insert([\n"
                . "        //     'id' => \$context->factory()->id(),\n"
                . "        // ]);"
            : "        \$factory = \$context->factory();\n\n"
                . "        \$context->table('$table')->insert([\n"
                . "            'id' => \$factory->id(),\n"
                . "        ]);";

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$this->namespace};

        use LombokClarion\\Persistence\\SeedContext;
        use LombokClarion\\Persistence\\Seeder;

        final class $className implements Seeder
        {
            public function seed(SeedContext \$context): void
            {
        $body
            }
        }

        PHP;
    }
}
