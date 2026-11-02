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
        }
        $input = NewProjectInput::fromArguments(['my-app']);
        self::assertSame('http', $input->setup->transport);
        self::assertSame('token', $input->setup->authorization);
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
        ] as $arguments)
            yield [$arguments];
    }
}
