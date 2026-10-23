<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

interface Block extends \JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
