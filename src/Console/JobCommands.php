<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;

/** Operator commands reveal lifecycle metadata, never job inputs or lease credentials. */
final class JobCommands
{
    #[ConsoleCommand(name: 'make:job', description: 'Create a named durable job and Tempest command handler')]
    public function make(string $name): ExitCode
    {
        try {
            $this->output(new ProjectScaffold(dirname(__DIR__, 2))->job(
                getcwd() ?: throw new \RuntimeException('Cannot resolve project directory.'),
                $name,
            ));
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(name: 'jobs:work', description: 'Run the durable named-job worker')]
    public function work(
        #[ConsoleArgument(description: 'Handle at most one due job and exit')]
        bool $once = false,
        #[ConsoleArgument(description: 'Seconds owned by an attempt; long handlers must heartbeat')]
        int $lease = 60,
        #[ConsoleArgument(name: 'max-jobs', description: 'Exit after this many jobs; zero runs until stopped')]
        int $maxJobs = 0,
    ): ExitCode {
        $stop = false;
        /** @var array<int, callable|int> $handlers */
        $handlers = [];
        $previousAsync = null;
        try {
            if ($lease < 1 || $lease > 86400 || $maxJobs < 0) {
                throw new \InvalidArgumentException('Use a lease of 1–86400 seconds and a non-negative job limit.');
            }
            $worker = \Tempest\Container\get(Worker::class);
            if (function_exists('pcntl_signal') && function_exists('pcntl_signal_get_handler')) {
                $previousAsync = pcntl_async_signals();
                pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM] as $signal) {
                    /** @var callable|int $handler PHP's PCNTL stub does not express its runtime signature. */
                    $handler = pcntl_signal_get_handler($signal);
                    if (!is_int($handler) && !is_callable($handler)) {
                        throw new \RuntimeException('Cannot preserve the worker signal handler.');
                    }
                    $handlers[$signal] = $handler;
                    pcntl_signal($signal, static function () use (&$stop): void {
                        $stop = true;
                    });
                }
            }
            if ($once) {
                $this->output($worker->runOnce($lease)?->summary());
                return ExitCode::SUCCESS;
            }
            $worker->run(
                static function () use (&$stop): bool {
                    return $stop;
                },
                $lease,
                $maxJobs,
            );
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($previousAsync !== null) {
                pcntl_async_signals($previousAsync);
            }
        }
    }

    #[ConsoleCommand(name: 'jobs:list', description: 'List recent job outcomes as JSON, without input data')]
    public function listJobs(int $limit = 50): ExitCode
    {
        try {
            $this->output(array_map(static fn($job): array => $job->summary(), $this->store()->recent($limit)));
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(name: 'jobs:show', description: 'Show one job lifecycle record without input data')]
    public function show(
        string $id,
        #[ConsoleArgument(name: 'history-limit', description: 'Maximum recent history entries, between 1 and 1000')]
        int $historyLimit = 100,
    ): ExitCode {
        try {
            if ($historyLimit < 1 || $historyLimit > 1000) {
                throw new \InvalidArgumentException('Show between 1 and 1000 history entries.');
            }
            $this->output($this->store()->inspect($id, $historyLimit));
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(name: 'jobs:cancel', description: 'Cancel a pending job or request running work to stop')]
    public function cancel(string $id): ExitCode
    {
        try {
            $this->output(['id' => $id, 'cancellation_requested' => $this->store()->cancel($id)]);
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(name: 'jobs:recover', description: 'Recover expired leases; unsafe outcomes become uncertain')]
    public function recover(): ExitCode
    {
        try {
            $this->output(['recovered' => $this->store()->recoverExpired()]);
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(name: 'jobs:retry', description: 'Retry an inspected failed, uncertain or cancelled job')]
    public function retry(
        string $id,
        #[ConsoleArgument(
            name: 'allow-repeat',
            description: 'Explicitly allow repeating non-idempotent work that may already have caused effects',
        )]
        bool $allowRepeat = false,
    ): ExitCode {
        try {
            $this->store()->retry($id, $allowRepeat);
            $this->output(['id' => $id, 'status' => 'pending']);
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(
        name: 'jobs:prune',
        description: 'Prune old terminal job inputs and history, retaining deduplication receipts',
    )]
    public function prune(
        #[ConsoleArgument(description: 'Positive age in days or hours, such as 30d or 12h')]
        string $before = '30d',
        #[ConsoleArgument(name: 'dry-run', description: 'Preview one batch without deleting jobs')]
        bool $dryRun = false,
        #[ConsoleArgument(description: 'Maximum jobs per batch, between 1 and 1000')]
        int $limit = 1000,
    ): ExitCode {
        try {
            // Validate before opening storage: invalid flags must not initialize or migrate it.
            if (preg_match('/\A([1-9][0-9]{0,5})(d|h)\z/', $before, $match) !== 1) {
                throw new \InvalidArgumentException(
                    'Use a positive retention age such as --before=30d or --before=12h.',
                );
            }
            if ($limit < 1 || $limit > 1000) {
                throw new \InvalidArgumentException('Prune in batches of 1 to 1000 jobs.');
            }
            $seconds = (int) $match[1] * ($match[2] === 'd' ? 86400 : 3600);
            $cutoff = new \DateTimeImmutable('@' . (time() - $seconds));
            $this->output($this->store()->prune($cutoff, $dryRun, $limit));
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    #[ConsoleCommand(
        name: 'jobs:migrate',
        description: 'Apply supported additive job-store migrations and report the schema version',
    )]
    public function migrate(): ExitCode
    {
        try {
            $this->output(['schema_version' => $this->store()->schemaVersion()]);
            return ExitCode::SUCCESS;
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    private function store(): SqliteJobStore
    {
        return \Tempest\Container\get(SqliteJobStore::class);
    }

    private function output(mixed $value): void
    {
        fwrite(STDOUT, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function error(\Throwable $exception): ExitCode
    {
        // Exception messages from arbitrary transports can contain secrets or payloads.
        $message =
            $exception instanceof \InvalidArgumentException
            || $exception instanceof \LogicException
            || $exception instanceof \OutOfBoundsException
                ? $exception->getMessage()
                : 'Job execution/storage failed. Check configuration and application logs.';
        fwrite(STDERR, $message . PHP_EOL);
        return ExitCode::ERROR;
    }
}
