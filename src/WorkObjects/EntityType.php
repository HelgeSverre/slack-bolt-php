<?php

declare(strict_types=1);

namespace Thunder\Bolt\WorkObjects;

enum EntityType: string
{
    case File = 'slack#/entities/file';
    case Task = 'slack#/entities/task';
    case Incident = 'slack#/entities/incident';
    case ContentItem = 'slack#/entities/content_item';
    case CalendarEvent = 'slack#/entities/calendar_event';
    case Item = 'slack#/entities/item';
}
