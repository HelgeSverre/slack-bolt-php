<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

use Thunder\Bolt\Exceptions\InvalidPayload;
use Thunder\Bolt\Payloads\Values\EventFields;

final readonly class ContextEntity
{
    public const string CHANNEL = 'slack#/types/channel_id';
    public const string CANVAS = 'slack#/types/canvas_id';
    public const string LIST = 'slack#/types/list_id';
    public const string MESSAGE = 'slack#/types/message_context';

    private function __construct(
        public string $type,
        public mixed $value,
        public ?string $teamId,
        public ?string $enterpriseId,
        public array $raw,
    ) {}

    public static function fromArray(array $body): self
    {
        $type = EventFields::text($body, 'type');
        if (!array_key_exists('value', $body)) {
            throw new InvalidPayload('A context entity requires a value.');
        }
        if (in_array($type, [self::CHANNEL, self::CANVAS, self::LIST], true)) {
            EventFields::text($body, 'value');
        } elseif ($type === self::MESSAGE) {
            $value = EventFields::object($body, 'value');
            EventFields::text($value, 'channel_id');
            EventFields::text($value, 'message_ts');
        }
        return new self(
            $type,
            $body['value'],
            EventFields::optionalText($body, 'team_id'),
            EventFields::optionalText($body, 'enterprise_id'),
            $body,
        );
    }

    public function channelId(): ?string
    {
        if ($this->type === self::CHANNEL && is_string($this->value)) {
            return $this->value;
        }
        return $this->type === self::MESSAGE && is_array($this->value) && is_string($this->value['channel_id'] ?? null)
            ? $this->value['channel_id']
            : null;
    }

    public function messageTs(): ?string
    {
        return $this->type === self::MESSAGE && is_array($this->value) && is_string($this->value['message_ts'] ?? null)
            ? $this->value['message_ts']
            : null;
    }

    public function toArray(): array
    {
        return $this->raw;
    }
}
