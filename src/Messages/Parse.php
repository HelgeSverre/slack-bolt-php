<?php

declare(strict_types=1);

namespace Thunder\Bolt\Messages;

enum Parse: string
{
    case Full = 'full';
    case None = 'none';
}
