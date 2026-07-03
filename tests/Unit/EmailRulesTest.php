<?php
declare(strict_types=1);

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

    public function testDefaultsReturnExpectedShape(): void
    {
        $d = EmailRules::defaults();
        $this->assertFalse($d['email_enabled']);
        $this->assertSame(1, $d['email_min_missing']);
        $this->assertFalse($d['include_zero_email']);
        $this->assertFalse($d['email_scan_enabled']);
    }

    public function testLoadReturnsDefaultsWhenNoFile(): void
    {
        $rules = EmailRules::load();
        $this->assertSame(EmailRules::defaults(), $rules);
    }

    public function testSaveAndLoad(): void
    {
        EmailRules::save(['email_enabled' => true, 'email_min_missing' => 3]);
        $rules = EmailRules::load();
        $this->assertTrue($rules['email_enabled']);
        $this->assertSame(3, $rules['email_min_missing']);
    }

    public function testNormaliseClampsnegativeThreshold(): void
    {
        $n = EmailRules::normalise(['email_min_missing' => -5]);
        $this->assertSame(0, $n['email_min_missing']);
    }

    public function testShouldNotifyAuditOffByDefault(): void
    {
        $this->assertFalse(EmailRules::shouldNotifyAudit(10));
    }

    public function testShouldNotifyAuditThreshold(): void
    {
        EmailRules::save(['email_enabled' => true, 'email_min_missing' => 2, 'include_zero_email' => false]);

        $this->assertFalse(EmailRules::shouldNotifyAudit(0));
        $this->assertFalse(EmailRules::shouldNotifyAudit(1));
        $this->assertTrue(EmailRules::shouldNotifyAudit(2));
        $this->assertTrue(EmailRules::shouldNotifyAudit(5));
    }

    public function testShouldNotifyAuditIncludeZero(): void
    {
        EmailRules::save(['email_enabled' => true, 'email_min_missing' => 0, 'include_zero_email' => true]);
        $this->assertTrue(EmailRules::shouldNotifyAudit(0));
    }

    public function testShouldNotifyScanOffByDefault(): void
    {
        $this->assertFalse(EmailRules::shouldNotifyScan(10));
    }

    public function testShouldNotifyScanEnabled(): void
    {
        EmailRules::save(['email_scan_enabled' => true]);
        $this->assertTrue(EmailRules::shouldNotifyScan(1));
        $this->assertFalse(EmailRules::shouldNotifyScan(0));
    }

    public function testLoadHandlesCorruptJson(): void
    {
        file_put_contents($this->tmpDir . '/email_rules.json', 'not-json');
        $rules = EmailRules::load();
        $this->assertSame(EmailRules::defaults(), $rules);
    }
}
