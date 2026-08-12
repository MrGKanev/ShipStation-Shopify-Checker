<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/SlackRules.php';

use PHPUnit\Framework\TestCase;

class SlackRulesTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/slackrules_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        SlackRules::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testDefaultsNotifyAuditAllClear(): void
    {
        $this->assertTrue(SlackRules::shouldNotifyAudit(0));
    }

    public function testAuditThreshold(): void
    {
        SlackRules::save(['audit_enabled' => true, 'audit_min_missing' => 2, 'include_zero_audit' => false]);

        $this->assertFalse(SlackRules::shouldNotifyAudit(0));
        $this->assertFalse(SlackRules::shouldNotifyAudit(1));
        $this->assertTrue(SlackRules::shouldNotifyAudit(2));
    }

    public function testScanNotificationsDefaultOff(): void
    {
        $this->assertFalse(SlackRules::shouldNotifyScan(10));
    }

    public function testScanNotifiesWhenEnabledAndAboveThreshold(): void
    {
        SlackRules::save(['scan_enabled' => true, 'scan_min_rows' => 5]);

        $this->assertFalse(SlackRules::shouldNotifyScan(4));
        $this->assertTrue(SlackRules::shouldNotifyScan(5));
    }

    public function testAuditDisabledOverridesThresholdAndZeroInclusion(): void
    {
        SlackRules::save(['audit_enabled' => false, 'audit_min_missing' => 0, 'include_zero_audit' => true]);

        $this->assertFalse(SlackRules::shouldNotifyAudit(0));
        $this->assertFalse(SlackRules::shouldNotifyAudit(10));
    }

    public function testNormaliseClampsAuditMinMissingToZero(): void
    {
        $rules = SlackRules::normalise(['audit_min_missing' => -5]);

        $this->assertSame(0, $rules['audit_min_missing']);
    }

    public function testNormaliseClampsScanMinRowsToOne(): void
    {
        $rules = SlackRules::normalise(['scan_min_rows' => 0]);

        $this->assertSame(1, $rules['scan_min_rows']);
    }

    public function testNormaliseFillsMissingKeysFromDefaults(): void
    {
        $rules = SlackRules::normalise(['audit_enabled' => false]);

        $this->assertFalse($rules['audit_enabled']);
        $this->assertSame(SlackRules::defaults()['scan_min_rows'], $rules['scan_min_rows']);
    }

    public function testSaveThenLoadRoundTrips(): void
    {
        SlackRules::save(['audit_enabled' => false, 'scan_enabled' => true, 'scan_min_rows' => 7]);

        $rules = SlackRules::load();

        $this->assertFalse($rules['audit_enabled']);
        $this->assertTrue($rules['scan_enabled']);
        $this->assertSame(7, $rules['scan_min_rows']);
    }

    public function testLoadReturnsDefaultsWhenFileMissing(): void
    {
        $this->assertSame(SlackRules::defaults(), SlackRules::load());
    }
}
