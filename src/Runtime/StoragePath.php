<?php

declare(strict_types=1);

namespace Thunder\Bolt\Runtime;

/** Resolves project-relative storage and checks both lexical and symlink exposure. */
final readonly class StoragePath
{
    public function __construct(
        private string $root,
        private string $path,
    ) {}

    public function absolute(): string
    {
        return str_starts_with($this->path, '/')
        || str_starts_with($this->path, '\\\\')
        || preg_match('/\A[A-Za-z]:[\\\\\/]/', $this->path) === 1
            ? $this->path
            : $this->root . '/' . $this->path;
    }

    /** @return array{writable: bool, public: bool} */
    public function inspect(bool $directory): array
    {
        $path = $this->absolute();
        $probe = $path;
        $suffix = [];
        while (!file_exists($probe) && dirname($probe) !== $probe) {
            array_unshift($suffix, basename($probe));
            $probe = dirname($probe);
        }
        $resolved = (realpath($probe) ?: $probe) . ($suffix === [] ? '' : '/' . implode('/', $suffix));
        $public = self::normalize($this->root . '/public');
        $resolvedPublic = self::normalize(realpath($this->root . '/public') ?: $this->root . '/public');
        $exposed = false;
        foreach ([self::normalize($path), self::normalize($resolved)] as $candidate) {
            foreach ([$public, $resolvedPublic] as $webRoot) {
                if ($candidate === $webRoot || str_starts_with($candidate, rtrim($webRoot, '/') . '/')) {
                    $exposed = true;
                }
            }
        }
        $wrongKind = file_exists($path) && ($directory ? !is_dir($path) : !is_file($path));

        return [
            'writable' =>
                !$wrongKind
                    && is_writable($probe)
                    && ($probe === $path || is_dir($probe))
                    && ($directory || !is_file($path) || is_writable(dirname($path))),
            'public' => $exposed,
        ];
    }

    private static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                if ($parts !== [] && !str_ends_with(end($parts), ':')) {
                    array_pop($parts);
                }
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return (str_starts_with($path, '//') ? '//' : (str_starts_with($path, '/') ? '/' : '')) . implode('/', $parts);
    }
}
