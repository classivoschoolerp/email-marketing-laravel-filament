<?php

declare(strict_types=1);

namespace App\Services\Settings\Readers;

use App\Settings\EnterpriseSsoSettings;

/**
 * Typed accessors for the `enterprise_sso` group (spec §6.7 F-6.7-002).
 *
 * "Configured" means the same here as it does for the P2 providers: enabled
 * AND every value the protocol cannot run without is present. A half-filled
 * form must never put a sign-in button on the login screen — the button would
 * only ever produce an error, and an error at the identity provider is much
 * harder for an operator to diagnose than a missing button.
 */
final readonly class EnterpriseSsoSettingsReader
{
    public function __construct(private EnterpriseSsoSettings $settings) {}

    // ---- Generic OIDC ---------------------------------------------------

    public function oidcEnabled(): bool
    {
        return $this->settings->oidc_enabled;
    }

    public function oidcLabel(): string
    {
        return $this->blank($this->settings->oidc_label) ?? __('sso.oidc.default_label');
    }

    /** Trailing slash removed: discovery concatenates a path onto it. */
    public function oidcIssuer(): ?string
    {
        $issuer = $this->blank($this->settings->oidc_issuer);

        return $issuer === null ? null : rtrim($issuer, '/');
    }

    public function oidcClientId(): ?string
    {
        return $this->blank($this->settings->oidc_client_id);
    }

    public function oidcClientSecret(): ?string
    {
        return $this->blank($this->settings->oidc_client_secret);
    }

    /**
     * `openid` is forced in. An authorization request without it is an OAuth2
     * request, not an OIDC one, and would come back with no id_token to check.
     *
     * @return list<string>
     */
    public function oidcScopes(): array
    {
        $scopes = preg_split('/[\s,]+/', (string) $this->settings->oidc_scopes) ?: [];
        $scopes = array_values(array_filter(array_map('trim', $scopes)));

        if (! in_array('openid', $scopes, true)) {
            array_unshift($scopes, 'openid');
        }

        return $scopes;
    }

    public function oidcConfigured(): bool
    {
        return $this->oidcEnabled()
            && $this->oidcIssuer() !== null
            && $this->oidcClientId() !== null
            && $this->oidcClientSecret() !== null;
    }

    // ---- SAML 2.0 -------------------------------------------------------

    public function samlEnabled(): bool
    {
        return $this->settings->saml_enabled;
    }

    public function samlLabel(): string
    {
        return $this->blank($this->settings->saml_label) ?? __('sso.saml.default_label');
    }

    public function samlIdpEntityId(): ?string
    {
        return $this->blank($this->settings->saml_idp_entity_id);
    }

    public function samlIdpSsoUrl(): ?string
    {
        return $this->blank($this->settings->saml_idp_sso_url);
    }

    /**
     * The IdP's signing certificates.
     *
     * A LIST, not one value, so a certificate rollover is a paste rather than
     * an outage: during the overlap both the old and the new certificate are
     * accepted, and the old one is removed afterwards. Operators paste either
     * full PEM blocks or the bare base64 body out of an IdP metadata document,
     * so both are split apart here and normalised downstream.
     *
     * @return list<string>
     */
    public function samlIdpCertificates(): array
    {
        $raw = $this->blank($this->settings->saml_idp_certificate);

        if ($raw === null) {
            return [];
        }

        if (str_contains($raw, '-----BEGIN CERTIFICATE-----')) {
            preg_match_all(
                '/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s',
                $raw,
                $matches,
            );

            return array_values(array_map('trim', $matches[0] ?? []));
        }

        // Bare base64: blank-line separated blocks.
        $blocks = preg_split('/\n\s*\n/', trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $blocks)));
    }

    /** Our EntityID; falls back to the application URL, which is stable. */
    public function samlSpEntityId(): string
    {
        return $this->blank($this->settings->saml_sp_entity_id)
            ?? rtrim((string) config('app.url'), '/').'/sso/saml/metadata';
    }

    public function samlWantAssertionsSigned(): bool
    {
        return $this->settings->saml_want_assertions_signed;
    }

    /** Clamped: an operator cannot widen the replay window to hours. */
    public function samlClockSkewSeconds(): int
    {
        return max(0, min(600, $this->settings->saml_clock_skew_seconds));
    }

    public function samlNameIdFormat(): string
    {
        return $this->blank($this->settings->saml_nameid_format)
            ?? 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
    }

    public function samlEmailAttribute(): ?string
    {
        return $this->blank($this->settings->saml_email_attribute);
    }

    public function samlFirstNameAttribute(): ?string
    {
        return $this->blank($this->settings->saml_first_name_attribute);
    }

    public function samlLastNameAttribute(): ?string
    {
        return $this->blank($this->settings->saml_last_name_attribute);
    }

    public function samlTrustAssertedEmail(): bool
    {
        return $this->settings->saml_trust_asserted_email;
    }

    public function samlConfigured(): bool
    {
        return $this->samlEnabled()
            && $this->samlIdpEntityId() !== null
            && $this->samlIdpSsoUrl() !== null
            && $this->samlIdpCertificates() !== [];
    }

    private function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
