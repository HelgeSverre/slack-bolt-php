<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Examples;

use App\Demo\AgentViewDemo;
use App\Demo\DemoConfig;
use App\Demo\Jobs\AgentAnswer;
use App\Demo\Jobs\Answer;
use App\Demo\Jobs\AnswerHandler;
use App\Demo\ThreadState;
use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Assistant;
use Thunder\Bolt\Assistant\ContextEntity;
use Thunder\Bolt\Jobs\JobRecord;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\SlackCall;

final class AgentViewDemoTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bolt-agent-routing-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function test_root_retries_and_followups_commit_distinct_focus_snapshots_without_outbound_calls(): void
    {
        $test = BoltTest::make(jobs: true)->jobHandlers(AnswerHandler::class);
        $test->bind(new DemoConfig($this->directory, []));
        $test->app->assistant(Assistant::forAgentView()->userMessage([AgentViewDemo::class, 'message']));
        $event = [
            'channel_type' => 'im',
            'channel' => 'D1',
            'user' => 'U1',
            'text' => 'Explain &amp; summarize.',
            'ts' => '1.001',
            'app_context' => [
                'entities' => [['type' => ContextEntity::CHANNEL, 'value' => 'C-focus', 'team_id' => 'T-hint']],
            ],
        ];
        $result = $test->event('message', $event, team: 'T-installed', eventId: 'Ev-root');
        $result->assertNoApiCalls()->assertJobQueued(
            AgentAnswer::class,
            static fn(JobRecord $record): bool => (
                $record->payload['text'] === 'Explain & summarize.'
                && $record->payload['appContext'] === $event['app_context']
                && $record->context->teamId === 'T-installed'
                && $record->context->threadTs === '1.001'
            ),
        );
        $test->event('message', $event, team: 'T-installed', eventId: 'Ev-root')->assertNoJobsQueued();
        unset($event['app_context']);
        $event['thread_ts'] = '1.001';
        $event['ts'] = '2.001';
        $test->event('message', $event, team: 'T-installed', eventId: 'Ev-followup')->assertJobQueued(
            AgentAnswer::class,
            static fn(JobRecord $record): bool => $record->payload['appContext'] === [],
        );
    }

    public function test_opening_messages_sets_dynamic_prompts_without_a_thread_or_a_job(): void
    {
        $test = BoltTest::make(jobs: true)->jobHandlers(AnswerHandler::class);
        $test->slack->respondsWith('assistant.threads.setSuggestedPrompts', ['ok' => true]);
        $test->app->assistant(require dirname(__DIR__, 2) . '/examples/hello-bolt/config/assistant.config.php');
        $test
            ->event('app_home_opened', ['user' => 'U1', 'channel' => 'D1', 'tab' => 'messages'])
            ->assertNoJobsQueued()
            ->assertApiCalled(
                'assistant.threads.setSuggestedPrompts',
                static fn(SlackCall $call): bool => (
                    $call->arguments['channel_id'] === 'D1'
                    && !isset($call->arguments['thread_ts'])
                    && count($call->arguments['prompts']) === 3
                ),
            );
    }

    public function test_new_command_preserves_the_legacy_answer_json_contract(): void
    {
        self::assertSame(['text' => 'hello', 'thread' => null, 'title' => null], get_object_vars(new Answer('hello')));
        self::assertSame(['text' => 'hello', 'appContext' => []], get_object_vars(new AgentAnswer('hello')));
        $this->expectException(\InvalidArgumentException::class);
        new AgentAnswer('hello', ['extra' => str_repeat('x', 16001)]);
    }

    public function test_terminal_threads_reach_the_owner_guarded_spool_instead_of_the_model(): void
    {
        $config = new DemoConfig($this->directory, ['UOWNER']);
        new ThreadState($this->directory)->put('T1', 'D1', '1.001', ['mode' => 'terminal', 'owner' => 'UOWNER']);
        $test = BoltTest::make(jobs: true)->bind($config)->jobHandlers(AnswerHandler::class);
        $test->app->assistant(Assistant::forAgentView()->userMessage([AgentViewDemo::class, 'message']));
        $event = [
            'channel_type' => 'im',
            'channel' => 'D1',
            'user' => 'UOWNER',
            'text' => 'echo smoke',
            'ts' => '2.001',
            'thread_ts' => '1.001',
        ];
        $test->event('message', $event, team: 'T1', eventId: 'Ev-terminal')->assertNoJobsQueued()->assertNoApiCalls();
        $item = $config->queue()->claim();
        self::assertSame('terminal', $item['job']['mode']);
        self::assertSame('echo smoke', $item['job']['text']);
        self::assertSame('input', $item['job']['operation']);
        $config->queue()->complete($item['id']);
        $event['user'] = 'UOTHER';
        $test->event('message', $event, team: 'T1', eventId: 'Ev-other')->assertNoJobsQueued()->assertNoApiCalls();
        self::assertNull($config->queue()->claim());
    }
}
