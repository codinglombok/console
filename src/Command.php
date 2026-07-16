<?php

declare(strict_types=1);

namespace LombokClarion\Console;

interface Command
{
    /**
     * The name typed on the CLI, e.g. "migrate" or "audit:sql".
     */
    public static function signature(): string;

    /**
     * @param list<string> $arguments positional args after the command name
     * @return int exit code
     */
    public function run(array $arguments): int;
}
