<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Container\Container;
use Tempest\Http\GenericResponse;
use Tempest\Http\Request;
use Tempest\Http\Status;
use Tempest\Router\Get;
use Tempest\Router\Stateless;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\OAuth\Installer;

#[Stateless]
final readonly class OAuthController
{
    public function __construct(
        private BoltConfig $config,
        private Container $container,
    ) {}

    #[Get('/slack/install')]
    public function install(): GenericResponse
    {
        return $this->handler()?->install() ?? $this->unavailable();
    }

    #[Get('/slack/oauth_redirect')]
    public function callback(Request $request): GenericResponse
    {
        return $this->handler()?->callback($request) ?? $this->unavailable();
    }

    private function handler(): ?OAuthHttpHandler
    {
        if ($this->config->oauth === null) {
            return null;
        }
        if (!$this->container->has(Installer::class)) {
            $this->container->get(App::class);
        }
        return $this->container->has(OAuthHttpHandler::class)
            ? $this->container->get(OAuthHttpHandler::class)
            : new OAuthHttpHandler($this->container->get(Installer::class));
    }

    private function unavailable(): GenericResponse
    {
        return new GenericResponse(Status::SERVICE_UNAVAILABLE, 'OAuth installation is not configured.');
    }
}
