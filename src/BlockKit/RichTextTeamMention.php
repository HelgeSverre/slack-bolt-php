<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class RichTextTeamMention extends RichTextLeaf
{
    private function __construct(
        private string $teamId,
        private RichTextStyle $textStyle,
    ) {}

    public static function for(string $teamId): self
    {
        if ($teamId === '') {
            throw new \InvalidArgumentException('A rich-text team ID is required.');
        }

        return new self($teamId, RichTextStyle::none());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = ['type' => 'team', 'team_id' => $this->teamId];
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
