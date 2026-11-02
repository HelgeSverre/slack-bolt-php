<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\CommandCatalog;

final class CommandCatalogTest extends TestCase
{
    public function test_catalog_keeps_project_creation_and_completion_commands_available(): void
    {
        self::assertContains('new', CommandCatalog::names());
        self::assertContains('completion:generate', CommandCatalog::names());
        self::assertContains('completion:install', CommandCatalog::names());
        self::assertContains('completion:show', CommandCatalog::names());
        self::assertContains('completion:uninstall', CommandCatalog::names());
    }
}
