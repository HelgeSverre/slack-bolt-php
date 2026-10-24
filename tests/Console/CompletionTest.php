<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;

final class CompletionTest extends TestCase
{
    private string $metadata;

    protected function setUp(): void
    {
        $this->metadata = tempnam(sys_get_temp_dir(), 'thunder-bolt-completion-');
        file_put_contents($this->metadata, json_encode([
            'version' => 1,
            'commands' => [
                'make:listener' => [
                    'hidden' => false,
                    'description' => 'Create a discovered Slack listener',
                    'flags' => [],
                ],
                'make:assistant' => [
                    'hidden' => false,
                    'description' => 'Create a configured Slack assistant listener',
                    'flags' => [],
                ],
                'make:job' => ['hidden' => false, 'description' => 'Create a durable named job', 'flags' => []],
                'jobs:work' => ['hidden' => false, 'description' => 'Run the durable worker', 'flags' => []],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        if (is_file($this->metadata)) {
            unlink($this->metadata);
        }
    }

    public function test_completion_scripts_are_bolt_specific(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = file_get_contents("{$root}/stubs/completion.{$shell}");
            self::assertIsString($script);
            self::assertStringContainsString('bolt-complete', $script);
            self::assertStringContainsString('.thunder-bolt', $script);
            $executableScript = preg_replace('/^\h*#.*$/m', '', $script);
            self::assertIsString($executableScript);
            self::assertStringNotContainsString('tempest', strtolower($executableScript));
        }
    }

    public function test_completion_helper_suggests_a_bolt_command(): void
    {
        $root = dirname(__DIR__, 2);
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg("{$root}/bin/bolt-complete"),
            escapeshellarg($this->metadata),
            '1',
            'bolt',
            'ma',
        ]);
        $output = shell_exec($command);

        self::assertIsString($output);
        self::assertStringContainsString('make:listener', $output);
        self::assertStringContainsString('make:assistant', $output);
        self::assertStringContainsString('make:job', $output);
    }
}
