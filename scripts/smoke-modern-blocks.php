<?php

declare(strict_types=1);

// Explicit live acceptance fixture. Credentials are loaded in-process and never printed.
require dirname(__DIR__) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client as HttpClient;
use Tempest\Container\GenericContainer;
use Thunder\Bolt\App;
use Thunder\Bolt\BlockKit\Actions;
use Thunder\Bolt\BlockKit\Button;
use Thunder\Bolt\BlockKit\Chart;
use Thunder\Bolt\BlockKit\ChartAxis;
use Thunder\Bolt\BlockKit\ChartPoint;
use Thunder\Bolt\BlockKit\ChartSeries;
use Thunder\Bolt\BlockKit\Container;
use Thunder\Bolt\BlockKit\DataTable;
use Thunder\Bolt\BlockKit\DataTableCell;
use Thunder\Bolt\BlockKit\DataTableRow;
use Thunder\Bolt\BlockKit\DataVisualization;
use Thunder\Bolt\BlockKit\Divider;
use Thunder\Bolt\BlockKit\Header;
use Thunder\Bolt\BlockKit\Input;
use Thunder\Bolt\BlockKit\Layout;
use Thunder\Bolt\BlockKit\LayoutItem;
use Thunder\Bolt\BlockKit\LayoutRow;
use Thunder\Bolt\BlockKit\PlainTextInput;
use Thunder\Bolt\BlockKit\RichText;
use Thunder\Bolt\BlockKit\RichTextSection;
use Thunder\Bolt\BlockKit\RichTextText;
use Thunder\Bolt\BlockKit\Section;
use Thunder\Bolt\BlockKit\Text;
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
use Thunder\Bolt\WebApi\SlackApiException;

function liveSetting(string $name): string
{
    $value = getenv($name);
    return $value !== false ? $value : (is_string($_ENV[$name] ?? null) ? $_ENV[$name] : '');
}

function liveRecord(string $path, array $record): void
{
    file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
    chmod($path, 0600);
}

try {
    if (liveSetting('SLACK_LIVE_TESTS') !== '1' || liveSetting('SLACK_LIVE_WRITES') !== '1') {
        throw new RuntimeException('Explicit SLACK_LIVE_TESTS=1 and SLACK_LIVE_WRITES=1 are required.');
    }
    $envFile = liveSetting('SLACK_LIVE_ENV_FILE');
    if ($envFile !== '') {
        Dotenv::createImmutable(dirname($envFile), basename($envFile))->load();
    }
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['post', 'update', 'listen', 'home', 'stream', 'inspect', 'settle'], true)) {
        throw new RuntimeException('Use post [case], update case, listen, home, stream [case], inspect or settle.');
    }
    $team = liveSetting('SLACK_LIVE_TEAM');
    $channel = liveSetting('SLACK_LIVE_CHANNEL');
    $user = liveSetting('SLACK_LIVE_USER');
    $token = liveSetting('SLACK_LIVE_TOKEN') ?: liveSetting('SLACK_BOT_TOKEN');
    if ($team === '' || $channel === '' || $user === '' || $token === '') {
        throw new RuntimeException('Team, channel, user and bot credentials are required.');
    }
    $client = new Client(new HttpClient(['timeout' => 15, 'connect_timeout' => 5]), $token);
    $auth = $client->call('auth.test');
    if ($auth->get('team_id') !== $team) {
        throw new RuntimeException('The configured token does not belong to the expected smoke-test workspace.');
    }
    $directory = dirname(__DIR__) . '/work/modern-blocks-live';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $path = $directory . '/receipt.json';
    $record = is_file($path)
        ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)
        : ['team' => $team, 'channel' => $channel, 'cases' => [], 'events' => []];
    if ($record['team'] !== $team || $record['channel'] !== $channel) {
        throw new RuntimeException('Existing receipt targets a different workspace or channel.');
    }
    $root = $record['root'] ?? null;
    if (!is_string($root)) {
        if ($mode !== 'post') {
            throw new RuntimeException('Run post to create the acceptance thread first.');
        }
        $root = $client
            ->chat()
            ->postMessage([
                'channel' => $channel,
                'text' =>
                    'Modern Block Kit live smoke test — '
                        . gmdate('j F Y')
                        . '. Containers, layouts, data tables, charts, Home, modal and streaming. Synthetic test data only.',
            ])
            ->get('ts');
        $record['root'] = $root;
        liveRecord($path, $record);
    }
    $text = static fn(string $value): RichText => RichText::make(RichTextSection::make(RichTextText::make($value)));
    $container = Container::make(
        'Collapsible deployment summary',
        Section::markdown('*Production* is ready.'),
        Section::text('Container child content verified.'),
    )
        ->subtitle(Text::markdown('Synthetic *live acceptance* fixture'))
        ->width('full')
        ->collapsible()
        ->collapsed()
        ->id('live_container');
    $richContainer = Container::make('Plain fallback title', Section::text('Rich title and header divider.'))
        ->richTextTitle($text('Rich deployment title'))
        ->headerDivider()
        ->collapsible(false)
        ->id('live_rich_container');
    $layout = Layout::make(
        'Release grid',
        LayoutRow::make(
            LayoutItem::make(Section::markdown("*Service*\nPayments"))->width('wide'),
            LayoutItem::make(Section::markdown("*Status*\nHealthy"))->width('narrow')->horizontalAlignment('end'),
        ),
        LayoutRow::make(LayoutItem::make(Container::make(
            'Nested container',
            Section::text('Two-level composition works.'),
        ))),
    )
        ->labelVisible()
        ->gridLines('rows')
        ->fallback(Section::text('Release grid fallback: Payments is healthy.'))
        ->id('live_layout');
    $rows = [];
    foreach ([['Alpha', 2], ['Beta', 10], ['Gamma', -1], ['Delta', 0]] as $index => [$name, $number]) {
        $rows[] = DataTableRow::make(
            DataTableCell::richText($text($name)),
            DataTableCell::number($number),
            DataTableCell::action(
                Button::make('Verify row', 'live_modern_row')->value('row_' . ($index + 1)),
                DataTableCell::text('Verify ' . $name),
            ),
        );
    }
    $table = DataTable::make(
        'Synthetic tasks',
        DataTableRow::make(DataTableCell::text('Task'), DataTableCell::text('Points'), DataTableCell::text('Action')),
        ...$rows,
    )
        ->pageSize(2)
        ->rowHeaderColumn(0)
        ->id('live_data_table');
    $axis = ChartAxis::categories('Mon', 'Tue', 'Wed')->xLabel('Day')->yLabel('Requests');
    $first = ChartSeries::make(
        'API',
        ChartPoint::make('Mon', 12),
        ChartPoint::make('Tue', 0),
        ChartPoint::make('Wed', 18),
    );
    $second = ChartSeries::make(
        'Web',
        ChartPoint::make('Mon', 6),
        ChartPoint::make('Tue', 9),
        ChartPoint::make('Wed', 14),
    );
    $pie = DataVisualization::make('Traffic by source', Chart::pie(
        ChartPoint::make('API', 75),
        ChartPoint::make('Web', 25),
    ))->id('live_pie');
    $bar = DataVisualization::make('Requests — grouped bars', Chart::bar($axis, $first, $second))->id('live_bar');
    $area = DataVisualization::make('Requests — area', Chart::area($axis, $first, $second))->id('live_area');
    $line = DataVisualization::make('Requests — line', Chart::line($axis, $first, $second))->id('live_line');
    $controls = Actions::make(
        Button::make('Open layout form', 'live_modern_modal'),
        Button::make('Publish test Home', 'live_modern_home'),
    );
    $cases = [
        'containers' => [$container, $richContainer],
        'layout' => [$layout, $controls],
        'data_table' => [$table],
        'pie_bar' => [$pie, $bar],
        'area_line' => [$area, $line],
    ];
    $home = Home::make(Header::make('Modern blocks smoke test'), $container, $layout, $table, $pie, $bar)->callbackId(
        'live_modern_home',
    );
    $modal = Modal::make(
        'Modern block form',
        Layout::make('Nested form', LayoutRow::make(
            LayoutItem::make(Input::make('Acceptance note', PlainTextInput::make('note'))->id('note')),
            LayoutItem::make(Section::text('This input is inside a layout.')),
        ))
            ->labelVisible()
            ->fallback(Section::text('Form requires a layout-capable Slack client.')),
    )
        ->submit('Verify')
        ->close('Cancel')
        ->callbackId('live_modern_submit');
    if ($mode === 'post') {
        foreach ($cases as $name => $blocks) {
            if (isset($argv[2]) && $argv[2] !== $name || isset($record['cases'][$name]['ts'])) {
                continue;
            }
            try {
                $result = $client
                    ->chat()
                    ->postMessage([
                        'channel' => $channel,
                        ...Message::blocks(Header::make('Live check: ' . $name), ...$blocks)
                            ->fallbackText('Modern blocks live check: ' . $name)
                            ->inThread($root)
                            ->toArray(),
                    ]);
                $record['cases'][$name] = ['api' => 'accepted', 'ts' => $result->get('ts')];
            } catch (SlackApiException $error) {
                $record['cases'][$name] = [
                    'api' => 'rejected',
                    'error' => $error->error,
                    'details' => $error->data['response_metadata']['messages'] ?? [],
                ];
            }
            liveRecord($path, $record);
            echo json_encode([$name => $record['cases'][$name]], JSON_THROW_ON_ERROR), PHP_EOL;
        }
    } elseif ($mode === 'update') {
        $name = $argv[2] ?? '';
        $timestamp = $record['cases'][$name]['ts'] ?? null;
        if (!isset($cases[$name]) || !is_string($timestamp)) {
            throw new RuntimeException('Update requires an existing accepted fixture case.');
        }
        $client
            ->chat()
            ->update([
                'channel' => $channel,
                'ts' => $timestamp,
                ...Message::blocks(Header::make('Live check: ' . $name), ...$cases[$name])
                    ->fallbackText('Modern blocks live check: ' . $name)
                    ->toArray(),
            ]);
        $record['cases'][$name]['updated_at'] = gmdate(DATE_ATOM);
        liveRecord($path, $record);
        echo "Updated existing live fixture.\n";
    } elseif ($mode === 'home') {
        $result = $client->views()->publish(['user_id' => $user, 'view' => $home->toArray()]);
        $record['home'] = ['api' => 'accepted', 'view_id' => $result->get('view')['id'] ?? null];
        liveRecord($path, $record);
        echo "Test Home published for the smoke-test user. Restore Boltling Home after verification.\n";
    } elseif ($mode === 'inspect') {
        $messages = $client
            ->conversations()
            ->replies(['channel' => $channel, 'ts' => $root, 'limit' => 100])
            ->get('messages');
        foreach ($messages ?? [] as $message) {
            echo
                json_encode([
                    'ts' => $message['ts'] ?? null,
                    'subtype' => $message['subtype'] ?? null,
                    'streaming_state' => $message['streaming_state'] ?? null,
                    'keys' => array_keys($message),
                    'types' => array_column($message['blocks'] ?? [], 'type'),
                ], JSON_THROW_ON_ERROR),
                PHP_EOL;
        }
    } elseif ($mode === 'settle') {
        foreach ($client
            ->conversations()
            ->replies(['channel' => $channel, 'ts' => $root, 'limit' => 100])
            ->get('messages') ?? [] as $message) {
            if (
                ($message['streaming_state'] ?? null) === 'in_progress'
                && ($message['user'] ?? null) === $auth->get('user_id')
            ) {
                $client->chat()->stopStream(['channel' => $channel, 'ts' => $message['ts']]);
            }
        }
        $client->call('agents.sessions.setStatus', [
            'channel_id' => $channel,
            'thread_ts' => $root,
            'status' => 'active',
        ]);
        echo "Acceptance session settled to active.\n";
    } elseif ($mode === 'stream') {
        $case = $argv[2] ?? 'combined';
        $minimalLayout = Layout::make(
            'Minimal stream layout',
            LayoutRow::make(LayoutItem::make(Section::text('Layout stream content.'))),
        );
        $streamBlocks = match ($case) {
            'containers' => [$container],
            'layout' => [$layout],
            'layout_minimal' => [$minimalLayout],
            'layout_fallback' => [$minimalLayout->fallback(Section::text('Layout fallback.'))],
            'layout_nested' => [Layout::make(
                'Nested stream layout',
                LayoutRow::make(LayoutItem::make(Container::make('Nested', Section::text('Content')))),
            )],
            'layout_nested_rich' => [Layout::make(
                'Nested rich stream',
                LayoutRow::make(LayoutItem::make(Container::make('Nested', $text('Content')))),
            )],
            'layout_nested_explicit' => [Layout::make(
                'Nested explicit stream',
                LayoutRow::make(LayoutItem::make(
                    Container::make('Nested', Section::text('Content'))
                        ->width('standard')
                        ->collapsible(false)
                        ->collapsed(false)
                        ->headerDivider(false),
                )),
            )],
            'container_nested' => [Container::make('Nested layout stream', $minimalLayout)],
            'data_table' => [$table],
            'chart' => [$line],
            'baseline', 'final_layout', 'append_layout' => [Section::text('Streaming baseline verified.')],
            'combined' => [$container, $layout, $table, $line],
            default => throw new RuntimeException('Unknown streaming fixture case.'),
        };
        $chunk = \Thunder\Bolt\Assistant\Streaming\Blocks::make(...$streamBlocks);
        $stream = $client
            ->chat()
            ->startStream([
                'channel' => $channel,
                'thread_ts' => $root,
                'recipient_team_id' => $team,
                'recipient_user_id' => $user,
                'chunks' => [$chunk->toArray()],
            ]);
        $timestamp = $stream->get('ts');
        $record['streams'][$case] = ['api' => 'started', 'ts' => $timestamp];
        liveRecord($path, $record);
        if ($case === 'append_layout') {
            $client
                ->chat()
                ->appendStream([
                    'channel' => $channel,
                    'ts' => $timestamp,
                    'chunks' => [\Thunder\Bolt\Assistant\Streaming\Blocks::make($layout)->toArray()],
                ]);
        }
        $client
            ->chat()
            ->stopStream([
                'channel' => $channel,
                'ts' => $timestamp,
                ...($case === 'final_layout' ? ['blocks' => [$layout->toArray()]] : []),
            ]);
        $record['streams'][$case] = ['api' => 'accepted', 'ts' => $timestamp];
        liveRecord($path, $record);
        echo "Typed streaming block chunk accepted and stopped.\n";
    } else {
        $app = new App(
            new GenericContainer(),
            $client,
            new BoltConfig(token: $token, botUserId: $auth->get('user_id'), botId: $auth->get('bot_id')),
            conversationStore: false,
        );
        $receipt = static function (string $name, array $fields) use ($path): void {
            $current = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $current['events'][] = ['name' => $name, 'at' => gmdate('c'), ...$fields];
            liveRecord($path, $current);
            echo json_encode(['event' => $name, ...$fields], JSON_THROW_ON_ERROR), PHP_EOL;
        };
        foreach (['live_modern_row', 'live_modern_modal', 'live_modern_home'] as $actionId) {
            $app->action($actionId, static function (Action $action, Delivery $delivery) use (
                $client,
                $channel,
                $root,
                $user,
                $team,
                $receipt,
                $home,
                $modal,
            ): ActionResponse {
                if ($action->userId !== $user || $delivery->authorizationSource?->teamId !== $team) {
                    return ActionResponse::empty();
                }
                if ($action->actionId === 'live_modern_modal') {
                    $client->views()->open(['trigger_id' => $action->triggerId, 'view' => $modal->toArray()]);
                    $receipt('modal_open', []);
                } elseif ($action->actionId === 'live_modern_home') {
                    $client->views()->publish(['user_id' => $user, 'view' => $home->toArray()]);
                    $receipt('home_publish', []);
                } else {
                    $value = $action->action['value'] ?? '';
                    if (!is_string($value) || !preg_match('/^row_[1-4]$/', $value)) {
                        return ActionResponse::empty();
                    }
                    $client
                        ->chat()
                        ->postMessage([
                            'channel' => $channel,
                            'thread_ts' => $root,
                            'text' => 'Live data-table action received: ' . $value,
                        ]);
                    $receipt('row_action', ['value' => $value, 'block_id' => $action->blockId]);
                }
                return ActionResponse::empty();
            });
        }
        $app->view('live_modern_submit', static function (View $view) use (
            $user,
            $receipt,
            $client,
            $channel,
            $root,
        ): ViewResponse {
            if ($view->userId !== $user) {
                return ViewResponse::empty();
            }
            $value = $view->value('note', 'note');
            $receipt('modal_submit', ['matches' => $value === 'Live nested input verified']);
            $client
                ->chat()
                ->postMessage([
                    'channel' => $channel,
                    'thread_ts' => $root,
                    'text' =>
                        'Live layout modal submission received: '
                            . ($value === 'Live nested input verified' ? 'verified' : 'unexpected value'),
                ]);
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
        echo "Live modern-block interaction receiver ready.\n";
        $started = time();
        new Runner(
            $app,
            $client,
            new Config(appToken: liveSetting('SLACK_APP_TOKEN'), receiveTimeoutSeconds: 1),
            new AmpConnector(),
        )->run(static function () use (&$stop, $started): bool {
            return $stop || (time() - $started) > 900;
        });
    }
} catch (Throwable $error) {
    if (isset($mode, $case, $path, $record) && $mode === 'stream') {
        $record['streams'][$case] = [
            'api' => 'rejected',
            'error' => $error instanceof SlackApiException ? $error->error : $error::class,
        ];
        if (isset($client, $channel, $root)) {
            try {
                $client->call('agents.sessions.setStatus', [
                    'channel_id' => $channel,
                    'thread_ts' => $root,
                    'status' => 'active',
                ]);
                $record['streams'][$case]['session_settled'] = true;
            } catch (Throwable) {
                $record['streams'][$case]['session_settled'] = false;
            }
        }
        liveRecord($path, $record);
    }
    echo
        json_encode([
            'failure' => $error::class,
            'error' => $error instanceof SlackApiException ? $error->error : null,
            'details' => $error instanceof SlackApiException ? $error->data['response_metadata']['messages'] ?? [] : [],
        ], JSON_THROW_ON_ERROR),
        PHP_EOL;
    exit(1);
}
