<?php

declare(strict_types=1);

use Tempest\Core\Tempest;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Router\HttpApplication;

require dirname(__DIR__) . '/vendor/autoload.php';

Tempest::boot(
    root: dirname(__DIR__),
    discoveryLocations: is_dir(dirname(__DIR__) . '/config')
        ? [new DiscoveryLocation('', dirname(__DIR__) . '/config')]
        : [],
    internalStorage: dirname(__DIR__) . '/.thunder-bolt',
)->get(HttpApplication::class)->run();
