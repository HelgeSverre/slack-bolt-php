<?php

declare(strict_types=1);

// Explicit integration check: resolves production dependencies with Composer.
// Slack traffic is intercepted; this script never reads workspace credentials.
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/bolt-project-smoke-' . bin2hex(random_bytes(6));
mkdir($workspace, 0700);
$project = $workspace . '/app';
$environment = [
    ...getenv(),
    'SLACK_BOT_TOKEN' => 'smoke-token',
    'SLACK_SIGNING_SECRET' => 'smoke-secret',
    'SLACK_BOT_USER_ID' => 'U-smoke-bot',
    'SLACK_CLIENT_ID' => '',
    'SLACK_CLIENT_SECRET' => '',
    'SLACK_OAUTH_REDIRECT_URI' => '',
    'SLACK_JOB_STORE_PATH' => '',
    'SLACK_CONTEXT_STORE_PATH' => '',
    'SLACK_CONTEXT_STORE' => 'memory',
];
$command = static function (array $arguments, string $directory) use ($environment): string {
    $process = proc_open(
        $arguments,
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes,
        $directory,
        $environment,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start smoke process.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Smoke command failed: ' . implode(' ', $arguments) . "\n" . $output);
    }
    return $output ?: '';
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $file) {
        $remove($file->getPathname());
    }
    rmdir($path);
};
try {
    echo $command([PHP_BINARY, $root . '/bin/bolt', 'new', 'app', '--no-interaction'], $workspace);
    echo $command(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'], $project);
    copy($root . '/tests/Fixtures/project-smoke/SmokeHttp.php.stub', $project . '/app/SmokeHttp.php');
    file_put_contents($project . '/config/http.config.php', '<?php return new App\\SmokeHttp();');
    echo $command([PHP_BINARY, 'bolt', 'make:listener', 'SmokeAction', '--action=smoke'], $project);
    echo $command([PHP_BINARY, 'bolt', 'make:job', 'SmokeJob'], $project);
    foreach ([
        'SmokeAction.php' => 'app/Listeners/SmokeAction.php',
        'SmokeJobHandler.php' => 'app/Jobs/SmokeJobHandler.php',
    ] as $fixture => $destination) {
        copy($root . '/tests/Fixtures/project-smoke/' . $fixture . '.stub', $project . '/' . $destination);
    }
    echo $command([PHP_BINARY, 'bolt', 'listener:list', '--format=json'], $project);
    echo $command([PHP_BINARY, $root . '/tests/Fixtures/project-smoke/dispatch.php.stub'], $project);
    $outcome = json_decode(
        $command([PHP_BINARY, 'bolt', 'jobs:work', '--once'], $project),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (
        ($outcome['status'] ?? '') !== 'succeeded'
        || ($outcome['attempts'] ?? 0) !== 2
        || file_get_contents($project . '/receipt.txt') !== 'durable hello'
    ) {
        throw new RuntimeException('Fresh worker did not recover and complete the abandoned job.');
    }
    $detail = json_decode(
        $command([PHP_BINARY, 'bolt', 'jobs:show', $outcome['id']], $project),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (!in_array('lease_expired_retry', array_column($detail['history'], 'reason'), true)) {
        throw new RuntimeException('Recovery decision missing from job history.');
    }
    if (trim($command([PHP_BINARY, 'bolt', 'jobs:work', '--once'], $project)) !== 'null') {
        throw new RuntimeException('Completed fixture was executed again.');
    }
    echo
        "Fresh project passed: production install, discovery, signed mention/button, deduplication, process restart and recovery.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    $failed = true;
} finally {
    $remove($workspace);
}
exit(isset($failed) ? 1 : 0);
