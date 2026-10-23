<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

enum AttachmentMarkdown: string
{
    case Pretext = 'pretext';
    case Text = 'text';
    case Fields = 'fields';
}
