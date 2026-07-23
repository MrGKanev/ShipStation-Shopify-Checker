<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * Shared helpers for classes that store state in a single JSON file.
 * All writes use exclusive file locking so concurrent PHP processes are safe.
 */
trait JsonFileLock
{
    protected static function writeJson(string $file, callable $mutator): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        $lock = fopen($file . '.lock', 'c+');
        if ($lock === false) {
            throw new RuntimeException("Could not open lock file for {$file}");
        }

        try {
            flock($lock, LOCK_EX);
            $raw  = file_exists($file) ? (string) file_get_contents($file) : '';
            $data = $raw ? (json_decode($raw, true) ?: []) : [];
            $data = $mutator($data);
            AtomicFile::writeJson($file, $data);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected static function readJson(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }
        return json_decode(file_get_contents($file), true) ?: [];
    }
}
