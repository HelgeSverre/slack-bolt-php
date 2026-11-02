<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\ProjectScaffold;
use Thunder\Bolt\Console\ProjectSetup;
use Thunder\Bolt\Console\ProjectSource;

final class ScaffoldSetupTest extends TestCase
{
    #[DataProvider('profiles')]
    public function test_generated_setup_manifest_and_instructions_agree(string $transport, string $authorization): void
    {
        $root = sys_get_temp_dir() . '/bolt-setup-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = getcwd();
        chdir($root);
        try {
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create(
                'app',
                new ProjectSetup($transport, $authorization),
            );
            $setup = require $project . '/config/setup.config.php';
            self::assertSame($transport, $setup->transport);
            self::assertSame($authorization, $setup->authorization);
            $manifest = file_get_contents($project . '/slack-manifest.yaml');
            $environment = file_get_contents($project . '/.env.example');
            $readme = file_get_contents($project . '/README.md');
            self::assertStringContainsString($setup->startCommand(), $readme);
            self::assertStringContainsString('php bolt jobs:work', $readme);
            self::assertFileDoesNotExist($project . '/.env');
            if ($transport === 'socket') {
                self::assertStringContainsString('socket_mode_enabled: true', $manifest);
                self::assertStringNotContainsString('request_url:', $manifest);
                self::assertStringContainsString('SLACK_APP_TOKEN=xapp-replace-me', $environment);
            } else {
                self::assertStringContainsString('socket_mode_enabled: false', $manifest);
                self::assertStringContainsString('request_url:', $manifest);
            }
            if ($authorization === 'oauth') {
                self::assertStringNotContainsString('SLACK_BOT_TOKEN=xoxb', $environment);
                self::assertStringContainsString('/slack/install', $readme);
                self::assertStringContainsString('also with Socket Mode', $readme);
            } else {
                self::assertStringContainsString('SLACK_BOT_TOKEN=xoxb-replace-me', $environment);
            }
        } finally {
            chdir($previous);
            $this->remove($root);
        }
    }

    public static function profiles(): iterable
    {
        foreach (['http', 'socket'] as $transport)
            foreach (['token', 'oauth'] as $authorization)
                yield [$transport, $authorization];
    }

    public function test_vcs_source_uses_only_composer_metadata_and_does_not_write_credentials_elsewhere(): void
    {
        $root = sys_get_temp_dir() . '/bolt-vcs-source-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = getcwd();
        chdir($root);
        try {
            $source = ProjectSource::fromOptions('vcs', dirname(__DIR__, 2), 'dev-main', dirname(__DIR__, 2));
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create('app', new ProjectSetup(), $source);
            $composer = json_decode(
                (string) file_get_contents($project . '/composer.json'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );

            self::assertSame('dev-main', $composer['require']['thunder/bolt']);
            self::assertSame(['type' => 'vcs', 'url' => realpath(dirname(__DIR__, 2))], $composer['repositories'][0]);
            self::assertStringContainsString(
                'VCS `dev-main` branch',
                (string) file_get_contents($project . '/README.md'),
            );
            self::assertStringNotContainsString('source', (string) file_get_contents($project . '/.env.example'));
        } finally {
            chdir($previous);
            $this->remove($root);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $file)
                $this->remove($file->getPathname());
            rmdir($path);
        } else
            unlink($path);
    }
}
