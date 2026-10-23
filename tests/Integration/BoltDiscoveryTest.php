<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Tempest\Discovery\DiscoveryItems;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Reflection\ClassReflector;
use Thunder\Bolt\Attributes\Action;
use Thunder\Bolt\Attributes\Options;
use Thunder\Bolt\Attributes\Shortcut;
use Thunder\Bolt\Attributes\View;
use Thunder\Bolt\Integration\BoltDiscovery;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Routing\ListenerDescriptor;
use Thunder\Bolt\Routing\ListenerKind;
use Thunder\Bolt\Routing\Regex;

final class BoltDiscoveryTest extends TestCase
{
    public function test_discovers_interactivity_attributes_as_constraint_descriptors(): void
    {
        $discovery = new BoltDiscovery(new GenericContainer());
        $items = new DiscoveryItems();
        $discovery->setItems($items);
        $location = new DiscoveryLocation(__NAMESPACE__, __DIR__);

        $discovery->discover($location, $this->reflector(InteractivityAttributeListener::class));

        $descriptors = $items->getForLocation($location);
        self::assertCount(4, $descriptors);
        self::assertContainsOnlyInstancesOf(ListenerDescriptor::class, $descriptors);

        $byKind = [];
        foreach ($descriptors as $descriptor) {
            $byKind[$descriptor->kind->value] = $descriptor;
        }

        self::assertSame(ListenerKind::Action, $byKind['action']->kind);
        self::assertInstanceOf(InteractionConstraints::class, $byKind['action']->selector);
        self::assertSame('approve', $byKind['action']->selector->actionId);
        self::assertSame('request', $byKind['action']->selector->blockId);
        self::assertSame('workflow', $byKind['action']->selector->callbackId);
        self::assertSame('block_actions', $byKind['action']->selector->type);

        self::assertSame('open_modal', $byKind['shortcut']->selector->callbackId);
        self::assertSame('message_action', $byKind['shortcut']->selector->type);
        self::assertSame('assignee', $byKind['options']->selector->actionId);
        self::assertSame('block_suggestion', $byKind['options']->selector->type);
        self::assertSame('submit_request', $byKind['view']->selector->callbackId);
        self::assertSame('view_submission', $byKind['view']->selector->type);
    }

    public function test_discovers_regex_interactivity_constraints_without_losing_their_patterns(): void
    {
        $discovery = new BoltDiscovery(new GenericContainer());
        $items = new DiscoveryItems();
        $discovery->setItems($items);
        $location = new DiscoveryLocation(__NAMESPACE__, __DIR__);

        $discovery->discover($location, $this->reflector(RegexInteractivityAttributeListener::class));

        $byKind = [];
        foreach ($items->getForLocation($location) as $descriptor) {
            $byKind[$descriptor->kind->value] = $descriptor;
        }

        self::assertSame('/^approve_\\d+$/', $byKind['action']->selector->actionId->pattern);
        self::assertSame('/^request_\\d+$/', $byKind['action']->selector->blockId->pattern);
        self::assertSame('/^workflow_\\d+$/', $byKind['action']->selector->callbackId->pattern);
        self::assertSame('/^open_/', $byKind['shortcut']->selector->callbackId->pattern);
        self::assertSame('/^search_/', $byKind['options']->selector->actionId->pattern);
        self::assertSame('/^submit_/', $byKind['view']->selector->callbackId->pattern);
        self::assertNull($byKind['view']->selector->type);
    }

    /**
     * @param  class-string<object>  $class
     * @return ClassReflector<object>
     */
    private function reflector(string $class): ClassReflector
    {
        return new ClassReflector($class);
    }
}

final class InteractivityAttributeListener
{
    #[Action('approve', blockId: 'request', callbackId: 'workflow', type: 'block_actions')]
    public function action(): void {}

    #[Shortcut('open_modal', type: 'message_action')]
    public function shortcut(): void {}

    #[Options('assignee', type: 'block_suggestion')]
    public function options(): void {}

    #[View('submit_request')]
    public function view(): void {}
}

final class RegexInteractivityAttributeListener
{
    #[Action(
        new Regex('/^approve_\\d+$/'),
        blockId: new Regex('/^request_\\d+$/'),
        callbackId: new Regex('/^workflow_\\d+$/'),
    )]
    public function action(): void {}

    #[Shortcut(new Regex('/^open_/'))]
    public function shortcut(): void {}

    #[Options(new Regex('/^search_/'))]
    public function options(): void {}

    #[View(new Regex('/^submit_/'), type: null)]
    public function view(): void {}
}
