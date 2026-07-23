<?php
declare(strict_types=1);

/**
 * Atomic file replacement helpers.
 */
final class AtomicFile
{
    public static function write(string $file, string $contents, int $permissions = 0644): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory {$dir}");
        }

        $tmp = tempnam($dir, basename($file) . '.tmp.');
        if ($tmp === false) {
            throw new RuntimeException("Could not create temporary file in {$dir}");
        }

        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Could not write temporary file {$tmp}");
            }
            @chmod($tmp, $permissions);
            if (!@rename($tmp, $file)) {
                throw new RuntimeException("Could not replace {$file}");
            }
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param mixed $data
     */
    public static function writeJson(string $file, mixed $data, int $flags = JSON_PRETTY_PRINT): void
    {
        $json = json_encode($data, $flags);
        if ($json === false) {
            throw new RuntimeException('Could not encode JSON: ' . json_last_error_msg());
        }

        self::write($file, $json);
    }
}
