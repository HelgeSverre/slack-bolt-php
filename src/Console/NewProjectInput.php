<?php

declare(strict_types=1);

namespace Thunder\Bolt\Console;

final readonly class NewProjectInput
{
    public const string USAGE = BoltCli::NEW_USAGE;

    private function __construct(
        public string $directory,
        public ProjectSetup $setup,
        public ProjectSource $source,
    ) {}

    /** @param list<string> $arguments Arguments after new; no prompting or network activity. */
    public static function fromArguments(array $arguments, ?string $packageRoot = null): self
    {
        $directory = null;
        $options = [];
        for ($i = 0; $i < count($arguments); $i++) {
            $argument = $arguments[$i];
            if ($argument === '--no-interaction') {
                continue;
            }
            if (str_starts_with($argument, '--')) {
                [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
                if (
                    !in_array($key, ['transport', 'auth', 'profile', 'source', 'repository', 'version'], true)
                    || array_key_exists($key, $options)
                ) {
                    throw new \InvalidArgumentException('Use each supported option once. ' . self::USAGE);
                }
                $value ??= $arguments[++$i] ?? null;
                if ($value === null || str_starts_with($value, '-')) {
                    throw new \InvalidArgumentException("--{$key} requires a value. " . self::USAGE);
                }
                $options[$key] = $value;
            } elseif ($directory === null && $argument !== '' && !str_starts_with($argument, '-')) {
                $directory = $argument;
            } else {
                throw new \InvalidArgumentException(self::USAGE);
            }
        }
        if ($directory === null) {
            throw new \InvalidArgumentException(self::USAGE);
        }
        return new self(
            $directory,
            new ProjectSetup(
                $options['transport'] ?? 'http',
                $options['auth'] ?? 'token',
                $options['profile'] ?? 'mentions',
            ),
            ProjectSource::fromOptions(
                $options['source'] ?? null,
                $options['repository'] ?? null,
                $options['version'] ?? null,
                $packageRoot ?? dirname(__DIR__, 2),
            ),
        );
    }
}
