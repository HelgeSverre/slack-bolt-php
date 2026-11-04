<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Composer\InstalledVersions;
use Tempest\Console\Actions\ExecuteConsoleCommand;
use Tempest\Console\Console;
use Tempest\Console\ConsoleConfig;
use Tempest\Console\Input\ConsoleArgumentBag;
use Tempest\Core\Kernel;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Highlight\Highlighter;

/**
 * Starts Bolt from a package checkout or Composer's vendor proxy.
 *
 * It deliberately owns only command-line orchestration: application wiring
 * remains in Tempest and project behavior remains in the discovered commands.
 */
final class BoltCli
{
    public const string NEW_USAGE = 'Usage: php /path/to/bolt new <directory> [--transport=http|socket] [--auth=token|oauth] [--source=packagist|path|vcs] [--repository=<url-or-local-git-repository>] [--version=<constraint>] [--no-interaction]';

    /** @param list<string> $arguments */
    public static function run(array $arguments, string $packageRoot, ?string $composerAutoload = null): int
    {
        if (self::isVersionRequest($arguments)) {
            self::writeVersion($packageRoot, $composerAutoload);

            return 0;
        }
        if (self::isNewCommand($arguments) && self::isHelpRequest($arguments)) {
            fwrite(STDOUT, self::NEW_USAGE . PHP_EOL);

            return 0;
        }

        $projectRoot = getcwd();
        if (!is_string($projectRoot)) {
            fwrite(STDERR, "Unable to determine the current directory.\n");

            return 2;
        }
        $hasProject = is_file($projectRoot . '/composer.json');
        if (!$hasProject && self::isHelpRequest($arguments)) {
            self::writeGlobalHelp();

            return 0;
        }
        if (!$hasProject && !self::isNewCommand($arguments)) {
            fwrite(STDERR, "Run this command from a Composer project, or use `php /path/to/bolt new <directory>`.\n");

            return 2;
        }
        if (!self::isNewCommand($arguments) && !is_file($projectRoot . '/vendor/composer/installed.json')) {
            return self::writeMissingDependenciesError($arguments);
        }
        if (!self::loadAutoloaders($packageRoot, $projectRoot, $composerAutoload)) {
            return 1;
        }
        if (self::isNewCommand($arguments)) {
            return self::createProject(array_slice($arguments, 1), $packageRoot);
        }

        return self::dispatchProjectCommand($arguments, $projectRoot);
    }

    /** @param list<string> $arguments */
    private static function isVersionRequest(array $arguments): bool
    {
        return count($arguments) === 1 && in_array($arguments[0], ['--version', '-V'], true);
    }

    /** @param list<string> $arguments */
    private static function isNewCommand(array $arguments): bool
    {
        return ($arguments[0] ?? null) === 'new';
    }

    /** @param list<string> $arguments */
    private static function isHelpRequest(array $arguments): bool
    {
        return in_array('--help', $arguments, true) || in_array('-h', $arguments, true);
    }

    private static function writeVersion(string $packageRoot, ?string $composerAutoload): void
    {
        $version = '0.7.x-dev';
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('helgesverre/slack-bolt')) {
            $version = InstalledVersions::getPrettyVersion('helgesverre/slack-bolt') ?? $version;
        } else {
            $installed =
                ($composerAutoload === null ? dirname($packageRoot, 2) : dirname($composerAutoload))
                . '/composer/installed.json';
            if (is_file($installed)) {
                $contents = file_get_contents($installed);
                $metadata = $contents === false ? null : json_decode($contents, true);
                $packages = is_array($metadata) ? $metadata['packages'] ?? $metadata : [];
                if (is_array($packages)) {
                    foreach ($packages as $package) {
                        if (
                            is_array($package)
                            && ($package['name'] ?? null) === 'helgesverre/slack-bolt'
                            && is_string($package['version'] ?? null)
                        ) {
                            $version = $package['version'];
                            break;
                        }
                    }
                }
            }
        }
        fwrite(STDOUT, "Thunder Bolt {$version}\n");
    }

    private static function writeGlobalHelp(): void
    {
        fwrite(
            STDOUT,
            "Thunder Bolt\n\n"
            . self::NEW_USAGE
            . "\n\nAfter installing a project, use php vendor/bin/bolt --help for its local commands.\n",
        );
    }

    private static function loadAutoloaders(string $packageRoot, string $projectRoot, ?string $composerAutoload): bool
    {
        $autoloads = [];
        if ($composerAutoload !== null && is_file($composerAutoload)) {
            $autoloads[] = $composerAutoload;
        } else {
            // A direct checkout can load itself. A project autoloader then adds
            // discovered App classes without changing the framework bootstrap.
            foreach ([$packageRoot . '/vendor/autoload.php', $projectRoot . '/vendor/autoload.php'] as $autoload) {
                if (!is_file($autoload)) {
                    continue;
                }
                $autoloads[] = $autoload;
            }
        }
        $autoloads = array_values(array_unique($autoloads));
        if ($autoloads === []) {
            fwrite(STDERR, "Unable to locate Composer autoload.php. Run composer install first.\n");

            return false;
        }
        foreach ($autoloads as $autoload) {
            require_once $autoload;
        }

        return true;
    }

    /** @param list<string> $arguments */
    private static function createProject(array $arguments, string $packageRoot): int
    {
        try {
            $input = NewProjectInput::fromArguments($arguments, $packageRoot);
            $project = new ProjectScaffold($packageRoot)->create($input->directory, $input->setup, $input->source);
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
        fwrite(STDOUT, "Created {$project}" . PHP_EOL);
        fwrite(
            STDOUT,
            'Next: cd '
            . $input->directory
            . ' && composer install && cp .env.example .env && php bolt doctor'
            . PHP_EOL,
        );
        fwrite(
            STDOUT,
            'After configuration: '
            . $input->setup->startCommand()
            . '. Read README.md for OAuth and worker setup.'
            . PHP_EOL,
        );

        return 0;
    }

    /** @param list<string> $arguments */
    private static function dispatchProjectCommand(array $arguments, string $projectRoot): int
    {
        try {
            $container = \Tempest\Core\Tempest::boot(
                root: $projectRoot,
                discoveryLocations: is_dir($projectRoot . '/config')
                    ? [new DiscoveryLocation('', $projectRoot . '/config')]
                    : [],
                internalStorage: $projectRoot . '/.thunder-bolt',
            );
        } catch (\Throwable $error) {
            return self::writeDiagnosticError($arguments, $projectRoot, $error);
        }
        $consoleConfig = $container->get(ConsoleConfig::class);
        $consoleConfig->name = 'Thunder Bolt';
        CommandCatalog::configure($consoleConfig);
        if (!function_exists('stream_isatty') || !stream_isatty(STDOUT)) {
            $container->unregister(Console::class);
            $container->unregister(Highlighter::class, tagged: true);
            $container->singleton(Highlighter::class, new Highlighter(new PlainTheme()), 'console');
        }
        $exitCode = $container->get(ExecuteConsoleCommand::class)(
            $container->get(ConsoleArgumentBag::class)->getCommandName(),
        );
        $exitCode = is_int($exitCode) ? $exitCode : $exitCode->value;
        $container->get(Kernel::class)->shutdown($exitCode);

        return $exitCode;
    }

    /** @param list<string> $arguments */
    private static function writeDiagnosticError(array $arguments, string $projectRoot, \Throwable $error): int
    {
        if (!in_array($arguments[0] ?? null, ['doctor', 'status'], true)) {
            throw $error;
        }
        if (!is_file($projectRoot . '/vendor/composer/installed.json')) {
            return self::writeMissingDependenciesError($arguments);
        }
        $message = 'Application configuration could not be loaded. Check .env and config/; OAuth requires complete credentials and an HTTPS redirect URL.';
        return self::writeDiagnosticMessage($arguments, $message);
    }

    /** @param list<string> $arguments */
    private static function writeMissingDependenciesError(array $arguments): int
    {
        $message = 'Composer dependencies are missing. Run composer install in this project, then run doctor again.';
        return self::writeDiagnosticMessage($arguments, $message);
    }

    /** @param list<string> $arguments */
    private static function writeDiagnosticMessage(array $arguments, string $message): int
    {
        $formatIndex = array_search('--format', $arguments, true);
        $json =
            in_array($arguments[0] ?? null, ['doctor', 'status'], true)
            && (
                in_array('--format=json', $arguments, true)
                || $formatIndex !== false
                && ($arguments[$formatIndex + 1] ?? null) === 'json'
            );
        if ($json) {
            fwrite(STDOUT, json_encode([
                'ok' => false,
                'ready' => false,
                'healthy' => false,
                'configurationError' => true,
                'failures' => [$message],
            ], JSON_THROW_ON_ERROR)
                . PHP_EOL);
        } else {
            fwrite(STDERR, $message . PHP_EOL);
        }

        return 1;
    }
}
