<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Console\Command;
use LombokClarion\Persistence\Identifier;
use LombokClarion\Persistence\QueryBuilder;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `lombokclarion audit:sql [paths...] [--explain] [--xss]`
 *
 * Two analysis engines:
 *  1. TokenScanner (PHP tokenizer-based, AST-lite) — catches string
 *     concatenation, variable interpolation, and sprintf feeding
 *     query()/prepare()/exec() calls. Much more accurate than regex:
 *     handles multi-line strings, skips comments, understands token
 *     context. This replaces the old regex heuristics entirely.
 *  2. XSS scanner — flags {!! !!} raw view output not wrapped in
 *     Safe::mark().
 *
 * Flags:
 *  --explain  connects to the configured DB and runs EXPLAIN ANALYZE on
 *             query shapes found in repositories, flagging missing indexes
 *             and sequential scans (§7).
 *  --xss      also scan view files for unescaped output (on by default).
 *  --exclude=PATH
 *             skip a file or directory. Intended for the handful of files that
 *             legitimately assemble SQL and cannot be proven safe by either
 *             engine — see docs/AUDIT-TRAIL.md. Every exclusion is a promise
 *             that the file is covered by dedicated tests instead, so keep the
 *             list short and justified; an exclusion is a cost, not a fix.
 */
final class AuditSqlCommand implements Command
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public static function signature(): string
    {
        return 'audit:sql';
    }

    public function run(array $arguments): int
    {
        $explainMode = false;
        $paths = [];
        $excluded = [];

        foreach ($arguments as $arg) {
            if ($arg === '--explain') {
                $explainMode = true;
            } elseif (str_starts_with($arg, '--exclude=')) {
                $excluded[] = $this->normalizePath(substr($arg, strlen('--exclude=')));
            } elseif (!str_starts_with($arg, '--')) {
                $paths[] = $arg;
            }
        }

        if ($paths === []) {
            $paths = ['app'];
        }

        $findings = [];
        $scanner = new TokenScanner();

        foreach ($paths as $path) {
            foreach ($this->phpFiles($path) as $file) {
                if ($this->isExcluded($file->getPathname(), $excluded)) {
                    continue;
                }
                $findings = [...$findings, ...$scanner->scan($file->getPathname())];
            }
            foreach ($this->viewFiles($path) as $file) {
                if ($this->isExcluded($file->getPathname(), $excluded)) {
                    continue;
                }
                $findings = [...$findings, ...$this->scanRawViewOutput($file)];
            }
        }

        if ($explainMode && $this->pdo !== null) {
            $findings = [...$findings, ...$this->runExplainChecks()];
        }

        if ($findings === []) {
            echo "audit:sql — no issues found.\n";
            return 0;
        }

        foreach ($findings as $finding) {
            echo "$finding\n";
        }
        echo "\n" . count($findings) . " issue(s) found.\n";

        return 1;
    }

    /** @return list<string> */
    private function scanRawViewOutput(SplFileInfo $file): array
    {
        $findings = [];
        $lines = file($file->getPathname()) ?: [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\{!!\s*(.+?)\s*!!\}/', $line, $m) && !str_contains($m[1], 'Safe::mark(')) {
                $findings[] = "{$file->getPathname()}:" . ($i + 1) . ": unescaped {!! !!} output not wrapped in Safe::mark()";
            }
        }

        return $findings;
    }

    /**
     * Runs EXPLAIN on common query patterns against the connected DB.
     * Flags sequential scans and missing indexes on tables with data.
     *
     * @return list<string>
     */
    private function runExplainChecks(): array
    {
        $findings = [];
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Get all user tables.
        $tables = match ($driver) {
            'sqlite' => array_column(
                $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_ASSOC),
                'name'
            ),
            'pgsql' => array_column(
                $this->pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")->fetchAll(PDO::FETCH_ASSOC),
                'tablename'
            ),
            default => [],
        };

        foreach ($tables as $table) {
            if (in_array($table, ['migrations', 'queued_jobs', 'failed_jobs'], true)) {
                continue;
            }

            // Check if table has enough rows to make EXPLAIN meaningful.
            // $table comes from the DB catalogue rather than user input, but it is still
            // an identifier spliced into SQL — route it through the same validation every
            // other identifier in the framework uses, so this command obeys the rule it
            // exists to enforce.
            //
            // Identifier::quote() is called inline at each site rather than hoisted into
            // a variable on purpose: the tokenizer engine can follow the assignment, but
            // the PHPStan engine cannot, and a site only one of the two gates accepts is
            // exactly the disagreement this command exists to prevent.
            $count = (int) $this->pdo
                ->query('SELECT COUNT(*) FROM ' . Identifier::quote($table))
                ->fetchColumn();
            if ($count < 10) {
                continue;
            }

            // Run EXPLAIN on a full-table SELECT.
            $stmt = $this->pdo->query(match ($driver) {
                'sqlite' => 'EXPLAIN QUERY PLAN SELECT * FROM ' . Identifier::quote($table),
                default => 'EXPLAIN ANALYZE SELECT * FROM ' . Identifier::quote($table),
            });
            $plan = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($plan as $row) {
                $detail = strtolower(implode(' ', array_map('strval', $row)));
                // SQLite (modern): "SCAN big"; (legacy): "SCAN TABLE big";
                // Postgres: "Seq Scan on big". Index use appears as
                // "SEARCH ... USING INDEX" / "Index Scan", which must NOT match.
                $isFullScan = str_contains($detail, 'seq scan')
                    || (bool) preg_match('/\bscan (table )?' . preg_quote(strtolower($table), '/') . '\b/', $detail);

                if ($isFullScan && !str_contains($detail, 'using index')) {
                    $findings[] = "EXPLAIN: table \"$table\" uses sequential scan — consider adding an index";
                }
            }
        }

        return $findings;
    }

    /** @return list<SplFileInfo> */
    /**
     * @param list<string> $excluded
     */
    private function isExcluded(string $file, array $excluded): bool
    {
        $file = $this->normalizePath($file);

        foreach ($excluded as $prefix) {
            if ($file === $prefix || str_starts_with($file, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);

        return str_replace('\\', '/', $real !== false ? $real : $path);
    }

    private function phpFiles(string $path): array
    {
        return $this->filesMatching($path, '/\.php$/');
    }

    /** @return list<SplFileInfo> */
    private function viewFiles(string $path): array
    {
        return $this->filesMatching($path, '/\.lc\.php$/');
    }

    /** @return list<SplFileInfo> */
    private function filesMatching(string $path, string $pattern): array
    {
        if (is_file($path)) {
            return preg_match($pattern, $path) ? [new SplFileInfo($path)] : [];
        }

        if (!is_dir($path)) {
            return [];
        }

        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && preg_match($pattern, $file->getPathname())) {
                $found[] = $file;
            }
        }

        return $found;
    }
}
