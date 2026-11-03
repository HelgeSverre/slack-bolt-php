<?php

declare(strict_types=1);

use App\Demo\AgentViewDemo;
use Thunder\Bolt\Assistant;

return Assistant::forAgentView()
    ->opened([AgentViewDemo::class, 'opened'])
    ->userMessage([AgentViewDemo::class, 'message']);
