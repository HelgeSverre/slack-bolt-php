<?php

declare(strict_types=1);

namespace Thunder\Bolt\BlockKit;

/** A typed inline element that can appear inside a Slack rich-text container. */
interface RichTextElement extends \JsonSerializable
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
