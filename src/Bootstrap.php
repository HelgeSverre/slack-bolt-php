<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Dotenv\Dotenv;
use Tempest\Container\Container;
use Tempest\Core\Tempest;
use Tempest\Discovery\DiscoveryLocation;
use Thunder\Bolt\Runtime\StoragePath;

/** Shared HTTP/CLI bootstrap; process environment takes precedence over .env. */
final class Bootstrap
{
    public static function boot(string $root, ?string $runtimePath = null): Container
    {
        $root = realpath($root) ?: throw new \InvalidArgumentException('The project root must exist.');
        if (!is_dir($root)) {
            throw new \InvalidArgumentException('The project root must be a directory.');
        }
        try {
            Dotenv::createUnsafeImmutable($root)->safeLoad();
        } catch (\Dotenv\Exception\InvalidFileException) {
            throw new \InvalidArgumentException(
                'The project .env file has invalid syntax. Check its configuration without sharing secret values.',
            );
        }
        $configured = getenv('BOLT_RUNTIME_PATH');
        $runtimePath ??= is_string($configured) && trim($configured) !== '' ? $configured : '.thunder-bolt';
        if (trim($runtimePath) === '' || str_contains($runtimePath, "\0")) {
            throw new \InvalidArgumentException('BOLT_RUNTIME_PATH must be a non-empty storage directory.');
        }
        $storage = new StoragePath($root, $runtimePath);
        $status = $storage->inspect(directory: true);
        if ($status['public'] || !$status['writable']) {
            throw new \InvalidArgumentException('Use a writable BOLT_RUNTIME_PATH directory outside public/.');
        }
        $directory = $storage->absolute();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the private BOLT_RUNTIME_PATH directory.');
        }

        return Tempest::boot(
            root: $root,
            discoveryLocations: is_dir($root . '/config') ? [new DiscoveryLocation('', $root . '/config')] : [],
            internalStorage: $directory,
        );
    }
}
