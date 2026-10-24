<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\Functions\FormatMessageCommand;
use App\Demo\Functions\FormatMessageFunction;
use App\Demo\Functions\FormatMessageWorker;
use App\Demo\Functions\FunctionExecutionUnavailable;
use App\Demo\Functions\FunctionExecutionVault;
use PHPUnit\Framework\TestCase;
use Tempest\CommandBus\AsyncCommandMiddleware;
use Tempest\CommandBus\AsyncCommandRepositories\MemoryRepository;
use Tempest\CommandBus\CommandBus;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\CommandBus\CommandHandler;
use Tempest\CommandBus\CommandRepository;
use Tempest\CommandBus\GenericCommandBus;
use Tempest\Container\GenericContainer;
use Tempest\Core\Middleware;
use Tempest\Reflection\MethodReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Functions\Execution;
use Thunder\Bolt\Functions\ExecutionContext;
use Thunder\Bolt\Functions\Inputs;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class CustomFunctionDemoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-function-demo-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    public function test_function_delivery_persists_a_protected_context_before_deferring_a_reference_only_command(): void
    {
        $config = new DemoConfig($this->directory);
        $http = new RecordingHttpClient();
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, $config);
        $repository = new MemoryRepository();
        $commandConfig = new CommandBusConfig(middleware: new Middleware(AsyncCommandMiddleware::class));
        $commandConfig->addHandler(
            new CommandHandler(),
            FormatMessageCommand::class,
            MethodReflector::fromParts(FormatMessageWorker::class, 'handle'),
        );
        $container->singleton(CommandRepository::class, $repository);
        $container->singleton(CommandBusConfig::class, $commandConfig);
        $container->singleton(AsyncCommandMiddleware::class, new AsyncCommandMiddleware($repository, $commandConfig));
        $container->singleton(CommandBus::class, new GenericCommandBus($container, $commandConfig));
        $app = new App($container, new Client($http, 'installed-token'), new BoltConfig());
        $app->registerClass(FormatMessageFunction::class);

        self::assertNull($app->dispatch($this->event()));
        self::assertNull($app->dispatch($this->event()));
        $pending = array_values($repository->getPendingCommands());
        self::assertCount(2, $pending);
        self::assertSame([], $http->requests, 'The function listener does not complete the workflow inline.');

        $command = $pending[0];
        self::assertInstanceOf(FormatMessageCommand::class, $command);
        self::assertSame($command->reference, $pending[1]->reference, 'Redelivery queues the same durable execution.');
        self::assertStringNotContainsString('workflow-token', serialize($command));
        self::assertStringNotContainsString('Fx-demo', serialize($command));

        $vault = new FunctionExecutionVault($this->directory . '/function-executions');
        self::assertSame('pending', $vault->inspect($command->reference)['state']);
        $record = (string) file_get_contents(
            $this->directory . '/function-executions/' . $command->reference . '.json',
        );
        self::assertStringNotContainsString('workflow-token', $record);
        self::assertSame(0700, fileperms($this->directory . '/function-executions') & 0777);
        self::assertSame(0600, fileperms($this->directory . '/function-executions/.key') & 0777);
        self::assertSame(
            0600,
            fileperms($this->directory . '/function-executions/' . $command->reference . '.json') & 0777,
        );
    }

    public function test_worker_uses_the_workflow_token_and_completes_the_deferred_execution_once(): void
    {
        $config = new DemoConfig($this->directory);
        $context = new ExecutionContext(
            'Fx-demo',
            new Inputs(['message' => 'hello world']),
            'demo_format_message',
            token: 'workflow-token',
        );
        $vault = new FunctionExecutionVault($this->directory . '/function-executions');
        $reference = $vault->store($context);
        $http = new RecordingHttpClient();

        $worker = new FormatMessageWorker($config, new Client($http, 'installed-token'));
        $command = new FormatMessageCommand($reference);
        $worker->handle($command);
        $worker->handle($command);

        self::assertCount(
            1,
            $http->requests,
            'A duplicate queued reference must not repeat completion or business work.',
        );
        self::assertSame('functions.completeSuccess', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('Bearer workflow-token', $http->requests[0]->getHeaderLine('Authorization'));
        self::assertSame(
            [
                'function_execution_id' => 'Fx-demo',
                'outputs' => ['message' => 'Formatted: hello world'],
            ],
            $http->sentPayload(),
        );
        self::assertSame('completed', $vault->inspect($reference)['state']);
        self::assertStringNotContainsString(
            'workflow-token',
            (string) file_get_contents($this->directory . '/function-executions/' . $reference . '.json'),
        );

        self::assertNull($vault->claim($reference));
    }

    public function test_expired_and_already_claimed_records_fail_closed_without_a_replay(): void
    {
        $now = 1_000;
        $vault = new FunctionExecutionVault(
            $this->directory . '/function-executions',
            ttlSeconds: 60,
            now: static function () use (&$now): int {
                return $now;
            },
        );
        $expired = $vault->store(
            new ExecutionContext('Fx-expired', new Inputs(['message' => 'later']), token: 'workflow-token'),
        );
        $now = 1_061;
        try {
            $vault->claim($expired);
            self::fail('An expired execution must not be claimed.');
        } catch (FunctionExecutionUnavailable) {
            self::assertSame('expired', $vault->inspect($expired)['state']);
        }

        $now = 2_000;
        $running = $vault->store(
            new ExecutionContext('Fx-running', new Inputs(['message' => 'once']), token: 'workflow-token'),
        );
        $vault->claim($running);
        self::assertSame('running', $vault->inspect($running)['state']);
        self::assertNull($vault->claim($running));
    }

    /** @return array<string, mixed> */
    private function event(): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'T-demo',
            'event_id' => 'Ev-demo',
            'event' => [
                'type' => 'function_executed',
                'function_execution_id' => 'Fx-demo',
                'workflow_execution_id' => 'Wx-demo',
                'bot_access_token' => 'workflow-token',
                'function' => ['id' => 'Fn-demo', 'callback_id' => 'demo_format_message'],
                'inputs' => ['message' => 'hello world'],
            ],
        ];
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path . '/' . $item);
            }
        }
        rmdir($path);
    }
}
