<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant;

final readonly class SuggestedPrompt implements \JsonSerializable
{
    public function __construct(
        public string $title,
        public string $message,
    ) {
        foreach ([$title, $message] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 3000) {
                throw new \InvalidArgumentException('Assistant prompt text must contain 1 to 3000 characters.');
            }
        }
    }

    /** @return array{title:string, message:string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'message' => $this->message];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
