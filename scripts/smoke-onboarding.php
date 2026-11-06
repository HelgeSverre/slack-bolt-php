<?php

declare(strict_types=1);

// Disposable production-dependency install; HTTP is loopback and Slack is never contacted.
require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$workspace = sys_get_temp_dir() . '/bolt-onboarding-' . bin2hex(random_bytes(6));
mkdir($workspace, 0700);
$project = $workspace . '/app';
$runtime = $workspace . '/state';
$environment = [];
foreach ([
    'PATH',
    'HOME',
    'TMPDIR',
    'SystemRoot',
    'APPDATA',
    'LOCALAPPDATA',
    'USERPROFILE',
    'COMPOSER_CACHE_DIR',
    'PHPRC',
    'PHP_INI_SCAN_DIR',
] as $name) {
    $value = getenv($name);
    if (is_string($value))
        $environment[$name] = $value;
}
$command = static function (array $arguments, string $directory, int $expected = 0, array $overrides = []) use (
    $environment,
): string {
    $process = proc_open(
        $arguments,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
        $pipes,
        $directory,
        $overrides + $environment,
    );
    if (!is_resource($process))
        throw new RuntimeException('Cannot start onboarding smoke process.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== $expected)
        throw new RuntimeException('Onboarding smoke command failed: ' . implode(' ', $arguments));
    return $output ?: '';
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || !is_dir($path)) {
        if (!is_link($path))
            chmod($path, 0600);
        unlink($path);
        return;
    }
    chmod($path, 0700);
    foreach (new FilesystemIterator($path) as $file)
        $remove($file->getPathname());
    rmdir($path);
};
$server = null;
try {
    $command([
        PHP_BINARY,
        $root . '/bin/bolt',
        'new',
        'app',
        '--profile=interactive',
        '--source=path',
        '--no-interaction',
    ], $workspace);
    $command(['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'], $project);
    file_put_contents(
        $project . '/.env',
        "ENVIRONMENT=production\nBOLT_RUNTIME_PATH=\"{$runtime}\"\nSIGNING_KEY=\nSLACK_BOT_TOKEN=smoke-token\nSLACK_SIGNING_SECRET=smoke-signing\n",
    );
    chmod($project . '/.env', 0600);
    file_put_contents($project . '/app/RuntimeProbe.php', <<<'PROBE'
        <?php
        namespace App;
        use Tempest\Core\Kernel;
        use Tempest\Http\GenericResponse;
        use Tempest\Http\Session\Session;
        use Tempest\Http\Status;
        use Tempest\Router\Get;
        final readonly class RuntimeProbe {
            #[Get('/probe/runtime')]
            public function runtime(Kernel $kernel): GenericResponse {
                return new GenericResponse(Status::OK, $kernel->internalStorage);
            }
            #[Get('/probe/expired')]
            public function expired(): GenericResponse {
                return new GenericResponse(Status::GONE, '<h1>Connection link expired</h1>', ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store']);
            }
            #[Get('/probe/session')]
            public function session(Session $session): GenericResponse {
                $count = (int) $session->get('visits', 0) + 1;
                $session->set('visits', $count);
                return new GenericResponse(Status::OK, (string) $count);
            }
            #[Get('/probe/exception')]
            public function exception(): never { throw new \RuntimeException('private-exception-canary'); }
        }
        PROBE);
    $before = json_decode(
        $command([PHP_BINARY, 'bolt', 'doctor', '--sessions', '--format=json'], $project, expected: 1),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if ($before['sessionsReady'] !== false || $before['configuration']['SIGNING_KEY'] !== 'missing')
        throw new RuntimeException('Missing session key was not diagnosed.');
    $output = $command([PHP_BINARY, 'bolt', 'key:generate'], $project);
    $first = file_get_contents($project . '/.env');
    $key = Dotenv\Dotenv::parse($first)['SIGNING_KEY'];
    if (str_contains($output, $key))
        throw new RuntimeException('Key generator disclosed a key.');
    $command([PHP_BINARY, 'bolt', 'key:generate'], $project);
    if (file_get_contents($project . '/.env') !== $first)
        throw new RuntimeException('A repeated key command silently rotated a key.');
    $after = json_decode(
        $command([PHP_BINARY, 'bolt', 'doctor', '--sessions', '--workers', '--format=json'], $project),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    if ($after['sessionsReady'] !== true || !$after['ready'] || is_dir($project . '/.thunder-bolt'))
        throw new RuntimeException('External-runtime readiness failed.');
    $code = 'require "vendor/autoload.php"; echo Thunder\\Bolt\\Bootstrap::boot(getcwd())->get(Tempest\\Core\\Kernel::class)->internalStorage;';
    $cliRuntime = $command([PHP_BINARY, '-r', $code], $project);
    $override = $workspace . '/process-state';
    $processRuntime = $command([PHP_BINARY, '-r', $code], $project, overrides: ['BOLT_RUNTIME_PATH' => $override]);
    if ($processRuntime !== realpath($override))
        throw new RuntimeException('Process runtime did not override dotenv.');

    // Do not follow the path-repository symlink into the framework checkout.
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    ) as $file) {
        if (!$file->isLink())
            chmod($file->getPathname(), $file->isDir() ? 0555 : 0444);
    }
    chmod($project, 0555);

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false)
        throw new RuntimeException('Cannot reserve a loopback test port.');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open(
        [PHP_BINARY, '-S', $address, '-t', $project . '/public'],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $workspace . '/http.log', 'a'],
            2 => ['file', $workspace . '/http.log', 'a'],
        ],
        $pipes,
        $project . '/public',
        $environment,
    );
    if (!is_resource($server))
        throw new RuntimeException('Cannot start loopback test server.');
    fclose($pipes[0]);
    $http = new GuzzleHttp\Client([
        'base_uri' => 'http://' . $address,
        'http_errors' => false,
        'timeout' => 5,
        'cookies' => true,
    ]);
    $response = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        try {
            $response = $http->get('/probe/runtime');
            break;
        } catch (GuzzleHttp\Exception\ConnectException) {
            usleep(20_000);
        }
    }
    if (
        $response?->getStatusCode() !== 200
        || (string) $response->getBody() !== $cliRuntime
        || $cliRuntime !== realpath($runtime)
    )
        throw new RuntimeException('CLI and HTTP did not share external runtime storage.');
    $expired = $http->get('/probe/expired');
    if (
        $expired->getStatusCode() !== 410
        || (string) $expired->getBody() !== '<h1>Connection link expired</h1>'
        || $expired->getHeaderLine('Cache-Control') !== 'no-store'
    )
        throw new RuntimeException('A deliberate HTTP error was replaced.');
    if (
        (string) $http->get('/probe/session')->getBody() !== '1'
        || (string) $http->get('/probe/session')->getBody() !== '2'
    )
        throw new RuntimeException('Encrypted browser session did not persist.');
    $failureResponse = $http->get('/probe/exception');
    if (
        $failureResponse->getStatusCode() !== 500
        || str_contains((string) $failureResponse->getBody(), 'private-exception-canary')
    )
        throw new RuntimeException('Production exception handling regressed.');
    if (is_dir($project . '/.thunder-bolt') || is_dir($project . '/public/.thunder-bolt'))
        throw new RuntimeException('Runtime state leaked into a release or web root.');
    echo
        "Onboarding passed: fresh interactive app, external runtime shared by CLI/HTTP, environment precedence, deliberate HTTP 410, production exception handling, safe key generation and encrypted session round-trip.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $remove($workspace);
}
exit(isset($failed) ? 1 : 0);
