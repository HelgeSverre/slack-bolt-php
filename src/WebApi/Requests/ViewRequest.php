<?php

declare(strict_types=1);

namespace Thunder\Bolt\WebApi\Requests;

use Thunder\Bolt\Views\Home;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\RequestPayload;

/** A native view payload for one of Slack's view operations. */
final readonly class ViewRequest implements RequestPayload
{
    private const string OPEN = 'open';
    private const string PUSH = 'push';
    private const string PUBLISH = 'publish';
    private const string UPDATE = 'update';

    private function __construct(
        private string $operation,
        private Modal|Home $view,
        private ?string $triggerId = null,
        private ?string $userId = null,
        private ?string $viewId = null,
        private ?string $externalId = null,
        private ?string $hash = null,
    ) {}

    public static function open(string $triggerId, Modal $view): self
    {
        self::required($triggerId, 'triggerId');

        return new self(self::OPEN, $view, triggerId: $triggerId);
    }

    public static function push(string $triggerId, Modal $view): self
    {
        self::required($triggerId, 'triggerId');

        return new self(self::PUSH, $view, triggerId: $triggerId);
    }

    public static function publish(string $userId, Home $view): self
    {
        self::required($userId, 'userId');

        return new self(self::PUBLISH, $view, userId: $userId);
    }

    public static function update(string $viewId, Modal $view): self
    {
        self::required($viewId, 'viewId');

        return new self(self::UPDATE, $view, viewId: $viewId);
    }

    public static function updateByExternalId(string $externalId, Modal $view): self
    {
        self::required($externalId, 'externalId');
        if (mb_strlen($externalId) > 255) {
            throw new \InvalidArgumentException('externalId must not exceed 255 characters.');
        }

        return new self(self::UPDATE, $view, externalId: $externalId);
    }

    #[\NoDiscard]
    public function withHash(string $hash): self
    {
        if (!in_array($this->operation, [self::PUBLISH, self::UPDATE], true)) {
            throw new \LogicException('Only views.publish and views.update accept a view hash.');
        }
        self::required($hash, 'hash');

        return clone($this, ['hash' => $hash]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $view = $this->view->toArray();

        return match ($this->operation) {
            self::OPEN, self::PUSH => ['trigger_id' => $this->triggerId, 'view' => $view],
            self::PUBLISH => [
                'user_id' => $this->userId,
                'view' => $view,
                ...($this->hash !== null ? ['hash' => $this->hash] : []),
            ],
            self::UPDATE => [
                'view' => $view,
                ...($this->viewId !== null ? ['view_id' => $this->viewId] : ['external_id' => $this->externalId]),
                ...($this->hash !== null ? ['hash' => $this->hash] : []),
            ],
            default => throw new \LogicException('Unknown view request operation.'),
        };
    }

    private static function required(string $value, string $name): void
    {
        if ($value === '') {
            throw new \InvalidArgumentException("{$name} cannot be empty.");
        }
    }
}
