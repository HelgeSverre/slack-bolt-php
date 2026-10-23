<?php

declare(strict_types=1);

namespace Thunder\Bolt;

use Thunder\Bolt\OAuth\OAuthConfig;
use Thunder\Bolt\SocketMode\Config as SocketModeConfig;

final readonly class BoltConfig
{
    public function __construct(
        #[\SensitiveParameter]
        public string $token = '',
        #[\SensitiveParameter]
        public string $signingSecret = '',
        public bool $ignoreSelf = true,
        public ?string $botUserId = null,
        public int $maxBodyBytes = 4_194_304,
        public int $maxTimestampSkewSeconds = 300,
        public ?OAuthConfig $oauth = null,
        public ?string $installationStorePath = null,
        public string $conversationStoreDriver = 'file',
        public ?string $conversationStorePath = null,
        public ?SocketModeConfig $socketMode = null,
    ) {
        if ($maxBodyBytes < 1 || $maxTimestampSkewSeconds < 0) {
            throw new \InvalidArgumentException(
                'Body limit must be positive and timestamp tolerance must not be negative.',
            );
        }
        if (!in_array($conversationStoreDriver, ['file', 'sqlite', 'memory', 'disabled'], true)) {
            throw new \InvalidArgumentException('Conversation storage must use file, sqlite, memory, or disabled.');
        }
    }
}
