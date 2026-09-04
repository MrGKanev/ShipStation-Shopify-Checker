<?php
declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * Minimal Google OpenID Connect client for dashboard authentication.
 *
 * Access is decided from Google's verified `hd` (hosted domain) claim. The
 * email suffix is deliberately not used as proof of Workspace membership.
 */
class GoogleAuth
{
    private const string AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const string TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const string USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** @var array<int, string> */
    private array $allowedDomains;
    private ClientInterface $http;

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        string $allowedDomains,
        private readonly string $defaultRole = 'viewer',
        ?ClientInterface $http = null,
    ) {
        $this->allowedDomains = self::parseDomains($allowedDomains);
        $this->http = $http ?? new Client(['timeout' => 10, 'connect_timeout' => 5]);
    }

    public static function fromEnvironment(?ClientInterface $http = null): self
    {
        return new self(
            trim((string) (getenv('GOOGLE_CLIENT_ID') ?: '')),
            trim((string) (getenv('GOOGLE_CLIENT_SECRET') ?: '')),
            trim((string) (getenv('GOOGLE_REDIRECT_URI') ?: '')),
            (string) (getenv('GOOGLE_ALLOWED_DOMAINS') ?: ''),
            trim((string) (getenv('GOOGLE_DEFAULT_ROLE') ?: 'viewer')),
            $http,
        );
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== ''
            && $this->clientSecret !== ''
            && self::validRedirectUri($this->redirectUri)
            && $this->allowedDomains !== [];
    }

    /** @return array<int, string> */
    public function configurationErrors(): array
    {
        $errors = [];
        if ($this->clientId === '') $errors[] = 'GOOGLE_CLIENT_ID is missing.';
        if ($this->clientSecret === '') $errors[] = 'GOOGLE_CLIENT_SECRET is missing.';
        if (!self::validRedirectUri($this->redirectUri)) {
            $errors[] = 'GOOGLE_REDIRECT_URI must use HTTPS (HTTP is allowed only for localhost).';
        }
        if ($this->allowedDomains === []) $errors[] = 'GOOGLE_ALLOWED_DOMAINS has no valid domains.';
        if (!in_array($this->defaultRole, ['viewer', 'operator', 'admin'], true)) {
            $errors[] = 'GOOGLE_DEFAULT_ROLE must be viewer, operator, or admin.';
        }
        return $errors;
    }

    public function role(): string
    {
        return in_array($this->defaultRole, ['viewer', 'operator', 'admin'], true)
            ? $this->defaultRole
            : 'viewer';
    }

    public function authorizationUrl(string $state, string $codeVerifier): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Google authentication is not configured.');
        }

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => self::base64Url(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];

        // This only streamlines Google's account picker. Authorization still
        // depends on the server-side claim check in isAllowedIdentity().
        if (count($this->allowedDomains) === 1) {
            $params['hd'] = $this->allowedDomains[0];
        }

        return self::AUTHORIZE_URL . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange an authorization code and fetch its Google identity claims.
     *
     * @return array<string, mixed>
     */
    public function identityFromCode(string $code, string $codeVerifier): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Google authentication is not configured.');
        }

        $response = $this->http->request('POST', self::TOKEN_URL, [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => [
                'code' => $code,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri' => $this->redirectUri,
                'grant_type' => 'authorization_code',
                'code_verifier' => $codeVerifier,
            ],
            'http_errors' => false,
        ]);
        $tokenData = self::decodeResponse($response->getStatusCode(), (string) $response->getBody(), 'token');
        $accessToken = (string) ($tokenData['access_token'] ?? '');
        if ($accessToken === '') {
            throw new RuntimeException('Google token response did not contain an access token.');
        }

        $response = $this->http->request('GET', self::USERINFO_URL, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'http_errors' => false,
        ]);

        return self::decodeResponse($response->getStatusCode(), (string) $response->getBody(), 'userinfo');
    }

    /** @param array<string, mixed> $identity */
    public function isAllowedIdentity(array $identity): bool
    {
        $hostedDomain = strtolower(trim((string) ($identity['hd'] ?? '')));

        return trim((string) ($identity['sub'] ?? '')) !== ''
            && filter_var((string) ($identity['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false
            && ($identity['email_verified'] ?? false) === true
            && in_array($hostedDomain, $this->allowedDomains, true);
    }

    /** @return array<int, string> */
    public static function parseDomains(string $domains): array
    {
        $result = [];
        foreach (preg_split('/[,\s]+/', strtolower($domains)) ?: [] as $domain) {
            $domain = ltrim(trim($domain), '@');
            if ($domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
                $result[$domain] = $domain;
            }
        }
        return array_values($result);
    }

    /** @return array<string, mixed> */
    private static function decodeResponse(int $status, string $body, string $endpoint): array
    {
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Google {$endpoint} endpoint returned invalid JSON.");
        }
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            throw new RuntimeException("Google {$endpoint} request failed with HTTP {$status}.");
        }
        return $data;
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function validRedirectUri(string $uri): bool
    {
        if (filter_var($uri, FILTER_VALIDATE_URL) === false) return false;
        $parts = parse_url($uri);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localhost = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        return $scheme === 'https' || ($scheme === 'http' && $localhost);
    }
}
