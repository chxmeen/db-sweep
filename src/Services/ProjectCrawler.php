<?php

declare(strict_types=1);

namespace DbSweep\Services;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use UnexpectedValueException;

final class ProjectCrawler
{
    private const MAX_DEPTH = 5;

    private const SKIP_DIRS = [
        'vendor',
        'node_modules',
        '.git',
        '.cache',
        'storage',
        'var',
        'tmp',
    ];

    /** @return list<string> */
    public function discover(string $root, bool $caseInsensitive = false): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $databases = [];

        try {
            $directory = new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
            );

            // Prune directory recursion at iterator level for performance; case-fold for APFS/macOS compatibility
            $filtered = new RecursiveCallbackFilterIterator(
                $directory,
                function (\SplFileInfo $file, string $key, RecursiveDirectoryIterator $iterator) {
                    if ($file->isDir()) {
                        return !in_array(mb_strtolower($file->getFilename()), self::SKIP_DIRS, true);
                    }

                    return true;
                }
            );

            $iterator = new RecursiveIteratorIterator(
                $filtered,
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            $iterator->setMaxDepth(self::MAX_DEPTH);

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || !$file->isReadable()) {
                    continue;
                }

                $filename = $file->getFilename();

                if (preg_match('/^\.env(\.[a-zA-Z0-9._-]+)?$/', $filename)) {
                    $databases = array_merge($databases, $this->parseEnvFile($file->getPathname()));
                } elseif ($filename === 'wp-config.php') {
                    $databases = array_merge($databases, $this->parseWpConfig($file->getPathname()));
                } elseif (preg_match('/^(docker-)?compose(\.[a-zA-Z0-9._-]+)?\.ya?ml$/', $filename)) {
                    $databases = array_merge($databases, $this->parseDockerCompose($file->getPathname()));
                }
            }
        } catch (UnexpectedValueException) {
            return [];
        }

        $databases = array_unique(array_filter($databases));

        if ($caseInsensitive) {
            $databases = array_unique(array_map('mb_strtolower', $databases));
        }

        return array_values($databases);
    }

    /** @return list<string> */
    private function parseEnvFile(string $path): array
    {
        $names = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // DB_DATABASE*, WORDPRESS_DB_NAME, and container variables with optional export prefix
            if (preg_match('/^(?:export\s+)?(DB_DATABASE\w*|WORDPRESS_DB_NAME|MYSQL_DATABASE|MARIADB_DATABASE)\s*=\s*(.+)$/i', $line, $m)) {
                $names[] = $this->stripValue($m[2]);
                continue;
            }

            // DATABASE_URL or MYSQL_URL connection strings (handles quotes and percent-encoding)
            if (preg_match('/^(?:export\s+)?(?:DATABASE_URL|MYSQL_URL)\s*=\s*["\']?mysql:\/\/[^\/]*\/([^\s?#"\']+)/i', $line, $m)) {
                $names[] = urldecode($this->stripValue($m[1]));
            }
        }

        return $names;
    }

    /** @return list<string> */
    private function parseWpConfig(string $path): array
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            return [];
        }

        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
            return [$m[1]];
        }

        return [];
    }

    /** @return list<string> */
    private function parseDockerCompose(string $path): array
    {
        $names = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            if (preg_match('/(?:MYSQL_DATABASE|MARIADB_DATABASE)\s*[:=]\s*(.+)$/i', $line, $m)) {
                $val = $this->stripValue(trim($m[1]));

                // Resolve variable fallbacks like ${DB_NAME:-default_db}
                if (preg_match('/^\$\{[a-zA-Z0-9_]+:-(.+)\}$/', $val, $vm)) {
                    $val = $this->stripValue($vm[1]);
                } elseif (preg_match('/^\$\{?[a-zA-Z0-9_]+\}?$/', $val)) {
                    continue;
                }

                if ($val !== '') {
                    $names[] = $val;
                }
            }
        }

        return $names;
    }

    private function stripValue(string $raw): string
    {
        $raw = trim($raw);

        // Quoted value — extract content between matching quotes, ignore anything after
        if (preg_match('/^(["\'])(.*?)\\1/', $raw, $m)) {
            return $m[2];
        }

        // Unquoted — strip inline comments (including values that are entirely a comment)
        $raw = preg_replace('/(^|\s+)#.*$/', '', $raw) ?? $raw;

        return trim($raw);
    }
}
