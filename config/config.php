<?php
/**
 * Pillbox configuration.
 *
 * Every value can be set with an environment variable (handy for Docker), or
 * overridden by creating config/config.local.php that returns an array with
 * the same shape as the one below. config.local.php is git-ignored.
 */

$env = static function (string $key, $default = null) {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};
$bool = static fn ($v): bool => in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);

$defaults = [
    'app' => [
        'name'                 => $env('APP_NAME', 'Pillbox'),
        // Set if the app is served from a sub-directory, e.g. '/pillbox'.
        'base_path'            => rtrim($env('APP_BASE_PATH', ''), '/'),
        'default_timezone'     => $env('APP_TIMEZONE', 'Europe/London'),
        'debug'                => $bool($env('APP_DEBUG', '0')),
        // Trust X-Forwarded-Proto / X-Forwarded-For from a reverse proxy.
        'trust_proxy'          => $bool($env('APP_TRUST_PROXY', '0')),
        'allow_local_login'    => $bool($env('APP_ALLOW_LOCAL_LOGIN', '1')),
        'session_idle_minutes' => (int) $env('APP_SESSION_IDLE_MINUTES', '480'),
        // Where the JWKS cache is written. Must be writable by the web server.
        'storage_path'         => $env('APP_STORAGE_PATH', dirname(__DIR__) . '/storage'),
    ],
    'db' => [
        'host'     => $env('DB_HOST', '127.0.0.1'),
        'port'     => (int) $env('DB_PORT', '3306'),
        'name'     => $env('DB_NAME', 'pillbox'),
        'user'     => $env('DB_USER', 'pillbox'),
        'password' => $env('DB_PASSWORD', ''),
    ],
    'entra' => [
        'enabled'        => $bool($env('ENTRA_ENABLED', '0')),
        // Directory (tenant) ID GUID. Use 'organizations' for multi-tenant apps
        // and list the permitted tenant GUIDs in allowed_tenants.
        'tenant_id'      => $env('ENTRA_TENANT_ID', 'organizations'),
        'client_id'      => $env('ENTRA_CLIENT_ID', ''),
        'client_secret'  => $env('ENTRA_CLIENT_SECRET', ''),
        // Must exactly match a redirect URI registered on the app registration,
        // e.g. https://pillbox.example.com/auth_callback.php
        'redirect_uri'   => $env('ENTRA_REDIRECT_URI', ''),
        // Extra tenant GUIDs allowed to sign in (comma separated in the env var).
        // '*' allows any tenant - only sensible together with auto_provision = false.
        'allowed_tenants' => array_values(array_filter(array_map('trim', explode(',', $env('ENTRA_ALLOWED_TENANTS', ''))))),
        // Create a Pillbox account automatically the first time someone signs in.
        'auto_provision' => $bool($env('ENTRA_AUTO_PROVISION', '1')),
        // If set, users holding this app role are admins (synced at every sign-in).
        'admin_role'     => $env('ENTRA_ADMIN_ROLE', 'Pillbox.Admin'),
        'button_label'   => $env('ENTRA_BUTTON_LABEL', 'Sign in with Microsoft'),
        // Also sign the user out of Microsoft when they sign out of Pillbox.
        'single_logout'  => $bool($env('ENTRA_SINGLE_LOGOUT', '0')),
    ],
    // NHS medicines dictionary (dm+d) lookup via the NHS England Terminology Server.
    // Needs a free system-to-system account: https://digital.nhs.uk/services/terminology-server
    'nhs_terminology' => [
        'enabled'       => $bool($env('NHS_TERMINOLOGY_ENABLED', '0')),
        'client_id'     => $env('NHS_TERMINOLOGY_CLIENT_ID', ''),
        'client_secret' => $env('NHS_TERMINOLOGY_CLIENT_SECRET', ''),
        'token_url'     => $env('NHS_TERMINOLOGY_TOKEN_URL', 'https://ontology.nhs.uk/authorisation/auth/realms/nhs-digital-terminology/protocol/openid-connect/token'),
        'fhir_base'     => $env('NHS_TERMINOLOGY_FHIR_BASE', 'https://ontology.nhs.uk/production1/fhir'),
        // SNOMED CT expression for what to search: the dm+d VMP (generic product) reference set.
        'ecl'           => $env('NHS_TERMINOLOGY_ECL', '^999000561000001109'),
    ],
    'doses' => [
        // A scheduled dose with nothing logged counts as missed this long after its time.
        'missed_after_minutes' => (int) $env('DOSE_MISSED_AFTER_MINUTES', '120'),
    ],
];

$local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

return array_replace_recursive($defaults, is_array($local) ? $local : []);
