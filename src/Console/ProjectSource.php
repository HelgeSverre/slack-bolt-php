<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use InvalidArgumentException;

/** A generated project's dependency requirement and optional Composer repository. */
final readonly class ProjectSource
{
    public const string PACKAGIST = 'packagist';
    public const string PATH = 'path';
    public const string VCS = 'vcs';
    public const string DEFAULT_VERSION = '^0.5';
    public const string DEVELOPMENT_VERSION = '0.5.x-dev';

    private function __construct(
        public string $type,
        public ?string $repository,
        public string $version,
    ) {}

    public static function local(string $packageRoot): self
    {
        return new self(self::PATH, $packageRoot, self::DEVELOPMENT_VERSION);
    }

    public static function fromOptions(?string $type, ?string $repository, ?string $version, string $packageRoot): self
    {
        $type ??= self::PACKAGIST;
        if (!in_array($type, [self::PACKAGIST, self::PATH, self::VCS], true)) {
            throw new InvalidArgumentException('Source must be packagist, path or vcs.');
        }
        if ($type === self::PACKAGIST) {
            if ($repository !== null) {
                throw new InvalidArgumentException('--repository requires --source=vcs.');
            }
            return new self(self::PACKAGIST, null, self::validateVersion($version ?? self::DEFAULT_VERSION));
        }
        if ($type === self::PATH) {
            if ($repository !== null || $version !== null) {
                throw new InvalidArgumentException('--repository and --version require --source=vcs.');
            }

            return self::local($packageRoot);
        }
        if ($repository === null) {
            throw new InvalidArgumentException('--source=vcs requires --repository.');
        }
        $repository = self::validateRepository($repository);
        $version = self::validateVersion($version ?? 'dev-main');

        return new self(self::VCS, $repository, $version);
    }

    private static function validateVersion(string $version): string
    {
        if ($version === 'dev-main') {
            return $version;
        }
        try {
            $parser = new VersionParser();
            if (Intervals::isSubsetOf($parser->parseConstraints($version), $parser->parseConstraints('>=0.3.0'))) {
                return $version;
            }
        } catch (\UnexpectedValueException) {
            // Keep input errors actionable without echoing arbitrary command-line data.
        }
        throw new InvalidArgumentException(
            'Use a valid Composer constraint requiring version 0.3.0 or newer, or dev-main.',
        );
    }

    /** @return null|array{type: 'path'|'vcs', url: string, options?: array{symlink: true, versions: array<string, string>}} */
    public function composerRepository(): ?array
    {
        if ($this->type === self::PACKAGIST) {
            return null;
        }
        if ($this->type === self::VCS) {
            return [
                'type' => 'vcs',
                'url' => $this->repository ?? throw new \LogicException('A local or VCS source requires a repository.'),
            ];
        }

        return [
            'type' => 'path',
            'url' => $this->repository ?? throw new \LogicException('A path source requires a repository.'),
            'options' => ['symlink' => true, 'versions' => ['helgesverre/slack-bolt' => $this->version]],
        ];
    }

    private static function validateRepository(string $repository): string
    {
        $repository = trim($repository);
        if ($repository === '' || str_contains($repository, "\0") || preg_match('/\s/', $repository)) {
            throw new InvalidArgumentException('--repository must not be empty or contain whitespace.');
        }
        if (str_starts_with($repository, 'https://')) {
            $parts = parse_url($repository);
            if (
                !is_array($parts)
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
                || !isset($parts['host'])
            ) {
                throw new InvalidArgumentException(
                    '--repository must not embed credentials. Use an HTTPS URL without user info.',
                );
            }

            return $repository;
        }
        if (preg_match('#^git@[A-Za-z0-9.-]+:[A-Za-z0-9._/-]+(?:\.git)?$#', $repository)) {
            return $repository;
        }
        if (self::isAbsolutePath($repository) && is_dir($repository)) {
            $resolved = realpath($repository);
            if ($resolved !== false && self::isGitRepository($resolved)) {
                return $resolved;
            }
        }

        throw new InvalidArgumentException(
            '--repository must be an HTTPS URL, a git@ SSH URL, or an absolute local Git repository.',
        );
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private static function isGitRepository(string $path): bool
    {
        $bare = self::gitOutput(['git', '-C', $path, 'rev-parse', '--is-bare-repository']);
        $gitDirectory = self::gitOutput(['git', '-C', $path, 'rev-parse', '--absolute-git-dir']);
        if ($bare === null || $gitDirectory === null) {
            return false;
        }
        $gitDirectory = realpath(trim($gitDirectory));
        if ($gitDirectory === false) {
            return false;
        }
        if (trim($bare) === 'true') {
            return $gitDirectory === $path;
        }

        $topLevel = self::gitOutput(['git', '-C', $path, 'rev-parse', '--show-toplevel']);
        $prefix = self::gitOutput(['git', '-C', $path, 'rev-parse', '--show-prefix']);

        return $topLevel !== null && $prefix !== null && realpath(trim($topLevel)) === $path && trim($prefix) === '';
    }

    /** @param list<string> $arguments */
    private static function gitOutput(array $arguments): ?string
    {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process) || !isset($pipes[0], $pipes[1], $pipes[2])) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $output !== false ? $output : null;
    }
}
