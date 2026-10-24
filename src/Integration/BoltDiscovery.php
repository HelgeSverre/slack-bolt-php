<?php

declare(strict_types=1);

namespace Thunder\Bolt\Integration;

use Tempest\Container\Container;
use Tempest\Discovery\Discovery;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Discovery\IsDiscovery;
use Tempest\Reflection\ClassReflector;
use Thunder\Bolt\App;
use Thunder\Bolt\Attributes\Action;
use Thunder\Bolt\Attributes\Event;
use Thunder\Bolt\Attributes\Message;
use Thunder\Bolt\Attributes\Options;
use Thunder\Bolt\Attributes\Shortcut;
use Thunder\Bolt\Attributes\SlackFunction;
use Thunder\Bolt\Attributes\SlashCommand;
use Thunder\Bolt\Attributes\View;
use Thunder\Bolt\Routing\HandlerTarget;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\ListenerDescriptor;

/**
 * Discovers declarative listeners into cache-safe ListenerDescriptor values.
 * App creation is deferred to apply(), after Tempest has discovered Initializers.
 */
final class BoltDiscovery implements Discovery
{
    use IsDiscovery;

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @param ClassReflector<object> $class */
    public function discover(DiscoveryLocation $location, ClassReflector $class): void
    {
        foreach ($class->getPublicMethods() as $method) {
            $events = $method->getAttributes(Event::class);
            $functions = $method->getAttributes(SlackFunction::class);
            $messages = $method->getAttributes(Message::class);
            $commands = $method->getAttributes(SlashCommand::class);
            $actions = $method->getAttributes(Action::class);
            $shortcuts = $method->getAttributes(Shortcut::class);
            $options = $method->getAttributes(Options::class);
            $views = $method->getAttributes(View::class);
            if (
                $events === []
                && $functions === []
                && $messages === []
                && $commands === []
                && $actions === []
                && $shortcuts === []
                && $options === []
                && $views === []
            ) {
                continue;
            }
            $target = HandlerTarget::classMethod($class->getName(), $method->getName());
            $source = sprintf('%s::%s', $class->getName(), $method->getName());
            foreach ($events as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::event(
                    $attribute->type,
                    $target,
                    source: "{$source} #[Event]",
                ));
            }
            foreach ($functions as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::function(
                    $attribute->callbackId,
                    $target,
                    source: "{$source} #[SlackFunction]",
                ));
            }
            foreach ($messages as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::message(
                    $attribute->contains,
                    $target,
                    source: "{$source} #[Message]",
                ));
            }
            foreach ($commands as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::command(
                    $attribute->name,
                    $target,
                    source: "{$source} #[SlashCommand]",
                ));
            }
            foreach ($actions as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::action(
                    new InteractionConstraints(
                        actionId: is_string($attribute->actionId) && $attribute->actionId === ''
                            ? null
                            : $attribute->actionId,
                        blockId: $attribute->blockId,
                        callbackId: $attribute->callbackId,
                        type: $attribute->type,
                    ),
                    $target,
                    source: "{$source} #[Action]",
                ));
            }
            foreach ($shortcuts as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::shortcut(
                    new InteractionConstraints(callbackId: $attribute->callbackId, type: $attribute->type),
                    $target,
                    source: "{$source} #[Shortcut]",
                ));
            }
            foreach ($options as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::options(
                    new InteractionConstraints(
                        actionId: is_string($attribute->actionId) && $attribute->actionId === ''
                            ? null
                            : $attribute->actionId,
                        blockId: $attribute->blockId,
                        callbackId: $attribute->callbackId,
                        type: $attribute->type,
                    ),
                    $target,
                    source: "{$source} #[Options]",
                ));
            }
            foreach ($views as $attribute) {
                $this->discoveryItems->add($location, ListenerDescriptor::view(
                    new InteractionConstraints(callbackId: $attribute->callbackId, type: $attribute->type),
                    $target,
                    source: "{$source} #[View]",
                ));
            }
        }
    }

    public function apply(): void
    {
        $app = $this->app();
        foreach ($this->discoveryItems as $descriptor) {
            /** @var ListenerDescriptor $descriptor */
            $app->registerDescriptor($descriptor);
        }
    }

    private function app(): App
    {
        if ($this->container->has(App::class)) {
            return $this->container->get(App::class);
        }
        $app = new AppInitializer()->initialize($this->container);
        $this->container->singleton(App::class, $app);

        return $app;
    }
}
