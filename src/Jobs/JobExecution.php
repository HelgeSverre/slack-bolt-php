<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

/** Cooperative cancellation and lease renewal for one attempt. */
final readonly class JobExecution
{
    public function __construct(
        private SqliteJobStore $store,
        public JobRecord $job,
        private int $leaseSeconds = 60,
    ) {}

    public function checkpoint(): void
    {
        $this->store->checkpoint($this->job->id, $this->token());
    }

    public function heartbeat(): void
    {
        $this->store->heartbeat($this->job->id, $this->token(), $this->leaseSeconds);
    }

    public function isCancellationRequested(): bool
    {
        try {
            $this->checkpoint();
            return false;
        } catch (JobCancelled) {
            return true;
        }
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize a live job execution.');
    }

    private function token(): string
    {
        return $this->job->leaseToken() ?? throw new LeaseLost('No live job lease.');
    }
}
