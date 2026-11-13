<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Country-specific consent rules (spec §4.5 F-4.5-003)
|--------------------------------------------------------------------------
| CASL and the other anti-spam regimes differ in three ways that actually
| change what this platform must do, so those are the three things modelled
| here — everything else about them is legal text, not software:
|
|   requires_express_consent  the regime does not accept a bare opt-in; the
|                             list must run double opt-in so a confirmation
|                             timestamp + IP exists as proof.
|   implied_consent_days      how long a subscriber WITHOUT that confirmation
|                             may still be mailed, counted from subscribed_at.
|                             null = indefinitely (CAN-SPAM has no such clock).
|   requires_sender_identification
|                             every message must identify the sender and carry
|                             a physical postal address.
|
| The regime for a campaign comes from the target list's declared company
| country (`lists.company_country`) — the jurisdiction the sender operates
| under and the one whose address goes in the footer. `default` applies when
| the list declares no country or an unmapped one.
|
| Enforcement is OFF unless `enforce` is true. Turning it on can drop
| recipients from a send (implied consent that has aged out) and can block
| scheduling (single opt-in under an express-consent regime), so it is an
| explicit operator decision, exactly like the §4.5 CAN-SPAM address gate.
*/

return [

    'enforce' => (bool) env('EMP_CONSENT_ENFORCE', false),

    /*
     * Regime obligations. `law` is the display name shown in the campaign
     * pre-flight detail so an operator can see WHICH law is being applied.
     */
    'rules' => [
        'default' => [
            'law' => 'CAN-SPAM',
            'requires_express_consent' => false,
            'implied_consent_days' => null,
            'requires_sender_identification' => true,
        ],

        // Canada — Anti-Spam Legislation. Express consent never expires;
        // implied consent runs out 24 months after the transaction that
        // created it, which for a mailing list is the subscription itself.
        'casl' => [
            'law' => 'CASL',
            'requires_express_consent' => true,
            'implied_consent_days' => 730,
            'requires_sender_identification' => true,
        ],

        // EU/EEA — GDPR + ePrivacy: consent must be freely given, specific
        // and demonstrable, which in practice means confirmed opt-in.
        'gdpr' => [
            'law' => 'GDPR / ePrivacy',
            'requires_express_consent' => true,
            'implied_consent_days' => null,
            'requires_sender_identification' => true,
        ],

        // United Kingdom — PECR survives Brexit with the same shape.
        'pecr' => [
            'law' => 'UK PECR',
            'requires_express_consent' => true,
            'implied_consent_days' => null,
            'requires_sender_identification' => true,
        ],

        // Australia — Spam Act 2003. Inferred consent from an existing
        // relationship, with no statutory clock; identification mandatory.
        'spam_act' => [
            'law' => 'Spam Act 2003',
            'requires_express_consent' => false,
            'implied_consent_days' => null,
            'requires_sender_identification' => true,
        ],
    ],

    /*
     * ISO-3166 alpha-2 country => regime key. Anything unlisted falls back
     * to `default`.
     */
    'countries' => [
        'CA' => 'casl',

        'GB' => 'pecr',

        'AU' => 'spam_act',

        // EU + EEA.
        'AT' => 'gdpr', 'BE' => 'gdpr', 'BG' => 'gdpr', 'HR' => 'gdpr', 'CY' => 'gdpr',
        'CZ' => 'gdpr', 'DK' => 'gdpr', 'EE' => 'gdpr', 'FI' => 'gdpr', 'FR' => 'gdpr',
        'DE' => 'gdpr', 'GR' => 'gdpr', 'HU' => 'gdpr', 'IE' => 'gdpr', 'IT' => 'gdpr',
        'LV' => 'gdpr', 'LT' => 'gdpr', 'LU' => 'gdpr', 'MT' => 'gdpr', 'NL' => 'gdpr',
        'PL' => 'gdpr', 'PT' => 'gdpr', 'RO' => 'gdpr', 'SK' => 'gdpr', 'SI' => 'gdpr',
        'ES' => 'gdpr', 'SE' => 'gdpr',
        'IS' => 'gdpr', 'LI' => 'gdpr', 'NO' => 'gdpr',
    ],

];
