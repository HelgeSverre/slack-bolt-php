<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Thunder\Bolt\Console\ProjectScaffold;

final class ProjectScaffoldTest extends TestCase
{
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
