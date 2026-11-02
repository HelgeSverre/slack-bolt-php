<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\NewProjectInput;

final class NewProjectInputTest extends TestCase
{
    public function test_flags_work_before_or_after_the_directory_without_prompting(): void
    {
        foreach ([
            ['my-app', '--transport=socket', '--auth=oauth', '--no-interaction'],
            ['--transport', 'socket', 'my-app', '--auth', 'oauth'],
        ] as $arguments) {
            $input = NewProjectInput::fromArguments($arguments);
            self::assertSame('my-app', $input->directory);
            self::assertSame('socket', $input->setup->transport);
            self::assertSame('oauth', $input->setup->authorization);
            self::assertSame('packagist', $input->source->type);
        }
        $input = NewProjectInput::fromArguments(['my-app']);
        self::assertSame('http', $input->setup->transport);
        self::assertSame('token', $input->setup->authorization);
        self::assertSame('packagist', $input->source->type);
    }

    public function test_vcs_source_is_explicit_and_defaults_to_the_development_branch(): void
    {
        $input = NewProjectInput::fromArguments([
            'my-app',
            '--source=vcs',
            '--repository=https://github.com/acme/thunder-bolt.git',
        ]);

        self::assertSame('vcs', $input->source->type);
        self::assertSame('https://github.com/acme/thunder-bolt.git', $input->source->repository);
        self::assertSame('dev-main', $input->source->version);
    }

    public function test_packagist_uses_no_repository_and_local_development_remains_explicit(): void
    {
        $registry = NewProjectInput::fromArguments(['app']);
        self::assertNull($registry->source->composerRepository());
        self::assertSame('^0.4', $registry->source->version);
        $local = NewProjectInput::fromArguments(['app', '--source=path']);
        self::assertSame('path', $local->source->composerRepository()['type']);
        self::assertSame('0.4.x-dev', $local->source->version);
    }

    public function test_registry_and_git_sources_accept_compatible_composer_constraints(): void
    {
        foreach (['^0.3', '~0.3.0', '0.3.1', '>=0.3.0 <0.4.0', 'dev-main'] as $version) {
            self::assertSame(
                $version,
                NewProjectInput::fromArguments(['app', '--version=' . $version])->source->version,
            );
            self::assertSame(
                $version,
                NewProjectInput::fromArguments([
                    'app',
                    '--source=vcs',
                    '--repository=https://github.com/HelgeSverre/slack-bolt-php.git',
                    '--version=' . $version,
                ])->source->version,
            );
        }
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_flags_fail_before_creating_a_project(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NewProjectInput::fromArguments($arguments);
    }

    public static function invalidArguments(): iterable
    {
        foreach ([
            [],
            ['--auth'],
            ['x', '--transport'],
            ['x', '--unknown'],
            ['x', '--auth', '--no-interaction'],
            ['x', '--auth=invalid'],
            ['x', '--transport=invalid'],
            ['x', 'y'],
            ['x', '--auth=oauth', '--auth=token'],
            ['x', '--source=vcs'],
            ['x', '--source=packagist', '--version=^0.2'],
            ['x', '--version=not-a-version'],
            ['x', '--version=>=0.2'],
            ['x', '--source=path', '--version=dev-main'],
            ['x', '--repository=https://github.com/acme/thunder-bolt.git'],
            ['x', '--source=vcs', '--repository=https://secret@example.test/thunder-bolt.git'],
            ['x', '--source=vcs', '--repository=https://example.test/thunder-bolt.git?token=secret'],
            ['x', '--source=vcs', '--repository=https://github.com/acme/thunder-bolt.git', '--version=v0.2.0'],
        ] as $arguments)
            yield [$arguments];
    }
}
