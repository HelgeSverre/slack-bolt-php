<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SlashCommand
{
    public function __construct(
        public string $name,
    ) {}
}
