<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ConfigValidator.php';

use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private string $tmpDir;
    private string|false $previousWebPassword;
    private string|false $previousStateStorage;
    /** @var array<string, string|false> */
    private array $previousGoogleEnvironment = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/configvalidator_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->previousWebPassword = getenv('WEB_PASSWORD');
        $this->previousStateStorage = getenv('STATE_STORAGE');
        putenv('STATE_STORAGE');
        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'GOOGLE_ALLOWED_DOMAINS', 'GOOGLE_DEFAULT_ROLE', 'GOOGLE_LOGIN_ONLY'] as $name) {
            $this->previousGoogleEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousWebPassword === false) {
            putenv('WEB_PASSWORD');
        } else {
            putenv('WEB_PASSWORD=' . $this->previousWebPassword);
        }
        foreach ($this->previousGoogleEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
        if ($this->previousStateStorage === false) {
            putenv('STATE_STORAGE');
        } else {
            putenv('STATE_STORAGE=' . $this->previousStateStorage);
        }
        $this->removeDir($this->tmpDir);
    }

    public function testEnvironmentRejectsUnknownStateStorageDriver(): void
    {
        putenv('STATE_STORAGE=redis');
        putenv('WEB_PASSWORD=a-safe-password');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('STATE_STORAGE must be sqlite or json.', implode("\n", $result['issues']));
    }

    public function testValidOrderTypesPasses(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode([
            'fallback' => 'Other',
            'rules' => [
                ['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'pro-'],
            ],
        ]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertTrue($result['ok']);
    }

    public function testInvalidOrderTypesReportsUnsupportedMatch(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode([
            'rules' => [
                ['name' => 'Bad', 'match' => 'unknown', 'value' => 'x'],
            ],
        ]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not supported', implode("\n", $result['issues']));
    }

    public function testOrderTypesMissingFileReportsPresentFalse(): void
    {
        $result = ConfigValidator::validateOrderTypes($this->tmpDir . '/order_types.json');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['present']);
        $this->assertNotEmpty($result['notes']);
    }

    public function testOrderTypesReportsInvalidJson(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, '{not valid json');

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Root must be a JSON object.', implode("\n", $result['issues']));
    }

    public function testOrderTypesRejectsNonArrayRoot(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode('not an object'));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Root must be a JSON object.', implode("\n", $result['issues']));
    }

    public function testOrderTypesReportsMissingRulesArray(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode(['fallback' => 'Other']));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Missing rules array.', implode("\n", $result['issues']));
    }

    public function testOrderTypesRejectsNonObjectRuleEntry(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode(['rules' => ['not-an-object']]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertStringContainsString('rules[0] must be an object.', implode("\n", $result['issues']));
    }

    public function testOrderTypesReportsMissingNameAndValue(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode(['rules' => [
            ['match' => 'sku_starts_with'],
        ]]));

        $result = ConfigValidator::validateOrderTypes($path);

        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('rules[0].name is required.', $issues);
        $this->assertStringContainsString('rules[0].value is required.', $issues);
    }

    public function testOrderTypesReportsDuplicateRuleName(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode(['rules' => [
            ['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'pro-'],
            ['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'pro2-'],
        ]]));

        $result = ConfigValidator::validateOrderTypes($path);

        $this->assertStringContainsString("Duplicate rule name 'Pro'.", implode("\n", $result['issues']));
    }

    public function testOrderTypesRequiredItemsValidation(): void
    {
        $path = $this->tmpDir . '/order_types.json';
        file_put_contents($path, json_encode(['rules' => [
            [
                'name' => 'Bundle', 'match' => 'sku_starts_with', 'value' => 'bun-',
                'required_items' => [
                    'not-an-object',
                    ['match' => 'unsupported'],
                ],
            ],
        ]]));

        $result = ConfigValidator::validateOrderTypes($path);

        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('required_items[0] must be an object.', $issues);
        $this->assertStringContainsString('required_items[1].label is required.', $issues);
        $this->assertStringContainsString("required_items[1].match 'unsupported' is not supported.", $issues);
    }

    public function testMissingTagPolicyIsOkWithNote(): void
    {
        $result = ConfigValidator::validateTagPolicy($this->tmpDir . '/tag_policy.json');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['present']);
        $this->assertNotEmpty($result['notes']);
    }

    public function testEnvironmentReportsMissingWebPassword(): void
    {
        putenv('WEB_PASSWORD');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('WEB_PASSWORD is not set', implode("\n", $result['issues']));
    }

    public function testEnvironmentReportsPlaceholderWebPassword(): void
    {
        putenv('WEB_PASSWORD=change_me_now');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unsafe default', implode("\n", $result['issues']));
    }

    public function testEnvironmentAcceptsConfiguredWebPassword(): void
    {
        putenv('WEB_PASSWORD=real-password');

        $result = ConfigValidator::validateEnvironment();

        $this->assertTrue($result['ok']);
    }

    public function testEnvironmentAcceptsMissingWebPasswordInMultiUserMode(): void
    {
        putenv('WEB_PASSWORD');
        mkdir($this->tmpDir . '/data');
        $usersFile = $this->tmpDir . '/data/users.json';
        file_put_contents($usersFile, json_encode([
            ['name' => 'admin', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'role' => 'admin'],
        ]));

        $result = ConfigValidator::validateEnvironment($usersFile);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('multi-user login', implode("\n", $result['notes']));
    }

    public function testEnvironmentAcceptsConfiguredGoogleLoginWithoutWebPassword(): void
    {
        putenv('WEB_PASSWORD');
        putenv('GOOGLE_CLIENT_ID=client-id');
        putenv('GOOGLE_CLIENT_SECRET=client-secret');
        putenv('GOOGLE_REDIRECT_URI=https://ops.example.com/?auth=google_callback');
        putenv('GOOGLE_ALLOWED_DOMAINS=example.com,subsidiary.com');

        try {
            $result = ConfigValidator::validateEnvironment();

            $this->assertTrue($result['ok'], implode("\n", $result['issues']));
            $this->assertStringContainsString('Google sign-in is enabled', implode("\n", $result['notes']));
        } finally {
            foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI', 'GOOGLE_ALLOWED_DOMAINS'] as $name) {
                putenv($name);
            }
        }
    }

    public function testEnvironmentRejectsIncompleteGoogleOnlyConfiguration(): void
    {
        putenv('WEB_PASSWORD');
        putenv('GOOGLE_LOGIN_ONLY=1');
        putenv('GOOGLE_CLIENT_ID=client-id');

        try {
            $result = ConfigValidator::validateEnvironment();

            $this->assertFalse($result['ok']);
            $issues = implode("\n", $result['issues']);
            $this->assertStringContainsString('GOOGLE_CLIENT_SECRET is missing', $issues);
            $this->assertStringContainsString('GOOGLE_LOGIN_ONLY is enabled', $issues);
        } finally {
            putenv('GOOGLE_LOGIN_ONLY');
            putenv('GOOGLE_CLIENT_ID');
        }
    }

    public function testEnvironmentReportsAllMissingPartsOfPartialGoogleConfiguration(): void
    {
        putenv('WEB_PASSWORD=real-password');
        putenv('GOOGLE_CLIENT_ID=client-id');

        $result = ConfigValidator::validateEnvironment();
        $issues = implode("\n", $result['issues']);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('GOOGLE_CLIENT_SECRET is missing', $issues);
        $this->assertStringContainsString('GOOGLE_REDIRECT_URI', $issues);
        $this->assertStringContainsString('GOOGLE_ALLOWED_DOMAINS', $issues);
    }

    public function testEnvironmentRejectsInvalidGoogleRole(): void
    {
        putenv('WEB_PASSWORD=real-password');
        putenv('GOOGLE_CLIENT_ID=client-id');
        putenv('GOOGLE_CLIENT_SECRET=client-secret');
        putenv('GOOGLE_REDIRECT_URI=https://ops.example.com/?auth=google_callback');
        putenv('GOOGLE_ALLOWED_DOMAINS=example.com');
        putenv('GOOGLE_DEFAULT_ROLE=owner');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('viewer, operator, or admin', implode("\n", $result['issues']));
    }

    public function testEnvironmentRejectsInvalidAllowedDomains(): void
    {
        putenv('WEB_PASSWORD=real-password');
        putenv('GOOGLE_CLIENT_ID=client-id');
        putenv('GOOGLE_CLIENT_SECRET=client-secret');
        putenv('GOOGLE_REDIRECT_URI=https://ops.example.com/?auth=google_callback');
        putenv('GOOGLE_ALLOWED_DOMAINS=invalid_domain');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('no valid domains', implode("\n", $result['issues']));
    }

    public function testEnvironmentRejectsInsecureProductionGoogleRedirect(): void
    {
        putenv('WEB_PASSWORD=real-password');
        putenv('GOOGLE_CLIENT_ID=client-id');
        putenv('GOOGLE_CLIENT_SECRET=client-secret');
        putenv('GOOGLE_REDIRECT_URI=http://ops.example.com/?auth=google_callback');
        putenv('GOOGLE_ALLOWED_DOMAINS=example.com');

        $result = ConfigValidator::validateEnvironment();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('must use HTTPS', implode("\n", $result['issues']));
    }

    public function testConfiguredGoogleLoginMakesUnsafeLegacyPasswordNonBlocking(): void
    {
        putenv('WEB_PASSWORD=change_me_now');
        putenv('GOOGLE_CLIENT_ID=client-id');
        putenv('GOOGLE_CLIENT_SECRET=client-secret');
        putenv('GOOGLE_REDIRECT_URI=https://ops.example.com/?auth=google_callback');
        putenv('GOOGLE_ALLOWED_DOMAINS=example.com');
        putenv('GOOGLE_LOGIN_ONLY=1');

        $result = ConfigValidator::validateEnvironment();

        $this->assertTrue($result['ok'], implode("\n", $result['issues']));
        $this->assertStringNotContainsString('unsafe default', implode("\n", $result['issues']));
    }

    public function testInvalidTagPolicyReportsMalformedRequiredAndForbiddenRules(): void
    {
        $path = $this->tmpDir . '/tag_policy.json';
        file_put_contents($path, json_encode([
            'required'  => [['when' => [], 'must_have' => ['x']]],
            'forbidden' => [['tags' => ['only-one']]],
        ]));

        $result = ConfigValidator::validateTagPolicy($path);

        $this->assertFalse($result['ok']);
        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('when must be a non-empty array', $issues);
        $this->assertStringContainsString('at least two tags', $issues);
    }

    public function testTagPolicyRejectsNonArrayRoot(): void
    {
        $path = $this->tmpDir . '/tag_policy.json';
        file_put_contents($path, json_encode('not an object'));

        $result = ConfigValidator::validateTagPolicy($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Root must be a JSON object.', implode("\n", $result['issues']));
    }

    public function testTagPolicyRejectsNonObjectRuleEntries(): void
    {
        $path = $this->tmpDir . '/tag_policy.json';
        file_put_contents($path, json_encode([
            'required'  => ['not-an-object'],
            'forbidden' => ['not-an-object'],
        ]));

        $result = ConfigValidator::validateTagPolicy($path);

        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('required[0] must be an object.', $issues);
        $this->assertStringContainsString('forbidden[0] must be an object.', $issues);
    }

    public function testTagPolicyReportsMissingMustHave(): void
    {
        $path = $this->tmpDir . '/tag_policy.json';
        file_put_contents($path, json_encode([
            'required' => [['when' => ['vip']]],
        ]));

        $result = ConfigValidator::validateTagPolicy($path);

        $this->assertStringContainsString('must_have must be a non-empty array.', implode("\n", $result['issues']));
    }

    public function testMissingStoresIsOkWithNote(): void
    {
        $result = ConfigValidator::validateStores($this->tmpDir . '/stores.json');

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['present']);
        $this->assertStringContainsString('single-store .env mode', implode("\n", $result['notes']));
    }

    public function testStoresRejectsNonArrayRoot(): void
    {
        $path = $this->tmpDir . '/stores.json';
        file_put_contents($path, json_encode('not-an-array'));

        $result = ConfigValidator::validateStores($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('array of stores', implode("\n", $result['issues']));
    }

    public function testStoresReportsMissingRequiredFields(): void
    {
        $path = $this->tmpDir . '/stores.json';
        file_put_contents($path, json_encode([
            ['id' => 'store-a'],
        ]));

        $result = ConfigValidator::validateStores($path);

        $this->assertFalse($result['ok']);
        $issues = implode("\n", $result['issues']);
        $this->assertStringContainsString('stores[0].shopify_store is required', $issues);
        $this->assertStringContainsString('stores[0].shopify_token is required', $issues);
    }

    public function testStoresRejectsNonObjectEntry(): void
    {
        $path = $this->tmpDir . '/stores.json';
        file_put_contents($path, json_encode(['not-an-object']));

        $result = ConfigValidator::validateStores($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('stores[0] must be an object', implode("\n", $result['issues']));
    }

    public function testStoresReportsDuplicateIds(): void
    {
        $path = $this->tmpDir . '/stores.json';
        file_put_contents($path, json_encode([
            ['id' => 'dup', 'shopify_store' => 'a.myshopify.com', 'shopify_token' => 'tok'],
            ['id' => 'dup', 'shopify_store' => 'b.myshopify.com', 'shopify_token' => 'tok'],
        ]));

        $result = ConfigValidator::validateStores($path);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString("Duplicate store id 'dup'", implode("\n", $result['issues']));
    }

    public function testValidStoresPasses(): void
    {
        $path = $this->tmpDir . '/stores.json';
        file_put_contents($path, json_encode([
            ['id' => 'a', 'shopify_store' => 'a.myshopify.com', 'shopify_token' => 'tok-a'],
            ['id' => 'b', 'shopify_store' => 'b.myshopify.com', 'shopify_token' => 'tok-b'],
        ]));

        $result = ConfigValidator::validateStores($path);

        $this->assertTrue($result['ok']);
    }

    public function testValidateAllRunsAllFourValidatorsAgainstRootDir(): void
    {
        putenv('WEB_PASSWORD=real-password');
        file_put_contents($this->tmpDir . '/order_types.json', json_encode([
            'rules' => [['name' => 'Pro', 'match' => 'sku_starts_with', 'value' => 'pro-']],
        ]));
        file_put_contents($this->tmpDir . '/tag_policy.json', json_encode(['required' => [], 'forbidden' => []]));
        file_put_contents($this->tmpDir . '/stores.json', json_encode([
            ['id' => 'a', 'shopify_store' => 'a.myshopify.com', 'shopify_token' => 'tok'],
        ]));

        $results = ConfigValidator::validateAll($this->tmpDir);

        $this->assertCount(4, $results);
        $files = array_column($results, 'file');
        $this->assertSame(['order_types.json', 'tag_policy.json', 'stores.json', 'environment'], $files);
        foreach ($results as $result) {
            $this->assertTrue($result['ok'], $result['file'] . ': ' . implode('; ', $result['issues']));
        }
    }

    public function testValidateAllSurfacesIssuesFromEachSubValidator(): void
    {
        putenv('WEB_PASSWORD');
        file_put_contents($this->tmpDir . '/stores.json', json_encode([
            ['id' => 'dup', 'shopify_store' => '', 'shopify_token' => ''],
            ['id' => 'dup', 'shopify_store' => 'x', 'shopify_token' => 'y'],
        ]));

        $results = ConfigValidator::validateAll($this->tmpDir);

        $storesResult = $results[2];
        $this->assertSame('stores.json', $storesResult['file']);
        $this->assertFalse($storesResult['ok']);

        $envResult = $results[3];
        $this->assertSame('environment', $envResult['file']);
        $this->assertFalse($envResult['ok']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
