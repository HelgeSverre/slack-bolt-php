<?php

declare(strict_types=1);

namespace Thunder\Bolt\Errors;

use Thunder\Bolt\Authorization\AuthorizationSource;
use Thunder\Bolt\Invocation\Delivery;

final readonly class FailureContext implements \JsonSerializable
{
    public function __construct(
        public \Throwable $error,
        public FailureStage $stage,
        public ?Delivery $delivery = null,
        public ?AuthorizationSource $source = null,
        public array $metadata = [],
        public bool $acknowledgementPrepared = false,
    ) {}

    public function summary(): array
    {
        $source = $this->source ?? $this->delivery?->authorizationSource;
        return [
            'error' => $this->error::class,
            'stage' => $this->stage->value,
            'listener' => $this->delivery?->listener?->source,
            'team_id' => $source?->teamId,
            'enterprise_id' => $source?->enterpriseId,
            'channel_id' => $source?->conversationId,
            'user_id' => $source?->userId,
            'event_id' => self::identifier($this->metadata['eventId'] ?? null),
            'envelope_id' => self::identifier($this->metadata['envelopeId'] ?? null),
            'transport' => self::identifier($this->metadata['transport'] ?? null),
            'acknowledgement_prepared' => $this->acknowledgementPrepared,
            'acknowledgement_sent' => false,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->summary();
    }

    /** @internal Reflects buffered responses before policy execution, never a sent acknowledgement. */
    public function withAcknowledgementPrepared(bool $prepared): self
    {
        return clone($this, ['acknowledgementPrepared' => $prepared]);
    }

    public function __debugInfo(): array
    {
        return $this->summary();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Do not serialize live failure context. Use its redacted summary.');
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
