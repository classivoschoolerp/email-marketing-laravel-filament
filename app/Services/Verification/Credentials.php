<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Models\Customer;
use App\Services\Preferences\CustomerPreferences;

/**
 * Credential + provider-choice resolution (§2.9.12 F-2.9.12-003, choice
 * documented in config/emp-verification.php): a customer's own API key
 * (customer_preferences "verification.api_key.{provider}") wins; the
 * operator's shared key (config('emp-verification.providers.{key}.api_key'),
 * env-driven) is the fallback. Provider choice follows the same chain:
 * customer preference "verification.provider" → config default (built-in).
 */
class Credentials
{
    public const PREF_PROVIDER = 'verification.provider';

    public const PREF_API_KEY_PREFIX = 'verification.api_key.';

    public function __construct(
        private readonly CustomerPreferences $preferences,
        private readonly ProviderRegistry $registry,
    ) {}

    /** The provider key this customer's verifications should use. */
    public function providerKeyFor(Customer $customer): string
    {
        $choice = (string) $this->preferences->get($customer, self::PREF_PROVIDER, '');

        if ($choice !== '' && $this->registry->has($choice)) {
            return $choice;
        }

        return (string) config('emp-verification.default', 'built-in');
    }

    public function apiKeyFor(Customer $customer, string $providerKey): ?string
    {
        $own = $this->preferences->get($customer, self::PREF_API_KEY_PREFIX.$providerKey);

        if ($own !== null && $own !== '') {
            return $own;
        }

        $shared = config('emp-verification.providers.'.$providerKey.'.api_key');

        return is_string($shared) && $shared !== '' ? $shared : null;
    }

    /** Ready-to-use provider for the customer (their choice + credential). */
    public function providerFor(Customer $customer, ?string $providerKey = null): VerificationProvider
    {
        $key = $providerKey !== null && $this->registry->has($providerKey)
            ? $providerKey
            : $this->providerKeyFor($customer);

        return $this->registry->make($key, $this->apiKeyFor($customer, $key));
    }
}
