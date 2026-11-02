<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Core\Kernel;
use Thunder\Bolt\OAuth\SystemClock;
use Thunder\Bolt\Runtime\RuntimeMonitor;

final class RuntimeMonitorInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): RuntimeMonitor
    {
        return new RuntimeMonitor(
            $container->get(Kernel::class)->internalStorage . '/runtime',
            $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock(),
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : new NullLogger(),
        );
    }
}
