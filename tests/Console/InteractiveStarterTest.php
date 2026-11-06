<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Console;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Console\ProjectScaffold;
use Thunder\Bolt\Console\ProjectSetup;
use Thunder\Bolt\Testing\BoltTest;
use Thunder\Bolt\Testing\InteractionFixture;

final class InteractiveStarterTest extends TestCase
{
    public function test_generated_interactive_app_handles_home_commands_buttons_and_form_validation(): void
    {
        $root = sys_get_temp_dir() . '/bolt-interactive-starter-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $previous = getcwd();
        chdir($root);
        try {
            $project = new ProjectScaffold(dirname(__DIR__, 2))->create(
                'app',
                new ProjectSetup(profile: 'interactive'),
            );
            require $project . '/app/Listeners/InteractiveApp.php';
            $bolt = BoltTest::make()->listeners(\App\Listeners\InteractiveApp::class);
            $bolt->event('app_home_opened', [
                'user' => 'U_TEST',
                'tab' => 'home',
            ])->assertApiCalledWith('views.publish', ['user_id' => 'U_TEST']);
            $bolt
                ->command('/bolt')
                ->assertAcknowledged(['response_type' => 'ephemeral'])
                ->assertApiCalledWith('views.open', ['view' => ['callback_id' => 'starter_form']]);
            $bolt
                ->interaction(InteractionFixture::action('starter_open'))
                ->assertAcknowledged()
                ->assertApiCalled('views.open');
            $bolt->interaction(InteractionFixture::viewSubmission('starter_form'))->assertAcknowledged([
                'response_action' => 'errors',
            ]);
            $bolt->interaction(InteractionFixture::viewSubmission('starter_form')->with([
                'view' => [
                    'state' => [
                        'values' => [
                            'name' => ['name' => ['type' => 'plain_text_input', 'value' => str_repeat('a', 101)]],
                        ],
                    ],
                ],
            ]))->assertAcknowledged([
                'response_action' => 'errors',
                'errors' => ['name' => 'Use at most 100 characters.'],
            ]);
            $result = $bolt->interaction(InteractionFixture::viewSubmission('starter_form')->with([
                'view' => [
                    'state' => [
                        'values' => [
                            'name' => ['name' => ['type' => 'plain_text_input', 'value' => '<@U_OTHER>']],
                        ],
                    ],
                ],
            ]));
            $result->assertAcknowledged([
                'response_action' => 'update',
                'view' => [
                    'blocks' => [['text' => [
                        'type' => 'plain_text',
                        'text' => 'Thanks, <@U_OTHER>. Your form was received.',
                    ]]],
                ],
            ]);
        } finally {
            chdir($previous);
            $this->remove($root);
        }
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $file)
                $this->remove($file->getPathname());
            rmdir($path);
        } else
            unlink($path);
    }
}
