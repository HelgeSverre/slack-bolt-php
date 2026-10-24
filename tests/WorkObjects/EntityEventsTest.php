<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\WorkObjects;

use PHPUnit\Framework\TestCase;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Payloads\Event;
use Thunder\Bolt\Payloads\Events\EntityCommentsRequested;
use Thunder\Bolt\Payloads\Events\EntityDetailsRequested;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\WebApi\Client;

final class EntityEventsTest extends TestCase
{
    public function test_app_dispatches_entity_events_as_typed_payloads_while_base_event_handlers_remain_compatible(): void
    {
        $app = new App(
            new GenericContainer(),
            new Client(new RecordingHttpClient(), 'token'),
            new BoltConfig(token: 'token', signingSecret: 'secret'),
        );
        $received = [];
        $app->event('entity_details_requested', static function (EntityDetailsRequested $event) use (&$received): void {
            $received['details'] = $event;
        });
        $app->event('entity_comments_requested', static function (Event $event) use (&$received): void {
            $received['generic'] = $event;
        });

        $app->dispatch([
            'event' => [
                'type' => 'entity_details_requested',
                'trigger_id' => 'DETAILS-1',
                'external_ref' => ['id' => 'WORK-1'],
                'entity_url' => 'https://work.example.test/WORK-1',
            ],
        ]);
        $app->dispatch([
            'event' => [
                'type' => 'entity_comments_requested',
                'trigger_id' => 'COMMENTS-1',
                'external_ref' => ['id' => 'WORK-1'],
                'limit' => 25,
            ],
        ]);

        self::assertInstanceOf(EntityDetailsRequested::class, $received['details']);
        self::assertSame('WORK-1', $received['details']->reference->id);
        self::assertInstanceOf(EntityCommentsRequested::class, $received['generic']);
        self::assertSame(25, $received['generic']->limit);
    }

    public function test_view_submission_keeps_its_normal_ack_separate_from_comment_actions(): void
    {
        $http = new RecordingHttpClient();
        $app = new App(
            new GenericContainer(),
            new Client($http, 'token'),
            new BoltConfig(token: 'token', signingSecret: 'secret'),
        );
        $received = null;
        $app->view('work-object-post-comment', static function (View $view) use (&$received): ViewResponse {
            $received = $view;

            return ViewResponse::empty();
        });

        $acknowledgement = $app->dispatch([
            'type' => 'view_submission',
            'trigger_id' => 'TRIGGER-1',
            'view' => ['id' => 'V-1', 'type' => 'entity_comment', 'callback_id' => 'work-object-post-comment'],
        ]);

        self::assertInstanceOf(View::class, $received);
        self::assertSame([], $acknowledgement?->toArray());
        self::assertSame([], $http->requests);
    }
}
