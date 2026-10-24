<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Jobs\DispatchResult;
use Thunder\Bolt\Jobs\Job;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;

/** A delivery-bound intake capability; dispatch returns only after SQLite commits. */
final readonly class Jobs
{
    /** @param SqliteJobStore|\Closure(): SqliteJobStore $store */
    public function __construct(
        private SqliteJobStore|\Closure $store,
        private JobCodec $codec,
        private JobContext|Delivery|null $context = null,
    ) {}

    public function in(JobContext $context): self
    {
        return new self($this->store, $this->codec, $context);
    }

    /** @internal */
    public function forDelivery(#[\SensitiveParameter] Delivery $delivery): self
    {
        return new self($this->store, $this->codec, $delivery);
    }

    public function dispatch(
        Job $job,
        ?string $key = null,
        ?RetryPolicy $retry = null,
        ?\DateTimeInterface $after = null,
    ): DispatchResult {
        try {
            $context = $this->context();
            $key ??= $context->deliveryId ?? throw new \InvalidArgumentException(
                'Supply a stable job key when this delivery has no Events API event ID.',
            );
            $payload = $this->codec->encode($job);
            return $this->store()->enqueue(
                $job::class,
                $payload,
                $context,
                $key,
                $retry ?? RetryPolicy::once(),
                $after?->getTimestamp(),
            );
        } catch (\Throwable $exception) {
            throw new \Thunder\Bolt\Jobs\IntakeFailed(
                'The named job could not be durably accepted.',
                previous: $exception,
            );
        }
    }

    public function cancel(string $id): bool
    {
        return $this->store()->cancel($id, $this->context()->tenant());
    }

    public function cancelThread(): int
    {
        return $this->store()->cancelThread($this->context());
    }

    public function __serialize(): array
    {
        throw new \LogicException('Queue named job data, never a live Jobs capability.');
    }

    public function __debugInfo(): array
    {
        return ['backend' => 'sqlite', 'scope' => $this->context === null ? 'unbound' : 'bound'];
    }

    private function context(): JobContext
    {
        return $this->context instanceof Delivery
            ? JobContext::fromDelivery($this->context)
            : $this->context ?? throw new \LogicException(
                'Resolve Jobs in a listener, or select an explicit JobContext with in().',
            );
    }

    private function store(): SqliteJobStore
    {
        $store = $this->store instanceof \Closure ? ($this->store)() : $this->store;
        return $store instanceof SqliteJobStore
            ? $store
            : throw new \LogicException('Invalid execution store provider.');
    }
}
