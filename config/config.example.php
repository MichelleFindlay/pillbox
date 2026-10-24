<?php
/*
 * Pillbox settings.
 *
 * Copy this file to config.php (in this same folder) and fill in your details.
 * config.php is the only settings file Pillbox reads. It is git-ignored so your
 * passwords never end up in the repository.
 */
return [
    'app' => [
        'name'                 => 'Pillbox',
        // Your site's full address, used for links that leave Pillbox (e.g. calendar feeds).
        // Leave empty to work it out from each request, e.g. 'https://pillbox.example.com'.
        'public_url'           => '',
        // Web path the app lives under, without a trailing slash.
        //   https://example.com/          -> ''
        //   https://example.com/pillbox/  -> '/pillbox'
        'base_path'            => '',
        'default_timezone'     => 'Europe/London',
        // Shows full error details in the browser. Only turn on while fixing a problem.
        'debug'                => false,
        // true if a reverse proxy / load balancer sits in front of Apache (it terminates HTTPS, and sends the
        // visitor's address in X-Forwarded-For). Then sign-in and setup limits apply to each visitor's own
        // address, not the proxy's. Only turn it on behind a proxy you control, as the header can be faked.
        'trust_proxy'          => false,
        // false = no username/password sign-in at all (single sign-on only).
        'allow_local_login'    => true,
        // true = while any single sign-on (Microsoft or an OIDC provider below) is enabled and
        // configured, nobody can sign in with a Pillbox username and password. If single
        // sign-on is switched off (or not set up), password sign-in works again automatically.
        'disable_local_login_when_sso' => false,
        // Sign people out after this many minutes without activity.
        'session_idle_minutes' => 480,
        // "Remember this device" on the sign-in page: how many days a remembered device stays signed in
        // after it was last used. 0 turns the option off.
        'remember_days'        => 90,
        // Show an approximate city/country next to each entry in Account > Active sessions, by
        // looking up the IP address with a third-party service (ipapi.co) at sign-in. Off by
        // default: this sends the IP address off-server, which is otherwise never done.
        'geoip_lookup'         => false,
        // GitHub repository ("owner/name") linked in the footer and checked for new versions.
        // Set to '' to hide the link and turn off update checks.
        'github_repo'          => 'MichelleFindlay/pillbox',
        // Compare this install's VERSION with the newest published GitHub release (admins see the result).
        'update_check'         => true,
        // Only needed if the repository is private: a fine-grained GitHub token with
        // read-only "Contents" access to the repository.
        'github_token'         => '',
        // Addresses without ".php" (e.g. /medications). On automatically when Apache's mod_rewrite is
        // available (see public/.htaccess); set to false to always use ".php".
        // 'pretty_urls'       => false,
        // Cache folder; must be writable by the web server.
        'storage_path'         => __DIR__ . '/../storage',
    ],

    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'pillbox',
        'user'     => 'pillbox',
        'password' => 'CHANGE-ME',
    ],

    // Nightscout (CGM data, Diabetes): addresses on private networks are blocked so people can't make
    // this server reach into its own network. Set true only if everyone using this Pillbox is trusted.
    'nightscout' => ['allow_private' => false],

    // Terms of use and privacy policy (terms.php, privacy.php). Generated from what Pillbox does and these
    // details; 'terms' / 'privacy' replace the generated text ("## " headings, "- " bullets, **bold**).
    'legal' => [
        'operator'     => '',   // who runs this Pillbox (the data controller), e.g. 'Jane Smith' or 'Example Ltd'
        'contact'      => '',   // e.g. 'privacy@example.com'
        'jurisdiction' => '',   // governing law; empty = 'England and Wales'
        'terms'        => '',
        'privacy'      => '',
    ],

    // People creating their own accounts: "Create an account" on the sign-in page, with a
    // username and password (if password sign-in is on) or any single sign-on button below.
    // Providers with 'auto_provision' => true still create accounts on first sign-in whatever this says.
    'signup' => [
        'enabled'          => false,  // false = only admins add people
        'require_approval' => true,   // new accounts can't sign in until an admin approves them (Admin page)
        'notify_admins'    => true,   // email admins about each new sign-up (needs the 'mail' settings)
    ],

    // Microsoft Entra ID single sign-on. See README.md for the app registration steps.
    'entra' => [
        'enabled'         => false,
        // Directory (tenant) ID. Use 'organizations' for multi-tenant and list tenants below.
        'tenant_id'       => '00000000-0000-0000-0000-000000000000',
        'client_id'       => '00000000-0000-0000-0000-000000000000',
        'client_secret'   => 'CHANGE-ME',
        // Must exactly match the redirect URI on the Entra app registration.
        'redirect_uri'    => 'https://example.com/auth_callback.php',
        // Extra tenant IDs allowed to sign in (multi-tenant setups only).
        'allowed_tenants' => [],
        // Create a Pillbox account automatically the first time someone signs in.
        'auto_provision'  => true,
        // App role that makes a user a Pillbox admin ('' to manage admins in Pillbox only).
        'admin_role'      => 'Pillbox.Admin',
        'button_label'    => 'Sign in with Microsoft',
        // Also sign the user out of Microsoft when they sign out of Pillbox.
        'single_logout'   => false,
    ],

    // Single sign-on with any OpenID Connect (OIDC) provider: Google, Okta, Auth0, Keycloak,
    // Authentik, Authelia, Zitadel, ... Add as many as you like; each gets its own button.
    // For every provider, register this redirect URI:  https://<your site>/oidc_callback.php
    // See README.md for step-by-step setup.
    'oidc' => [
        'providers' => [
            'google' => [
                'enabled'       => false,
                'name'          => 'Google',               // shown in messages and on the Account page
                'label'         => 'Sign in with Google',  // the login button
                // The provider's issuer URL; Pillbox reads <issuer>/.well-known/openid-configuration
                'issuer'        => 'https://accounts.google.com',
                'client_id'     => 'CHANGE-ME.apps.googleusercontent.com',
                'client_secret' => 'CHANGE-ME',
                'redirect_uri'  => 'https://example.com/oidc_callback.php',
                'scopes'        => 'openid email profile',
                // Create a Pillbox account the first time someone signs in. With Google, ALWAYS
                // combine this with allowed_email_domains, or anyone with a Google account gets in.
                'auto_provision' => false,
                // Only accept verified emails at these domains, e.g. ['example.com']. Empty = any.
                'allowed_email_domains' => [],
                // Make users admins from a claim in the ID token, e.g. 'groups' => ['pillbox-admins'].
                // Leave 'admin_claim' empty to manage admins in Pillbox only.
                'admin_claim'   => '',
                'admin_values'  => [],
                // Also sign out of the provider when signing out of Pillbox (if it supports it).
                'single_logout' => false,
            ],

            // More examples - copy, rename the key (lowercase letters, numbers, - or _) and fill in:
            //
            // 'okta' => [
            //     'enabled' => true, 'name' => 'Okta', 'label' => 'Sign in with Okta',
            //     'issuer' => 'https://YOUR-ORG.okta.com',            // or .../oauth2/default
            //     'client_id' => '...', 'client_secret' => '...',
            //     'redirect_uri' => 'https://example.com/oidc_callback.php',
            //     'auto_provision' => true, 'admin_claim' => 'groups', 'admin_values' => ['Pillbox Admins'],
            // ],
            // 'keycloak' => [
            //     'enabled' => true, 'name' => 'Keycloak', 'label' => 'Sign in with Keycloak',
            //     'issuer' => 'https://sso.example.com/realms/YOUR-REALM',
            //     'client_id' => 'pillbox', 'client_secret' => '...',
            //     'redirect_uri' => 'https://example.com/oidc_callback.php', 'auto_provision' => true,
            // ],
            // 'authentik' => [
            //     'enabled' => true, 'name' => 'Authentik', 'label' => 'Sign in with Authentik',
            //     'issuer' => 'https://auth.example.com/application/o/pillbox/',
            //     'client_id' => '...', 'client_secret' => '...',
            //     'redirect_uri' => 'https://example.com/oidc_callback.php', 'auto_provision' => true,
            // ],
        ],
    ],

    // NHS medicines dictionary (dm+d) search when adding medications.
    // Request a free system-to-system account at https://digital.nhs.uk/services/terminology-server
    'nhs_terminology' => [
        'enabled'       => false,
        'client_id'     => 'CHANGE-ME',
        'client_secret' => 'CHANGE-ME',
        'token_url'     => 'https://ontology.nhs.uk/authorisation/auth/realms/nhs-digital-terminology/protocol/openid-connect/token',
        'fhir_base'     => 'https://ontology.nhs.uk/production1/fhir',
        // SNOMED CT expression for what to search: the dm+d VMP (generic product) reference set.
        'ecl'           => '^999000561000001109',
    ],

    'doses' => [
        // A scheduled dose with nothing logged counts as missed this long after its time.
        'missed_after_minutes' => 120,
    ],

    // Email: daily summaries, appointment reminders and update notices. Sent by the
    // scheduled tasks (see 'cron' below), so those need setting up too.
    'mail' => [
        'enabled'   => false,
        'from'      => 'pillbox@example.com',
        'from_name' => 'Pillbox',
        // 'mail' uses PHP's mail() (fine on most shared hosting); 'smtp' talks to a mail server directly.
        'transport' => 'mail',
        'smtp' => [
            'host'     => 'smtp.example.com',
            'port'     => 587,
            'secure'   => 'tls',      // 'tls' (STARTTLS, usually port 587), 'ssl' (port 465) or '' (none)
            'username' => '',
            'password' => '',
        ],
    ],

    // Scheduled tasks (emails, update check, tidying up). Run every 5 to 15 minutes, either
    //   from cron:  */10 * * * * php /path/to/pillbox/bin/cron.php
    //   or a web cron service calling:  https://your-site/cron.php?token=<token below>
    'cron' => [
        // A long random secret for the web address; leave empty to allow only the command line.
        'token' => '',
    ],

    // Prescription prices (England), for the cost log and prepayment certificate calculator.
    // They usually change in April: check https://www.nhs.uk/nhs-services/prescriptions/ and update.
    'prescriptions' => [
        'charge'  => 9.90,     // per item
        'ppc_3'   => 32.05,    // 3-month prepayment certificate
        'ppc_12'  => 114.50,   // 12-month prepayment certificate
        'hrt_ppc' => 19.80,    // 12-month HRT prepayment certificate
        'prices_from' => 'May 2025',
    ],

    // Demo mode: a "Try the demo" button on the sign-in page. Each visitor gets their own copy of a
    // sample account; nothing they change is kept after they sign out (or after 'hours').
    // Account settings, email, sharing and sync links are switched off in the demo.
    'demo' => [
        'enabled' => false,
        'hours'   => 2,
    ],
];
