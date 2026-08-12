<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ToolRegistry.php';
require_once __DIR__ . '/../../src/EmailRules.php';

use PHPUnit\Framework\TestCase;

class EmailRulesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/emailrules_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        EmailRules::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testDefaultsCoverEveryCatalogToolAsOff(): void
    {
        $defaults = EmailRules::defaults();

        $this->assertSame(array_keys(ToolRegistry::triggerCatalog()), array_keys($defaults));
        foreach ($defaults as $rule) {
            $this->assertSame('off', $rule['mode']);
            $this->assertSame(1, $rule['threshold']);
            $this->assertFalse($rule['include_zero']);
            $this->assertSame('', $rule['email']);
        }
    }

    public function testLoadReturnsDefaultsWhenNoFile(): void
    {
        $this->assertSame(EmailRules::defaults(), EmailRules::load());
    }

    public function testLoadHandlesCorruptJson(): void
    {
        file_put_contents($this->tmpDir . '/email_rules.json', 'not-json');
        $this->assertSame(EmailRules::defaults(), EmailRules::load());
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        EmailRules::save(['scan_addresses' => ['mode' => 'immediate', 'threshold' => 3, 'email' => 'risk@example.com']]);

        $rule = EmailRules::load()['scan_addresses'];

        $this->assertSame('immediate', $rule['mode']);
        $this->assertSame(3, $rule['threshold']);
        $this->assertSame('risk@example.com', $rule['email']);
    }

    public function testNormaliseDropsUnknownToolKeys(): void
    {
        $rules = EmailRules::normalise(['not_a_real_tool' => ['mode' => 'immediate']]);

        $this->assertArrayNotHasKey('not_a_real_tool', $rules);
    }

    public function testNormaliseRejectsInvalidMode(): void
    {
        $rules = EmailRules::normalise(['scan_addresses' => ['mode' => 'sometimes']]);

        $this->assertSame('off', $rules['scan_addresses']['mode']);
    }

    public function testNormaliseClampsScanThresholdToOne(): void
    {
        $rules = EmailRules::normalise(['scan_addresses' => ['mode' => 'immediate', 'threshold' => 0]]);

        $this->assertSame(1, $rules['scan_addresses']['threshold']);
    }

    public function testNormaliseAllowsRunAuditThresholdOfZero(): void
    {
        $rules = EmailRules::normalise(['run_audit' => ['mode' => 'immediate', 'threshold' => 0]]);

        $this->assertSame(0, $rules['run_audit']['threshold']);
    }

    public function testNormaliseClampsNegativeThresholdToMinimum(): void
    {
        $rules = EmailRules::normalise(['run_audit' => ['threshold' => -5]]);

        $this->assertSame(0, $rules['run_audit']['threshold']);
    }

    public function testNormaliseClearsInvalidEmailAddress(): void
    {
        $rules = EmailRules::normalise(['scan_addresses' => ['email' => 'not-an-email']]);

        $this->assertSame('', $rules['scan_addresses']['email']);
    }

    public function testNormaliseAcceptsValidEmailAddress(): void
    {
        $rules = EmailRules::normalise(['scan_addresses' => ['email' => 'ops@example.com']]);

        $this->assertSame('ops@example.com', $rules['scan_addresses']['email']);
    }

    // ── shouldNotify (immediate mode) ───────────────────────────────────────

    public function testShouldNotifyFalseWhenOff(): void
    {
        $this->assertFalse(EmailRules::shouldNotify('scan_addresses', 10));
    }

    public function testShouldNotifyFalseWhenDigestMode(): void
    {
        EmailRules::save(['scan_addresses' => ['mode' => 'digest', 'threshold' => 1]]);

        $this->assertFalse(EmailRules::shouldNotify('scan_addresses', 10));
    }

    public function testShouldNotifyTrueWhenImmediateAndThresholdMet(): void
    {
        EmailRules::save(['scan_addresses' => ['mode' => 'immediate', 'threshold' => 3]]);

        $this->assertFalse(EmailRules::shouldNotify('scan_addresses', 2));
        $this->assertTrue(EmailRules::shouldNotify('scan_addresses', 3));
    }

    public function testShouldNotifyRunAuditIncludeZero(): void
    {
        EmailRules::save(['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => true]]);

        $this->assertTrue(EmailRules::shouldNotify('run_audit', 0));
    }

    public function testShouldNotifyRunAuditExcludesZeroByDefault(): void
    {
        EmailRules::save(['run_audit' => ['mode' => 'immediate', 'threshold' => 0, 'include_zero' => false]]);

        $this->assertFalse(EmailRules::shouldNotify('run_audit', 0));
    }

    public function testOneToolsModeDoesNotAffectAnother(): void
    {
        EmailRules::save(['scan_addresses' => ['mode' => 'immediate', 'threshold' => 1]]);

        $this->assertTrue(EmailRules::shouldNotify('scan_addresses', 1));
        $this->assertFalse(EmailRules::shouldNotify('scan_bundle', 1));
    }

    // ── isDigestEnabled / digestTools ────────────────────────────────────────

    public function testIsDigestEnabledReflectsMode(): void
    {
        EmailRules::save(['scan_bundle' => ['mode' => 'digest']]);

        $this->assertTrue(EmailRules::isDigestEnabled('scan_bundle'));
        $this->assertFalse(EmailRules::isDigestEnabled('scan_addresses'));
    }

    public function testDigestToolsListsOnlyDigestModeTools(): void
    {
        EmailRules::save([
            'scan_bundle'    => ['mode' => 'digest'],
            'scan_addresses' => ['mode' => 'immediate'],
            'scan_emails'    => ['mode' => 'digest'],
        ]);

        $this->assertSame(['scan_bundle', 'scan_emails'], EmailRules::digestTools());
    }

    public function testDigestToolsEmptyByDefault(): void
    {
        $this->assertSame([], EmailRules::digestTools());
    }

    // ── recipientFor ─────────────────────────────────────────────────────────

    public function testRecipientForReturnsBlankWhenNotSet(): void
    {
        $this->assertSame('', EmailRules::recipientFor('scan_addresses'));
    }

    public function testRecipientForReturnsCustomEmail(): void
    {
        EmailRules::save(['scan_addresses' => ['email' => 'risk@example.com']]);

        $this->assertSame('risk@example.com', EmailRules::recipientFor('scan_addresses'));
    }

    // ── meetsThreshold ───────────────────────────────────────────────────────

    public function testMeetsThresholdIndependentOfMode(): void
    {
        EmailRules::save(['scan_addresses' => ['mode' => 'off', 'threshold' => 2]]);

        $this->assertFalse(EmailRules::meetsThreshold('scan_addresses', 1));
        $this->assertTrue(EmailRules::meetsThreshold('scan_addresses', 2));
    }

    // ── thresholdMet (pure, rule-array form) ────────────────────────────────

    public function testThresholdMetMirrorsMeetsThreshold(): void
    {
        $rule = ['mode' => 'digest', 'threshold' => 3, 'include_zero' => false, 'email' => ''];

        $this->assertFalse(EmailRules::thresholdMet($rule, 2));
        $this->assertTrue(EmailRules::thresholdMet($rule, 3));
    }

    public function testThresholdMetRespectsIncludeZero(): void
    {
        $rule = ['mode' => 'digest', 'threshold' => 0, 'include_zero' => true, 'email' => ''];

        $this->assertTrue(EmailRules::thresholdMet($rule, 0));
    }
}
