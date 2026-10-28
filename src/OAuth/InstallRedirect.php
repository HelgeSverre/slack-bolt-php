<?php

declare(strict_types=1);

namespace Thunder\Bolt\OAuth;

final readonly class InstallRedirect
{
    public function __construct(
        public string $authorizationUrl,
        #[\SensitiveParameter]
        public string $cookieValue,
        public int $expiresAt,
        public int $maxAge,
        public string $cookieName = Installer::STATE_COOKIE,
    ) {}

    public function __debugInfo(): array
    {
        return [
            'authorizationUrl' => $this->authorizationUrl,
            'cookieValue' => '[redacted]',
            'expiresAt' => $this->expiresAt,
            'maxAge' => $this->maxAge,
        ];
    }
}
