<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Tempest\Console\ConsoleCommand;
use Tempest\Console\ConsoleConfig;
use Tempest\Reflection\MethodReflector;

/** Keeps the executable command surface and manually registered completion commands in one place. */
final class CommandCatalog
{
    /** @var list<string> */
    private const array FRAMEWORK_COMMANDS = [
        'new',
        'make:listener',
        'make:assistant',
        'serve',
        'socket:serve',
        'auth:verify',
        'listener:list',
        'doctor',
        'key:generate',
        'status',
        'routes',
        'about',
        'discovery:generate',
        'discovery:clear',
        'discovery:status',
        'command:monitor',
        'command:handle',
        'make:job',
        'jobs:work',
        'jobs:list',
        'jobs:show',
        'jobs:cancel',
        'jobs:recover',
        'jobs:retry',
        'jobs:prune',
        'jobs:migrate',
        'completion:generate',
        'completion:install',
        'completion:show',
        'completion:uninstall',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return self::FRAMEWORK_COMMANDS;
    }

    public static function configure(ConsoleConfig $config): void
    {
        $config->commands = array_filter(
            $config->commands,
            static fn(ConsoleCommand $command): bool => (
                in_array($command->getName(), self::FRAMEWORK_COMMANDS, true)
                || str_starts_with($command->handler->getDeclaringClass()->getName(), 'App\\')
            ),
        );
        // Prefer Bolt's non-rotating key command over Tempest's default override.
        $config->commands = array_filter(
            $config->commands,
            static fn(ConsoleCommand $command): bool => (
                $command->getName() !== 'key:generate'
                || $command->handler->getDeclaringClass()->getName()
                !== \Tempest\Cryptography\GenerateSigningKeyCommand::class
            ),
        );
        if (!isset($config->commands['key:generate'])) {
            $reflector = MethodReflector::fromParts(SigningKeyCommand::class, 'generate');
            $command = $reflector->getAttribute(ConsoleCommand::class);
            if ($command instanceof ConsoleCommand) {
                $config->addCommand($reflector, $command);
            }
        }
        foreach (['generate', 'show', 'install', 'uninstall'] as $method) {
            $reflector = MethodReflector::fromParts(CompletionCommands::class, $method);
            $command = $reflector->getAttribute(ConsoleCommand::class);
            if ($command instanceof ConsoleCommand) {
                $config->addCommand($reflector, $command);
            }
        }
    }
}
