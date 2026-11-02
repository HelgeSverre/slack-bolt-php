<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Psr\Log\LoggerInterface;
use Tempest\CommandBus\CommandBusConfig;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Jobs\JobCodec;
use Thunder\Bolt\Jobs\SqliteJobStore;
use Thunder\Bolt\Jobs\Worker;
use Thunder\Bolt\Runtime\RuntimeMonitor;
use Thunder\Bolt\WebApi\Client;

final class JobWorkerInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): Worker
    {
        $app = $container->get(App::class);
        return new Worker(
            $container->get(SqliteJobStore::class),
            new JobCodec($container->get(CommandBusConfig::class)),
            $container,
            $container->get(Client::class),
            $container->get(BoltConfig::class),
            new \Thunder\Bolt\Authorization\CallbackAuthorizer($app->authorizeSource(...)),
            $container->has(ConversationStore::class) ? $container->get(ConversationStore::class) : null,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            $container->get(RuntimeMonitor::class),
        );
    }
}
