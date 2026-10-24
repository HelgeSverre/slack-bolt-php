<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

final readonly class ThreadContext implements \JsonSerializable
{
    public function __construct(
        public ?string $channelId = null,
        public ?string $teamId = null,
        public ?string $enterpriseId = null,
        private array $extra = [],
    ) {
        foreach (array_keys($extra) as $key) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Assistant context keys must be non-empty strings.');
            }
        }
        json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public static function fromArray(array $context): self
    {
        $channelId = null;
        $teamId = null;
        $enterpriseId = null;
        $extra = [];
        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Assistant context keys must be non-empty strings.');
            }
            if (in_array($key, ['channel_id', 'team_id', 'enterprise_id'], true)) {
                if ($value !== null && !is_string($value)) {
                    throw new \InvalidArgumentException("Assistant context {$key} must be a string.");
                }
                match ($key) {
                    'channel_id' => $channelId = $value,
                    'team_id' => $teamId = $value,
                    'enterprise_id' => $enterpriseId = $value,
                };
                continue;
            }
            $extra[$key] = $value;
        }

        return new self(channelId: $channelId, teamId: $teamId, enterpriseId: $enterpriseId, extra: $extra);
    }

    public function toArray(): array
    {
        return [
            ...$this->extra,
            ...($this->channelId !== null ? ['channel_id' => $this->channelId] : []),
            ...($this->teamId !== null ? ['team_id' => $this->teamId] : []),
            ...($this->enterpriseId !== null ? ['enterprise_id' => $this->enterpriseId] : []),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
