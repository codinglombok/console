<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

/**
 * Uses PHP's tokenizer (token_get_all) for AST-lite analysis instead of
 * regex. This catches patterns that regex misses (multi-line string
 * concatenation, heredocs feeding SQL, variable interpolation inside
 * double-quoted query strings) and avoids false positives on comments
 * and string literals that happen to contain "query(" as text.
 *
 * Patterns detected:
 *  1. $pdo->query("...{$var}...") — variable interpolation in query string
 *  2. $pdo->query("..." . $var) — concatenation into query/prepare
 *  3. $pdo->query(sprintf(...)) — sprintf feeding query/prepare
 *  4. $stmt = $pdo->prepare("SELECT * FROM t WHERE id = $id") — prepare with interpolation
 *  5. PDO::query() / PDO::prepare() with string concat outside driver internals
 */
final class TokenScanner
{
    /** @var list<string> */
    private array $findings = [];

    /**
     * @return list<string>
     */
    public function scan(string $filePath): array
    {
        $this->findings = [];
        $source = file_get_contents($filePath);
        if ($source === false) {
            return [];
        }

        $tokens = token_get_all($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            // Look for ->query( or ->prepare( calls
            if ($tokens[$i][0] === T_OBJECT_OPERATOR) {
                $next = $this->nextMeaningful($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $methodName = $tokens[$next][1];
                    if (in_array($methodName, ['query', 'prepare', 'exec'], true)) {
                        $parenPos = $this->nextMeaningful($tokens, $next);
                        if ($parenPos !== null && $tokens[$parenPos] === '(') {
                            $this->checkQueryArgument($tokens, $parenPos, $filePath, $tokens[$next][2]);
                        }
                    }
                }
            }
        }

        return $this->findings;
    }

    private function checkQueryArgument(array $tokens, int $openParen, string $file, int $line): void
    {
        $depth = 1;
        $i = $openParen + 1;
        $count = count($tokens);
        $foundConcat = false;
        $foundInterpolatedString = false;
        $foundSprintf = false;

        while ($i < $count && $depth > 0) {
            $tok = $tokens[$i];

            if ($tok === '(') {
                $depth++;
            } elseif ($tok === ')') {
                $depth--;
            }

            if ($depth <= 0) {
                break;
            }

            if (is_array($tok)) {
                // Pattern 1: double-quoted string with embedded variable
                if ($tok[0] === T_ENCAPSED_AND_WHITESPACE || $tok[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $foundInterpolatedString = true;
                }

                // Check for variable inside encapsed string
                if ($tok[0] === T_VARIABLE && $i > 0) {
                    $prev = $this->prevMeaningful($tokens, $i);
                    if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_ENCAPSED_AND_WHITESPACE) {
                        $foundInterpolatedString = true;
                    }
                }

                // Pattern 3: sprintf/vsprintf as argument
                if ($tok[0] === T_STRING && in_array($tok[1], ['sprintf', 'vsprintf'], true)) {
                    $foundSprintf = true;
                }
            }

            // Pattern 2: string concatenation with a variable
            if ($tok === '.') {
                $next = $this->nextMeaningful($tokens, $i);
                $prev = $this->prevMeaningful($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_VARIABLE) {
                    $foundConcat = true;
                }
                if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_VARIABLE) {
                    $foundConcat = true;
                }
            }

            $i++;
        }

        if ($foundInterpolatedString) {
            $this->findings[] = "$file:$line: SQL query/prepare with variable interpolation inside string";
        }
        if ($foundConcat) {
            $this->findings[] = "$file:$line: SQL query/prepare built via string concatenation with variable";
        }
        if ($foundSprintf) {
            $this->findings[] = "$file:$line: SQL query/prepare built via sprintf/vsprintf";
        }
    }

    private function nextMeaningful(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from + 1; $i < $count; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }

    private function prevMeaningful(array $tokens, int $from): ?int
    {
        for ($i = $from - 1; $i >= 0; $i--) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $i;
        }
        return null;
    }
}
