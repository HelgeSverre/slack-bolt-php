<?php

declare(strict_types=1);

namespace Thunder\Bolt\Routing;

enum ListenerKind: string
{
    case Event = 'event';
    case Function = 'function';
    case Message = 'message';
    case SlashCommand = 'slash_command';
    case Action = 'action';
    case Shortcut = 'shortcut';
    case Options = 'options';
    case View = 'view';
}
