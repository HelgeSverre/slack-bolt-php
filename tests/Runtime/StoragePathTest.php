<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Bootstrap;
use Thunder\Bolt\Runtime\StoragePath;

final class StoragePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bolt-storage-path-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
        mkdir($this->root . '/public', 0700);
    }

    protected function tearDown(): void
    {
        foreach (['alias', 'public/alias', 'file', '.env'] as $path) {
            if (is_file($this->root . '/' . $path) || is_link($this->root . '/' . $path))
                unlink($this->root . '/' . $path);
        }
        rmdir($this->root . '/public');
        rmdir($this->root);
    }

    public function test_relative_paths_are_rooted_at_the_project_without_creating_anything(): void
    {
        $path = new StoragePath($this->root, 'private/jobs/queue.sqlite');
        self::assertSame($this->root . '/private/jobs/queue.sqlite', $path->absolute());
        self::assertSame(['writable' => true, 'public' => false], $path->inspect(directory: false));
        self::assertDirectoryDoesNotExist($this->root . '/private');
    }

    public function test_bootstrap_rejects_lexically_disguised_public_runtime_paths_before_booting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside public/');
        Bootstrap::boot($this->root, 'private/../public/runtime');
    }

    public function test_both_directions_of_a_public_symlink_are_rejected(): void
    {
        if (PHP_OS_FAMILY === 'Windows')
            self::markTestSkipped('Symlinks require privileges on Windows.');
        symlink($this->root . '/public', $this->root . '/alias');
        symlink($this->root, $this->root . '/public/alias');
        foreach (['alias/runtime', 'public/alias/runtime'] as $path) {
            self::assertTrue(new StoragePath($this->root, $path)->inspect(directory: true)['public']);
        }
        $this->expectException(\InvalidArgumentException::class);
        Bootstrap::boot($this->root, 'alias/runtime');
    }

    public function test_runtime_must_be_a_directory(): void
    {
        file_put_contents($this->root . '/file', 'existing');
        $this->expectException(\InvalidArgumentException::class);
        Bootstrap::boot($this->root, 'file');
    }

    public function test_windows_drive_and_unc_paths_are_absolute(): void
    {
        foreach (['C:\\state\\bolt', '\\\\server\\share\\bolt'] as $absolute) {
            self::assertSame($absolute, new StoragePath($this->root, $absolute)->absolute());
        }
    }

    public function test_malformed_environment_errors_do_not_retain_secret_values(): void
    {
        file_put_contents($this->root . '/.env', 'PRIVATE_KEY=private-syntax-canary unexpected');
        try {
            Bootstrap::boot($this->root);
            self::fail('Malformed environment configuration must fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringNotContainsString('private-syntax-canary', (string) $exception);
            self::assertNull($exception->getPrevious());
        }
    }
}
