<?php
declare(strict_types=1);

/**
 * Recursive cleanup for temp directories used as RunLog/AuditSnapshot data dirs
 * in tests, which now nest files under subdirectories (e.g. history/<tool>/*.json).
 */
final class TmpDir
{
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "{$dir}/{$item}";
            is_dir($path) ? self::remove($path) : unlink($path);
        }
        rmdir($dir);
    }
}
