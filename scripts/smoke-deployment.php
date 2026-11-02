<?php

declare(strict_types=1);

// Builds and runs only a disposable local deployment. Never publishes an image or calls Slack.
$options = getopt('', ['image:', 'skip-build']);
$name = 'bolt-deployment-smoke-' . bin2hex(random_bytes(6));
$image = $options['image'] ?? 'thunder-bolt-deployment:' . $name;
if (!is_string($image) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._\/:@-]*\z/', $image) !== 1)
    throw new InvalidArgumentException('Use a local Docker image name.');
$volume = $name . '-state';
$mount = $volume . ':/opt/thunder/work/app/.thunder-bolt/state';
$environment = ['-e', 'SLACK_BOT_TOKEN=local-fixture-token'];
$command = static function (array $arguments, int $expected = 0): string {
    $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, dirname(__DIR__));
    if (!is_resource($process))
        throw new RuntimeException('Cannot start deployment check.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== $expected)
        throw new RuntimeException('Deployment command failed: ' . implode(' ', $arguments));
    return trim($output);
};
$containerCreated = false;
$volumeCreated = false;
$imageCreated = false;
try {
    if (!isset($options['skip-build'])) {
        $command(['docker', 'build', '-f', 'examples/deployment/Dockerfile', '-t', $image, '.']);
        $imageCreated = true;
    }
    $command(['docker', 'volume', 'create', $volume]);
    $volumeCreated = true;
    $command(['docker', 'run', '--rm', ...$environment, '-v', $mount, $image, 'php', 'bolt', 'jobs:migrate']);
    $command(['docker', 'run', '-d', '--name', $name, ...$environment, '-v', $mount, $image]);
    $containerCreated = true;
    if ($command(['docker', 'exec', $name, 'id', '-u']) !== '10001' || $command(['docker', 'exec', $name, 'php', '-r', 'echo getenv("ENVIRONMENT");']) !== 'production')
        throw new RuntimeException('Deployment must run as the non-root application user in production mode.');
    $first = json_decode($command(['docker', 'exec', $name, 'php', 'bolt', 'demo:enqueue', 'deploy-check']), true, flags: JSON_THROW_ON_ERROR);
    $deadline = hrtime(true) + 30_000_000_000;
    do {
        $report = json_decode($command(['docker', 'exec', $name, 'php', 'bolt', 'status', '--format=json', '--require=jobs']), true, flags: JSON_THROW_ON_ERROR);
        if (($report['queue']['counts']['succeeded'] ?? 0) === 1)
            break;
        usleep(100_000);
    } while (hrtime(true) < $deadline);
    if (($report['healthy'] ?? false) !== true || ($report['queue']['counts']['succeeded'] ?? 0) !== 1)
        throw new RuntimeException('The supervised local worker did not complete its job.');
    $duplicate = json_decode($command(['docker', 'exec', $name, 'php', 'bolt', 'demo:enqueue', 'deploy-check']), true, flags: JSON_THROW_ON_ERROR);
    if ($duplicate['id'] !== $first['id'] || !$duplicate['duplicate'])
        throw new RuntimeException('Deployment did not preserve its duplicate receipt.');
    $command(['docker', 'exec', $name, 'php', '-r', 'if (file_get_contents(".thunder-bolt/state/receipt-".hash("sha256", "deploy-check").".txt") !== "deploy-check") exit(1);']);
    $command(['docker', 'stop', '--time', '10', $name]);
    $stopped = json_decode($command(['docker', 'run', '--rm', ...$environment, '-v', $mount, $image, 'php', 'bolt', 'status', '--format=json', '--require=jobs'], 1), true, flags: JSON_THROW_ON_ERROR);
    if ($stopped['healthy'] || $stopped['active']['jobs'] !== 0)
        throw new RuntimeException('Stopped worker still appears healthy.');
    $command(['docker', 'start', $name]);
    $afterRestart = json_decode($command(['docker', 'exec', $name, 'php', 'bolt', 'jobs:list']), true, flags: JSON_THROW_ON_ERROR);
    if (count($afterRestart) !== 1 || $afterRestart[0]['id'] !== $first['id'] || $afterRestart[0]['attempts'] !== 1)
        throw new RuntimeException('Restart lost or repeated a completed job.');
    $command(['docker', 'stop', '--time', '10', $name]);
    fwrite(STDOUT, "Deployment passed: non-root production worker, observed health, durable receipt, deduplication and graceful restart.\n");
} finally {
    if ($containerCreated)
        $command(['docker', 'rm', '-f', $name]);
    if ($volumeCreated)
        $command(['docker', 'volume', 'rm', $volume]);
    if ($imageCreated && !isset($options['image']))
        $command(['docker', 'image', 'rm', $image]);
}
