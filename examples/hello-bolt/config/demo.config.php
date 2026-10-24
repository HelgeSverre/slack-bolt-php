<?php

declare(strict_types=1);

use App\Demo\DemoConfig;

use function Tempest\env;

return new DemoConfig(
    directory: getcwd() . '/.thunder-bolt/demo',
    terminalUsers: array_values(array_filter(array_map('trim', explode(
        ',',
        (string) env('SLACK_TERMINAL_ALLOWED_USERS', ''),
    )))),
    openAiKey: (string) env('OPENAI_API_KEY', ''),
    openAiModel: (string) env('OPENAI_MODEL', 'gpt-5.5'),
    workObjectBaseUrl: (string) env('SLACK_WORK_OBJECT_BASE_URL', 'https://example.com/thunder-bolt/work-items'),
);
