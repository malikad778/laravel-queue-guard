<?php

declare(strict_types=1);

namespace Malikad\QueueGuard\Core;

use Symfony\Component\Finder\Finder;

final class FileCollector
{
    /**
     * @param  list<string>  $paths
     * @param  list<string>  $exclude
     * @return list<string>
     */
    public function collect(array $paths, array $exclude = [], string $baseDir = ''): array
    {
        $candidateFiles = [];
        $directories = [];

        foreach ($paths as $path) {
            $resolved = $this->resolvePath($path, $baseDir);
            if (is_file($resolved) && str_ends_with($resolved, '.php')) {
                $candidateFiles[] = realpath($resolved) ?: $resolved;
            } elseif (is_dir($resolved)) {
                $directories[] = realpath($resolved) ?: $resolved;
            }
        }

        if ($directories !== []) {
            $finder = new Finder;
            $finder->files()->in($directories)->name('*.php');

            foreach ($finder as $fileInfo) {
                $real = $fileInfo->getRealPath();
                if ($real !== false) {
                    $candidateFiles[] = $real;
                }
            }
        }

        $files = [];
        foreach ($candidateFiles as $file) {
            if (! $this->isExcluded($file, $exclude, $baseDir)) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $exclude
     */
    public function isExcluded(string $filePath, array $exclude, string $baseDir = ''): bool
    {
        $normalizedFile = str_replace('\\', '/', $filePath);
        $normalizedBase = $baseDir !== '' ? rtrim(str_replace('\\', '/', $baseDir), '/') : '';

        $relative = $normalizedFile;
        if ($normalizedBase !== '' && str_starts_with($normalizedFile, $normalizedBase.'/')) {
            $relative = substr($normalizedFile, strlen($normalizedBase) + 1);
        }

        foreach ($exclude as $pattern) {
            $cleanPattern = trim(str_replace('\\', '/', $pattern), '/');
            if ($cleanPattern === '') {
                continue;
            }

            // Exact match
            if ($relative === $cleanPattern || $normalizedFile === $cleanPattern) {
                return true;
            }

            // Directory boundary match: (^|/)pattern(/|$)
            $regex = '#(^|/)'.preg_quote($cleanPattern, '#').'(/|$)#i';
            if (preg_match($regex, $relative) === 1 || preg_match($regex, $normalizedFile) === 1) {
                return true;
            }

            // If pattern contains slash (e.g. app/Legacy), check suffix if scanned inside subdirectory
            if (str_contains($cleanPattern, '/')) {
                $parts = explode('/', $cleanPattern);
                $suffix = implode('/', array_slice($parts, 1));
                $suffixRegex = '#(^|/)'.preg_quote($suffix, '#').'(/|$)#i';
                if (preg_match($suffixRegex, $relative) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function resolvePath(string $path, string $baseDir): string
    {
        if ($baseDir === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return rtrim($baseDir, '/\\').DIRECTORY_SEPARATOR.$path;
    }
}
