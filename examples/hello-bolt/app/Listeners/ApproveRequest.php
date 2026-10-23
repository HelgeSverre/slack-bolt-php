<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Action as ActionAttribute;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Reply;
use Thunder\Bolt\Responses\ActionResponse;

final class ApproveRequest
{
    #[ActionAttribute('approve_request', type: 'block_actions')]
    public function __invoke(Action $action, Reply $reply): ActionResponse
    {
        if ($action->responseUrl === null) {
            throw new \LogicException('The approve_request action requires Slack to provide a response_url.');
        }

        $reply->replaceOriginal('Approved. The request can move forward.');

        return ActionResponse::empty();
    }
}
