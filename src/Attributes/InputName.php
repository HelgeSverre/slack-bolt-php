<?php

declare(strict_types=1);

namespace Thunder\Bolt\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class InputName
{
    public function __construct(
        public string $name,
    ) {
        if (trim($name) === '') {
            throw new \InvalidArgumentException('An input name cannot be empty.');
        }
    }
}
