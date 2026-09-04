<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/GoogleAuth.php';
require_once __DIR__ . '/../../src/GoogleAuthFlow.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GoogleAuthFlowTest extends TestCase
{
    public function testBeginStoresFreshStateVerifierAndTimestamp(): void
    {
        $session = [];
        $url = (new GoogleAuthFlow($this->auth()))->begin($session, 123456);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session['_google_oauth']['state']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $session['_google_oauth']['code_verifier']);
        $this->assertSame(123456, $session['_google_oauth']['created_at']);
        $this->assertSame($session['_google_oauth']['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testCancelledCallbackConsumesSessionWithoutCallingGoogle(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->auth());
        $flow->begin($session, 1000);

        $result = $flow->complete(['error' => 'access_denied'], $session, 1001);

        $this->assertSame(GoogleAuthFlow::CANCELLED, $result['status']);
        $this->assertArrayNotHasKey('_google_oauth', $session);
    }

    public function testWrongStateIsRejectedAndCannotBeRetried(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->auth());
        $flow->begin($session, 1000);
        $correctState = $session['_google_oauth']['state'];

        $first = $flow->complete(['state' => 'wrong', 'code' => 'code'], $session, 1001);
        $second = $flow->complete(['state' => $correctState, 'code' => 'code'], $session, 1001);

        $this->assertSame(GoogleAuthFlow::EXPIRED, $first['status']);
        $this->assertSame(GoogleAuthFlow::EXPIRED, $second['status']);
    }

    public function testStateExpiresAfterTenMinutes(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->auth());
        $flow->begin($session, 1000);
        $state = $session['_google_oauth']['state'];

        $result = $flow->complete(['state' => $state, 'code' => 'code'], $session, 1601);

        $this->assertSame(GoogleAuthFlow::EXPIRED, $result['status']);
    }

    public function testStateIsStillValidAtTenMinuteBoundary(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->authWithIdentity('example.com'));
        $flow->begin($session, 1000);
        $state = $session['_google_oauth']['state'];

        $result = $flow->complete(['state' => $state, 'code' => 'code'], $session, 1600);

        $this->assertSame(GoogleAuthFlow::ALLOWED, $result['status']);
    }

    public function testMissingAuthorizationCodeReturnsError(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->auth());
        $flow->begin($session, 1000);
        $state = $session['_google_oauth']['state'];

        $result = $flow->complete(['state' => $state], $session, 1001);

        $this->assertSame(GoogleAuthFlow::ERROR, $result['status']);
    }

    public function testAllowedCallbackReturnsIdentityAndConfiguredRole(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->authWithIdentity('example.com', 'operator'));
        $flow->begin($session, 1000);
        $state = $session['_google_oauth']['state'];

        $result = $flow->complete(['state' => $state, 'code' => 'google-code'], $session, 1001);

        $this->assertSame(GoogleAuthFlow::ALLOWED, $result['status']);
        $this->assertSame('person@example.com', $result['identity']['email']);
        $this->assertSame('operator', $result['role']);
        $this->assertArrayNotHasKey('_google_oauth', $session);
    }

    public function testUnlistedWorkspaceDomainReturnsDenied(): void
    {
        $session = [];
        $flow = new GoogleAuthFlow($this->authWithIdentity('outsider.example'));
        $flow->begin($session, 1000);
        $state = $session['_google_oauth']['state'];

        $result = $flow->complete(['state' => $state, 'code' => 'google-code'], $session, 1001);

        $this->assertSame(GoogleAuthFlow::DENIED, $result['status']);
        $this->assertArrayNotHasKey('identity', $result);
    }

    private function auth(): GoogleAuth
    {
        return new GoogleAuth(
            'client',
            'secret',
            'https://app.example.com/?auth=google_callback',
            'example.com',
            'viewer',
            new Client(['handler' => HandlerStack::create(new MockHandler())]),
        );
    }

    private function authWithIdentity(string $hostedDomain, string $role = 'viewer'): GoogleAuth
    {
        $mock = new MockHandler([
            new Response(200, [], '{"access_token":"access-token"}'),
            new Response(200, [], json_encode([
                'sub' => 'subject',
                'email' => 'person@example.com',
                'email_verified' => true,
                'hd' => $hostedDomain,
                'name' => 'Example Person',
            ])),
        ]);
        return new GoogleAuth(
            'client',
            'secret',
            'https://app.example.com/?auth=google_callback',
            'example.com',
            $role,
            new Client(['handler' => HandlerStack::create($mock)]),
        );
    }
}
