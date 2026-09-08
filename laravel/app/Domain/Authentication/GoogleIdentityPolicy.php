<?php

namespace App\Domain\Authentication;

use Laravel\Socialite\Two\User;

class GoogleIdentityPolicy
{
    /** @var list<string> */
    private array $allowedDomains;

    public function __construct(?string $allowedDomains = null)
    {
        $domains = $allowedDomains ?? (string) config('services.google.allowed_domains', '');
        $parsed = preg_split('/[,\s]+/', mb_strtolower($domains)) ?: [];
        $this->allowedDomains = array_values(array_unique(array_filter(array_map(fn (string $domain): string => ltrim(trim($domain), '@'), $parsed), fn (string $domain): bool => $domain !== '' && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false)));
    }

    public function allows(User $identity): bool
    {
        $raw = $identity->getRaw();
        $hostedDomain = mb_strtolower(trim(is_scalar($raw['hd'] ?? null) ? (string) $raw['hd'] : ''));

        return trim((string) $identity->getId()) !== ''
            && filter_var($identity->getEmail(), FILTER_VALIDATE_EMAIL) !== false
            && ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true
            && in_array($hostedDomain, $this->allowedDomains, true);
    }

    /** @return list<string> */
    public function allowedDomains(): array
    {
        return $this->allowedDomains;
    }
}
