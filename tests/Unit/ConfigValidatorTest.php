<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ConfigValidator.php';

use PHPUnit\Framework\TestCase;

class ConfigValidatorTest extends TestCase
{
    private string $tmpDir;
    private string|false $previousWebPassword;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/configvalidator_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->previousWebPassword = getenv('WEB_PASSWORD');
    }

    protected function tearDown(): void
    {
        if ($this->previousWebPassword === false) {
            putenv('WEB_PASSWORD');
        } else {
            putenv('WEB_PASSWORD=' . $this->previousWebPassword);
        }
        $this->removeDir($this->tmpDir);
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
