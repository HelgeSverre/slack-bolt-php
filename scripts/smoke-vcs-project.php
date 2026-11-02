<?php

declare(strict_types=1);

// This proof never publishes anything. It snapshots only package inputs into a
// disposable local Git repository, so Composer exercises VCS resolution using
// the code currently in this working tree (including uncommitted changes).
$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/thunder-bolt-vcs-project-' . bin2hex(random_bytes(6));
$source = $workspace . '/source';
$project = $workspace . '/app';
/** @var array<string, string> $environment */
$environment = [];
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
$token = hash('sha256', 'thunder-bolt-vcs-smoke-token');
$signingSecret = hash('sha256', 'thunder-bolt-vcs-smoke-signing-secret');
$environment += [
    'SLACK_BOT_TOKEN' => $token,
    'SLACK_SIGNING_SECRET' => $signingSecret,
    'SLACK_BOT_USER_ID' => 'U-smoke-bot',
    'SLACK_CONTEXT_STORE' => 'memory',
    'SLACK_CONTEXT_STORE_PATH' => '',
    'SLACK_JOB_STORE_PATH' => '',
];

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 */
function run(array $command, string $workingDirectory, array $environment, int $expectedExit = 0): string
{
    if ($workingDirectory === '') {
        throw new RuntimeException('A working directory is required.');
    }
    $suffix = bin2hex(random_bytes(6));
    $stdoutPath = sys_get_temp_dir() . '/thunder-bolt-vcs-stdout-' . $suffix;
    $stderrPath = sys_get_temp_dir() . '/thunder-bolt-vcs-stderr-' . $suffix;
    /** @var array<int, resource> $pipes */
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']],
        $pipes,
        $workingDirectory,
        $environment,
    );
    if (!is_resource($process) || !isset($pipes[0])) {
        throw new RuntimeException('Unable to start smoke process.');
    }
    fclose($pipes[0]);
    $exit = proc_close($process);
    $stdout = read_output($stdoutPath);
    $stderr = read_output($stderrPath);
    unlink($stdoutPath);
    unlink($stderrPath);
    if ($exit !== $expectedExit) {
        throw new RuntimeException(implode(' ', $command) . " failed.\n" . $stdout . $stderr);
    }

    return $stdout;
}

function read_output(string $path): string
{
    $output = file_get_contents($path);

    return $output === false ? '' : substr($output, 0, 32_768);
}

/** @return array<array-key, mixed> */
function decode_json_array(string $json): array
{
    return (static function (mixed $decoded): array {
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON array output from the generated project.');
        }

        return $decoded;
    })(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
}

function snapshot_package(string $root, string $source): void
{
    mkdir($source, 0700, true);
    foreach (['composer.json', 'composer.lock', 'src', 'bin', 'stubs'] as $entry) {
        copy_entry($root . '/' . $entry, $source . '/' . $entry);
    }
}

function copy_entry(string $from, string $to): void
{
    if (is_file($from)) {
        $directory = dirname($to);
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        if (!copy($from, $to)) {
            throw new RuntimeException("Unable to copy {$from}.");
        }
        $permissions = fileperms($from);
        if ($permissions !== false) {
            chmod($to, $permissions & 0777);
        }

        return;
    }
    if (!is_dir($from)) {
        throw new RuntimeException("Snapshot input {$from} is missing.");
    }
    mkdir($to, 0700, true);
    foreach (new FilesystemIterator($from) as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }
        copy_entry($entry->getPathname(), $to . '/' . $entry->getFilename());
    }
}

function remove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            remove($file->getPathname());
        }
        rmdir($path);

        return;
    }
    if (file_exists($path) || is_link($path)) {
        unlink($path);
    }
}

mkdir($workspace, 0700, true);
try {
    snapshot_package($root, $source);
    run(['git', 'init', '--quiet', '--initial-branch=main', $source], $workspace, $environment);
    run(['git', '-C', $source, 'add', '--all'], $workspace, $environment);
    run(
        [
            'git',
            '-C',
            $source,
            '-c',
            'user.name=Thunder Bolt smoke',
            '-c',
            'user.email=smoke@example.test',
            'commit',
            '--quiet',
            '-m',
            'VCS fixture',
        ],
        $workspace,
        $environment,
    );
    run(
        [
            PHP_BINARY,
            $root . '/bin/bolt',
            'new',
            'app',
            '--source=vcs',
            '--repository=' . $source,
            '--version=dev-main',
            '--no-interaction',
        ],
        $workspace,
        $environment,
    );
    run(
        [
            getenv('COMPOSER_BINARY') ?: 'composer',
            'install',
            '--no-dev',
            '--no-interaction',
            '--no-progress',
            '--prefer-dist',
        ],
        $project,
        $environment,
    );
    if (is_link($project . '/vendor/helgesverre/slack-bolt')) {
        throw new RuntimeException('VCS installation must not be a path repository symlink.');
    }
    rename($source, $workspace . '/source-detached');
    $doctor = decode_json_array(run([PHP_BINARY, 'bolt', 'doctor', '--format=json'], $project, $environment));
    if (($doctor['ready'] ?? false) !== true || ($doctor['networkChecked'] ?? true) !== false) {
        throw new RuntimeException('The source-independent VCS project did not pass doctor.');
    }
    $listeners = decode_json_array(run([PHP_BINARY, 'bolt', 'listener:list', '--format=json'], $project, $environment));
    if ($listeners === []) {
        throw new RuntimeException('The source-independent VCS project did not discover its generated listener.');
    }
    run([PHP_BINARY, 'bolt', '--version'], $project, $environment);

    fwrite(STDOUT, "VCS project smoke test passed.\n");
} finally {
    remove($workspace);
}
