<?php

declare(strict_types=1);

namespace Thunder\Bolt\Invocation;

use ArrayIterator;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\Authorization\Authorization;
use Thunder\Bolt\WebApi\Client;

/** @internal A separate resolution cache prevents request credentials entering the application container. */
final class InvocationScope
{
    public static function create(Container $container, Client $client, Authorization $authorization): Container
    {
        if (!$container instanceof GenericContainer) {
            throw new \InvalidArgumentException(
                'Delivery-scoped authorization requires Tempest GenericContainer. A custom container must provide an equivalent credential scope.',
            );
        }

        $initializers = $container->getInitializers();
        $dynamicInitializers = $container->getDynamicInitializers();
        $decorators = $container->getDecorators();
        /** @var array<array-key, class-string> $initializers */
        /** @var array<array-key, class-string> $dynamicInitializers */
        /** @var array<array-key, array<array-key, class-string>> $decorators */
        $scope = new GenericContainer(
            definitions: new ArrayIterator($container->getDefinitions()),
            singletonDefinitions: new ArrayIterator($container->getSingletons()),
            initializers: new ArrayIterator($initializers),
            dynamicInitializers: new ArrayIterator($dynamicInitializers),
            decorators: new ArrayIterator($decorators),
        );
        $scope->singleton(Client::class, $client);
        $scope->singleton(Authorization::class, $authorization);

        return $scope;
    }
}
