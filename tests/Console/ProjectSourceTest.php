<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\ProjectSource;

final class ProjectSourceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bolt-project-source-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_plain_head_file_does_not_make_an_arbitrary_directory_a_vcs_repository(): void
    {
        $fake = $this->root . '/fake';
        mkdir($fake, 0700);
        file_put_contents($fake . '/HEAD', "ref: refs/heads/main\n");

        $this->expectException(\InvalidArgumentException::class);
        ProjectSource::fromOptions('vcs', $fake, 'dev-main', dirname(__DIR__, 2));
    }

    public function test_git_worktree_with_a_dot_git_file_is_a_valid_vcs_repository(): void
    {
        $repository = $this->root . '/repository';
        $worktree = $this->root . '/worktree';
        $this->git(['init', '--quiet', '--initial-branch=main', $repository]);
        $this->git(['-C', $repository, 'config', 'user.name', 'Thunder Bolt test']);
        $this->git(['-C', $repository, 'config', 'user.email', 'test@example.test']);
        file_put_contents($repository . '/README.md', "fixture\n");
        $this->git(['-C', $repository, 'add', 'README.md']);
        $this->git(['-C', $repository, 'commit', '--quiet', '-m', 'fixture']);
        $this->git(['-C', $repository, 'worktree', 'add', '--quiet', $worktree]);

        self::assertFileExists($worktree . '/.git');
        self::assertTrue(is_file($worktree . '/.git'));
        $source = ProjectSource::fromOptions('vcs', $worktree, 'dev-main', dirname(__DIR__, 2));

        self::assertSame(ProjectSource::VCS, $source->type);
        self::assertSame(realpath($worktree), $source->repository);
    }

    public function test_nested_directory_inside_a_repository_is_not_a_vcs_repository_root(): void
    {
        $repository = $this->root . '/repository';
        $nested = $repository . '/nested';
        $this->git(['init', '--quiet', '--initial-branch=main', $repository]);
        mkdir($nested, 0700);

        $this->expectException(\InvalidArgumentException::class);
        ProjectSource::fromOptions('vcs', $nested, 'dev-main', dirname(__DIR__, 2));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            ['git', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $file) {
                $this->remove($file->getPathname());
            }
            rmdir($path);

            return;
        }
        if (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
