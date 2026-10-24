<?php

declare(strict_types=1);

namespace Thunder\Bolt\Payloads\Events;

use Thunder\Bolt\Exceptions\InvalidPayload;

final readonly class AgentSessionTitleChanged extends AgentSessionEvent
{
    public string $title;
    public ?string $previousTitle;
    public ?string $teamId;
    public ?string $enterpriseId;

    public function __construct(array $body)
    {
        parent::__construct($body);
        if (!is_string($body['title'] ?? null)) {
            throw new InvalidPayload('An agent session title changed event requires a title.');
        }
        foreach (['previous_title', 'team_id', 'enterprise_id'] as $field) {
            if (isset($body[$field]) && !is_string($body[$field])) {
                throw new InvalidPayload('An agent session title changed event has an invalid optional identifier.');
            }
        }
        $this->title = $body['title'];
        $this->previousTitle = is_string($body['previous_title'] ?? null) ? $body['previous_title'] : null;
        $this->teamId = is_string($body['team_id'] ?? null) ? $body['team_id'] : null;
        $this->enterpriseId = is_string($body['enterprise_id'] ?? null) ? $body['enterprise_id'] : null;
    }

    public static function fromArray(array $body): self
    {
        return new self($body);
    }
}
