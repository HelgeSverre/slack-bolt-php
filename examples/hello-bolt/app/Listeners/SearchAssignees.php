<?php

declare(strict_types=1);

namespace App\Listeners;

use Thunder\Bolt\Attributes\Options as OptionsAttribute;
use Thunder\Bolt\BlockKit\Option;
use Thunder\Bolt\Payloads\Options;
use Thunder\Bolt\Responses\OptionsResponse;

final class SearchAssignees
{
    /** @var array<string, string> */
    private const array ASSIGNEES = [
        'U_ADA_LOVELACE' => 'Ada Lovelace',
        'U_GRACE_HOPPER' => 'Grace Hopper',
        'U_MARGARET_HAMILTON' => 'Margaret Hamilton',
    ];

    #[OptionsAttribute('search_assignees', blockId: 'assignee', callbackId: 'submit_request', type: 'block_suggestion')]
    public function __invoke(Options $options): OptionsResponse
    {
        $query = mb_strtolower(trim($options->value));
        $matches = [];

        foreach (self::ASSIGNEES as $userId => $name) {
            if ($query === '' || str_contains(mb_strtolower($name), $query)) {
                $matches[] = Option::text($name, $userId);
            }
        }

        return OptionsResponse::options(...$matches);
    }
}
