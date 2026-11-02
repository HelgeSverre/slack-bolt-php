<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Psr\Clock\ClockInterface;
use Tempest\Console\ConsoleArgument;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\ExitCode;
use Tempest\Core\Kernel;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Jobs\JobConfig;
use Thunder\Bolt\OAuth\SystemClock;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use Thunder\Bolt\Runtime\RuntimeStatus;

final class StatusCommands
{
    #[ConsoleCommand(
        name: 'status',
        description: 'Inspect observed processes and the existing queue without changing work',
    )]
    public function status(
        #[ConsoleArgument(description: 'Output format: table or json')]
        string $format = 'table',
        #[ConsoleArgument(name: 'require', description: 'Required roles: auto, socket, jobs, socket,jobs, or none')]
        string $required = 'auto',
        #[ConsoleArgument(name: 'stale-after', description: 'Heartbeat age in seconds, between 5 and 86400')]
        int $staleAfter = 30,
    ): ExitCode {
        if (
            !in_array($format, ['table', 'json'], true)
            || !in_array($required, ['auto', 'socket', 'jobs', 'socket,jobs', 'jobs,socket', 'none'], true)
            || $staleAfter < 5
            || $staleAfter > 86400
        ) {
            fwrite(
                STDERR,
                'Use --format=table|json, --require=auto|socket|jobs|socket,jobs|none and --stale-after=5..86400.'
                . PHP_EOL,
            );
            return ExitCode::INVALID;
        }
        try {
            $container = \Tempest\Container\get(\Tempest\Container\Container::class);
            $kernel = $container->get(Kernel::class);
            if ($required === 'auto') {
                $socket = $container->has(ProjectSetup::class)
                    ? $container->get(ProjectSetup::class)->transport === 'socket'
                    : $container->get(BoltConfig::class)->socketMode?->isConfigured() ?? false;
                $required = $socket ? 'socket' : 'none';
            }
            $roles = $required === 'none' ? [] : explode(',', $required);
            $report = new RuntimeStatus(
                $container->get(RuntimeMonitor::class),
                $container->get(JobConfig::class)->path ?? $kernel->internalStorage . '/jobs/queue.sqlite',
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock(),
            )->report($roles, $staleAfter);
        } catch (\Throwable) {
            $report = [
                'healthy' => false,
                'failures' => [
                    'Runtime status could not be read. Check private storage, queue schema and application configuration.',
                ],
            ];
        }
        if ($format === 'json') {
            fwrite(
                STDOUT,
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            );
        } else {
            fwrite(
                STDOUT,
                (
                    $report['inspection_only'] ?? false
                        ? 'Inspection only (no required roles)'
                        : ($report['healthy'] ? 'Healthy' : 'Not healthy')
                )
                . ' — observed local processes; no Slack probe was made.'
                . PHP_EOL,
            );
            foreach ($report['processes'] ?? [] as $process)
                fwrite(
                    STDOUT,
                    sprintf(
                        '%s pid=%d %s%s last_seen=%s',
                        $process['role'],
                        $process['pid'],
                        $process['state'],
                        $process['stale'] ? ' (stale)' : '',
                        gmdate('c', $process['seen_at']),
                    )
                        . PHP_EOL,
                );
            if (isset($report['queue']))
                fwrite(
                    STDOUT,
                    'Queue: ' . json_encode($report['queue'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
                );
            foreach ($report['failures'] as $failure)
                fwrite(STDOUT, $failure . PHP_EOL);
        }
        return $report['healthy'] ? ExitCode::SUCCESS : ExitCode::ERROR;
    }
}
