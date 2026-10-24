<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Support;

final class FunctionPayloads
{
    /** @return array<string, mixed> */
    public static function executed(string $callbackId = 'collect_feedback'): array
    {
        return [
            'type' => 'event_callback',
            'team_id' => 'T-function',
            'event_id' => 'Ev-function',
            'event' => [
                'type' => 'function_executed',
                'function' => [
                    'id' => 'Fn-feedback',
                    'callback_id' => $callbackId,
                ],
                'inputs' => [
                    'summary_text' => 'Ship this',
                    'retry_count' => 2,
                ],
                'function_execution_id' => 'Fx-function',
                'workflow_execution_id' => 'Wx-function',
                'bot_access_token' => 'xwfp-function-token',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function action(): array
    {
        return [
            'type' => 'block_actions',
            'team' => ['id' => 'T-function'],
            'user' => ['id' => 'U-function'],
            'container' => ['type' => 'message', 'channel_id' => 'C-function', 'message_ts' => '1.000001'],
            'actions' => [['action_id' => 'approve_feedback', 'block_id' => 'feedback', 'type' => 'button']],
            'function_data' => self::functionData(),
            'bot_access_token' => 'xwfp-interaction-token',
        ];
    }

    /** @return array<string, mixed> */
    public static function viewSubmission(): array
    {
        return [
            'type' => 'view_submission',
            'team' => ['id' => 'T-function'],
            'user' => ['id' => 'U-function'],
            'view' => [
                'id' => 'V-function',
                'type' => 'modal',
                'callback_id' => 'feedback_modal',
                'state' => ['values' => []],
            ],
            'function_data' => self::functionData(),
            'bot_access_token' => 'xwfp-interaction-token',
        ];
    }

    /** @return array<string, mixed> */
    public static function viewClosed(): array
    {
        return [
            'type' => 'view_closed',
            'team' => ['id' => 'T-function'],
            'user' => ['id' => 'U-function'],
            'view' => [
                'id' => 'V-function',
                'type' => 'modal',
                'callback_id' => 'feedback_modal',
                'state' => ['values' => []],
            ],
            'function_data' => self::functionData(),
            'bot_access_token' => 'xwfp-interaction-token',
        ];
    }

    /** @return array<string, mixed> */
    public static function blockSuggestion(): array
    {
        return [
            'type' => 'block_suggestion',
            'team' => ['id' => 'T-function'],
            'user' => ['id' => 'U-function'],
            'view' => ['id' => 'V-function', 'type' => 'modal', 'callback_id' => 'feedback_modal'],
            'action_id' => 'feedback_search',
            'block_id' => 'feedback',
            'value' => 'ship',
            'function_data' => self::functionData(),
            'bot_access_token' => 'xwfp-interaction-token',
        ];
    }

    /** @return array<string, mixed> */
    private static function functionData(): array
    {
        return [
            'execution_id' => 'Fx-interaction',
            'callback_id' => 'collect_feedback',
            'function_id' => 'Fn-feedback',
            'workflow_execution_id' => 'Wx-function',
            'inputs' => ['summary_text' => 'Ship this', 'retry_count' => 2],
        ];
    }
}
