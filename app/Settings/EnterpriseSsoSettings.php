<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Enterprise SSO — generic OIDC and SAML 2.0 (spec §6.7 F-6.7-002, WP-46).
 *
 * A NEW group rather than fields on {@see SsoSettings}, which is frozen. The
 * split is not only bookkeeping: `sso` holds the P2 consumer-provider rows
 * (Google/Microsoft/GitHub buttons, the one-time login token) and this holds
 * the enterprise identity-provider integration, which is a different feature
 * with a different operator and a different threat model.
 *
 * EVERYTHING DEFAULTS OFF. Both protocols hand authentication to a third
 * party; neither may become live because an upgrade ran.
 *
 * Secrets are in {@see self::encrypted()} — spatie encrypts those at rest with
 * the app key, the D-48 treatment. The IdP's X.509 certificate is deliberately
 * NOT encrypted: it is a public key, publishing it is its entire purpose, and
 * encrypting it would only make it harder for an operator to check that the
 * value they pasted is the one their IdP is using.
 */
class EnterpriseSsoSettings extends Settings
{
    // ---- Generic OIDC ---------------------------------------------------

    public bool $oidc_enabled;

    /**
     * Shown on the login button ("Continue with <label>"). Operators name
     * their own IdP; "Continue with OIDC" means nothing to an end user.
     */
    public string $oidc_label;

    /**
     * Issuer URL. Discovery appends `/.well-known/openid-configuration`, and
     * the `issuer` in the discovery document must match this value exactly —
     * that check is what stops a redirected discovery from silently swapping
     * the identity provider.
     */
    public ?string $oidc_issuer;

    public ?string $oidc_client_id;

    public ?string $oidc_client_secret;

    /** Space-separated. `openid` is added if the operator leaves it out. */
    public string $oidc_scopes;

    // ---- SAML 2.0 -------------------------------------------------------

    public bool $saml_enabled;

    public string $saml_label;

    /** The IdP's EntityID. Every assertion's Issuer must equal it. */
    public ?string $saml_idp_entity_id;

    /** The IdP's HTTP-Redirect SSO endpoint (where AuthnRequests go). */
    public ?string $saml_idp_sso_url;

    /**
     * The IdP's signing certificate(s), PEM or bare base64, one per line-run.
     * A list so a certificate rollover does not require downtime.
     */
    public ?string $saml_idp_certificate;

    /**
     * Our EntityID. Assertions must carry it in an AudienceRestriction, which
     * is what stops an assertion minted for a different service provider from
     * being replayed here.
     */
    public ?string $saml_sp_entity_id;

    /**
     * Require the ASSERTION itself to be signed, not merely the enclosing
     * Response. True is the correct setting and the default; turn it off only
     * for an IdP that signs the Response only, and understand that the
     * assertion is then trusted solely through the Response's digest.
     */
    public bool $saml_want_assertions_signed;

    /** Seconds of clock skew tolerated on NotBefore/NotOnOrAfter. */
    public int $saml_clock_skew_seconds;

    /** NameIDPolicy Format requested in the AuthnRequest. */
    public string $saml_nameid_format;

    /** Attribute name carrying the email address, if not the NameID. */
    public ?string $saml_email_attribute;

    public ?string $saml_first_name_attribute;

    public ?string $saml_last_name_attribute;

    /**
     * Treat an email address asserted by the IdP as VERIFIED.
     *
     * Off by default, and the default is the safe one. SAML has no
     * `email_verified` claim, so with this off an assertion can only ever sign
     * in an account already linked to its NameID — it can never claim an
     * existing local account by naming its address. Turning it on is the
     * operator declaring "this IdP is authoritative for these mailboxes",
     * which is true of a corporate directory and false of anything open to
     * self-registration. See docs/sso.md.
     */
    public bool $saml_trust_asserted_email;

    public static function group(): string
    {
        return 'enterprise_sso';
    }

    /**
     * @return list<string>
     */
    public static function encrypted(): array
    {
        return [
            'oidc_client_secret',
        ];
    }
}
