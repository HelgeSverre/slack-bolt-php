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
    public function test_generated_setup_manifest_and_instructions_agree(
        string $transport,
        string $authorization,
        string $profile,
    ): void {
        $root = sys_get_temp_dir() . '/bolt-setup-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = getcwd();
        chdir($root);
        try {
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create(
                'app',
                new ProjectSetup($transport, $authorization, $profile),
            );
            $setup = require $project . '/config/setup.config.php';
            self::assertSame($transport, $setup->transport);
            self::assertSame($authorization, $setup->authorization);
            self::assertSame($profile, $setup->profile);
            $manifest = file_get_contents($project . '/slack-manifest.yaml');
            $parsed = \Symfony\Component\Yaml\Yaml::parse($manifest);
            self::assertSame($transport === 'socket', $parsed['settings']['socket_mode_enabled']);
            $environment = file_get_contents($project . '/.env.example');
            $readme = file_get_contents($project . '/README.md');
            self::assertStringContainsString($setup->startCommand(), $readme);
            self::assertStringContainsString('php bolt jobs:work', $readme);
            self::assertFileDoesNotExist($project . '/.env');
            self::assertStringContainsString('Bootstrap::boot', file_get_contents($project . '/public/index.php'));
            self::assertStringContainsString('BOLT_RUNTIME_PATH=', $environment);
            self::assertStringContainsString('SIGNING_KEY=', $environment);
            $scopes = match ($profile) {
                'mentions' => ['app_mentions:read', 'chat:write'],
                'interactive' => ['commands', 'chat:write'],
                'agent' => ['assistant:write', 'chat:write', 'im:history'],
            };
            self::assertStringContainsString('SLACK_BOT_SCOPES=' . implode(',', $scopes), $environment);
            foreach ($scopes as $scope)
                self::assertStringContainsString('      - ' . $scope, $manifest);
            self::assertSame($scopes, $parsed['oauth_config']['scopes']['bot']);
            if ($profile !== 'mentions') {
                self::assertFileDoesNotExist($project . '/app/Listeners/GreetOnMention.php');
                self::assertStringNotContainsString('app_mentions:read', $manifest);
            }
            if ($profile === 'interactive') {
                self::assertFileExists($project . '/app/Listeners/InteractiveApp.php');
                self::assertStringContainsString('command: /bolt', $manifest);
                self::assertStringContainsString('interactivity:', $manifest);
                self::assertStringContainsString('home_tab_enabled: true', $manifest);
            }
            if ($profile === 'agent') {
                self::assertFileExists($project . '/app/Listeners/WorkspaceAgent.php');
                self::assertFileExists($project . '/config/assistant.config.php');
                self::assertStringContainsString('agent_description:', $manifest);
                self::assertStringContainsString('agent_session_stopped', $manifest);
                self::assertStringNotContainsString('assistant_thread_started', $manifest);
            }
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
                self::assertStringContainsString('redirect_urls:', $manifest);
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
                foreach (['mentions', 'interactive', 'agent'] as $profile)
                    yield [$transport, $authorization, $profile];
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

            self::assertSame('dev-main', $composer['require']['helgesverre/slack-bolt']);
            self::assertSame(['type' => 'vcs', 'url' => realpath(dirname(__DIR__, 2))], $composer['repositories'][0]);
            self::assertStringContainsString(
                'VCS using `dev-main`',
                (string) file_get_contents($project . '/README.md'),
            );
            self::assertStringNotContainsString('source', (string) file_get_contents($project . '/.env.example'));
        } finally {
            chdir($previous);
            $this->remove($root);
        }
    }

    public function test_modern_starters_reject_older_dependency_constraints_before_creating_a_directory(): void
    {
        $scaffold = new ProjectScaffold(dirname(__DIR__, 2));
        foreach (['interactive', 'agent'] as $profile) {
            $target = 'unsupported-starter-' . bin2hex(random_bytes(6));
            try {
                $scaffold->create(
                    $target,
                    new ProjectSetup(profile: $profile),
                    ProjectSource::fromOptions('packagist', null, '^0.3', dirname(__DIR__, 2)),
                );
                self::fail('Modern starter accepted an incompatible dependency.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('0.8.0 or newer', $exception->getMessage());
                self::assertDirectoryDoesNotExist(getcwd() . '/' . $target);
            }
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
