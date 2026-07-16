<?php

declare(strict_types=1);

namespace LombokClarion\Console\BuiltIn;

use LombokClarion\Bus\Queue\QueueWorker;
use LombokClarion\Console\Command;

/**
 * `lombokclarion work` — processes queued jobs. By default drains all
 * available jobs and exits (suitable for cron-driven or serverless
 * invocation). Pass `--loop` for a long-running worker that sleeps
 * between polls.
 */
final class WorkCommand implements Command
{
    public function __construct(private readonly QueueWorker $worker)
    {
    }

    public static function signature(): string
    {
        return 'work';
    }

    public function run(array $arguments): int
    {
        $queue = null;
        $loop = false;
        $sleep = 1;

        foreach ($arguments as $arg) {
            if (str_starts_with($arg, '--queue=')) {
                $queue = substr($arg, 8);
            } elseif ($arg === '--loop') {
                $loop = true;
            } elseif (str_starts_with($arg, '--sleep=')) {
                $sleep = max(1, (int) substr($arg, 8));
            }
        }

        if (!$loop) {
            $count = $this->worker->drain($queue);
            echo "Processed $count job(s).\n";
            return 0;
        }

        echo "Worker started (queue=" . ($queue ?? 'default') . ", sleep={$sleep}s). Ctrl+C to stop.\n";

        while (true) {
            $processed = $this->worker->drain($queue, maxJobs: 10);
            if ($processed === 0) {
                sleep($sleep);
            }
        }
    }
}
