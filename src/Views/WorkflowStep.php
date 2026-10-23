<?php

declare(strict_types=1);

namespace Thunder\Bolt\Views;

use Thunder\Bolt\BlockKit\Block;

/** Wire support for the pinned SDK's legacy workflow_step view. */
final readonly class WorkflowStep implements View
{
    private function __construct(
        private Home $content,
        private ?bool $disabled = null,
    ) {}

    public static function make(Block ...$blocks): self
    {
        return new self(Home::make(...$blocks));
    }

    #[\NoDiscard]
    public function callbackId(string $id): self
    {
        return clone($this, ['content' => $this->content->callbackId($id)]);
    }

    #[\NoDiscard]
    public function privateMetadata(string $metadata): self
    {
        return clone($this, ['content' => $this->content->privateMetadata($metadata)]);
    }

    #[\NoDiscard]
    public function externalId(string $id): self
    {
        return clone($this, ['content' => $this->content->externalId($id)]);
    }

    #[\NoDiscard]
    public function submitDisabled(bool $disabled = true): self
    {
        return clone($this, ['disabled' => $disabled]);
    }

    public function toArray(): array
    {
        $data = $this->content->toArray();
        $data['type'] = 'workflow_step';
        if ($this->disabled !== null) {
            $data['submit_disabled'] = $this->disabled;
        }
        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
