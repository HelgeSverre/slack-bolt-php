<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** Launches a Slack workflow link trigger with optional custom inputs. */
final readonly class WorkflowButton implements Element
{
    /** @param list<array{name: string, value: string}> $inputs */
    private function __construct(
        private string $text,
        private string $triggerUrl,
        private array $inputs = [],
        private ?string $buttonStyle = null,
        private ?string $accessibilityLabel = null,
        private ?Confirm $confirmation = null,
    ) {}

    public static function make(string $text, string $triggerUrl): self
    {
        self::label($text);
        self::url($triggerUrl);

        return new self($text, $triggerUrl);
    }

    #[\NoDiscard]
    public function input(string $name, string $value): self
    {
        if ($name === '' || $value === '') {
            throw new \InvalidArgumentException('Workflow input names and values are required.');
        }

        return clone($this, ['inputs' => [...$this->inputs, ['name' => $name, 'value' => $value]]]);
    }

    #[\NoDiscard]
    public function primary(): self
    {
        return clone($this, ['buttonStyle' => 'primary']);
    }

    #[\NoDiscard]
    public function danger(): self
    {
        return clone($this, ['buttonStyle' => 'danger']);
    }

    #[\NoDiscard]
    public function accessibilityLabel(string $label): self
    {
        self::label($label);

        return clone($this, ['accessibilityLabel' => $label]);
    }

    #[\NoDiscard]
    public function confirm(Confirm $confirm): self
    {
        return clone($this, ['confirmation' => $confirm]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $trigger = ['url' => $this->triggerUrl];
        if ($this->inputs !== []) {
            $trigger['customizable_input_parameters'] = $this->inputs;
        }
        $data = [
            'type' => 'workflow_button',
            'text' => Text::plain($this->text)->toArray(),
            'workflow' => ['trigger' => $trigger],
        ];
        if ($this->buttonStyle !== null) {
            $data['style'] = $this->buttonStyle;
        }
        if ($this->accessibilityLabel !== null) {
            $data['accessibility_label'] = $this->accessibilityLabel;
        }
        if ($this->confirmation !== null) {
            $data['confirm'] = $this->confirmation->toArray();
        }

        return $data;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function label(string $value): void
    {
        if ($value === '' || mb_strlen($value) > 75) {
            throw new \InvalidArgumentException('A workflow button label needs between 1 and 75 characters.');
        }
    }

    private static function url(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('A workflow button needs a valid trigger URL.');
        }
    }
}
