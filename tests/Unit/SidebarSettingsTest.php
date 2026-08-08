<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/SidebarSettings.php';

use PHPUnit\Framework\TestCase;

class SidebarSettingsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sidebar_settings_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        SidebarSettings::setDataDir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) unlink($f);
        rmdir($this->tmpDir);
    }

    public function testDefaultsShowBothSectionsWhenNothingSavedYet(): void
    {
        $settings = SidebarSettings::load();

        $this->assertTrue($settings['show_missing_orders']);
        $this->assertTrue($settings['show_recent_activity']);
    }

    public function testSaveThenLoadRoundTripsBothToggles(): void
    {
        SidebarSettings::save(['show_missing_orders' => false, 'show_recent_activity' => true]);

        $settings = SidebarSettings::load();

        $this->assertFalse($settings['show_missing_orders']);
        $this->assertTrue($settings['show_recent_activity']);
    }

    public function testSaveWithMissingKeysFallsBackToDefaults(): void
    {
        SidebarSettings::save([]);

        $settings = SidebarSettings::load();

        $this->assertTrue($settings['show_missing_orders']);
        $this->assertTrue($settings['show_recent_activity']);
    }

    public function testBothTogglesCanBeDisabled(): void
    {
        SidebarSettings::save(['show_missing_orders' => false, 'show_recent_activity' => false]);

        $settings = SidebarSettings::load();

        $this->assertFalse($settings['show_missing_orders']);
        $this->assertFalse($settings['show_recent_activity']);
    }
}
