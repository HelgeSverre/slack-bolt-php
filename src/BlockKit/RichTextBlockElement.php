<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** A typed rich-text container that can appear in a rich-text block. */
interface RichTextBlockElement extends \JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
