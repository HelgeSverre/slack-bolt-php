<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Psr\Clock\ClockInterface;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Core\Kernel;
use Thunder\Bolt\Jobs\JobConfig;
use Thunder\Bolt\Jobs\SqliteJobStore;

final class JobStoreInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): SqliteJobStore
    {
        $path =
            $container->get(JobConfig::class)->path
            ?? $container->get(Kernel::class)->internalStorage . '/jobs/queue.sqlite';
        return SqliteJobStore::open(
            $path,
            $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
        );
    }
}
