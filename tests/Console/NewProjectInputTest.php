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
            self::assertSame('path', $input->source->type);
        }
        $input = NewProjectInput::fromArguments(['my-app']);
        self::assertSame('http', $input->setup->transport);
        self::assertSame('token', $input->setup->authorization);
        self::assertSame('path', $input->source->type);
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
            ['x', '--repository=https://github.com/acme/thunder-bolt.git'],
            ['x', '--source=vcs', '--repository=https://secret@example.test/thunder-bolt.git'],
            ['x', '--source=vcs', '--repository=https://example.test/thunder-bolt.git?token=secret'],
            ['x', '--source=vcs', '--repository=https://github.com/acme/thunder-bolt.git', '--version=v0.2.0'],
        ] as $arguments)
            yield [$arguments];
    }
}
