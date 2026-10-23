<?php

declare(strict_types=1);

namespace Thunder\Bolt\Authorization;

use Thunder\Bolt\OAuth\InstallationStore;
use Thunder\Bolt\OAuth\TokenRotator;

final readonly class InstallationAuthorizer implements Authorizer
{
    public function __construct(
        private InstallationStore $store,
        private ?TokenRotator $rotator = null,
    ) {}

    public function authorize(AuthorizationSource $source): Authorization
    {
        $workspace = $source->query();
        $bot = $this->store->fetch($workspace->forBot());
        if ($bot !== null && $this->rotator !== null) {
            $bot = $this->rotator->refreshBot($workspace);
        }

        $user = null;
        if ($source->userId !== null) {
            $userQuery = $workspace->forUser($source->userId);
            $user = $this->store->fetch($userQuery);
            if ($user !== null && $this->rotator !== null) {
                $user = $this->rotator->refreshUser($userQuery);
            }
        }

        $botToken = self::token($bot?->botToken);
        $userToken = self::token($user?->userToken);
        if ($botToken === null && $userToken === null) {
            throw new AuthorizationException('No installation credential matches this Slack delivery.');
        }

        if ($botToken !== null && $bot !== null) {
            $userScopes = $userToken === null || $user === null ? [] : $user->userScopes;
            $userExpiresAt = $userToken === null || $user === null ? null : $user->userTokenExpiresAt;

            return new Authorization(
                botToken: $botToken,
                userToken: $userToken,
                botUserId: $bot->botUserId,
                botId: $bot->botId,
                userId: $source->userId,
                teamId: $source->teamId,
                enterpriseId: $source->enterpriseId,
                isEnterpriseInstall: $source->isEnterpriseInstall,
                botScopes: $bot->botScopes,
                userScopes: $userScopes,
                botTokenExpiresAt: $bot->botTokenExpiresAt,
                userTokenExpiresAt: $userExpiresAt,
            );
        }

        if ($user === null) {
            throw new AuthorizationException('No user installation matches this Slack delivery.');
        }

        return new Authorization(
            userToken: $userToken,
            botUserId: $user->botUserId,
            botId: $user->botId,
            userId: $source->userId,
            teamId: $source->teamId,
            enterpriseId: $source->enterpriseId,
            isEnterpriseInstall: $source->isEnterpriseInstall,
            userScopes: $user->userScopes,
            userTokenExpiresAt: $user->userTokenExpiresAt,
        );
    }

    private static function token(#[\SensitiveParameter] ?string $token): ?string
    {
        return $token === '' ? null : $token;
    }
}
