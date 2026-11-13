<?php

declare(strict_types=1);

/*
| Themes, white-label branding, multi-domain and SSO (spec §6.3, §3.21,
| §4.7, §6.4, §6.7 — WP-38).
|
| One file for four sections because they are one surface: a brand is a
| theme plus a logo plus a host, and the SSO strings are the login-screen
| half of that same branded surface.
*/

return [

    'nav_group' => 'Appearance',

    // ---- §6.3 built-in themes ------------------------------------------
    'builtin' => [
        'default' => 'Default',
        'dark' => 'Dark',
        'light' => 'Light',
        'red' => 'Red',
        'blue' => 'Blue',
        'green' => 'Green',
        'pink' => 'Pink',
        'brown' => 'Brown',
        'classic' => 'Classic',
        'modern' => 'Modern',
    ],

    'source' => [
        'builtin' => 'Built-in',
        'package' => 'Uploaded',
    ],

    'areas' => [
        'backend' => 'Admin panel',
        'customer' => 'Customer panel',
        'frontend' => 'Public site',
    ],

    // ---- §3.21 theme manager -------------------------------------------
    'manager' => [
        'title' => 'Themes',
        'navigation_label' => 'Themes',
        'label' => 'Theme',
        'label_plural' => 'Themes',
        'description' => 'Pick the active theme for the admin panel, the customer panel and the public site independently. Users may override the choice for themselves.',
        'sections' => [
            'active' => 'Active themes',
            'installed' => 'Installed theme packages',
        ],
        'fields' => [
            'slug' => 'Slug',
            'name' => 'Name',
            'version' => 'Version',
            'author' => 'Author',
            'source' => 'Source',
            'areas' => 'Supported areas',
            'installed_at' => 'Installed',
            'checksum' => 'Package checksum',
        ],
        'actions' => [
            'upload' => 'Upload theme',
            'delete' => 'Delete',
            'activate' => 'Activate',
        ],
        'upload' => [
            'heading' => 'Upload a theme package',
            'file' => 'Theme .zip',
            'trust' => 'A theme package contains stylesheets and images only. Files that could execute — .php, .js, .sh and similar — are rejected outright, and nothing inside a package is ever included or run.',
            'accepted' => 'I understand this replaces any installed theme with the same slug.',
            'submit' => 'Upload & install',
        ],
        'activate' => [
            'heading' => 'Activate for an area',
            'area' => 'Area',
            'submit' => 'Activate',
        ],
        'notifications' => [
            'installed' => 'Theme ":name" installed.',
            'deleted' => 'Theme ":name" deleted. Any area using it now falls back to the default.',
            'activated' => 'Theme ":name" is now active for :area.',
            'rejected' => 'The theme package was rejected.',
        ],
        'empty' => 'No theme packages are installed. The built-in themes are always available.',
    ],

    // ---- §3.21 / §4.7 customiser ---------------------------------------
    'customiser' => [
        'title' => 'Branding & customiser',
        'navigation_label' => 'Branding',
        'description' => 'Logo, favicon, brand colours and login background for this installation. Every value here can be overridden per brand domain.',
        'sections' => [
            'identity' => 'Logo & favicon',
            'colours' => 'Brand colours',
            'login' => 'Login screen',
            'white_label' => 'White-label',
            'themes' => 'Active themes',
            'preview' => 'Preview',
        ],
        'fields' => [
            'logo_path' => 'Logo',
            'favicon_path' => 'Favicon',
            'brand_primary_color' => 'Primary colour',
            'brand_secondary_color' => 'Secondary colour',
            'login_background_path' => 'Login background image',
            'white_label_product_name' => 'Product name',
            'hide_branding' => 'Hide product branding',
            'brand_email_footer' => 'Branded email footer',
            'brand_email_footer_html' => 'Custom email footer HTML',
            'theme_backend' => 'Admin panel theme',
            'theme_customer' => 'Customer panel theme',
            'theme_frontend' => 'Public site theme',
        ],
        'help' => [
            'colour' => 'Hex value, e.g. #4f46e5. Anything else is ignored rather than injected into the page.',
            'white_label_product_name' => 'Replaces the product name everywhere the interface shows it. Leave blank to use the configured application name.',
            'hide_branding' => 'Removes the product name and links from footers, emails and public pages.',
            'logo' => 'Shown above the panel navigation and on the public site. Stored on the public disk.',
            'brand_email_footer' => 'Whether subscribers on this plan get the tenant’s brand stamped on their outgoing mail. Leave unset to follow the white-label switch above.',
            'brand_email_footer_html' => 'Replaces the generated footer block. Rendered as authored — inline styles only, no external CSS.',
        ],
        'options' => [
            'footer_on' => 'Always show a branded footer',
            'footer_off' => 'Never show a branded footer',
            'footer_inherit' => 'Follow the white-label switch',
        ],
        'preview' => [
            'heading' => 'Live preview',
            'body' => 'This block renders with the colours and theme currently entered above.',
            'button' => 'Primary button',
        ],
        'saved' => 'Branding saved.',
    ],

    // ---- §6.3 per-user preference --------------------------------------
    'preference' => [
        'title' => 'My theme',
        'navigation_label' => 'My theme',
        'field' => 'Theme',
        'inherit' => 'Use the default for this installation',
        'help' => 'Applies to your account only. Other users keep their own choice.',
        'saved' => 'Theme updated.',
    ],

    // ---- §6.4 multi-domain ---------------------------------------------
    'domains' => [
        'label' => 'Brand domain',
        'label_plural' => 'Brand domains',
        'navigation_label' => 'Brand domains',
        'description' => 'Run several brands from this one installation. The request host selects the brand.',
        'sections' => [
            'identity' => 'Identity',
            'branding' => 'Branding',
            'email' => 'From-addresses & email templates',
            'pages' => 'Legal pages',
            'auth' => 'Registration & login',
            'ssl' => 'TLS certificate',
        ],
        'fields' => [
            'host' => 'Host',
            'host_help' => 'Bare hostname, e.g. mail.acme.example. No scheme, no port. Point it at this server with a DNS A/CNAME record.',
            'name' => 'Brand name',
            'active' => 'Active',
            'is_default' => 'Default brand',
            'is_default_help' => 'Used for hosts that match no other brand. Only one domain can hold this.',
            'customer' => 'Assigned customer (agency mode)',
            'theme' => 'Theme',
            'logo_path' => 'Logo',
            'favicon_path' => 'Favicon',
            'login_background_path' => 'Login background',
            'primary_color' => 'Primary colour',
            'secondary_color' => 'Secondary colour',
            'from_name' => 'From name',
            'from_email' => 'From address',
            'reply_to_email' => 'Reply-to address',
            'terms_page_slug' => 'Terms page slug',
            'privacy_page_slug' => 'Privacy page slug',
            'registration_enabled' => 'Allow registration on this host',
            'login_enabled' => 'Allow login on this host',
            // F-4.7-002
            'panel_enabled' => 'Serve the customer panel on this host',
            'panel_enabled_help' => 'Off for a marketing-only brand: /customer answers 404 here.',
            'panel_restricted' => 'Only this brand\'s accounts may use the panel here',
            'panel_restricted_help' => 'On: a signed-in customer that does not belong to this brand is signed out and sent back to the login page. With an assigned customer above, only that one account is admitted.',
            'customer_group' => 'Customer group for sign-ups',
            'force_https' => 'Redirect http:// to https://',
            'ssl_status' => 'Certificate',
            'ssl_issuer' => 'Issuer',
            'ssl_expires_at' => 'Expires',
            'ssl_checked_at' => 'Last checked',
            'ssl_error' => 'Last error',
            'customers_count' => 'Customers',
        ],
        'actions' => [
            'create' => 'New brand domain',
            'check_ssl' => 'Check certificate',
        ],
        'panel' => [
            'not_your_domain' => 'That account cannot sign in on this address.',
        ],
        'ssl_status' => [
            'unknown' => 'Not checked',
            'none' => 'No certificate',
            'active' => 'Valid',
            'expiring' => 'Expiring soon',
            'expired' => 'Expired',
            'mismatch' => 'Wrong hostname',
            'error' => 'Unreachable',
        ],
        'ssl_errors' => [
            'no_host' => 'No host to check.',
            'no_certificate' => 'The host answered but presented no certificate.',
            'unparsable' => 'The certificate could not be parsed.',
            'mismatch' => 'The certificate covers :names, which does not include this host.',
        ],
        'ssl_notice' => 'This platform REPORTS certificate state; it does not issue or renew certificates. Install one on your web server or terminating proxy (Let’s Encrypt, ACME, or your own CA) and re-check here.',
        'notifications' => [
            'ssl_checked' => 'Certificate checked: :status',
        ],
        'errors' => [
            'host_taken' => 'Another brand already uses the host ":host".',
            'host_is_app_host' => 'This is the application’s own host; it is already served and cannot be a brand domain.',
        ],
    ],

    // ---- §6.7 SSO -------------------------------------------------------
    'sso' => [
        'title' => 'SSO & external login',
        'navigation_label' => 'SSO',
        'description' => 'One-time login tokens for external portals, and social sign-in for customers.',
        'sections' => [
            'login_token' => 'One-time login token API',
            'login_token_description' => 'Lets an external application obtain a short-lived, single-use token and drop a customer straight into their account. The token logs somebody in with no password and no second factor, so keep the lifetime short.',
            'google' => 'Google sign-in',
            'microsoft' => 'Microsoft sign-in',
            'github' => 'GitHub sign-in',
            'accounts' => 'Account handling',
            'saml' => 'SAML / OIDC',
            'saml_moved' => 'SAML 2.0 and generic OpenID Connect (§6.7 F-6.7-002) are configured on their own screen: :url',
        ],
        'fields' => [
            'login_token_enabled' => 'Enable the one-time login token API',
            'login_token_ttl_seconds' => 'Token lifetime (seconds)',
            'google_enabled' => 'Enable Google sign-in',
            'google_client_id' => 'Google client ID',
            'google_client_secret' => 'Google client secret',
            'microsoft_enabled' => 'Enable Microsoft sign-in',
            'microsoft_client_id' => 'Microsoft client ID',
            'microsoft_client_secret' => 'Microsoft client secret',
            'microsoft_tenant' => 'Microsoft tenant',
            'auto_create_accounts' => 'Create an account on first social sign-in',
        ],
        'help' => [
            'redirect_uri' => 'Redirect URI to register with the provider: :url',
            'ttl' => 'Clamped to :max seconds by configuration; an API caller can request less but never more.',
            'auto_create_accounts' => 'Off: only customers who already exist can sign in socially. On: a first-time, provider-verified email creates a customer. It also decides whether the provider buttons appear on the registration form at all.',
            'microsoft_tenant' => '“common” for any Microsoft account. Use your directory ID for a single-tenant app registration.',
            'github_env' => 'GitHub credentials are read from the environment, not from this page: set EMP_SSO_GITHUB_ENABLED=true, EMP_SSO_GITHUB_CLIENT_ID and EMP_SSO_GITHUB_CLIENT_SECRET, then clear the config cache. Everything else about the provider — the buttons, the callback, account creation — behaves exactly like Google and Microsoft.',
        ],
        'providers' => [
            'google' => 'Google',
            'microsoft' => 'Microsoft',
            'github' => 'GitHub',
        ],
        'status' => [
            'configured' => 'Configured and offered on the sign-in and sign-up screens.',
            'disabled' => 'Not configured. This provider is not offered anywhere.',
        ],
        'continue_with' => 'Continue with :provider',
        'errors' => [
            'unknown_provider' => 'Unknown sign-in provider ":provider".',
            'not_configured' => ':provider sign-in is not configured.',
            'provider_unreachable' => 'The sign-in provider could not be reached. Please try again.',
            'exchange_failed' => 'The sign-in could not be completed.',
            'identity_failed' => 'The sign-in provider did not return your account details.',
            'no_subject' => 'The sign-in provider did not identify the account.',
            'state_mismatch' => 'The sign-in request expired or did not match. Please try again.',
            'cancelled' => 'Sign-in was cancelled.',
            'no_code' => 'The sign-in provider did not return an authorization code.',
            'no_account' => 'No account here matches that identity, and self-service sign-up through this provider is turned off.',
            'email_unverified' => 'The sign-in provider has not verified that email address.',
            'account_unavailable' => 'This account cannot sign in.',
            'token_rejected' => 'This login link is not valid.',
            'token_api_disabled' => 'The one-time login token API is disabled.',
            'invalid_redirect' => 'redirect_to must be a path on this site beginning with "/".',
        ],
    ],

    // ---- §6.3 packaging errors -----------------------------------------
    'errors' => [
        'package_invalid' => 'The theme package is not valid.',
        'archive_missing' => 'The uploaded file could not be found.',
        'archive_unreadable' => 'The uploaded file is not a readable .zip archive.',
        'archive_empty' => 'The archive contains no usable theme files.',
        'archive_unsafe_entry' => 'The archive contains an unsafe path (:entry).',
        'archive_path_too_long' => 'The archive contains an over-long path (:entry).',
        'archive_symlink' => 'The archive contains a symbolic link (:entry).',
        'archive_too_many_entries' => 'The archive contains more than :max files.',
        'archive_too_large' => 'The archive expands to more than :max MB.',
        'archive_upload_too_large' => 'The archive is larger than :max MB.',
        'archive_compression_ratio' => 'The archive entry :entry has a suspicious compression ratio.',
        'archive_executable_file' => 'A theme may not contain executable or scriptable files (:entry is a .:extension file).',
        'manifest_missing' => 'The archive has no :file manifest.',
        'manifest_unparsable' => ':file is not valid JSON.',
        'manifest_slug_missing' => 'The manifest has no slug.',
        'manifest_slug_invalid' => 'The slug ":slug" may contain only lower-case letters, numbers and single hyphens.',
        'manifest_slug_length' => 'The slug must be between :min and :max characters.',
        'manifest_slug_reserved' => 'The slug ":slug" is reserved.',
        'manifest_name_missing' => 'The manifest has no name.',
        'manifest_name_too_long' => 'The theme name is too long.',
        'manifest_version_invalid' => 'The version ":version" is not a valid version number.',
        'manifest_stylesheet_missing' => 'The manifest declares no stylesheet.',
        'manifest_stylesheet_unsafe' => 'The stylesheet path ":path" is not a safe relative path.',
        'manifest_stylesheet_not_css' => 'The stylesheet ":path" must be a .css file.',
        'manifest_area_unknown' => 'Unknown area(s): :areas.',
        'manifest_token_unknown' => 'Unknown palette token ":token".',
        'manifest_token_not_hex' => 'Palette token ":token" must be a hex colour, got ":value".',
        'manifest_scheme_invalid' => 'The scheme ":scheme" must be "light" or "dark".',
        'stylesheet_missing' => 'The archive does not contain the declared stylesheet (:path).',
        'extract_failed' => 'Could not write to :path.',
    ],

    // §4.7 F-4.7-004 — agency mode, the customer's own branding.
    'agency' => [
        'title' => 'Agency branding',
        'subheading' => 'Brand this panel with your own name, logo and colours. Your team members see it, and so do the system emails they receive.',
        'save' => 'Save branding',
        'saved' => 'Branding updated.',
        'overridden_by_domain' => 'Your account is served under a brand domain set by the platform operator. Their branding takes priority over the settings below.',
        'fields' => [
            'enabled' => 'Use my branding',
            'enabled_help' => 'Turn this off to fall back to the platform branding without losing what you uploaded.',
            'product_name' => 'Product name',
            'product_name_help' => 'Replaces the platform name in the panel and in system emails to your team.',
            'from_name' => 'Email from name',
            'from_name_help' => 'Used as the sender name on system emails to your team members.',
            'logo' => 'Logo',
            'favicon' => 'Favicon',
            'primary_color' => 'Primary colour',
            'secondary_color' => 'Secondary colour',
        ],
        'errors' => [
            'colour' => 'Enter a hex colour such as #4f46e5.',
        ],
    ],

];
