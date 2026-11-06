<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\CommandCatalog;

final class CommandCatalogTest extends TestCase
{
    public function test_safe_key_command_replaces_only_the_upstream_default_and_preserves_extensions(): void
    {
        foreach ([
            [self::class, 'customKey'],
            [\Tempest\Cryptography\GenerateSigningKeyCommand::class, '__invoke'],
            [\Thunder\Bolt\Console\SigningKeyCommand::class, 'generate'],
        ] as [$class, $method]) {
            $config = new \Tempest\Console\ConsoleConfig();
            $reflector = \Tempest\Reflection\MethodReflector::fromParts($class, $method);
            $attribute = $class === self::class
                ? new \Tempest\Console\ConsoleCommand(name: 'key:generate')
                : $reflector->getAttribute(\Tempest\Console\ConsoleCommand::class);
            $config->addCommand($reflector, $attribute);
            CommandCatalog::configure($config);
            self::assertArrayHasKey('key:generate', $config->commands);
            self::assertSame(
                $class === self::class ? self::class : \Thunder\Bolt\Console\SigningKeyCommand::class,
                $config->commands['key:generate']->handler->getDeclaringClass()->getName(),
            );
            self::assertArrayNotHasKey('generate:key', $config->commands);
        }
    }

    public function customKey(): void {}

    public function test_catalog_keeps_project_creation_and_completion_commands_available(): void
    {
        self::assertContains('new', CommandCatalog::names());
        self::assertContains('completion:generate', CommandCatalog::names());
        self::assertContains('completion:install', CommandCatalog::names());
        self::assertContains('completion:show', CommandCatalog::names());
        self::assertContains('completion:uninstall', CommandCatalog::names());
    }
}
