<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Actions implements Block
{
    /** @param list<Element> $elements */
    private function __construct(
        private array $elements,
        private ?string $blockId = null,
    ) {}

    public static function make(Element ...$elements): self
    {
        if (count($elements) < 1 || count($elements) > 25) {
            throw new \InvalidArgumentException('An actions block needs between 1 and 25 elements.');
        }
        foreach ($elements as $element) {
            self::assertAllowed($element);
        }

        return new self(array_values($elements));
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        if ($id === '' || mb_strlen($id) > 255) {
            throw new \InvalidArgumentException('An actions block ID needs between 1 and 255 characters.');
        }

        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        return array_filter(
            [
                'type' => 'actions',
                'block_id' => $this->blockId,
                'elements' => array_map(static fn(Element $element): array => $element->toArray(), $this->elements),
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function assertAllowed(Element $element): void
    {
        $data = $element->toArray();
        if (!is_string($data['type'] ?? null) || $data['type'] === '') {
            throw new \InvalidArgumentException('An actions block element needs a type.');
        }
        $type = $data['type'];

        $allowed = [
            'button',
            'checkboxes',
            'datepicker',
            'datetimepicker',
            'multi_users_select',
            'multi_static_select',
            'multi_conversations_select',
            'multi_channels_select',
            'multi_external_select',
            'overflow',
            'radio_buttons',
            'static_select',
            'users_select',
            'conversations_select',
            'channels_select',
            'external_select',
            'timepicker',
            'workflow_button',
            'rich_text_input',
        ];
        if (in_array($type, $allowed, true)) {
            return;
        }
        if ($element instanceof RawElement && !in_array($type, self::knownNonActionTypes(), true)) {
            return;
        }

        throw new \InvalidArgumentException("{$type} cannot be used in an actions block.");
    }

    private static function knownNonActionTypes(): array
    {
        return [
            'plain_text_input',
            'email_text_input',
            'url_text_input',
            'number_input',
            'file_input',
            'image',
            'multi_select',
            'icon_button',
            'feedback_buttons',
            'url_source',
        ];
    }
}
