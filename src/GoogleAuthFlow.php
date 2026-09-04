<?php
declare(strict_types=1);

/**
 * Manages the one-time session state around the Google OAuth round trip.
 */
class GoogleAuthFlow
{
    public const string ALLOWED = 'allowed';
    public const string DENIED = 'denied';
    public const string CANCELLED = 'cancelled';
    public const string EXPIRED = 'expired';
    public const string ERROR = 'error';

    private const string SESSION_KEY = '_google_oauth';
    private const int STATE_TTL = 600;

    public function __construct(private readonly GoogleAuth $auth)
    {
    }

    /** @param array<string, mixed> $session */
    public function begin(array &$session, ?int $now = null): string
    {
        $state = bin2hex(random_bytes(32));
        $codeVerifier = bin2hex(random_bytes(32));
        $session[self::SESSION_KEY] = [
            'state' => $state,
            'code_verifier' => $codeVerifier,
            'created_at' => $now ?? time(),
        ];

        return $this->auth->authorizationUrl($state, $codeVerifier);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $session
     * @return array{status:string,identity?:array<string,mixed>,role?:string}
     */
    public function complete(array $query, array &$session, ?int $now = null): array
    {
        $oauth = $session[self::SESSION_KEY] ?? [];
        unset($session[self::SESSION_KEY]);

        if (isset($query['error'])) {
            return ['status' => self::CANCELLED];
        }

        $expectedState = (string) ($oauth['state'] ?? '');
        $actualState = (string) ($query['state'] ?? '');
        $createdAt = (int) ($oauth['created_at'] ?? 0);
        $currentTime = $now ?? time();
        if ($expectedState === ''
            || $actualState === ''
            || !hash_equals($expectedState, $actualState)
            || $createdAt < $currentTime - self::STATE_TTL
        ) {
            return ['status' => self::EXPIRED];
        }

        $code = trim((string) ($query['code'] ?? ''));
        $codeVerifier = trim((string) ($oauth['code_verifier'] ?? ''));
        if (!$this->auth->isConfigured() || $code === '' || $codeVerifier === '') {
            return ['status' => self::ERROR];
        }

        $identity = $this->auth->identityFromCode($code, $codeVerifier);
        if (!$this->auth->isAllowedIdentity($identity)) {
            return ['status' => self::DENIED];
        }

        return [
            'status' => self::ALLOWED,
            'identity' => $identity,
            'role' => $this->auth->role(),
        ];
    }
}
