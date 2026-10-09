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
        // Web path the app lives under, without a trailing slash.
        //   https://example.com/          -> ''
        //   https://example.com/pillbox/  -> '/pillbox'
        'base_path'            => '',
        'default_timezone'     => 'Europe/London',
        // Shows full error details in the browser. Only turn on while fixing a problem.
        'debug'                => false,
        // true if a reverse proxy / load balancer terminates HTTPS in front of Apache.
        'trust_proxy'          => false,
        // false = Microsoft sign-in only.
        'allow_local_login'    => true,
        // Sign people out after this many minutes without activity.
        'session_idle_minutes' => 480,
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
        // true = while Microsoft sign-in is enabled and configured, nobody can sign in with a
        // Pillbox username and password; Microsoft is the only way in. If Microsoft sign-in is
        // switched off (or not set up), password sign-in works again automatically.
        'disable_local_login_when_sso' => false,
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
];
