<?php

declare(strict_types=1);

namespace MarkusDoell\LogInsight\Support;

/**
 * Maps absolute file paths from a log (which may come from another server
 * or deployment directory) to paths relative to the application root.
 */
final readonly class AppPaths
{
    /** @var list<string> */
    private array $appPaths;

    /**
     * @param  list<string>  $appPaths  application directories, e.g. ['app/', 'src/']
     */
    public function __construct(
        private string $basePath,
        array $appPaths,
    ) {
        $this->appPaths = array_values(array_filter(
            array_map(static fn (string $path): string => trim($path, '/').'/', $appPaths),
            static fn (string $path): bool => $path !== '/',
        ));
    }

    /**
     * The path relative to the application root, or null for vendor files
     * and files outside the configured application directories.
     */
    public function relative(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);

        if (str_contains($file, '/vendor/') || str_starts_with($file, 'vendor/')) {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', $this->basePath), '/').'/';

        if ($base !== '/' && str_starts_with($file, $base)) {
            $relative = substr($file, strlen($base));

            return $this->isApplicationPath($relative) ? $relative : null;
        }

        // Log written on another machine or in another release directory:
        // use the right-most application directory found in the path.
        $best = null;

        foreach ($this->appPaths as $appPath) {
            $position = strrpos('/'.$file, '/'.$appPath);

            if ($position !== false && ($best === null || $position > $best)) {
                $best = $position;
            }
        }

        return $best === null ? null : substr('/'.$file, $best + 1);
    }

    public function isApplicationFile(string $file): bool
    {
        return $this->relative($file) !== null;
    }

    /**
     * Absolute path of an application file on this machine.
     */
    public function local(string $relative): string
    {
        return rtrim($this->basePath, '/').'/'.ltrim($relative, '/');
    }

    /**
     * Readable local file for an application-relative path, or null when it
     * does not exist or resolves outside the base path (e.g. "app/../../etc/passwd").
     */
    public function readableLocal(string $relative): ?string
    {
        $base = realpath($this->basePath);
        $file = realpath($this->local($relative));

        if ($base === false || $file === false || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        return str_starts_with($file, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ? $file : null;
    }

    private function isApplicationPath(string $relative): bool
    {
        foreach ($this->appPaths as $appPath) {
            if (str_starts_with($relative, $appPath)) {
                return true;
            }
        }

        return false;
    }
}
