<?php

declare(strict_types=1);

use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\OAuth\OAuthConfig;

use function Tempest\env;

$clientId = (string) env('SLACK_CLIENT_ID', '');
$clientSecret = (string) env('SLACK_CLIENT_SECRET', '');
$redirectUri = (string) env('SLACK_OAUTH_REDIRECT_URI', '');
$scopes = static function (string $name): array {
    $value = env($name, '');

    return is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)))) : [];
};
$botUserId = env('SLACK_BOT_USER_ID');
$installationStorePath = env('SLACK_INSTALLATION_STORE_PATH');
$conversationStorePath = env('SLACK_CONTEXT_STORE_PATH');

return new BoltConfig(
    token: (string) env('SLACK_BOT_TOKEN', ''),
    signingSecret: (string) env('SLACK_SIGNING_SECRET', ''),
    ignoreSelf: filter_var(env('SLACK_IGNORE_SELF', 'true'), FILTER_VALIDATE_BOOL),
    botUserId: is_string($botUserId) && $botUserId !== '' ? $botUserId : null,
    oauth: $clientId !== '' && $clientSecret !== '' && $redirectUri !== ''
        ? new OAuthConfig(
            $clientId,
            $clientSecret,
            $redirectUri,
            $scopes('SLACK_BOT_SCOPES'),
            $scopes('SLACK_USER_SCOPES'),
        )
        : null,
    installationStorePath: is_string($installationStorePath) && $installationStorePath !== ''
        ? $installationStorePath
        : null,
    conversationStoreDriver: (string) env('SLACK_CONTEXT_STORE', 'file'),
    conversationStorePath: is_string($conversationStorePath) && $conversationStorePath !== ''
        ? $conversationStorePath
        : null,
);
