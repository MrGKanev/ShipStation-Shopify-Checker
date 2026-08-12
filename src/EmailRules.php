<?php
declare(strict_types=1);

require_once __DIR__ . '/AtomicFile.php';
require_once __DIR__ . '/ToolRegistry.php';

/**
 * File-backed, per-tool email notification rules. Unlike SlackRules (one
 * shared toggle for "audit" and one for "all scans"), each tool in
 * ToolRegistry::triggerCatalog() gets its own mode/threshold/recipient so
 * operators can pick exactly which checks land in an inbox and where.
 */
class EmailRules
{
    private const array MODES = ['off', 'immediate', 'digest'];

    private static string $customFile = '';

    public static function setDataDir(string $dir): void
    {
        self::$customFile = rtrim($dir, '/') . '/email_rules.json';
    }

    private static function file(): string
    {
        return self::$customFile ?: (__DIR__ . '/../data/email_rules.json');
    }

    /**
     * @return array<string, array{mode: string, threshold: int, include_zero: bool, email: string}>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (ToolRegistry::triggerCatalog() as $tool => $meta) {
            $defaults[$tool] = self::defaultRule();
        }
        return $defaults;
    }

    /**
     * @return array{mode: string, threshold: int, include_zero: bool, email: string}
     */
    private static function defaultRule(): array
    {
        return ['mode' => 'off', 'threshold' => 1, 'include_zero' => false, 'email' => ''];
    }

    /**
     * @return array<string, array{mode: string, threshold: int, include_zero: bool, email: string}>
     */
    public static function load(): array
    {
        $file = self::file();
        if (!file_exists($file)) {
            return self::defaults();
        }
        $decoded = json_decode(file_get_contents($file), true);
        return self::normalise(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function save(array $rules): void
    {
        $file = self::file();
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        AtomicFile::writeJson($file, self::normalise($rules));
    }

    /**
     * Fills in every catalog tool (defaulting to 'off'), drops entries for
     * unknown tools, and coerces/clamps each field. An invalid email address
     * is silently cleared rather than rejected, since a blank email means
     * "fall back to ALERT_EMAIL" - the safe default.
     *
     * @param  array<string, mixed> $rules
     * @return array<string, array{mode: string, threshold: int, include_zero: bool, email: string}>
     */
    public static function normalise(array $rules): array
    {
        $normalised = [];
        foreach (ToolRegistry::triggerCatalog() as $tool => $meta) {
            $raw = is_array($rules[$tool] ?? null) ? $rules[$tool] : [];

            $mode = (string) ($raw['mode'] ?? 'off');
            if (!in_array($mode, self::MODES, true)) {
                $mode = 'off';
            }

            $minThreshold = $tool === 'run_audit' ? 0 : 1;
            $threshold = max($minThreshold, (int) ($raw['threshold'] ?? 1));

            $email = trim((string) ($raw['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
            }

            $normalised[$tool] = [
                'mode'         => $mode,
                'threshold'    => $threshold,
                'include_zero' => (bool) ($raw['include_zero'] ?? false),
                'email'        => $email,
            ];
        }
        return $normalised;
    }

    /**
     * @return array{mode: string, threshold: int, include_zero: bool, email: string}
     */
    public static function ruleFor(string $tool): array
    {
        return self::load()[$tool] ?? self::defaultRule();
    }

    /**
     * Whether $count clears the tool's threshold, independent of mode.
     * Shared by shouldNotify() (immediate sends) and EmailDigest::buildSections()
     * (digest sends use the same threshold semantics, just on a delay).
     */
    public static function meetsThreshold(string $tool, int $count): bool
    {
        return self::thresholdMet(self::ruleFor($tool), $count);
    }

    /**
     * Pure version of meetsThreshold() that takes an already-loaded rule
     * instead of a tool name, so callers holding a batch of rules (e.g.
     * EmailDigest::buildSections() iterating the full rule set) don't
     * re-read the rules file once per tool.
     *
     * @param array{mode: string, threshold: int, include_zero: bool, email: string} $rule
     */
    public static function thresholdMet(array $rule, int $count): bool
    {
        if ($count === 0 && !$rule['include_zero']) {
            return false;
        }
        return $count >= $rule['threshold'];
    }

    public static function shouldNotify(string $tool, int $count): bool
    {
        return self::ruleFor($tool)['mode'] === 'immediate' && self::meetsThreshold($tool, $count);
    }

    public static function isDigestEnabled(string $tool): bool
    {
        return self::ruleFor($tool)['mode'] === 'digest';
    }

    /** Custom recipient for this tool, or '' to fall back to ALERT_EMAIL. */
    public static function recipientFor(string $tool): string
    {
        return self::ruleFor($tool)['email'];
    }

    /**
     * @return string[] tool keys currently set to digest mode
     */
    public static function digestTools(): array
    {
        return array_keys(array_filter(self::load(), fn(array $r): bool => $r['mode'] === 'digest'));
    }
}
