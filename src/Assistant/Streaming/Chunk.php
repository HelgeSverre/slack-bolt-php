<?php

declare(strict_types=1);

namespace Thunder\Bolt\Assistant\Streaming;

interface Chunk extends \JsonSerializable
{
    public function toArray(): array;
}
