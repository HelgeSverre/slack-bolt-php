<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextUserMention extends RichTextLeaf
{
    private function __construct(
        private string $userId,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $userId): self
    {
        if ($userId === '') {
            throw new \InvalidArgumentException('A rich-text user ID is required.');
        }

        return new self($userId, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'user', 'user_id' => $this->userId];
        $this->addStyle($data, $this->textStyle);

        return $data;
    }

    protected function currentStyle(): RichTextStyle
    {
        return $this->textStyle;
    }

    protected function withStyle(RichTextStyle $style): static
    {
        return clone($this, ['textStyle' => $style]);
    }
}
