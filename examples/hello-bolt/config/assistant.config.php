<?php

declare(strict_types=1);

use App\Demo\AssistantDemo;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;

return Assistant::make()
    ->threadStarted([AssistantDemo::class, 'started'])
    ->threadContextChanged([AssistantDemo::class, 'contextChanged'])
    ->userMessage([AssistantDemo::class, 'message'])
    ->contextStore(new StoredThreadContextStore(
        new FileConversationStore(getcwd() . '/.thunder-bolt/demo/assistant-context'),
    ));
