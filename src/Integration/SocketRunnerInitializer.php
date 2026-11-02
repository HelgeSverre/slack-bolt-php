<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Psr\Log\LoggerInterface;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Thunder\Bolt\App;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\WebApi\Client;

final class SocketRunnerInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): Runner
    {
        $app = $container->get(App::class);
        return new Runner(
            $app,
            $container->get(Client::class),
            $container->get(Config::class),
            $container->get(Connector::class),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            monitor: $container->get(RuntimeMonitor::class),
        );
    }
}
