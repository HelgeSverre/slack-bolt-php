<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

final readonly class Section implements Block
{
    /** @param list<Text> $fields */ private function __construct(
        private ?Text $text = null,
        private array $fields = [],
        private ?Element $accessory = null,
        private ?string $blockId = null,
        private ?bool $alwaysExpand = null,
    ) {}

    public static function markdown(string|Text $text): self
    {
        return new self(is_string($text) ? Text::markdown($text) : $text);
    }

    public static function text(string|Text $text): self
    {
        return new self(is_string($text) ? Text::plain($text) : $text);
    }

    public static function fields(Text ...$fields): self
    {
        if ($fields === [] || count($fields) > 10)
            throw new \InvalidArgumentException('A section needs between 1 and 10 fields.');
        return new self(fields: array_values($fields));
    }

    #[\NoDiscard]
    public function withFields(Text ...$fields): self
    {
        if ($fields === [] || count($fields) > 10)
            throw new \InvalidArgumentException('A section needs between 1 and 10 fields.');
        return clone($this, ['fields' => array_values($fields)]);
    }

    #[\NoDiscard]
    public function accessory(Element $element): self
    {
        $type = $element->toArray()['type'] ?? '';
        $allowed = [
            'button',
            'checkboxes',
            'datepicker',
            'image',
            'overflow',
            'radio_buttons',
            'timepicker',
            'workflow_button',
            'static_select',
            'external_select',
            'users_select',
            'channels_select',
            'conversations_select',
            'multi_static_select',
            'multi_external_select',
            'multi_users_select',
            'multi_channels_select',
            'multi_conversations_select',
        ];
        if (!in_array($type, $allowed, true) && !$element instanceof RawElement) {
            throw new \InvalidArgumentException('This element cannot be a section accessory.');
        }
        return clone($this, ['accessory' => $element]);
    }

    #[\NoDiscard]
    public function expand(bool $enabled = true): self
    {
        return clone($this, ['alwaysExpand' => $enabled]);
    }

    #[\NoDiscard]
    public function id(string $id): self
    {
        if ($id === '' || mb_strlen($id) > 255)
            throw new \InvalidArgumentException('A block ID needs between 1 and 255 characters.');
        return clone($this, ['blockId' => $id]);
    }

    public function toArray(): array
    {
        if ($this->text === null && $this->fields === [])
            throw new \LogicException('A section needs text or fields.');
        $d = ['type' => 'section'];
        if ($this->text !== null)
            $d['text'] = $this->text->toArray();
        if ($this->fields !== [])
            $d['fields'] = array_map(static fn(Text $f) => $f->toArray(), $this->fields);
        foreach ($this->fields as $field) {
            if (mb_strlen($field->value) > 2000) {
                throw new \InvalidArgumentException('Section fields must not exceed 2000 characters.');
            }
        }
        if ($this->accessory !== null)
            $d['accessory'] = $this->accessory->toArray();
        if ($this->blockId !== null)
            $d['block_id'] = $this->blockId;
        if ($this->alwaysExpand !== null)
            $d['expand'] = $this->alwaysExpand;
        return $d;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
