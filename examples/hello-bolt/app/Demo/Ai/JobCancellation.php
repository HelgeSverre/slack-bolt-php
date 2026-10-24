<?php

declare(strict_types=1);

namespace App\Demo\Ai;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Revolt\EventLoop;
use Thunder\Bolt\Jobs\JobCancelled;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\Jobs\LeaseLost;

/** Couples a durable cancellation flag and lease heartbeat to cancellable network I/O. */
final class JobCancellation
{
    private readonly DeferredCancellation $deferred;
    private ?string $watcher = null;
    private ?\Throwable $stopped = null;
    private float $lastHeartbeat = 0.0;
    private float $heartbeatEvery = 5.0;

    public function __construct(
        private readonly JobExecution $execution,
    ) {
        $this->deferred = new DeferredCancellation();
    }

    public function start(): Cancellation
    {
        if ($this->watcher !== null) {
            throw new \LogicException('A durable AI cancellation bridge is already running.');
        }
        $this->checkpoint();
        $this->execution->heartbeat();
        $this->lastHeartbeat = microtime(true);
        $this->heartbeatEvery = max(0.1, min(5.0, $this->execution->leaseSeconds() / 3));
        $this->watcher = EventLoop::repeat(0.1, function (): void {
            try {
                if ((microtime(true) - $this->lastHeartbeat) >= $this->heartbeatEvery) {
                    $this->execution->heartbeat();
                    $this->lastHeartbeat = microtime(true);
                }
                $this->execution->checkpoint();
            } catch (JobCancelled|LeaseLost $exception) {
                $this->stopped = $exception;
                $this->deferred->cancel($exception);
            } catch (\Throwable $exception) {
                $this->stopped = $exception;
                $this->deferred->cancel($exception);
            }
        });

        return $this->deferred->getCancellation();
    }

    public function checkpoint(): void
    {
        if ($this->stopped !== null) {
            throw $this->stopped;
        }
        $this->execution->checkpoint();
    }

    public function close(): void
    {
        if ($this->watcher !== null) {
            EventLoop::cancel($this->watcher);
            $this->watcher = null;
        }
    }
}
