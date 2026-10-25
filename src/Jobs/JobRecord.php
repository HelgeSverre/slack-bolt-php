<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

final readonly class JobRecord implements \JsonSerializable
{
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public JobContext $context,
        public RetryPolicy $retry,
        public JobStatus $status,
        public int $attempts,
        public int $attemptLimit,
        public int $availableAt,
        public ?int $leaseExpiresAt,
        #[\SensitiveParameter]
        private ?string $leaseToken,
        public bool $cancellationRequested,
        public ?string $failureType,
        public ?int $createdAt = null,
        public ?int $updatedAt = null,
        public ?int $finishedAt = null,
    ) {}

    public function summary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'tenant' => $this->context->tenant(),
            'status' => $this->status->value,
            'attempts' => $this->attempts,
            'attempt_limit' => $this->attemptLimit,
            'available_at' => $this->availableAt,
            'lease_expires_at' => $this->leaseExpiresAt,
            'cancellation_requested' => $this->cancellationRequested,
            'failure_type' => $this->failureType,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->summary();
    }

    public function jsonSerialize(): array
    {
        return $this->summary();
    }

    /** @internal Attempt ownership is an explicit capability, excluded from summaries. */
    public function leaseToken(): ?string
    {
        return $this->leaseToken;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Queue named job inputs, not a live execution record.');
    }
}
