<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\CommandBus\CommandBusConfig;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Thunder\Bolt\Jobs;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\SqliteJobStore;

final class JobsInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): Jobs
    {
        return new Jobs(
            static fn(): SqliteJobStore => $container->get(SqliteJobStore::class),
            new JobCodec($container->get(CommandBusConfig::class)),
        );
    }
}
