<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Views;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
use Thunder\Bolt\Generated\WebApi\Models\Requests\ViewsOpenRequest;
use Thunder\Bolt\Tests\Support\RecordingHttpClient;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\Views\WorkflowStep;
use Thunder\Bolt\WebApi\Client;

final class ViewContractTest extends TestCase
{
    public function test_typed_view_serializes_through_endpoint_request(): void
    {
        $modal = Modal::make('Éditer', Input::make(
            'Reason',
            PlainTextInput::make('reason')->length(0)->focusOnLoad()->dispatchOn('on_enter_pressed'),
        ))
            ->callbackId('edit')
            ->submit('Save')
            ->clearOnClose(false)
            ->notifyOnClose()
            ->privateMetadata('0')
            ->externalId('external');
        $http = new RecordingHttpClient([new Response(200, [], '{"ok":true,"view":{"id":"V1"}}')]);
        new Client($http, 'token')
            ->views()
            ->open(new ViewsOpenRequest($modal, triggerId: 'trigger'));
        $view = $http->sentPayload()['view'];
        self::assertSame('modal', $view['type']);
        self::assertSame('Éditer', $view['title']['text']);
        self::assertFalse($view['clear_on_close']);
        self::assertTrue($view['notify_on_close']);
        self::assertSame('0', $view['private_metadata']);
        self::assertSame('external', $view['external_id']);
        self::assertArrayNotHasKey('max_length', $view['blocks'][0]['element']);
        self::assertSame(
            ['on_enter_pressed'],
            $view['blocks'][0]['element']['dispatch_action_config']['trigger_actions_on'],
        );
    }

    public function test_plain_text_title_and_label_limits_are_enforced(): void
    {
        foreach ([Text::markdown('Wrong'), str_repeat('é', 25)] as $title) {
            try {
                Modal::make($title);
                self::fail('Expected invalid title.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_input_modal_requires_submit_at_serialization_time(): void
    {
        $this->expectException(\LogicException::class);
        Modal::make('Edit', Input::make('Reason', PlainTextInput::make('reason')))->toArray();
    }

    public function test_home_and_workflow_wire_types_share_native_metadata_without_mutation(): void
    {
        $home = Home::make(Section::text('Hello'));
        $updated = $home->callbackId('home')->externalId('external')->privateMetadata('metadata');
        self::assertArrayNotHasKey('callback_id', $home->toArray());
        self::assertSame('home', $updated->toArray()['type']);
        self::assertSame('home', $updated->toArray()['callback_id']);
        $legacy = WorkflowStep::make()->submitDisabled(false);
        self::assertSame(['type' => 'workflow_step', 'blocks' => [], 'submit_disabled' => false], $legacy->toArray());
    }

    public function test_variadic_named_arguments_are_normalized_to_json_lists(): void
    {
        $home = Home::make(first: Section::text('First'), second: Section::text('Second'));
        self::assertTrue(array_is_list($home->toArray()['blocks']));
    }
}
