<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Views;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\Views\Modal;

final class ModalTest extends TestCase
{
    public function test_modal_can_be_used_for_view_update_response(): void
    {
        $modal = Modal::make('Edit', Section::markdown('*Updated*'))
            ->callbackId('edit')
            ->submit('Save')
            ->close('Cancel');
        $response = ViewResponse::update($modal)->toArray();
        self::assertSame('modal', $response['view']['type']);
        self::assertSame('update', $response['response_action']);
    }
}
