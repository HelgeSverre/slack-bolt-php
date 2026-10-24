<?php

declare(strict_types=1);

namespace App\Demo\Jobs;

use App\Demo\DemoConfig;
use App\Demo\ThreadState;
use Tempest\CommandBus\CommandHandler;
use Thunder\Bolt\Assistant\Stores\StoredThreadContextStore;
use Thunder\Bolt\Assistant\SuggestedPrompt;
use Thunder\Bolt\Assistant\Thread;
use Thunder\Bolt\Assistant\ThreadIdentity;
use Thunder\Bolt\Conversation\ConversationKey;
use Thunder\Bolt\Conversation\Stores\FileConversationStore;
use Thunder\Bolt\Jobs\JobContext;
use Thunder\Bolt\Jobs\JobExecution;
use Thunder\Bolt\WebApi\Client;

final readonly class StartAssistantSessionHandler
{
    public function __construct(
        private DemoConfig $config,
        private Client $client,
        private JobContext $context,
        private JobExecution $execution,
    ) {}

    #[CommandHandler]
    public function handle(StartAssistantSession $job): void
    {
        $team = $this->context->teamId ?? throw new \LogicException('Assistant onboarding needs a workspace.');
        $channel = $this->context->channelId ?? throw new \LogicException('Assistant onboarding needs a channel.');
        $threadTs = $this->context->threadTs ?? throw new \LogicException('Assistant onboarding needs a thread.');
        $user = $this->context->userId ?? throw new \LogicException('Assistant onboarding needs a user.');
        $thread = new Thread(
            $this->client,
            new ThreadIdentity(new ConversationKey($channel, teamId: $team, threadTs: $threadTs), $user),
            new StoredThreadContextStore(new FileConversationStore($this->config->directory . '/assistant-context')),
        );
        $this->execution->checkpoint();
        $state = new ThreadState($this->config->directory)->update(
            $team,
            $channel,
            $threadTs,
            static fn(array $state): array => $state,
        );
        $thread->session()->processing(($state['user_title'] ?? false) === true ? null : 'Thunder Bolt');
        try {
            $thread->setSuggestedPrompts([
                new SuggestedPrompt('What can you do?', 'What can you help me with in Slack?'),
                new SuggestedPrompt('Check Boltling', 'How is Boltling doing?'),
                new SuggestedPrompt('Search the web', 'Search the web for a useful answer.'),
            ], 'Try asking');
            $this->execution->checkpoint();
            $thread->say('Ready. Ask a question, search the web, or ask about Boltling.');
        } finally {
            $thread->session()->active();
        }
    }
}
