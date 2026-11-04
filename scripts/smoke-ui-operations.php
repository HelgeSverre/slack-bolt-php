<?php

declare(strict_types=1);

// Opt-in live fixture. Synthetic content only; credentials never enter receipts.
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client as HttpClient;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Header;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextMessageMention;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextTag;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\BlockKit\RichTextWorkObjectMention;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BoltConfig;
use Thunder\Bolt\Invocation\Delivery;
use Thunder\Bolt\Messages\Message;
use Thunder\Bolt\Payloads\Action;
use Thunder\Bolt\Payloads\View;
use Thunder\Bolt\Responses\ActionResponse;
use Thunder\Bolt\Responses\ViewResponse;
use Thunder\Bolt\SocketMode\Config;
use Thunder\Bolt\SocketMode\Runner;
use Thunder\Bolt\SocketMode\Transport\AmpConnector;
use Thunder\Bolt\Views\Home;
use Thunder\Bolt\Views\Modal;
use Thunder\Bolt\WebApi\Client;
use Thunder\Bolt\WebApi\Requests\ChatPostEphemeral;
use Thunder\Bolt\WebApi\Requests\ChatUpdate;
use Thunder\Bolt\WebApi\Requests\ViewRequest;
use Thunder\Bolt\WebApi\SlackApiException;
use Thunder\Bolt\WorkObjects\Entity;
use Thunder\Bolt\WorkObjects\ExternalReference;

function uiSetting(string $name): string
{
    $value = getenv($name);
    return $value !== false ? $value : (is_string($_ENV[$name] ?? null) ? $_ENV[$name] : '');
}

function uiSave(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    chmod($path, 0600);
}

function uiLoad(string $path): array
{
    return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

function uiModal(string $externalId, string $status = 'Ready for typed operations.'): Modal
{
    return Modal::make(
        'Typed UI acceptance',
        Section::text($status),
        Input::make('Acceptance note', PlainTextInput::make('note'))->id('note'),
        Actions::make(
            Button::make('Update by ID', 'ui_accept_update_view'),
            Button::make('Update by external ID', 'ui_accept_update_external'),
            Button::make('Push view', 'ui_accept_push'),
        ),
    )
        ->externalId($externalId)
        ->callbackId('ui_accept_submit')
        ->submit('Verify')
        ->close('Cancel');
}

try {
    if (uiSetting('SLACK_LIVE_TESTS') !== '1' || uiSetting('SLACK_LIVE_WRITES') !== '1') {
        throw new RuntimeException('SLACK_LIVE_TESTS=1 and SLACK_LIVE_WRITES=1 are required.');
    }
    $envFile = uiSetting('SLACK_LIVE_ENV_FILE');
    if ($envFile !== '') {
        Dotenv::createImmutable(dirname($envFile), basename($envFile))->load();
    }
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['post', 'listen', 'inspect'], true)) {
        throw new RuntimeException('Use post, listen or inspect.');
    }
    $team = uiSetting('SLACK_LIVE_TEAM');
    $channel = uiSetting('SLACK_LIVE_CHANNEL');
    $user = uiSetting('SLACK_LIVE_USER');
    $appId = uiSetting('SLACK_LIVE_APP');
    $run = uiSetting('SLACK_LIVE_RUN');
    $token = uiSetting('SLACK_LIVE_TOKEN') ?: uiSetting('SLACK_BOT_TOKEN');
    if (
        $team === ''
        || $channel === ''
        || $user === ''
        || $appId === ''
        || $token === ''
        || !preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/', $run)
    ) {
        throw new RuntimeException('Explicit team, channel, user, app, credentials and a safe run name are required.');
    }
    $client = new Client(new HttpClient(['timeout' => 15, 'connect_timeout' => 5]), $token);
    $auth = $client->call('auth.test');
    if ($auth->get('team_id') !== $team) {
        throw new RuntimeException('Unexpected Slack workspace.');
    }
    $directory = dirname(__DIR__) . '/work/ui-operations-live';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory . '/' . $run . '.json';
    $record = is_file($path)
        ? uiLoad($path)
        : ['team' => $team, 'channel' => $channel, 'user' => $user, 'app' => $appId, 'events' => []];
    if (
        $record['team'] !== $team
        || $record['channel'] !== $channel
        || $record['user'] !== $user
        || $record['app'] !== $appId
    ) {
        throw new RuntimeException('The existing receipt targets another fixture identity.');
    }
    $files = [
        'WebApi/Requests/ViewRequest.php',
        'WebApi/Requests/ChatUpdate.php',
        'WebApi/Requests/ChatPostEphemeral.php',
        'BlockKit/RichTextTag.php',
        'BlockKit/RichTextMessageMention.php',
        'BlockKit/RichTextWorkObjectMention.php',
        'BlockKit/RichTextLeaf.php',
        'BlockKit/RichTextStyle.php',
    ];
    $digest = hash('sha256', implode('', array_map(static fn(string $file): string => hash_file(
        'sha256',
        dirname(__DIR__) . '/src/' . $file,
    ), $files)));
    if (isset($record['runtime_digest']) && $record['runtime_digest'] !== $digest) {
        throw new RuntimeException('Runtime changed; use a new run name for fresh acceptance.');
    }
    $record['runtime_digest'] = $digest;
    $record['revision'] ??= uiSetting('SLACK_LIVE_REVISION');
    if ($mode === 'post' && !isset($record['root'])) {
        $record['root'] = $client
            ->chat()
            ->postMessage([
                'channel' => $channel,
                'text' =>
                    'Typed UI operations and rich-text live acceptance — ' . gmdate('j F Y') . '. Synthetic data only.',
            ])
            ->get('ts');
        uiSave($path, $record);
    }
    $root = $record['root'] ?? throw new RuntimeException('Run post first.');
    $entityId = 'ui-acceptance-' . $run;
    $entityUrl = 'https://example.com/thunder-bolt/work-items/' . $entityId;
    $entity = Entity::task(new ExternalReference($entityId, 'ui-acceptance'), $entityUrl, 'Typed acceptance work item');
    $rich = RichText::make(
        RichTextSection::make(RichTextText::make('Status: '), RichTextTag::make('In progress')->blue()->bold()),
        RichTextSection::make(
            RichTextText::make('Source: '),
            RichTextMessageMention::for($channel, $root)->author($auth->get('user_id'))->text(
                'Acceptance source message',
            ),
        ),
        RichTextSection::make(
            RichTextText::make('Work: '),
            RichTextWorkObjectMention::for($entityId, $appId, 'Typed acceptance work item', $entityUrl)->product(
                'Thunder',
            )->fullSizePreview(false),
        ),
    );
    $controls = Actions::make(
        Button::make('Open typed modal', 'ui_accept_open'),
        Button::make('Update typed message', 'ui_accept_message'),
        Button::make('Send ephemeral', 'ui_accept_ephemeral'),
        Button::make('Publish typed Home', 'ui_accept_home'),
    );
    $message = Message::blocks(Header::make('Typed UI acceptance'), $rich, $controls)
        ->fallbackText('Typed UI acceptance.')
        ->inThread($root);
    $home = Home::make(
        Header::make('Typed UI acceptance'),
        $rich,
        Actions::make(Button::make('Refresh typed Home', 'ui_accept_home')),
    )->callbackId('ui_accept_home');
    if ($mode === 'post') {
        if (!isset($record['entity_message'])) {
            $posted = $client
                ->chat()
                ->postMessage([
                    'channel' => $channel,
                    ...Message::text('Synthetic Work Object for rich-text acceptance.')
                        ->entities($entity)
                        ->inThread($root)
                        ->toArray(),
                ]);
            $record['entity_message'] = $posted->get('ts');
            $record['entity_metadata'] = $posted->get('message')['metadata'] ?? null;
            uiSave($path, $record);
        }
        if (!isset($record['panel'])) {
            $record['panel'] = $client->chat()->postMessage(['channel' => $channel, ...$message->toArray()])->get('ts');
            uiSave($path, $record);
        }
        echo "Acceptance panel posted.\n";
    } elseif ($mode === 'inspect') {
        foreach ($client
            ->conversations()
            ->replies(['channel' => $channel, 'ts' => $root, 'limit' => 100])
            ->get('messages') ?? [] as $item) {
            echo
                json_encode([
                    'ts' => $item['ts'] ?? null,
                    'types' => array_column($item['blocks'] ?? [], 'type'),
                ], JSON_THROW_ON_ERROR),
                PHP_EOL;
        }
    } else {
        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig(token: $token, botUserId: $auth->get('user_id'), botId: $auth->get('bot_id')),
            conversationStore: false,
        );
        $receipt = static function (string $name, array $fields = []) use ($path): void {
            $current = uiLoad($path);
            $current['events'][] = ['name' => $name, 'at' => gmdate(DATE_ATOM), ...$fields];
            uiSave($path, $current);
            echo json_encode(['event' => $name, ...$fields], JSON_THROW_ON_ERROR), PHP_EOL;
        };
        foreach (['open', 'message', 'ephemeral', 'home', 'update_view', 'update_external', 'push'] as $name) {
            $app->action('ui_accept_' . $name, static function (Action $action, Delivery $delivery) use (
                $name,
                $client,
                $team,
                $user,
                $channel,
                $root,
                $path,
                $message,
                $home,
                $receipt,
            ): ActionResponse {
                if ($action->userId !== $user || $delivery->authorizationSource?->teamId !== $team) {
                    return ActionResponse::empty();
                }
                $view = $action->raw['view'] ?? [];
                if ($name === 'open') {
                    $opened = $client
                        ->views()
                        ->open(ViewRequest::open($action->triggerId, uiModal('ui-' . bin2hex(random_bytes(8)))))
                        ->get('view');
                    $receipt('modal_open', ['id' => $opened['id'], 'hash' => $opened['hash']]);
                } elseif ($name === 'message') {
                    $current = uiLoad($path);
                    $client
                        ->chat()
                        ->update(
                            new ChatUpdate(
                                $channel,
                                $current['panel'],
                                Message::blocks(
                                    Header::make('Typed message updated'),
                                    ...array_map(
                                        static fn(array $block) => \Thunder\Bolt\BlockKit\RawBlock::fromArray($block),
                                        array_slice($message->toArray()['blocks'], 1),
                                    ),
                                )->fallbackText('Typed message updated.'),
                            ),
                        );
                    $receipt('message_update');
                } elseif ($name === 'ephemeral') {
                    $client
                        ->chat()
                        ->postEphemeral(
                            new ChatPostEphemeral(
                                $channel,
                                $user,
                                'Typed ephemeral delivery verified.',
                                threadTs: $root,
                            ),
                        );
                    $receipt('ephemeral');
                } elseif ($name === 'home') {
                    $request = ViewRequest::publish($user, $home);
                    if (is_string($view['hash'] ?? null)) {
                        $request = $request->withHash($view['hash']);
                    }
                    $published = $client->views()->publish($request)->get('view');
                    $receipt('home_publish', ['id' => $published['id'], 'hash_checked' => isset($view['hash'])]);
                } elseif ($name === 'push') {
                    $client
                        ->views()
                        ->push(ViewRequest::push(
                            $action->triggerId,
                            Modal::make('Pushed typed view', Section::text('Typed views.push verified.'))->close(
                                'Back',
                            ),
                        ));
                    $receipt('modal_push');
                } else {
                    $updated = uiModal(
                        $view['external_id'],
                        $name === 'update_view'
                            ? 'Typed update by ID verified.'
                            : 'Typed update by external ID verified.',
                    );
                    $request = $name === 'update_view'
                        ? ViewRequest::update($view['id'], $updated)
                        : ViewRequest::updateByExternalId($view['external_id'], $updated);
                    $client->views()->update($request->withHash($view['hash']));
                    $receipt($name, ['hash_checked' => true]);
                }
                return ActionResponse::empty();
            });
        }
        $app->view('ui_accept_submit', static function (View $view) use ($user, $receipt): ViewResponse {
            if ($view->userId === $user) {
                $receipt('modal_submit', ['matches' => $view->value('note', 'note') === 'Typed fixtures verified']);
            }
            return ViewResponse::empty();
        });
        $app->error(static function (Throwable $error) use ($receipt): void {
            $receipt('handler_error', [
                'type' => $error::class,
                'error' => $error instanceof SlackApiException ? $error->error : null,
            ]);
        });
        $stop = false;
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM] as $signal) {
            pcntl_signal($signal, static function () use (&$stop): void {
                $stop = true;
            });
        }
        $started = time();
        echo "Typed UI interaction receiver ready.\n";
        new Runner(
            $app,
            $client,
            new Config(appToken: uiSetting('SLACK_APP_TOKEN'), receiveTimeoutSeconds: 1),
            new AmpConnector(),
        )->run(static function () use (&$stop, $started): bool {
            return $stop || (time() - $started) > 900;
        });
    }
} catch (Throwable $error) {
    echo
        json_encode([
            'failure' => $error::class,
            'error' => $error instanceof SlackApiException ? $error->error : null,
        ], JSON_THROW_ON_ERROR),
        PHP_EOL;
    exit(1);
}
