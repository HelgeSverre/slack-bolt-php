<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use App\Demo\ThreadState;
use App\Listeners\Playground;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class PlaygroundTest extends TestCase
{
    private string $directory;
    private DemoConfig $config;
    private RecordingHttpClient $http;
    private App $app;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-playground-' . bin2hex(random_bytes(8));
        $this->config = new DemoConfig($this->directory, ['UOWNER']);
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, $this->config);
        $this->http = new RecordingHttpClient();
        $this->app = new App($container, new Client($this->http, 'fake-bot'), new BoltConfig(token: 'fake-bot'));
        $this->app->registerClass(Playground::class);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_command_acknowledges_after_durable_enqueue_without_outgoing_http(): void
    {
        $ack = $this->app->dispatch($this->command('ai How big is the sun?'));
        self::assertStringContainsString('Queued', $ack->toArray()['text']);
        self::assertSame([], $this->http->requests);
        $item = $this->config->queue()->claim();
        $job = DemoJob::fromArray($item['job']);
        self::assertSame('ai', $job->mode);
        self::assertSame('T1', $job->team);
        self::assertSame('UOWNER', $job->user);
        self::assertSame('How big is the sun?', $job->text);
    }

    public function test_terminal_is_denied_to_other_users_before_enqueuing(): void
    {
        $ack = $this->app->dispatch($this->command('terminal', 'UOTHER'));
        self::assertStringContainsString('limited', $ack->toArray()['text']);
        self::assertNull($this->config->queue()->claim());
    }

    public function test_event_redelivery_is_deduplicated_and_mentions_are_routed_without_http(): void
    {
        $body = [
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event_id' => 'Ev1',
            'event' => [
                'type' => 'app_mention',
                'channel' => 'C1',
                'user' => 'UOWNER',
                'text' => '<@B1> pet',
                'ts' => '1.001',
            ],
        ];
        $this->app->dispatch($body);
        $this->app->dispatch($body);
        $queue = $this->config->queue();
        self::assertSame('pet', $queue->claim()['job']['mode']);
        self::assertNull($queue->claim());
        self::assertSame([], $this->http->requests);
    }

    public function test_pet_buttons_derive_the_actor_and_workspace_from_slack_delivery(): void
    {
        $ack = $this->app->dispatch([
            'type' => 'block_actions',
            'team' => ['id' => 'T1'],
            'user' => ['id' => 'UCAREGIVER'],
            'channel' => ['id' => 'C1'],
            'message' => ['ts' => '1.001'],
            'actions' => [['action_id' => 'demo_pet_feed', 'action_ts' => '2.001']],
        ]);
        self::assertSame([], $ack->toArray());
        $job = $this->config->queue()->claim()['job'];
        self::assertSame('UCAREGIVER', $job['user']);
        self::assertSame('T1', $job['team']);
        self::assertSame('feed', $job['text']);
        self::assertSame('1.001', $job['message']);
    }

    public function test_followup_messages_only_route_known_threads_and_terminal_owners(): void
    {
        $state = new ThreadState($this->directory);
        $state->put('T1', 'C1', '1.001', ['mode' => 'terminal', 'owner' => 'UOWNER']);
        foreach (['UOTHER', 'UOWNER'] as $user) {
            $this->app->dispatch([
                'type' => 'event_callback',
                'team_id' => 'T1',
                'event_id' => 'Ev-' . $user,
                'event' => [
                    'type' => 'message',
                    'channel' => 'C1',
                    'user' => $user,
                    'text' => 'pwd',
                    'thread_ts' => '1.001',
                    'ts' => '2.001',
                ],
            ]);
        }
        $queue = $this->config->queue();
        self::assertSame('UOWNER', $queue->claim()['job']['user']);
        self::assertNull($queue->claim());
        self::assertNull($state->get('T2', 'C1', '1.001'));
    }

    public function test_other_users_cannot_fill_the_spool_using_terminal_control_buttons(): void
    {
        new ThreadState($this->directory)->put('T1', 'C1', '1.001', ['mode' => 'terminal', 'owner' => 'UOWNER']);
        foreach (['UOTHER', 'UOWNER'] as $user) {
            $this->app->dispatch([
                'type' => 'block_actions',
                'team' => ['id' => 'T1'],
                'user' => ['id' => $user],
                'channel' => ['id' => 'C1'],
                'message' => ['ts' => '2.001', 'thread_ts' => '1.001'],
                'actions' => [['action_id' => 'demo_terminal_close', 'action_ts' => '3.001']],
            ]);
        }
        $queue = $this->config->queue();
        self::assertSame('UOWNER', $queue->claim()['job']['user']);
        self::assertNull($queue->claim());
    }

    private function command(string $text, string $user = 'UOWNER'): array
    {
        return [
            'command' => '/bolt',
            'team_id' => 'T1',
            'channel_id' => 'C1',
            'user_id' => $user,
            'trigger_id' => 'trigger-1',
            'text' => $text,
            'response_url' => 'https://example.test/unused',
        ];
    }
}
