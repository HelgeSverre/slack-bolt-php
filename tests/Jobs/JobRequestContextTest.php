<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\Container\GenericContainer;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Context;
use Thunder\Bolt\Jobs\Job;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobStatus;
use Thunder\Bolt\Jobs\RetryPolicy;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class JobRequestContextTest extends TestCase
{
    public function test_worker_context_is_fresh_per_attempt_and_never_inherits_the_root_context(): void
    {
        $store = SqliteJobStore::forTesting();
        $config = new CommandBusConfig();
        $config->addHandler(
            new CommandHandler(),
            ContextJob::class,
            MethodReflector::fromParts(ContextJobHandler::class, 'handle'),
        );
        $codec = new JobCodec($config);
        $container = new GenericContainer();
        $container->singleton(Context::class, new Context()->set('root-secret', 'private'));
        $worker = new Worker(
            $store,
            $codec,
            $container,
            new Client(new RecordingHttpClient(), 'fixture'),
            new BoltConfig(token: 'fixture'),
        );
        foreach (['one', 'two'] as $key) {
            $store->enqueue(ContextJob::class, [], JobContext::workspace('T1'), $key, RetryPolicy::once());
            self::assertSame(JobStatus::Succeeded, $worker->runOnce()->status);
        }
        self::assertFalse($container->get(Context::class)->has('attempt-value'));
    }
}

final readonly class ContextJob implements Job
{
    public function __construct() {}
}

final readonly class ContextJobHandler
{
    public function __construct(
        private Context $context,
    ) {}

    #[CommandHandler]
    public function handle(ContextJob $job): void
    {
        TestCase::assertFalse($this->context->has('root-secret'));
        TestCase::assertFalse($this->context->has('attempt-value'));
        $this->context->set('attempt-value', new \stdClass());
    }
}
