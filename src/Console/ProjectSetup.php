<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

/** The project's onboarding defaults; delivery configuration still belongs to BoltConfig. */
final readonly class ProjectSetup
{
    public function __construct(
        public string $transport = 'http',
        public string $authorization = 'token',
        public string $profile = 'mentions',
    ) {
        if (!in_array($transport, ['http', 'socket'], true)) {
            throw new \InvalidArgumentException('Transport must be http or socket.');
        }
        if (!in_array($authorization, ['token', 'oauth'], true)) {
            throw new \InvalidArgumentException('Authorization must be token or oauth.');
        }
        if (StarterProfile::tryFrom($profile) === null) {
            throw new \InvalidArgumentException('Profile must be mentions, interactive or agent.');
        }
    }

    public function startCommand(): string
    {
        return $this->transport === 'http' ? 'php bolt serve' : 'php bolt socket:serve';
    }
}
