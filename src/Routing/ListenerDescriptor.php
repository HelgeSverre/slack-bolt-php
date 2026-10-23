<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

use Thunder\Bolt\Middleware\Middleware;

final readonly class ListenerDescriptor
{
    /** @param list<callable|Middleware> $middleware */
    private function __construct(
        public ListenerKind $kind,
        public string|Regex|InteractionConstraints $selector,
        public HandlerTarget $handler,
        public array $middleware = [],
        public string $source = 'fluent registration',
    ) {}

    /** @param list<callable|Middleware> $middleware */
    public static function event(
        string|Regex $type,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(ListenerKind::Event, $type, $handler, $middleware, $source);
    }

    /** @param list<callable|Middleware> $middleware */
    public static function message(
        string|Regex $selector,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(ListenerKind::Message, $selector, $handler, $middleware, $source);
    }

    /** @param list<callable|Middleware> $middleware */
    public static function command(
        string|Regex $name,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(ListenerKind::SlashCommand, $name, $handler, $middleware, $source);
    }

    /** @param list<callable|Middleware> $middleware */
    public static function action(
        string|Regex|InteractionConstraints $selector,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(
            ListenerKind::Action,
            InteractionConstraints::normalize(ListenerKind::Action, $selector),
            $handler,
            $middleware,
            $source,
        );
    }

    /** @param list<callable|Middleware> $middleware */
    public static function shortcut(
        string|Regex|InteractionConstraints $selector,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(
            ListenerKind::Shortcut,
            InteractionConstraints::normalize(ListenerKind::Shortcut, $selector),
            $handler,
            $middleware,
            $source,
        );
    }

    /** @param list<callable|Middleware> $middleware */
    public static function options(
        string|Regex|InteractionConstraints $selector,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(
            ListenerKind::Options,
            InteractionConstraints::normalize(ListenerKind::Options, $selector),
            $handler,
            $middleware,
            $source,
        );
    }

    /** @param list<callable|Middleware> $middleware */
    public static function view(
        string|Regex|InteractionConstraints $selector,
        HandlerTarget $handler,
        array $middleware = [],
        string $source = 'fluent registration',
    ): self {
        return new self(
            ListenerKind::View,
            InteractionConstraints::normalize(ListenerKind::View, $selector),
            $handler,
            $middleware,
            $source,
        );
    }

    public function key(): string
    {
        $selector = match (true) {
            $this->selector instanceof InteractionConstraints => 'constraints:' . $this->selector->key(),
            $this->selector instanceof Regex => 'regex:' . $this->selector->pattern,
            default => 'literal:' . $this->selector,
        };

        return implode('|', [$this->kind->value, $selector, $this->handler->key()]);
    }

    /** @return array{kind:string, selector:string, target:string, source:string} */
    public function summary(): array
    {
        return [
            'kind' => $this->kind->value,
            'selector' => match (true) {
                $this->selector instanceof InteractionConstraints => $this->selector->describe(),
                $this->selector instanceof Regex => $this->selector->pattern,
                default => $this->selector,
            },
            'target' => $this->handler->key(),
            'source' => $this->source,
        ];
    }
}
