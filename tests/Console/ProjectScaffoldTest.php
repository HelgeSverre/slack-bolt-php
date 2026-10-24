<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tempest\Container\GenericContainer;
use Tempest\Discovery\DiscoveryItems;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Reflection\ClassReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Console\ProjectScaffold;
use Thunder\Bolt\Integration\BoltDiscovery;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class ProjectScaffoldTest extends TestCase
{
    public function test_function_listener_scaffold_registers_and_can_complete_a_workflow(): void
    {
        $path = new ProjectScaffold(dirname(__DIR__, 2))->listener(
            $this->workspace,
            'FormatFunction',
            'function',
            'format_text',
        );
        require $path;
        $http = new \Thunder\Bolt\Tests\Support\RecordingHttpClient();
        $app = new \Thunder\Bolt\App(
            new \Tempest\Container\GenericContainer(),
            new \Thunder\Bolt\WebApi\Client($http, 'test'),
            new \Thunder\Bolt\BoltConfig('test'),
        );
        $app->registerClass(\App\Listeners\FormatFunction::class);
        $app->dispatch([
            'type' => 'event_callback',
            'event' => [
                'type' => 'function_executed',
                'function' => ['callback_id' => 'format_text'],
                'function_execution_id' => 'Fx1',
                'inputs' => [],
            ],
        ]);
        self::assertSame('Fx1', $http->sentPayload()['function_execution_id']);
        self::assertStringContainsString('"outputs":{}', (string) $http->requests[0]->getBody());
    }

    public function test_event_listener_scaffolds_discover_and_dispatch_known_and_generic_payloads(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);
        $mention = $scaffold->listener($project, 'GeneratedMention', 'event', 'app_mention');
        $reaction = $scaffold->listener($project, 'GeneratedReaction', 'event', 'reaction_added');
        $future = $scaffold->listener($project, 'GeneratedFutureEvent', 'event', 'future_slack_event');
        $uninstalled = $scaffold->listener($project, 'GeneratedUninstalled', 'event', 'app_uninstalled');
        $channelless = $scaffold->listener($project, 'GeneratedChannelless', 'event', 'future_channelless_event');
        require $mention;
        require $reaction;
        require $future;
        require $uninstalled;
        require $channelless;

        self::assertStringContainsString('Events\\AppMention', (string) file_get_contents($mention));
        self::assertStringContainsString('Events\\ReactionAdded', (string) file_get_contents($reaction));
        self::assertStringContainsString('Payloads\\Event as SlackEvent', (string) file_get_contents($future));
        self::assertStringContainsString('Events\\AppUninstalled', (string) file_get_contents($uninstalled));
        self::assertStringNotContainsString('Reply', (string) file_get_contents($uninstalled));
        self::assertStringNotContainsString('inThread', (string) file_get_contents($channelless));

        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, 'test'), new BoltConfig('test'));
        $items = new DiscoveryItems();
        $discovery = new BoltDiscovery(new GenericContainer());
        $discovery->setItems($items);
        $location = new DiscoveryLocation('App', dirname($mention));
        foreach ([
            \App\Listeners\GeneratedMention::class,
            \App\Listeners\GeneratedReaction::class,
            \App\Listeners\GeneratedFutureEvent::class,
            \App\Listeners\GeneratedUninstalled::class,
            \App\Listeners\GeneratedChannelless::class,
        ] as $listener) {
            $discovery->discover($location, new ClassReflector($listener));
        }
        foreach ($items->getForLocation($location) as $descriptor) {
            $app->registerDescriptor($descriptor);
        }

        $app->dispatch(['event' => [
            'type' => 'app_mention',
            'user' => 'U1',
            'channel' => 'C1',
            'text' => 'hello',
            'ts' => '1.001',
        ]]);
        $app->dispatch([
            'event' => [
                'type' => 'reaction_added',
                'user' => 'U1',
                'reaction' => 'thumbsup',
                'event_ts' => '1.002',
                'item' => ['type' => 'message', 'channel' => 'C1', 'ts' => '1.002'],
            ],
        ]);
        $app->dispatch(['event' => [
            'type' => 'future_slack_event',
            'user' => 'U1',
            'channel' => 'C1',
            'text' => 'hello again',
            'ts' => '1.003',
        ]]);
        $app->dispatch(['type' => 'event_callback', 'team_id' => 'T1', 'event' => ['type' => 'app_uninstalled']]);
        $app->dispatch(['event' => ['type' => 'future_channelless_event']]);

        self::assertCount(1, $http->requests);
    }

    public function test_assistant_scaffold_creates_a_safe_configured_listener_without_changing_the_manifest(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);
        $manifest = $project . '/slack-manifest.yaml';
        file_put_contents($manifest, "oauth_config:\n  scopes:\n    bot:\n      - app_mentions:read\n");
        $originalManifest = (string) file_get_contents($manifest);
        $paths = $scaffold->assistant($project, 'GeneratedAssistant');

        foreach ($paths as $path) {
            $output = [];
            exec(implode(' ', [escapeshellarg(PHP_BINARY), '-l', escapeshellarg($path)]), $output, $status);
            self::assertSame(0, $status, implode(PHP_EOL, $output));
        }
        require $paths['listener'];
        $assistant = require $paths['config'];

        self::assertInstanceOf(Assistant::class, $assistant);
        $http = new RecordingHttpClient();
        $app = new App(new GenericContainer(), new Client($http, 'test'), new BoltConfig('test'));
        $app->assistant($assistant)->dispatch([
            'type' => 'event_callback',
            'team_id' => 'T1',
            'event' => [
                'type' => 'assistant_thread_started',
                'event_ts' => '1.001',
                'assistant_thread' => [
                    'channel_id' => 'D1',
                    'thread_ts' => '1.001',
                    'user_id' => 'U1',
                    'context' => ['team_id' => 'T1'],
                ],
            ],
        ]);

        self::assertCount(1, $http->requests);
        self::assertSame($originalManifest, (string) file_get_contents($manifest));
        self::assertStringNotContainsString('assistant:write', $originalManifest);
        $this->expectException(RuntimeException::class);
        $scaffold->assistant($project, 'GeneratedAssistant');
    }

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/thunder-bolt-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->workspace);
    }

    public function test_creates_project_with_only_project_local_bolt_entrypoint(): void
    {
        $cwd = getcwd();
        chdir($this->workspace);
        try {
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create('demo');
        } finally {
            chdir($cwd);
        }

        self::assertSame(realpath($this->workspace) . '/demo', $project);
        self::assertFileExists($project . '/composer.json');
        self::assertFileExists($project . '/bolt');
        self::assertFileExists($project . '/public/index.php');
        self::assertFileExists($project . '/app/Listeners/GreetOnMention.php');
        self::assertStringContainsString('0.1.x-dev', (string) file_get_contents($project . '/composer.json'));
        self::assertStringContainsString(
            'vendor/thunder/bolt/bin/bolt',
            (string) file_get_contents($project . '/bolt'),
        );
        self::assertStringContainsString('SLACK_APP_TOKEN=', (string) file_get_contents($project . '/.env.example'));
        self::assertStringContainsString(
            'socket_mode_enabled: false',
            (string) file_get_contents($project . '/slack-manifest.yaml'),
        );
    }

    public function test_refuses_existing_or_traversal_project_paths(): void
    {
        mkdir($this->workspace . '/existing');
        $cwd = getcwd();
        chdir($this->workspace);
        try {
            $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
            foreach (['existing', '../escape', '/absolute'] as $path) {
                try {
                    $scaffold->create($path);
                    self::fail("Expected {$path} to be rejected.");
                } catch (RuntimeException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            chdir($cwd);
        }
    }

    public function test_listener_rejects_invalid_name_and_existing_file(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);

        $this->expectException(RuntimeException::class);
        $scaffold->listener($project, 'not-valid', 'event', 'app_mention');
    }

    public function test_listener_generation_refuses_overwrite(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);
        $path = $scaffold->listener($project, 'DeployListener', 'slash-command', '/deploy');
        self::assertFileExists($path);
        $this->expectException(RuntimeException::class);
        $scaffold->listener($project, 'DeployListener', 'slash-command', '/deploy');
    }

    public function test_listener_escapes_message_selector_as_valid_php(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);
        $path = $scaffold->listener($project, 'QuotedMessage', 'message', "I'm here\\there");

        exec(implode(' ', [escapeshellarg(PHP_BINARY), '-l', escapeshellarg($path)]), $output, $status);

        self::assertSame(0, $status, implode(PHP_EOL, $output));
        self::assertStringContainsString("MessageAttribute('I\\'m here\\\\there')", (string) file_get_contents($path));
    }

    public function test_listener_rejects_reserved_php_class_name_without_writing_a_file(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);

        try {
            $scaffold->listener($project, 'Match', 'event', 'app_mention');
            self::fail('Expected Match to be rejected as a reserved PHP class name.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertFileDoesNotExist($project . '/app/Listeners/Match.php');
    }

    public function test_interactivity_listener_templates_are_valid_php_and_use_explicit_response_families(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);

        $listeners = [
            ['ApproveAction', 'action', 'approve_request', 'block_actions', 'ActionAttribute', 'ActionResponse'],
            ['OpenShortcut', 'shortcut', 'open_modal', 'shortcut', 'ShortcutAttribute', 'ShortcutResponse'],
            ['SearchOptions', 'options', 'search_users', 'block_suggestion', 'OptionsAttribute', 'OptionsResponse'],
            ['SubmitView', 'view', 'submit_request', 'view_submission', 'ViewAttribute', 'ViewResponse'],
        ];

        foreach ($listeners as [$class, $kind, $selector, $type, $attribute, $response]) {
            $path = $scaffold->listener($project, $class, $kind, $selector, $type);
            $output = [];
            exec(implode(' ', [escapeshellarg(PHP_BINARY), '-l', escapeshellarg($path)]), $output, $status);

            self::assertSame(0, $status, implode(PHP_EOL, $output));
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString("{$attribute}('{$selector}', type: '{$type}')", $contents);
            self::assertStringContainsString("{$response}::empty()", $contents);
        }
    }

    public function test_legacy_interaction_types_generate_callback_id_constraints(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        $project = $this->workspace . '/project';
        mkdir($project, 0775, true);

        $listeners = [
            [
                'LegacyAction',
                'action',
                'expense_report',
                'interactive_message',
                'ActionAttribute(callbackId: \'expense_report\', type: \'interactive_message\')',
            ],
            [
                'DialogAction',
                'action',
                'expense_report',
                'dialog_submission',
                'ActionAttribute(callbackId: \'expense_report\', type: \'dialog_submission\')',
            ],
            [
                'LegacyOptions',
                'options',
                'expense_report',
                'interactive_message',
                'OptionsAttribute(callbackId: \'expense_report\', type: \'interactive_message\')',
            ],
            [
                'DialogOptions',
                'options',
                'expense_report',
                'dialog_suggestion',
                'OptionsAttribute(callbackId: \'expense_report\', type: \'dialog_suggestion\')',
            ],
        ];

        foreach ($listeners as [$class, $kind, $selector, $type, $attribute]) {
            $path = $scaffold->listener($project, $class, $kind, $selector, $type);
            $contents = (string) file_get_contents($path);

            self::assertStringContainsString($attribute, $contents);
        }
    }

    private function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item !== '.' && $item !== '..') {
                    $this->remove($path . '/' . $item);
                }
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
