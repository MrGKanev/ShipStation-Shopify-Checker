<?php
declare(strict_types=1);

require_once __DIR__ . '/GoogleAuth.php';

/**
 * Validates local JSON configuration files used by operational checks.
 */
class ConfigValidator
{
    private const ORDER_MATCH_TYPES = [
        'sku_starts_with',
        'sku_contains',
        'sku_not_starts_with',
        'title_contains',
        'vendor_is',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function validateAll(string $rootDir): array
    {
        return [
            self::validateOrderTypes($rootDir . '/order_types.json'),
            self::validateTagPolicy($rootDir . '/tag_policy.json'),
            self::validateStores($rootDir . '/stores.json'),
            self::validateEnvironment($rootDir . '/data/users.json'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateOrderTypes(string $path): array
    {
        [$data, $issues, $exists] = self::readJson($path);
        if (!$exists) {
            return self::result('order_types.json', false, [], ['File not present; order type fallback only.']);
        }

        if (!is_array($data)) {
            return self::result('order_types.json', false, ['Root must be a JSON object.'], $issues);
        }

        if (!isset($data['rules']) || !is_array($data['rules'])) {
            $issues[] = 'Missing rules array.';
        }
        $names = [];
        foreach (($data['rules'] ?? []) as $i => $rule) {
            if (!is_array($rule)) {
                $issues[] = "rules[{$i}] must be an object.";
                continue;
            }
            $name = trim((string)($rule['name'] ?? ''));
            if ($name === '') $issues[] = "rules[{$i}].name is required.";
            if ($name !== '' && isset($names[$name])) $issues[] = "Duplicate rule name '{$name}'.";
            $names[$name] = true;
            $match = (string)($rule['match'] ?? '');
            if (!in_array($match, self::ORDER_MATCH_TYPES, true)) {
                $issues[] = "rules[{$i}].match '{$match}' is not supported.";
            }
            if (!array_key_exists('value', $rule)) {
                $issues[] = "rules[{$i}].value is required.";
            }
            foreach (($rule['required_items'] ?? []) as $j => $req) {
                if (!is_array($req)) {
                    $issues[] = "rules[{$i}].required_items[{$j}] must be an object.";
                    continue;
                }
                if (trim((string)($req['label'] ?? '')) === '') $issues[] = "rules[{$i}].required_items[{$j}].label is required.";
                $reqMatch = (string)($req['match'] ?? '');
                if (!in_array($reqMatch, self::ORDER_MATCH_TYPES, true)) {
                    $issues[] = "rules[{$i}].required_items[{$j}].match '{$reqMatch}' is not supported.";
                }
            }
        }

        return self::result('order_types.json', true, $issues, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateTagPolicy(string $path): array
    {
        [$data, $issues, $exists] = self::readJson($path);
        if (!$exists) {
            return self::result('tag_policy.json', false, [], ['File not present; Tag Policy Audit remains disabled.']);
        }

        if (!is_array($data)) {
            return self::result('tag_policy.json', false, ['Root must be a JSON object.'], $issues);
        }

        foreach (($data['required'] ?? []) as $i => $rule) {
            if (!is_array($rule)) {
                $issues[] = "required[{$i}] must be an object.";
                continue;
            }
            if (empty($rule['when']) || !is_array($rule['when'])) $issues[] = "required[{$i}].when must be a non-empty array.";
            if (empty($rule['must_have']) || !is_array($rule['must_have'])) $issues[] = "required[{$i}].must_have must be a non-empty array.";
        }
        foreach (($data['forbidden'] ?? []) as $i => $rule) {
            if (!is_array($rule)) {
                $issues[] = "forbidden[{$i}] must be an object.";
                continue;
            }
            if (empty($rule['tags']) || !is_array($rule['tags']) || count($rule['tags']) < 2) {
                $issues[] = "forbidden[{$i}].tags must contain at least two tags.";
            }
        }

        return self::result('tag_policy.json', true, $issues, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateStores(string $path): array
    {
        [$data, $issues, $exists] = self::readJson($path);
        if (!$exists) {
            return self::result('stores.json', false, [], ['File not present; single-store .env mode is active.']);
        }

        if (!is_array($data)) {
            return self::result('stores.json', false, ['Root must be an array of stores.'], $issues);
        }

        $ids = [];
        foreach ($data as $i => $store) {
            if (!is_array($store)) {
                $issues[] = "stores[{$i}] must be an object.";
                continue;
            }
            foreach (['id', 'shopify_store', 'shopify_token'] as $key) {
                if (trim((string)($store[$key] ?? '')) === '') {
                    $issues[] = "stores[{$i}].{$key} is required.";
                }
            }
            $id = (string)($store['id'] ?? '');
            if ($id !== '' && isset($ids[$id])) $issues[] = "Duplicate store id '{$id}'.";
            $ids[$id] = true;
        }

        return self::result('stores.json', true, $issues, []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateEnvironment(string $usersFile = ''): array
    {
        $issues = [];
        $notes = [];
        $webPassword = trim((string) (getenv('WEB_PASSWORD') ?: ''));
        $multiUserMode = $usersFile !== '' && file_exists($usersFile);
        $googleLoginOnly = filter_var(getenv('GOOGLE_LOGIN_ONLY') ?: '', FILTER_VALIDATE_BOOLEAN);
        $googleValues = [
            getenv('GOOGLE_CLIENT_ID'),
            getenv('GOOGLE_CLIENT_SECRET'),
            getenv('GOOGLE_REDIRECT_URI'),
            getenv('GOOGLE_ALLOWED_DOMAINS'),
            getenv('GOOGLE_DEFAULT_ROLE'),
        ];
        $googleRequested = $googleLoginOnly || array_filter($googleValues, static fn($value) => trim((string) $value) !== '') !== [];
        $googleAuth = GoogleAuth::fromEnvironment();
        $googleConfigured = $googleAuth->isConfigured();

        if ($googleRequested) {
            foreach ($googleAuth->configurationErrors() as $googleError) {
                $issues[] = $googleError;
            }
            if ($googleConfigured) {
                $notes[] = 'Google sign-in is enabled for: ' . implode(', ', GoogleAuth::parseDomains((string) getenv('GOOGLE_ALLOWED_DOMAINS')));
            }
        }
        if ($googleLoginOnly && !$googleConfigured) {
            $issues[] = 'GOOGLE_LOGIN_ONLY is enabled, but Google sign-in is not fully configured.';
        }

        if ($multiUserMode) {
            $notes[] = 'data/users.json is present; multi-user login takes precedence over WEB_PASSWORD.';
        } elseif ($webPassword === '' && !$googleConfigured) {
            $issues[] = 'WEB_PASSWORD is not set; legacy single-user login is disabled outside localhost.';
        } elseif (in_array($webPassword, ['changeme', 'change_me_now'], true) && !$googleConfigured) {
            $issues[] = 'WEB_PASSWORD uses an unsafe default placeholder.';
        }

        $stateStorage = strtolower(trim((string) (getenv('STATE_STORAGE') ?: 'sqlite')));
        if (!in_array($stateStorage, ['sqlite', 'json'], true)) {
            $issues[] = 'STATE_STORAGE must be sqlite or json.';
        } elseif ($stateStorage === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $issues[] = 'STATE_STORAGE=sqlite requires the pdo_sqlite PHP extension.';
        } elseif ($stateStorage === 'sqlite') {
            $notes[] = 'Job queue and operator audit state use SQLite; legacy JSON files are retained for rollback.';
        }

        $allowPublicMetrics = filter_var(getenv('METRICS_ALLOW_PUBLIC') ?: '', FILTER_VALIDATE_BOOLEAN);
        if (!getenv('METRICS_TOKEN')) {
            $notes[] = $allowPublicMetrics
                ? 'METRICS_TOKEN is not set and METRICS_ALLOW_PUBLIC=1; metrics.php is public.'
                : 'METRICS_TOKEN is not set; metrics.php is disabled unless METRICS_ALLOW_PUBLIC=1.';
        }

        return self::result('environment', true, $issues, $notes);
    }

    /**
     * @return array{0:mixed,1:array<int,string>,2:bool}
     */
    private static function readJson(string $path): array
    {
        if (!file_exists($path)) {
            return [null, [], false];
        }
        $raw = file_get_contents($path);
        $decoded = json_decode((string)$raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [null, ['Invalid JSON: ' . json_last_error_msg()], true];
        }
        return [$decoded, [], true];
    }

    /**
     * @param array<int, string> $issues
     * @param array<int, string> $notes
     * @return array<string, mixed>
     */
    private static function result(string $file, bool $present, array $issues, array $notes): array
    {
        return [
            'file'    => $file,
            'present' => $present,
            'ok'      => $issues === [],
            'issues'  => $issues,
            'notes'   => $notes,
        ];
    }
}
