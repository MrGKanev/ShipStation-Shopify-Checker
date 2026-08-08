<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';

/**
 * File-backed toggles for the sidebar's shared history sections
 * (Missing Orders and Recent Activity), editable from Settings.
 */
class SidebarSettings
{
    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/sidebar_settings.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/sidebar_settings.json');
    }

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return [
            'show_missing_orders'  => true,
            'show_recent_activity' => true,
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function load(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return self::defaults();
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        return self::normalise(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function save(array $settings): void
    {
        AtomicFile::writeJson(self::file(), self::normalise($settings));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, bool>
     */
    private static function normalise(array $settings): array
    {
        $d = self::defaults();
        return [
            'show_missing_orders'  => (bool) ($settings['show_missing_orders']  ?? $d['show_missing_orders']),
            'show_recent_activity' => (bool) ($settings['show_recent_activity'] ?? $d['show_recent_activity']),
        ];
    }
}
