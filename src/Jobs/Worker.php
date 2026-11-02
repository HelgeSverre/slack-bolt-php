<?php

declare(strict_types=1);

namespace Thunder\Bolt\Jobs;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tempest\Container\Container;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\Authorization\Authorizer;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationContext;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\ThreadContext;
use Thunder\Bolt\Invocation\InvocationScope;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Runtime\ProcessHeartbeat;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use Thunder\Bolt\WebApi\Client;

final readonly class Worker
{
    private LoggerInterface $logger;

    public function __construct(
        private SqliteJobStore $store,
        private JobCodec $codec,
        private Container $container,
        private Client $client,
        private BoltConfig $config,
        private ?Authorizer $authorizer = null,
        private ?ConversationStore $conversations = null,
        ?LoggerInterface $logger = null,
        private ?RuntimeMonitor $monitor = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function runOnce(int $leaseSeconds = 60): ?JobRecord
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 86400) {
            throw new \InvalidArgumentException('A job lease must last between one second and one day.');
        }
        $process = $this->monitor?->start('jobs');
        $process?->update('idle');
        try {
            return $this->attempt($leaseSeconds, $process);
        } finally {
            $process?->stop();
        }
    }

    private function attempt(int $leaseSeconds, ?ProcessHeartbeat $process): ?JobRecord
    {
        $process?->pulse();
        $this->store->recoverExpired();
        $record = $this->store->claim($leaseSeconds);
        if ($record === null) {
            return null;
        }
        $process?->update('running', $record->id);
        $execution = new JobExecution($this->store, $record, $leaseSeconds, $process);
        try {
            $execution->checkpoint();
            $job = $this->codec->decode($record->type, $record->payload);
            $source = $record->context->source();
            $authorization = $this->authorizer?->authorize($source) ?? new Authorization(
                botToken: $this->config->token,
                botUserId: $this->config->botUserId,
                teamId: $source->teamId,
                enterpriseId: $source->enterpriseId,
                isEnterpriseInstall: $source->isEnterpriseInstall,
            );
            if (
                $authorization->teamId !== null
                && !$source->isEnterpriseInstall
                && $authorization->teamId !== $source->teamId
                || $authorization->isEnterpriseInstall !== $source->isEnterpriseInstall
                || $authorization->enterpriseId !== null && $authorization->enterpriseId !== $source->enterpriseId
            ) {
                throw new PermanentFailure('Job authorization resolved a different installation.');
            }
            $client = $this->client->withToken($authorization->token());
            $scope = InvocationScope::create($this->container, $client, $authorization);
            $scope->singleton(\Thunder\Bolt\Context::class, new \Thunder\Bolt\Context());
            $scope->singleton(JobContext::class, $record->context);
            $scope->singleton(JobExecution::class, $execution);
            $scope->singleton(
                Reply::class,
                new Reply($client, $record->context->channelId, $record->context->threadTs, $record->context->userId),
            );
            $scope->singleton(
                ConversationContext::class,
                new ConversationContext($this->conversations, $record->context->conversationKey(), $this->logger),
            );
            $scope->singleton(
                ThreadContext::class,
                new ThreadContext($this->conversations, $record->context->threadKey(), $this->logger),
            );
            $scope->singleton(
                Jobs::class,
                new Jobs($this->store, $this->codec)->in($record->context->withDeliveryId($record->id)),
            );
            $handler = $this->codec->handler($record->type)->handler;
            $execution->checkpoint();
            $handler->invokeArgs($scope->get($handler->getDeclaringClass()->getName()), [$job]);
            $execution->checkpoint();
            $this->store->settle($record, JobStatus::Succeeded);
        } catch (LeaseLost) {
            // Expiry/recovery owns the outcome; an old attempt must not overwrite it.
            $this->logger->notice('Named job attempt lost its lease.', ['job_id' => $record->id]);
        } catch (\Throwable $exception) {
            $status = match (true) {
                $exception instanceof JobCancelled => JobStatus::Cancelled,
                $exception instanceof PermanentFailure => JobStatus::Failed,
                $exception instanceof UncertainOutcome => JobStatus::Uncertain,
                $record->retry->safeToRepeat && $record->attempts < $record->attemptLimit => JobStatus::Pending,
                $exception instanceof NetworkExceptionInterface => JobStatus::Uncertain,
                default => JobStatus::Failed,
            };
            try {
                $this->store->settle(
                    $record,
                    $status,
                    $exception::class,
                    $this->store->now()
                    + ($status === JobStatus::Pending ? $record->retry->delay($record->attempts) : 0),
                );
            } catch (LeaseLost) {
                // Another owner or the recovery process decides this outcome.
                $this->logger->notice('Named job outcome belongs to recovery.', ['job_id' => $record->id]);
            }
            $this->logger->warning('Named job attempt did not complete.', [
                'job_id' => $record->id,
                'exception_type' => $exception::class,
            ]);
        }
        $process?->update('idle');
        return $this->store->find($record->id);
    }

    /** Drain until stopped; each iteration constructs a fresh job scope. */
    public function run(callable $shouldStop, int $leaseSeconds = 60, int $maxJobs = 0): void
    {
        if ($maxJobs < 0) {
            throw new \InvalidArgumentException('The worker job limit cannot be negative.');
        }
        if ($leaseSeconds < 1 || $leaseSeconds > 86400)
            throw new \InvalidArgumentException('A job lease must last between one second and one day.');
        $process = $this->monitor?->start('jobs');
        $handled = 0;
        $process?->update('idle');
        try {
            while (!$shouldStop()) {
                $process?->pulse();
                if ($this->attempt($leaseSeconds, $process) !== null) {
                    $handled++;
                    if ($maxJobs !== 0 && $handled >= $maxJobs)
                        return;
                } else {
                    usleep(100_000);
                }
            }
        } finally {
            $process?->stop();
        }
    }
}
