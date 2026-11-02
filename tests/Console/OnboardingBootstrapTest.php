<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;

final class OnboardingBootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bolt-bootstrap-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_new_fast_path_accepts_the_same_profile_flags_as_discovered_commands(): void
    {
        [$status, $output, $error] = $this->command([
            'new',
            'app',
            '--transport=socket',
            '--auth=oauth',
            '--no-interaction',
        ]);
        self::assertSame(0, $status, $error);
        self::assertStringContainsString('php bolt socket:serve', $output);
        self::assertStringContainsString('cp .env.example .env', $output);
        $setup = require $this->root . '/app/config/setup.config.php';
        self::assertSame('socket', $setup->transport);
        self::assertSame('oauth', $setup->authorization);
        [$status] = $this->command(['new', 'invalid', '--transport=invalid']);
        self::assertSame(2, $status);
        self::assertDirectoryDoesNotExist($this->root . '/invalid');
    }

    public function test_doctor_reports_missing_composer_dependencies_as_json(): void
    {
        $this->project("<?php return new \\Thunder\\Bolt\\BoltConfig(conversationStoreDriver: 'memory');");
        [$status, $output, $error] = $this->command(['doctor', '--format=json']);
        self::assertSame(1, $status, $error);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['ready']);
        self::assertTrue($report['configurationError']);
        self::assertStringContainsString('composer install', implode(' ', $report['failures']));
    }

    public function test_doctor_boot_errors_do_not_expose_exception_details(): void
    {
        $this->project("<?php throw new \\RuntimeException('private-canary-do-not-print');");
        [$status, $output, $error] = $this->command(['doctor', '--format=json']);
        self::assertSame(1, $status);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['ready']);
        self::assertTrue($report['configurationError']);
        self::assertStringNotContainsString('private-canary-do-not-print', $output . $error);
        self::assertStringNotContainsString($this->root, $output . $error);
    }

    public function test_status_boot_errors_remain_machine_readable_and_redacted(): void
    {
        $this->project("<?php throw new \\RuntimeException('status-private-canary');");
        [$status, $output, $error] = $this->command(['status', '--format=json']);
        self::assertSame(1, $status);
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report['healthy']);
        self::assertTrue($report['configurationError']);
        self::assertStringNotContainsString('status-private-canary', $output . $error);
    }

    private function project(string $configuration): void
    {
        mkdir($this->root . '/config');
        file_put_contents(
            $this->root . '/composer.json',
            '{"name":"acme/doctor-fixture","type":"project","autoload":{"psr-4":{"Probe\\\\":"app/"}}}',
        );
        file_put_contents($this->root . '/config/bolt.config.php', $configuration);
    }

    private function command(array $arguments): array
    {
        $environment = [];
        foreach ([
            'PATH',
            'HOME',
            'TMPDIR',
            'SystemRoot',
            'APPDATA',
            'LOCALAPPDATA',
            'USERPROFILE',
            'PHPRC',
            'PHP_INI_SCAN_DIR',
        ] as $name) {
            $value = getenv($name);
            if (is_string($value))
                $environment[$name] = $value;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/bolt', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $environment,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output, $error];
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
