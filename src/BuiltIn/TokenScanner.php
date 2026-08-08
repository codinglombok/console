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
 *  6. $sql = "... $id"; $pdo->query($sql); — the SAME unsafe SQL, merely routed
 *     through a local variable first (see the taint note below)
 *
 * Pattern 6 exists because checking only the call site left a one-line bypass of
 * the entire gate: assigning the string to a variable first hid it from patterns
 * 1-5. A tokenizer has no type inference (unlike the PHPStan sibling rule, which
 * can ask PHPStan whether the argument folds to a constant string), so this class
 * carries a deliberately small local dataflow pass instead: a variable assigned
 * SQL that was built with a variable becomes "tainted", and passing a tainted
 * variable to query/prepare/exec is reported. Assignments built purely from
 * literals — including conditional `.=` chains that only append literals — never
 * taint. The pass is intra-file and name-based, so it errs toward reporting: for
 * a security gate a false positive costs a review, a false negative costs an
 * incident.
 */
final class TokenScanner
{
    /** @var list<string> */
    private array $findings = [];

    /** @var array<string, list<int>> tainted variable name => lines where it was assigned unsafe SQL */
    private array $tainted = [];

    /**
     * Variables assigned straight from a sanctioned source. Kept so the two
     * engines agree on the same escape hatches: without this, a site the
     * PHPStan sibling rule accepts would still be reported here, and a marker
     * that only satisfies one of the two gates is worthless.
     *
     * @var array<string, true>
     */
    private array $sanitized = [];

    /** Static calls that vouch for what they return (see Identifier, TrustedDdl). */
    private const SANITIZERS = [
        'Identifier' => ['validate', 'quote'],
        'TrustedDdl' => ['mark'],
    ];

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

        $this->sanitized = $this->collectSanitizedVariables($tokens);
        $this->tainted = $this->collectTaintedVariables($tokens);

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
        // `->exec(TrustedDdl::mark($sql))` — the site has been explicitly
        // reviewed and the marker is narrowed at runtime, so stop here.
        if ($this->argumentIsSanitizerCall($tokens, $openParen)) {
            return;
        }

        $depth = 1;
        $i = $openParen + 1;
        $count = count($tokens);
        $foundConcat = false;
        $foundInterpolatedString = false;
        $foundSprintf = false;

        // $depth > 0 is guaranteed here: the `if ($depth <= 0) break;` below
        // exits before the condition is re-checked, so testing it again is dead.
        while ($i < $count) {
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

            // Pattern 2: string concatenation with a variable, unless that
            // variable came from a sanitizer (identifiers can never be bound).
            if ($tok === '.') {
                $next = $this->nextMeaningful($tokens, $i);
                $prev = $this->prevMeaningful($tokens, $i);
                if ($next !== null && $this->isUnsanitizedVariable($tokens, $next)) {
                    $foundConcat = true;
                }
                if ($prev !== null && $this->isUnsanitizedVariable($tokens, $prev)) {
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

        // Pattern 6: the argument is nothing but a variable that was assigned
        // unsafe SQL earlier in this file.
        $lone = $this->loneVariableArgument($tokens, $openParen);
        if ($lone !== null && isset($this->tainted[$lone])) {
            // Report the nearest preceding assignment rather than the first one
            // ever seen: a reused variable name would otherwise point a reader
            // at the wrong line.
            $origins = array_filter($this->tainted[$lone], static fn (int $l): bool => $l <= $line);
            if ($origins !== []) {
                $this->findings[] = "$file:$line: SQL query/prepare built from \$$lone, "
                    . 'assigned unsafe SQL at line ' . max($origins);
            }
        }
    }

    /**
     * Returns the variable name (without `$`) when the call's single argument is
     * exactly one variable, e.g. `->query($sql)`. Returns null otherwise.
     */
    private function loneVariableArgument(array $tokens, int $openParen): ?string
    {
        $args = [];
        $depth = 1;
        $count = count($tokens);

        for ($i = $openParen + 1; $i < $count; $i++) {
            $tok = $tokens[$i];

            if ($tok === '(') {
                $depth++;
            } elseif ($tok === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $args[] = $tok;
        }

        if (count($args) !== 1 || !is_array($args[0]) || $args[0][0] !== T_VARIABLE) {
            return null;
        }

        return ltrim($args[0][1], '$');
    }

    /**
     * Finds variables assigned SQL that was built using another variable.
     * Literal-only assignments (including `.=` chains appending literals) are
     * left untainted, which is what keeps bound-parameter builders clean.
     *
     * @return array<string, list<int>> variable name => lines
     */
    private function collectTaintedVariables(array $tokens): array
    {
        $tainted = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
                continue;
            }

            $opPos = $this->nextMeaningful($tokens, $i);
            if ($opPos === null) {
                continue;
            }

            $isAssign = $tokens[$opPos] === '=';
            $isConcatAssign = is_array($tokens[$opPos]) && $tokens[$opPos][0] === T_CONCAT_EQUAL;

            if (!$isAssign && !$isConcatAssign) {
                continue;
            }

            $name = ltrim($tokens[$i][1], '$');
            $line = $tokens[$i][2];

            if ($this->rangeIsTainted($tokens, $opPos + 1)) {
                $tainted[$name][] = $line;
            }
        }

        return $tainted;
    }

    /**
     * Scans a right-hand side up to its terminating `;` for the same unsafe
     * shapes patterns 1-3 look for.
     */
    private function rangeIsTainted(array $tokens, int $from): bool
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $from; $i < $count; $i++) {
            $tok = $tokens[$i];

            if ($tok === '(' || $tok === '[') {
                $depth++;
            } elseif ($tok === ')' || $tok === ']') {
                $depth--;
            } elseif ($tok === ';' && $depth <= 0) {
                return false;
            }

            if (is_array($tok)) {
                if ($tok[0] === T_DOLLAR_OPEN_CURLY_BRACES || $tok[0] === T_CURLY_OPEN) {
                    return true;
                }

                // A variable sitting inside a double-quoted / heredoc string.
                if ($tok[0] === T_VARIABLE) {
                    $prev = $this->prevMeaningful($tokens, $i);
                    if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_ENCAPSED_AND_WHITESPACE) {
                        return true;
                    }
                }

                if ($tok[0] === T_STRING && in_array($tok[1], ['sprintf', 'vsprintf'], true)) {
                    return true;
                }
            }

            // Concatenation where either side is a variable.
            if ($tok === '.') {
                $next = $this->nextMeaningful($tokens, $i);
                $prev = $this->prevMeaningful($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_VARIABLE) {
                    return true;
                }
                if ($prev !== null && is_array($tokens[$prev]) && $tokens[$prev][0] === T_VARIABLE) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isUnsanitizedVariable(array $tokens, int $pos): bool
    {
        if (!is_array($tokens[$pos]) || $tokens[$pos][0] !== T_VARIABLE) {
            return false;
        }

        return !isset($this->sanitized[ltrim($tokens[$pos][1], '$')]);
    }

    /**
     * True when the first token of the argument list is a sanctioned static
     * call, e.g. `->exec(TrustedDdl::mark($sql))`.
     */
    private function argumentIsSanitizerCall(array $tokens, int $openParen): bool
    {
        $first = $this->nextMeaningful($tokens, $openParen);

        return $first !== null && $this->isSanitizerCallAt($tokens, $first);
    }

    /**
     * Matches `Identifier::quote(` / `TrustedDdl::mark(` starting at $pos.
     */
    private function isSanitizerCallAt(array $tokens, int $pos): bool
    {
        if (!is_array($tokens[$pos]) || $tokens[$pos][0] !== T_STRING) {
            return false;
        }

        $class = $tokens[$pos][1];
        if (!isset(self::SANITIZERS[$class])) {
            return false;
        }

        $sep = $this->nextMeaningful($tokens, $pos);
        if ($sep === null || !is_array($tokens[$sep]) || $tokens[$sep][0] !== T_DOUBLE_COLON) {
            return false;
        }

        $method = $this->nextMeaningful($tokens, $sep);
        if ($method === null || !is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING) {
            return false;
        }

        return in_array($tokens[$method][1], self::SANITIZERS[$class], true);
    }

    /**
     * @return array<string, true>
     */
    private function collectSanitizedVariables(array $tokens): array
    {
        $sanitized = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE) {
                continue;
            }

            $opPos = $this->nextMeaningful($tokens, $i);
            if ($opPos === null || $tokens[$opPos] !== '=') {
                continue;
            }

            $rhs = $this->nextMeaningful($tokens, $opPos);
            if ($rhs !== null && $this->isSanitizerCallAt($tokens, $rhs)) {
                $sanitized[ltrim($tokens[$i][1], '$')] = true;
            }
        }

        return $sanitized;
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
