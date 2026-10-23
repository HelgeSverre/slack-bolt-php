<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\Attributes\Action as ActionAttribute;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Exceptions\DuplicateListener;
use Thunder\Bolt\Exceptions\InvalidListener;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Routing\InteractionConstraints;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class InteractivityRegistrationTest extends TestCase
{
    public function test_interaction_payload_mismatch_fails_at_registration(): void
    {
        $this->expectException(InvalidListener::class);
        $this->app()->action('approve', static fn(View $view) => ViewResponse::empty());
    }

    public function test_attribute_and_equivalent_fluent_constraints_are_duplicate_registrations(): void
    {
        $app = $this->app()->registerClass(AttributedAction::class);
        $this->expectException(DuplicateListener::class);
        $app->action(InteractionConstraints::action('approve', blockId: 'orders'), AttributedAction::class);
    }

    public function test_unknown_payload_type_constraint_fails_at_registration(): void
    {
        $this->expectException(InvalidListener::class);
        $this->app()->view(
            new InteractionConstraints(callbackId: 'modal', type: 'block_actions'),
            static fn() => ViewResponse::empty(),
        );
    }

    public function test_invalid_constraint_fields_cannot_broaden_shortcut_matching(): void
    {
        $this->expectException(InvalidListener::class);
        $this->app()->shortcut(new InteractionConstraints(actionId: 'wrong'), static fn() => null);
    }

    public function test_descriptor_equivalence_is_independent_of_constraint_factory(): void
    {
        $app = $this->app();
        $handler = static fn(Action $action) => ActionResponse::empty();
        $app->action('approve', $handler);
        $this->expectException(DuplicateListener::class);
        $app->action(new InteractionConstraints(actionId: 'approve'), $handler);
    }

    private function app(): App
    {
        return new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'test'),
            new BoltConfig('test', 'secret'),
        );
    }
}

final class AttributedAction
{
    #[ActionAttribute('approve', blockId: 'orders')]
    public function __invoke(Action $action): ActionResponse
    {
        return ActionResponse::empty();
    }
}
