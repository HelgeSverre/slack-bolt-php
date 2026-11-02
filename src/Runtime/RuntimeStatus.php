<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

use Psr\Clock\ClockInterface;
use Thunder\Bolt\OAuth\SystemClock;

final readonly class RuntimeStatus
{
    public function __construct(
        private RuntimeMonitor $monitor,
        private string $queuePath,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    /**
     * @param list<string> $required
     * @return array{healthy: bool, processes: list<array{role: string, pid: int, state: string, stale: bool, seen_at: int, ...}>, queue: array<string, mixed>, failures: list<string>, ...}
     */
    public function report(array $required, int $staleAfter = 30): array
    {
        if (array_diff($required, ['socket', 'jobs']) !== [])
            throw new \InvalidArgumentException('Require socket, jobs, socket,jobs, or none.');
        $processes = $this->monitor->snapshot($staleAfter);
        $queue = QueueSnapshot::read($this->queuePath, $this->clock->now()->getTimestamp());
        $active = ['socket' => 0, 'jobs' => 0];
        foreach ($processes as $process) {
            if (
                !$process['stale']
                && in_array(
                    $process['state'],
                    $process['role'] === 'socket' ? ['connected'] : ['idle', 'running'],
                    true,
                )
            )
                $active[$process['role']]++;
        }
        $failures = [];
        foreach ($required as $role) {
            if ($active[$role] === 0)
                $failures[] = "No fresh, ready {$role} process was observed.";
        }
        if (in_array('jobs', $required, true) && !$queue['available'])
            $failures[] = 'The queue is unavailable. Check configuration or run jobs:migrate during deployment.';
        return [
            'healthy' => $failures === [],
            'inspection_only' => $required === [],
            'observed_at' => $this->clock->now()->getTimestamp(),
            'scope' => 'shared-local-storage',
            'required' => array_values(array_unique($required)),
            'stale_after_seconds' => $staleAfter,
            'active' => $active,
            'processes' => $processes,
            'queue' => $queue,
            'attention' => [
                'failed_jobs' => $queue['counts']['failed'] ?? 0,
                'uncertain_jobs' => $queue['counts']['uncertain'] ?? 0,
                'expired_leases' => $queue['expired_leases'] ?? 0,
                'stale_processes' => count(array_filter(
                    $processes,
                    static fn(array $process): bool => $process['stale'],
                )),
            ],
            'failures' => $failures,
        ];
    }
}
