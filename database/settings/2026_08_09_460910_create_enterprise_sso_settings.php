<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Enterprise SSO settings — generic OIDC + SAML 2.0
 * (spec §6.7 F-6.7-002, WP-46).
 *
 * Same shape as the WP-38 `sso` group, and the same rule: EVERYTHING DEFAULTS
 * OFF. Both protocols delegate authentication to a third party, and an upgrade
 * running is not a decision to do that.
 *
 * `oidc_client_secret` is added with addEncrypted so it is encrypted at rest
 * with the app key (D-48). The SAML IdP certificate is NOT encrypted — it is a
 * public key whose whole purpose is publication, and hiding it would only stop
 * an operator checking that what they pasted matches their IdP.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('enterprise_sso', function (SettingsBlueprint $blueprint): void {
            // ---- Generic OIDC ------------------------------------------
            $blueprint->add('oidc_enabled', false);
            $blueprint->add('oidc_label', 'Single sign-on');
            $blueprint->add('oidc_issuer', null);
            $blueprint->add('oidc_client_id', null);
            $blueprint->addEncrypted('oidc_client_secret', null);
            $blueprint->add('oidc_scopes', 'openid email profile');

            // ---- SAML 2.0 ----------------------------------------------
            $blueprint->add('saml_enabled', false);
            $blueprint->add('saml_label', 'SAML single sign-on');
            $blueprint->add('saml_idp_entity_id', null);
            $blueprint->add('saml_idp_sso_url', null);
            $blueprint->add('saml_idp_certificate', null);
            $blueprint->add('saml_sp_entity_id', null);

            // The strict setting is the default: an assertion must carry its
            // own signature, not merely sit inside a signed Response.
            $blueprint->add('saml_want_assertions_signed', true);
            $blueprint->add('saml_clock_skew_seconds', 60);
            $blueprint->add('saml_nameid_format', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress');
            $blueprint->add('saml_email_attribute', null);
            $blueprint->add('saml_first_name_attribute', null);
            $blueprint->add('saml_last_name_attribute', null);

            // Off: without it, a SAML identity can only sign into an account
            // already linked to its NameID, never claim one by naming its
            // address. See App\Settings\EnterpriseSsoSettings.
            $blueprint->add('saml_trust_asserted_email', false);
        });
    }

    public function down(): void
    {
        $this->migrator->deleteGroup('enterprise_sso');
    }
};
