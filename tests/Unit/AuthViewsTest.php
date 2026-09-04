<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/ViewHelpers.php';

use PHPUnit\Framework\TestCase;

class AuthViewsTest extends TestCase
{
    public function testLoginShowsGoogleAndPasswordOptionsWhenBothAreEnabled(): void
    {
        $html = $this->render('login', $this->loginVars([
            'googleEnabled' => true,
            'googleLoginOnly' => false,
            'passwordLoginEnabled' => true,
        ]));

        $this->assertStringContainsString('Continue with Google', $html);
        $this->assertStringContainsString('name="username"', $html);
        $this->assertStringContainsString('name="password"', $html);
        $this->assertStringContainsString('name="action" value="login"', $html);
    }

    public function testGoogleOnlyLoginHidesPasswordForm(): void
    {
        $html = $this->render('login', $this->loginVars([
            'googleEnabled' => true,
            'googleLoginOnly' => true,
            'passwordLoginEnabled' => false,
        ]));

        $this->assertStringContainsString('Continue with Google', $html);
        $this->assertStringNotContainsString('name="username"', $html);
        $this->assertStringNotContainsString('name="password"', $html);
    }

    public function testIncompleteGoogleOnlyConfigurationShowsActionableError(): void
    {
        $html = $this->render('login', $this->loginVars([
            'googleEnabled' => false,
            'googleLoginOnly' => true,
            'passwordLoginEnabled' => false,
        ]));

        $this->assertStringContainsString('configuration is incomplete', $html);
        $this->assertStringNotContainsString('name="username"', $html);
    }

    public function testPasswordOnlyModeDoesNotShowGoogleButton(): void
    {
        $html = $this->render('login', $this->loginVars());

        $this->assertStringNotContainsString('Continue with Google', $html);
        $this->assertStringContainsString('name="username"', $html);
    }

    public function testLocalhostQuickLoginRemainsAvailableInGoogleOnlyMode(): void
    {
        $html = $this->render('login', $this->loginVars([
            'googleEnabled' => true,
            'googleLoginOnly' => true,
            'passwordLoginEnabled' => false,
            'isLocalhost' => true,
        ]));

        $this->assertStringContainsString('Quick login (localhost)', $html);
        $this->assertStringContainsString('name="action" value="dev_login"', $html);
    }

    public function testLoginEscapesErrorAndBrandingValues(): void
    {
        $html = $this->render('login', $this->loginVars([
            'error' => '<script>alert(1)</script>',
            'appBrand' => '<b>Brand</b>',
        ]));

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;b&gt;Brand&lt;/b&gt;', $html);
    }

    public function testAccessDeniedPageContainsGenericMessageAndRetryLink(): void
    {
        $html = $this->render('access-denied', [
            'appTitle' => 'Shopify Ops',
            'loginPath' => '/ops/index.php',
            'loginBgImage' => '',
        ]);

        $this->assertStringContainsString("Sorry, you're not part of the team", $html);
        $this->assertStringContainsString('Workspace domain is not allowed', $html);
        $this->assertStringContainsString('href="/ops/index.php"', $html);
        $this->assertStringNotContainsString('@', $html);
    }

    public function testAccessDeniedPageEscapesDynamicValues(): void
    {
        $html = $this->render('access-denied', [
            'appTitle' => '<script>title</script>',
            'loginPath' => '" onclick="alert(1)',
            'loginBgImage' => '" onerror="alert(2)',
        ]);

        $this->assertStringNotContainsString('<script>title</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;title&lt;/script&gt;', $html);
        $this->assertStringContainsString('&quot; onclick=&quot;alert(1)', $html);
        $this->assertStringContainsString('&quot; onerror=&quot;alert(2)', $html);
    }

    /** @param array<string, mixed> $vars */
    private function render(string $view, array $vars): string
    {
        extract($vars);
        ob_start();
        require dirname(__DIR__, 2) . "/views/{$view}.php";
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function loginVars(array $overrides = []): array
    {
        return array_replace([
            'appTitle' => 'Shopify Ops',
            'appStoreNumber' => '',
            'appLogo' => '',
            'appBrand' => 'Shopify Ops',
            'loginBgImage' => '',
            'error' => '',
            'googleEnabled' => false,
            'googleLoginOnly' => false,
            'passwordLoginEnabled' => true,
            'isLocalhost' => false,
        ], $overrides);
    }
}
