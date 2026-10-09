<?php
/*
 * Copy this file to config.local.php and fill in your details.
 * Anything you leave out falls back to the defaults in config.php.
 */
return [
    'app' => [
        'name'             => 'Pillbox',
        // Web path the app lives under, without a trailing slash.
        //   https://example.com/            -> ''
        //   https://example.com/pillbox/    -> '/pillbox'
        'base_path'        => '',
        'default_timezone' => 'Europe/London',
        'debug'            => false,
        // true if a reverse proxy / load balancer terminates HTTPS in front of Apache.
        'trust_proxy'      => false,
        // false = Microsoft sign-in only.
        'allow_local_login' => true,
    ],

    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => 'pillbox',
        'user'     => 'pillbox',
        'password' => 'CHANGE-ME',
    ],

    'entra' => [
        'enabled'         => false,
        'tenant_id'       => '00000000-0000-0000-0000-000000000000',
        'client_id'       => '00000000-0000-0000-0000-000000000000',
        'client_secret'   => 'CHANGE-ME',
        // Must exactly match the redirect URI on the Entra app registration.
        'redirect_uri'    => 'https://example.com/pillbox/auth_callback.php',
        // Extra tenant GUIDs allowed to sign in (multi-tenant setups only).
        'allowed_tenants' => [],
        // Create accounts automatically on first Microsoft sign-in.
        'auto_provision'  => true,
        // App role that makes a user a Pillbox admin ('' to manage admins in Pillbox only).
        'admin_role'      => 'Pillbox.Admin',
        'single_logout'   => false,
    ],

    // Search the NHS medicines dictionary (dm+d) when adding medications.
    // Request a free system-to-system account at https://digital.nhs.uk/services/terminology-server
    'nhs_terminology' => [
        'enabled'       => false,
        'client_id'     => 'CHANGE-ME',
        'client_secret' => 'CHANGE-ME',
    ],
];
