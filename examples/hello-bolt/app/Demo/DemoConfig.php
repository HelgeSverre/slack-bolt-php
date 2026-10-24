<?php

declare(strict_types=1);

namespace App\Demo;

final readonly class DemoConfig
{
    /** @param list<string> $terminalUsers */
    public function __construct(
        public string $directory,
        public array $terminalUsers = [],
        #[\SensitiveParameter]
        public string $openAiKey = '',
        public string $openAiModel = 'gpt-5.5',
        public string $workObjectBaseUrl = 'https://example.com/thunder-bolt/work-items',
    ) {}

    public function queue(): Queue
    {
        return new Queue($this->directory . '/jobs');
    }

    public function allowsTerminal(string $userId): bool
    {
        return $userId !== '' && in_array($userId, $this->terminalUsers, true);
    }

    public function __debugInfo(): array
    {
        return [
            'directory' => $this->directory,
            'terminalUsers' => $this->terminalUsers,
            'openAiKey' => $this->openAiKey === '' ? '' : '[redacted]',
            'openAiModel' => $this->openAiModel,
        ];
    }
}
