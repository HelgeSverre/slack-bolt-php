<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\View as ViewAttribute;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ViewResponse;

final class SubmitRequest
{
    #[ViewAttribute('submit_request')]
    public function __invoke(View $view): ViewResponse
    {
        $summary = $view->value('summary', 'title');
        if (!is_string($summary) || trim($summary) === '') {
            return ViewResponse::errors(['summary' => 'Please describe the request.']);
        }

        return ViewResponse::empty();
    }
}
