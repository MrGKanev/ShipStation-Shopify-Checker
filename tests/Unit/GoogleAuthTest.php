<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/GoogleAuth.php';

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class GoogleAuthTest extends TestCase
{
    public function testParsesNormalizesAndDeduplicatesDomains(): void
    {
        $this->assertSame(
            ['example.com', 'team.co.uk'],
            GoogleAuth::parseDomains(' Example.COM, @team.co.uk example.com invalid_domain '),
        );
    }

    public function testConfiguredRequiresCredentialsRedirectAndDomain(): void
    {
        $configured = new GoogleAuth('client', 'secret', 'https://app.example.com/?auth=google_callback', 'example.com');
        $missingDomain = new GoogleAuth('client', 'secret', 'https://app.example.com/?auth=google_callback', '');

        $this->assertTrue($configured->isConfigured());
        $this->assertFalse($missingDomain->isConfigured());
    }

    public function testConfigurationErrorsReportEveryMissingRequiredValue(): void
    {
        $auth = new GoogleAuth('', '', '', '');
        $errors = implode("\n", $auth->configurationErrors());

        $this->assertStringContainsString('GOOGLE_CLIENT_ID', $errors);
        $this->assertStringContainsString('GOOGLE_CLIENT_SECRET', $errors);
        $this->assertStringContainsString('GOOGLE_REDIRECT_URI', $errors);
        $this->assertStringContainsString('GOOGLE_ALLOWED_DOMAINS', $errors);
    }

    public function testRejectsInsecureNonLocalRedirectUri(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'http://app.example.com/callback', 'example.com');

        $this->assertFalse($auth->isConfigured());
        $this->assertStringContainsString('HTTPS', implode(' ', $auth->configurationErrors()));
    }

    public function testAllowsHttpRedirectOnlyForLocalDevelopment(): void
    {
        $localhost = new GoogleAuth('client', 'secret', 'http://localhost:8080/?auth=google_callback', 'example.com');
        $loopback = new GoogleAuth('client', 'secret', 'http://127.0.0.1:8080/callback', 'example.com');

        $this->assertTrue($localhost->isConfigured());
        $this->assertTrue($loopback->isConfigured());
    }

    public function testRejectsRedirectUriWithCredentialsOrFragment(): void
    {
        $credentials = new GoogleAuth('client', 'secret', 'https://user:pass@app.example.com/callback', 'example.com');
        $fragment = new GoogleAuth('client', 'secret', 'https://app.example.com/callback#fragment', 'example.com');

        $this->assertFalse($credentials->isConfigured());
        $this->assertFalse($fragment->isConfigured());
    }

    public function testAuthorizationUrlIncludesStatePkceAndSingleDomainHint(): void
    {
        $auth = new GoogleAuth('client-id', 'secret', 'https://app.example.com/?auth=google_callback', 'example.com');
        parse_str((string) parse_url($auth->authorizationUrl('state-token', str_repeat('a', 64)), PHP_URL_QUERY), $query);

        $this->assertSame('client-id', $query['client_id']);
        $this->assertSame('https://app.example.com/?auth=google_callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame('state-token', $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('_-BU_nrgy23GXDr5th1SCfQ5hR20PQulmXM33xVGaOs', $query['code_challenge']);
        $this->assertSame('select_account', $query['prompt']);
        $this->assertSame('example.com', $query['hd']);
    }

    public function testAuthorizationUrlCannotBeBuiltFromIncompleteConfiguration(): void
    {
        $this->expectException(RuntimeException::class);
        (new GoogleAuth('', '', '', ''))->authorizationUrl('state', str_repeat('a', 64));
    }

    public function testMultipleAllowedDomainsDoNotSendMisleadingDomainHint(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'one.com,two.com');
        parse_str((string) parse_url($auth->authorizationUrl('state', str_repeat('b', 64)), PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('hd', $query);
    }

    public function testAllowsVerifiedWorkspaceIdentityFromAllowedHostedDomain(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com');

        $this->assertTrue($auth->isAllowedIdentity([
            'sub' => 'google-user-id',
            'email' => 'person@example.com',
            'email_verified' => true,
            'hd' => 'EXAMPLE.COM',
        ]));
    }

    public function testRejectsMatchingEmailSuffixWithoutWorkspaceHostedDomainClaim(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com');

        $this->assertFalse($auth->isAllowedIdentity([
            'sub' => 'google-user-id',
            'email' => 'person@example.com',
            'email_verified' => true,
        ]));
    }

    public function testRejectsUnverifiedEmailAndUnlistedDomain(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com');

        $this->assertFalse($auth->isAllowedIdentity([
            'sub' => 'one', 'email' => 'person@example.com', 'email_verified' => false, 'hd' => 'example.com',
        ]));
        $this->assertFalse($auth->isAllowedIdentity([
            'sub' => 'two', 'email' => 'person@other.com', 'email_verified' => true, 'hd' => 'other.com',
        ]));
    }

    public function testRejectsMissingSubjectMalformedEmailAndStringVerifiedFlag(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com');

        $this->assertFalse($auth->isAllowedIdentity([
            'email' => 'person@example.com', 'email_verified' => true, 'hd' => 'example.com',
        ]));
        $this->assertFalse($auth->isAllowedIdentity([
            'sub' => 'one', 'email' => 'not-an-email', 'email_verified' => true, 'hd' => 'example.com',
        ]));
        $this->assertFalse($auth->isAllowedIdentity([
            'sub' => 'two', 'email' => 'person@example.com', 'email_verified' => 'true', 'hd' => 'example.com',
        ]));
    }

    public function testDomainMatchingIsExactAndDoesNotAllowSubdomainsOrSuffixes(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com');
        $identity = ['sub' => 'one', 'email' => 'person@example.com', 'email_verified' => true];

        $this->assertFalse($auth->isAllowedIdentity($identity + ['hd' => 'sub.example.com']));
        $this->assertFalse($auth->isAllowedIdentity($identity + ['hd' => 'example.com.evil.test']));
    }

    public function testExchangesCodeAndFetchesUserInfo(): void
    {
        $history = [];
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['access_token' => 'access-token'])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'sub' => 'subject',
                'email' => 'person@example.com',
                'email_verified' => true,
                'hd' => 'example.com',
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack]);
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com', 'viewer', $client);

        $identity = $auth->identityFromCode('authorization-code', str_repeat('c', 64));

        $this->assertSame('subject', $identity['sub']);
        $this->assertTrue($auth->isAllowedIdentity($identity));
        $this->assertCount(2, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('https://oauth2.googleapis.com/token', (string) $history[0]['request']->getUri());
        parse_str((string) $history[0]['request']->getBody(), $tokenRequest);
        $this->assertSame('authorization-code', $tokenRequest['code']);
        $this->assertSame('client', $tokenRequest['client_id']);
        $this->assertSame('secret', $tokenRequest['client_secret']);
        $this->assertSame('authorization_code', $tokenRequest['grant_type']);
        $this->assertSame(str_repeat('c', 64), $tokenRequest['code_verifier']);
        $this->assertSame('GET', $history[1]['request']->getMethod());
        $this->assertSame('https://openidconnect.googleapis.com/v1/userinfo', (string) $history[1]['request']->getUri());
        $this->assertSame('Bearer access-token', $history[1]['request']->getHeaderLine('Authorization'));
    }

    public function testTokenEndpointHttpFailureIsRejected(): void
    {
        $auth = $this->authWithResponses([new Response(401, [], '{"error":"invalid_grant"}')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token request failed with HTTP 401');
        $auth->identityFromCode('code', str_repeat('a', 64));
    }

    public function testInvalidTokenJsonIsRejected(): void
    {
        $auth = $this->authWithResponses([new Response(200, [], 'not-json')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token endpoint returned invalid JSON');
        $auth->identityFromCode('code', str_repeat('a', 64));
    }

    public function testMissingAccessTokenIsRejected(): void
    {
        $auth = $this->authWithResponses([new Response(200, [], '{}')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not contain an access token');
        $auth->identityFromCode('code', str_repeat('a', 64));
    }

    public function testUserInfoHttpFailureIsRejected(): void
    {
        $auth = $this->authWithResponses([
            new Response(200, [], '{"access_token":"token"}'),
            new Response(500, [], '{"error":"server_error"}'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('userinfo request failed with HTTP 500');
        $auth->identityFromCode('code', str_repeat('a', 64));
    }

    public function testIdentityExchangeCannotRunWhenConfigurationIsIncomplete(): void
    {
        $auth = new GoogleAuth('', '', '', '', 'viewer', new Client(['handler' => HandlerStack::create(new MockHandler())]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not configured');
        $auth->identityFromCode('code', str_repeat('a', 64));
    }

    public function testInvalidConfiguredRoleFallsBackToViewer(): void
    {
        $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com', 'owner');

        $this->assertSame('viewer', $auth->role());
        $this->assertStringContainsString('GOOGLE_DEFAULT_ROLE', implode(' ', $auth->configurationErrors()));
    }

    public function testSupportsEveryExistingRbacRole(): void
    {
        foreach (['viewer', 'operator', 'admin'] as $role) {
            $auth = new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com', $role);
            $this->assertSame($role, $auth->role());
        }
    }

    /** @param array<int, Response> $responses */
    private function authWithResponses(array $responses): GoogleAuth
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
        return new GoogleAuth('client', 'secret', 'https://app.example.com/callback', 'example.com', 'viewer', $client);
    }
}
