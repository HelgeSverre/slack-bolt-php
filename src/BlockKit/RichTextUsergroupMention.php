<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextUsergroupMention extends RichTextLeaf
{
    private function __construct(
        private string $usergroupId,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $usergroupId): self
    {
        if ($usergroupId === '') {
            throw new \InvalidArgumentException('A rich-text usergroup ID is required.');
        }

        return new self($usergroupId, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'usergroup', 'usergroup_id' => $this->usergroupId];
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
