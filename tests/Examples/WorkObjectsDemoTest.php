<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\DemoConfig;
use App\Demo\DemoJob;
use App\Demo\Work\WorkItemStore;
use App\Demo\Work\WorkObjectsDemo;
use App\Listeners\WorkObjects;
use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WorkObjects\Actor;
use Thunder\Bolt\WorkObjects\ExternalReference;

final class WorkObjectsDemoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-work-demo-' . bin2hex(random_bytes(6));
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

    public function test_queued_comments_ack_before_outgoing_http_and_are_saved_with_trusted_actor(): void
    {
        $config = new DemoConfig($this->directory);
        $http = new RecordingHttpClient();
        $client = new Client($http, 'test');
        $container = new GenericContainer();
        $container->singleton(DemoConfig::class, $config);
        $app = new App($container, $client, new BoltConfig(token: 'test'));
        $app->registerClass(WorkObjects::class);
        $demo = new WorkObjectsDemo($config, $client);
        $demo->handle(new DemoJob('work', 'Discuss this task', 'T1', 'U1', 'C1'), '0123456789abcdef');
        self::assertSame('chat.postMessage', basename($http->requests[0]->getUri()->getPath()));
        self::assertSame('slack#/entities/task', $http->sentPayload()['metadata']['entities'][0]['entity_type']);
        self::assertSame(0, $http->sentPayload()['metadata']['entities'][0]['entity_comments']['count']);
        $body = [
            'type' => 'view_submission',
            'team' => ['id' => 'T1'],
            'user' => ['id' => 'U2'],
            'trigger_id' => 'tr-post',
            'view' => [
                'type' => 'entity_comment',
                'callback_id' => 'work-object-post-comment',
                'external_ref' => ['id' => '0123456789ab', 'type' => 'bolt-work-item'],
                'state' => [
                    'values' => [
                        'comment_input_block' => [
                            'comment_input_action' => [
                                'type' => 'rich_text_input',
                                'rich_text_value' => [
                                    'type' => 'rich_text',
                                    'elements' => [[
                                        'type' => 'rich_text_section',
                                        'elements' => [['type' => 'text', 'text' => 'A useful comment.']],
                                    ]],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        self::assertSame([], $app->dispatch($body)->toArray());
        $app->dispatch($body);
        self::assertCount(1, $http->requests);
        $item = $config->queue()->claim();
        $job = DemoJob::fromArray($item['job']);
        self::assertSame('U2', $job->user);
        self::assertArrayNotHasKey('token', $job->payload);
        self::assertNull($config->queue()->claim());
        $demo->handle($job, $item['id']);
        self::assertSame('entity.acknowledgeCommentAction', basename($http->requests[1]->getUri()->getPath()));
        self::assertSame('U2', $http->sentPayload(1)['comment']['sender']['user_id']);
        $reference = new ExternalReference('0123456789ab', 'bolt-work-item');
        self::assertSame(
            1,
            new WorkItemStore($this->directory . '/work-items.json')->item(
                new Actor('T1', 'U1'),
                $reference,
            )['comment_count'],
        );
        $demo->handle(new DemoJob('work', '', 'T1', 'U1', '', operation: 'comments', payload: [
            'type' => 'entity_comments_requested',
            'user' => 'U1',
            'trigger_id' => 'tr-read',
            'external_ref' => $reference->toArray(),
        ]), 'read');
        self::assertSame(false, $http->sentPayload(2)['comments'][0]['can_edit']);
        self::assertSame(false, $http->sentPayload(2)['comments'][0]['can_delete']);
        self::assertSame(true, $http->sentPayload(2)['can_post_comment']);
    }
}
