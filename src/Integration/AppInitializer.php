<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Tempest\Container\Container;
use Tempest\Container\Initializer;
use Tempest\Core\Kernel;
use Tempest\Router\HandleRouteExceptionMiddleware;
use Tempest\Router\RouteConfig;
use Thunder\Bolt\App;
use Thunder\Bolt\Authorization\Authorizer;
use Thunder\Bolt\Authorization\InstallationAuthorizer;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Conversation\ConversationStore;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Conversation\Stores\MemoryConversationStore;
use Thunder\Bolt\Conversation\Stores\SqliteConversationStore;
use Thunder\Bolt\OAuth\InstallationStore;
use Thunder\Bolt\OAuth\Installer;
use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\OAuth\State\FileStateStore;
use Thunder\Bolt\OAuth\State\StateStore;
use Thunder\Bolt\OAuth\Stores\FileInstallationStore;
use Thunder\Bolt\OAuth\SystemClock;
use Thunder\Bolt\OAuth\TokenRotator;
use Thunder\Bolt\SocketMode\Config as SocketModeConfig;
use Thunder\Bolt\SocketMode\Transport\AmpConnector;
use Thunder\Bolt\SocketMode\Transport\Connector;
use Thunder\Bolt\WebApi\Client as WebApiClient;

/**
 * Creates the application lazily. Empty credentials are intentional here: commands
 * such as `doctor`, `--help`, and discovery must work before Slack is configured.
 * Receiver dispatch is responsible for rejecting an unconfigured application.
 */
final class AppInitializer implements Initializer
{
    #[\Tempest\Container\Singleton]
    public function initialize(Container $container): App
    {
        $routeConfig = $container->get(RouteConfig::class);
        $routeConfig
            ->middleware
            ->remove(HandleRouteExceptionMiddleware::class)
            ->add(SlackHttpFailurePassthroughMiddleware::class);

        $config = $container->get(BoltConfig::class);
        $http = $container->has(ClientInterface::class)
            ? $container->get(ClientInterface::class)
            : new GuzzleClient([
                'connect_timeout' => 1.0,
                'timeout' => 2.0,
                'http_errors' => false,
            ]);
        $client = new WebApiClient(http: $http, token: $config->oauth === null ? $config->token : '');

        $container->singleton(WebApiClient::class, $client);

        $socketMode = $config->socketMode ?? new SocketModeConfig();
        $container->singleton(SocketModeConfig::class, $socketMode);
        if (!$container->has(Connector::class)) {
            $container->singleton(
                Connector::class,
                new AmpConnector(
                    connectTimeoutSeconds: $socketMode->helloTimeoutSeconds,
                    tlsHandshakeTimeoutSeconds: $socketMode->helloTimeoutSeconds,
                    maxMessageBytes: $socketMode->maxMessageBytes,
                ),
            );
        }

        $authorizer = $container->has(Authorizer::class) ? $container->get(Authorizer::class) : null;
        if ($config->oauth !== null) {
            $container->singleton(OAuthConfig::class, $config->oauth);
            $directory = $config->installationStorePath ?? $container->get(Kernel::class)->internalStorage . '/oauth';
            $directory = new \Thunder\Bolt\Runtime\StoragePath(
                $container->get(Kernel::class)->root,
                $directory,
            )->absolute();
            $store = $container->has(InstallationStore::class)
                ? $container->get(InstallationStore::class)
                : new FileInstallationStore($directory . '/installations');
            $states = $container->has(StateStore::class)
                ? $container->get(StateStore::class)
                : new FileStateStore($directory . '/states');
            $clock = $container->has(ClockInterface::class)
                ? $container->get(ClockInterface::class)
                : new SystemClock();
            $rotator = new TokenRotator($config->oauth, $client->withToken(''), $store, $clock);
            $container->singleton(InstallationStore::class, $store);
            $container->singleton(StateStore::class, $states);
            $container->singleton(TokenRotator::class, $rotator);
            $container->singleton(
                Installer::class,
                new Installer($config->oauth, $client->withToken(''), $store, $states, $clock),
            );
            if (!$container->has(OAuthHttpHandler::class)) {
                $container->singleton(OAuthHttpHandler::class, new OAuthHttpHandler($container->get(Installer::class)));
            }
            $authorizer ??= new InstallationAuthorizer($store, $rotator);
        }

        $clock = $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : new SystemClock();
        $contextStore = false;
        if ($config->conversationStoreDriver !== 'disabled') {
            if ($container->has(ConversationStore::class)) {
                $contextStore = $container->get(ConversationStore::class);
            } else {
                $path =
                    $config->conversationStorePath
                    ?? $container->get(Kernel::class)->internalStorage
                        . ($config->conversationStoreDriver === 'sqlite' ? '/conversations.sqlite' : '/conversations');
                $path = new \Thunder\Bolt\Runtime\StoragePath($container->get(Kernel::class)->root, $path)->absolute();
                $contextStore = match ($config->conversationStoreDriver) {
                    'memory' => new MemoryConversationStore($clock),
                    'sqlite' => new SqliteConversationStore($this->sqlite($path), $clock),
                    default => new FileConversationStore($path, $clock),
                };
            }
            $container->singleton(ConversationStore::class, $contextStore);
        }

        $app = new App(
            $container,
            $client,
            $config,
            $authorizer,
            $contextStore,
            $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            jobs: $container->get(\Thunder\Bolt\Jobs::class),
        );
        if ($authorizer !== null) {
            $container->singleton(Authorizer::class, $authorizer);
        }
        if ($container->has(\Thunder\Bolt\Assistant::class)) {
            $app->assistant($container->get(\Thunder\Bolt\Assistant::class));
        }
        return $app;
    }

    private function sqlite(string $path): PDO
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create the SQLite context directory.');
        }
        $file = fopen($path, 'c');
        if ($file === false) {
            throw new \RuntimeException('Unable to create the SQLite context database.');
        }
        fclose($file);
        if (!chmod($path, 0600)) {
            throw new \RuntimeException('Unable to secure the SQLite context database.');
        }

        return new PDO('sqlite:' . $path);
    }
}
