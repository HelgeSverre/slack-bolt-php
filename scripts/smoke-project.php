<?php

declare(strict_types=1);

// Explicit integration check: resolves production dependencies with Composer.
// Slack traffic is intercepted; this script never reads workspace credentials.
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/bolt-project-smoke-' . bin2hex(random_bytes(6));
mkdir($workspace, 0700);
$project = $workspace . '/app';
$runtime = $workspace . '/runtime';
$environment = [];
// Keep executable/runtime paths, not ambient application keys or tokens.
foreach ([
    'PATH',
    'HOME',
    'TMPDIR',
    'TMP',
    'TEMP',
    'LANG',
    'LC_ALL',
    'PHPRC',
    'PHP_INI_SCAN_DIR',
    'SystemRoot',
    'COMSPEC',
    'PATHEXT',
    'APPDATA',
    'LOCALAPPDATA',
    'USERPROFILE',
    'COMPOSER_CACHE_DIR',
] as $name) {
    $value = getenv($name);
    if (is_string($value)) {
        $environment[$name] = $value;
    }
}
$environment += [
    'BOLT_RUNTIME_PATH' => $runtime,
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
$command = static function (array $arguments, string $directory, int $expectedExit = 0) use ($environment): string {
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
    if (proc_close($process) !== $expectedExit) {
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
    echo $command([PHP_BINARY, $root . '/bin/bolt', 'new', 'app', '--source=path', '--no-interaction'], $workspace);
    echo $command(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'], $project);
    copy($root . '/tests/Fixtures/project-smoke/SmokeHttp.php.stub', $project . '/app/SmokeHttp.php');
    file_put_contents($project . '/config/http.config.php', '<?php return new App\\SmokeHttp();');
    $doctor = json_decode($command([PHP_BINARY, 'bolt', 'doctor', '--format=json'], $project), true, flags: JSON_THROW_ON_ERROR);
    if (($doctor['ready'] ?? false) !== true || ($doctor['networkChecked'] ?? true) !== false) {
        throw new RuntimeException('Fresh application readiness check failed.');
    }
    $status = json_decode($command([PHP_BINARY, 'bolt', 'status', '--format=json', '--require=jobs'], $project, expectedExit: 1), true, flags: JSON_THROW_ON_ERROR);
    if (($status['healthy'] ?? true) !== false || ($status['active']['jobs'] ?? -1) !== 0 || is_file($runtime . '/jobs/queue.sqlite'))
        throw new RuntimeException('Status must not invent a running worker or initialize its queue.');
    $badConfig = $project . '/config/doctor-failure.config.php';
    file_put_contents($badConfig, '<?php throw new RuntimeException("doctor-private-canary");');
    try {
        $failureOutput = $command([PHP_BINARY, 'bolt', 'doctor', '--format=json'], $project, expectedExit: 1);
        $failure = json_decode($failureOutput, true, flags: JSON_THROW_ON_ERROR);
        if (($failure['configurationError'] ?? false) !== true || str_contains($failureOutput, 'doctor-private-canary')) {
            throw new RuntimeException('Doctor did not safely report invalid application configuration.');
        }
        $statusFailure = $command([PHP_BINARY, 'bolt', 'status', '--format=json'], $project, expectedExit: 1);
        if (json_decode($statusFailure, true, flags: JSON_THROW_ON_ERROR)['healthy'] !== false || str_contains($statusFailure, 'doctor-private-canary'))
            throw new RuntimeException('Status did not safely report invalid configuration.');
    } finally {
        unlink($badConfig);
    }
    echo $command([PHP_BINARY, 'bolt', 'make:listener', 'SmokeAction', '--action=smoke'], $project);
    echo $command([PHP_BINARY, 'bolt', 'make:job', 'SmokeJob'], $project);
    foreach ([
        'SmokeAction.php' => 'app/Listeners/SmokeAction.php',
        'SmokeJobHandler.php' => 'app/Jobs/SmokeJobHandler.php',
    ] as $fixture => $destination) {
        copy($root . '/tests/Fixtures/project-smoke/' . $fixture . '.stub', $project . '/' . $destination);
    }
    echo $command([PHP_BINARY, 'bolt', 'listener:list', '--format=json'], $project);
    $identity = json_decode($command([PHP_BINARY, 'bolt', 'auth:verify'], $project), true, flags: JSON_THROW_ON_ERROR);
    if (($identity['bot_id'] ?? null) !== 'B-smoke' || ($identity['user_id'] ?? null) !== 'U-smoke-bot') {
        throw new RuntimeException('Static token verification did not return the fixture identity.');
    }
    copy($root . '/tests/Fixtures/project-smoke/bolt.config.php.stub', $project . '/config/bolt.config.php');
    echo $command([PHP_BINARY, $root . '/tests/Fixtures/project-smoke/dispatch.php.stub'], $project);
    $outcome = json_decode(
        $command([PHP_BINARY, 'bolt', 'jobs:work', '--once'], $project),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if (
        ($outcome['status'] ?? '') !== 'succeeded'
        || ($outcome['attempts'] ?? 0) !== 2
        || file_get_contents($project . '/receipt.txt') !== '42:durable hello:smoke:urgent'
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
    $manifestBefore = file_get_contents($project . '/slack-manifest.yaml');
    echo $command([PHP_BINARY, 'bolt', 'make:assistant', 'FreshAgent', '--agent-view'], $project);
    if (file_get_contents($project . '/slack-manifest.yaml') !== $manifestBefore) {
        throw new RuntimeException('Agent generator changed the application manifest.');
    }
    $completion = $project . '/.thunder-bolt/agent-completion.json';
    $command([PHP_BINARY, 'bolt', 'completion:generate', $completion], $project);
    if (!str_contains((string) file_get_contents($completion), 'agent-view')) {
        throw new RuntimeException('Agent View flag is missing from discovered completions.');
    }
    echo $command([PHP_BINARY, $root . '/tests/Fixtures/project-smoke/agent-view.php.stub'], $project);
    echo
        "Fresh project passed: production install, discovery, OAuth metadata, HTTP/Socket context and typed forms, deduplication, worker restart and recovery.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    $failed = true;
} finally {
    $remove($workspace);
}
exit(isset($failed) ? 1 : 0);
