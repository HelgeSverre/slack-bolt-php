<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

/** One source of truth for a starter's permissions, events and generated files. */
enum StarterProfile: string
{
    case Mentions = 'mentions';
    case Interactive = 'interactive';
    case Agent = 'agent';

    /** @return list<string> */
    public function scopes(): array
    {
        return match ($this) {
            self::Mentions => ['app_mentions:read', 'chat:write'],
            self::Interactive => ['commands', 'chat:write'],
            self::Agent => ['assistant:write', 'chat:write', 'im:history'],
        };
    }

    /** @return array<string, string> Target path => stub name. */
    public function files(): array
    {
        return match ($this) {
            self::Mentions => ['app/Listeners/GreetOnMention.php' => 'mention-listener.php.stub'],
            self::Interactive => ['app/Listeners/InteractiveApp.php' => 'interactive-listener.php.stub'],
            self::Agent => [
                'app/Listeners/WorkspaceAgent.php' => 'agent-listener.php.stub',
                'config/assistant.config.php' => 'agent.config.php.stub',
            ],
        };
    }

    public function manifest(ProjectSetup $setup): string
    {
        $features = "  bot_user:\n    display_name: Thunder Bolt\n";
        if ($this === self::Interactive) {
            $features .= "  app_home:\n    home_tab_enabled: true\n    messages_tab_enabled: true\n    messages_tab_read_only_enabled: true\n  slash_commands:\n    - command: /bolt\n      description: Open the Thunder Bolt starter\n      should_escape: true\n";
            if ($setup->transport === 'http') {
                $features .= "      url: https://example.test/slack/events\n";
            }
        } elseif ($this === self::Agent) {
            $features .= "  agent_view:\n    agent_description: Ask questions and explore your Slack context.\n  app_home:\n    messages_tab_enabled: true\n    messages_tab_read_only_enabled: false\n";
        }
        $oauth = '';
        if ($setup->authorization === 'oauth') {
            $oauth .= "  redirect_urls:\n    - https://example.test/slack/oauth_redirect\n";
        }
        $oauth .= "  scopes:\n    bot:\n";
        foreach ($this->scopes() as $scope) {
            $oauth .= "      - {$scope}\n";
        }
        $settings = '  socket_mode_enabled: ' . ($setup->transport === 'socket' ? 'true' : 'false') . "\n";
        if ($this === self::Interactive) {
            $settings .= "  interactivity:\n    is_enabled: true\n";
            if ($setup->transport === 'http') {
                $settings .= "    request_url: https://example.test/slack/events\n";
            }
        }
        $settings .= "  event_subscriptions:\n";
        if ($setup->transport === 'http') {
            $settings .= "    request_url: https://example.test/slack/events\n";
        }
        $events = match ($this) {
            self::Mentions => ['app_mention'],
            self::Interactive => ['app_home_opened'],
            self::Agent => ['app_home_opened', 'app_context_changed', 'message.im', 'agent_session_stopped'],
        };
        $settings .= "    bot_events:\n";
        foreach ($events as $event) {
            $settings .= "      - {$event}\n";
        }

        return "_metadata:\n  major_version: 1\ndisplay_information:\n  name: Thunder Bolt App\nfeatures:\n{$features}oauth_config:\n{$oauth}settings:\n{$settings}";
    }

    public function instructions(): string
    {
        return match ($this) {
            self::Mentions => 'Invite the app to a channel and mention it to receive a reply.',
            self::Interactive
                => 'Open App Home or run /bolt. Choose Open form, submit a name, and receive a private confirmation. Interactivity is enabled in the manifest; HTTP uses /slack/events for commands and interactions.',
            self::Agent
                => 'Open the app’s Messages tab and send a message. The Agent View starter uses typed streaming and session Stop handling; no AI provider key is required for its example reply. Agent features depend on Slack workspace access. This profile targets a new app; do not migrate an existing Assistant View app without reviewing Slack’s migration rules.',
        };
    }
}
