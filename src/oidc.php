<?php
declare(strict_types=1);

/*
 * Sign-in with any OpenID Connect provider: Google, Okta, Auth0, Keycloak, Authentik,
 * Authelia, Zitadel, ... configured under 'oidc' => ['providers' => [...]] in config.php.
 *
 * Authorization code flow with PKCE. Endpoints come from the provider's discovery
 * document, and ID tokens are verified against its published keys (JWKS).
 * Microsoft Entra ID has its own, richer integration in entra.php.
 */

const OIDC_ALGS = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];

/** Enabled, fully configured providers, keyed by their config key (e.g. 'google'). */
function oidc_providers(): array
{
    $providers = [];
    foreach ((array) config('oidc.providers', []) as $key => $p) {
        if (!is_array($p) || empty($p['enabled']) || !preg_match('/^[a-z0-9_-]{1,30}$/', (string) $key)
            || empty($p['issuer']) || empty($p['client_id']) || empty($p['redirect_uri'])) {
            continue;
        }
        $name = (string) ($p['name'] ?? ucfirst((string) $key));
        $providers[$key] = $p + [
            'name'                  => $name,
            'label'                 => "Sign in with $name",
            'client_secret'         => '',
            'scopes'                => 'openid email profile',
            'auto_provision'        => false,
            'allowed_email_domains' => [],
            'admin_claim'           => '',
            'admin_values'          => [],
            'single_logout'         => false,
        ];
    }
    return $providers;
}

function oidc_provider(string $key): ?array
{
    return oidc_providers()[$key] ?? null;
}

/** Is any single sign-on (Microsoft or OIDC) available? */
function sso_enabled(): bool
{
    return entra_enabled() || oidc_providers() !== [];
}

function oidc_discovery(array $p, bool $refresh = false): array
{
    $cache = storage_path('oidc_discovery_' . md5($p['issuer']) . '.json');
    if (!$refresh && is_file($cache) && filemtime($cache) > time() - 86400) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $d = http_json(rtrim($p['issuer'], '/') . '/.well-known/openid-configuration', null, [], $p['name']);
    foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
        if (empty($d[$k]) || !is_string($d[$k])) {
            throw new RuntimeException("{$p['name']} sign-in is not set up correctly (its discovery document has no $k).");
        }
    }
    if (rtrim($d['issuer'], '/') !== rtrim($p['issuer'], '/')) {
        throw new RuntimeException("{$p['name']} sign-in is not set up correctly: the issuer in config.php doesn't match the provider's ({$d['issuer']}).");
    }
    @file_put_contents($cache, json_encode($d), LOCK_EX);
    return $d;
}

/** Origins of the configured providers, so sign-in redirects after a form post aren't blocked by the CSP. */
function oidc_csp_origins(): array
{
    $origins = [];
    foreach (oidc_providers() as $p) {
        $urls = [$p['issuer']];
        $cache = storage_path('oidc_discovery_' . md5($p['issuer']) . '.json');
        if (is_file($cache)) {
            $d = json_decode((string) file_get_contents($cache), true) ?: [];
            $urls[] = $d['authorization_endpoint'] ?? '';
            $urls[] = $d['end_session_endpoint'] ?? '';
        }
        foreach ($urls as $u) {
            $parts = parse_url((string) $u);
            if (!empty($parts['scheme']) && !empty($parts['host']) && in_array($parts['scheme'], ['https', 'http'], true)) {
                $origins[] = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
    }
    return array_values(array_unique($origins));
}

/** Start sign-in (or, with $linkUserId, linking an account) with provider $key. */
function oidc_begin(string $key, ?int $linkUserId = null): never
{
    $p = oidc_provider($key) ?? throw new RuntimeException('Unknown sign-in provider.');
    $d = oidc_discovery($p);
    $state = b64url_encode(random_bytes(32));
    $nonce = b64url_encode(random_bytes(32));
    $verifier = b64url_encode(random_bytes(48));
    $_SESSION['oidc'] = ['provider' => $key, 'state' => $state, 'nonce' => $nonce, 'verifier' => $verifier,
        'ts' => time(), 'link_uid' => $linkUserId];

    $params = [
        'client_id'             => $p['client_id'],
        'response_type'         => 'code',
        'redirect_uri'          => $p['redirect_uri'],
        'scope'                 => $p['scopes'],
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => b64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ];
    if ($linkUserId !== null) {
        $params['prompt'] = 'select_account';
    }
    $sep = str_contains($d['authorization_endpoint'], '?') ? '&' : '?';
    header('Location: ' . $d['authorization_endpoint'] . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}

/**
 * Handle the redirect back from a provider.
 * @return array{provider: string, claims: array, link_uid: ?int, id_token: string}
 * @throws RuntimeException
 */
function oidc_handle_callback(array $query): array
{
    $pending = $_SESSION['oidc'] ?? null;
    unset($_SESSION['oidc']);
    if (!is_array($pending) || time() - $pending['ts'] > 600) {
        throw new RuntimeException('The sign-in attempt expired. Please try again.');
    }
    $p = oidc_provider((string) $pending['provider']) ?? throw new RuntimeException('That sign-in provider is no longer available.');
    if (isset($query['error'])) {
        throw new RuntimeException("{$p['name']} sign-in was not completed: " . ($query['error_description'] ?? $query['error']));
    }
    if (!hash_equals($pending['state'], (string) ($query['state'] ?? ''))) {
        throw new RuntimeException('The sign-in response did not match this browser session. Please try again.');
    }
    $code = (string) ($query['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException("{$p['name']} did not return an authorisation code.");
    }

    $d = oidc_discovery($p);
    $form = [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => $p['redirect_uri'],
        'code_verifier' => $pending['verifier'],
        'client_id'     => $p['client_id'],
    ];
    $headers = [];
    $methods = $d['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
    if ($p['client_secret'] !== '') {
        if (in_array('client_secret_post', (array) $methods, true)) {
            $form['client_secret'] = $p['client_secret'];
        } else {
            $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode($p['client_id']) . ':' . rawurlencode($p['client_secret']));
        }
    }
    $tokens = http_json($d['token_endpoint'], $form, $headers, $p['name']);
    if (empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
        throw new RuntimeException("{$p['name']} did not return an ID token. Check that the 'openid' scope is allowed.");
    }
    $claims = oidc_validate_id_token($p, $d, $tokens['id_token'], $pending['nonce']);

    // Some providers leave email out of the ID token; ask the userinfo endpoint.
    if (empty($claims['email']) && !empty($d['userinfo_endpoint']) && !empty($tokens['access_token'])) {
        try {
            $info = http_json($d['userinfo_endpoint'], null, ['Authorization: Bearer ' . $tokens['access_token']], $p['name']);
            if (($info['sub'] ?? null) === $claims['sub']) {
                $claims += array_intersect_key($info, array_flip(['email', 'email_verified', 'name', 'preferred_username']));
            }
        } catch (RuntimeException $e) {
            log_line("{$p['name']} userinfo failed: " . $e->getMessage());
        }
    }

    return ['provider' => (string) $pending['provider'], 'claims' => $claims,
        'link_uid' => $pending['link_uid'] !== null ? (int) $pending['link_uid'] : null, 'id_token' => $tokens['id_token']];
}

function oidc_validate_id_token(array $p, array $d, string $jwt, string $nonce): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        throw new RuntimeException('Malformed ID token.');
    }
    [$header64, $payload64, $signature64] = $parts;
    $header = json_decode(b64url_decode($header64), true);
    $claims = json_decode(b64url_decode($payload64), true);
    if (!is_array($header) || !is_array($claims)) {
        throw new RuntimeException('Malformed ID token.');
    }
    $alg = (string) ($header['alg'] ?? '');
    if (!isset(OIDC_ALGS[$alg])) {
        throw new RuntimeException("{$p['name']} signed its token with $alg, which Pillbox doesn't accept. Configure the client to use RS256.");
    }

    $key = oidc_signing_key($p, $d, isset($header['kid']) ? (string) $header['kid'] : null);
    if (openssl_verify("$header64.$payload64", b64url_decode($signature64), $key, OIDC_ALGS[$alg]) !== 1) {
        throw new RuntimeException('The ID token signature is invalid.');
    }

    $now = time();
    $leeway = 300;
    $aud = $claims['aud'] ?? null;
    $audiences = is_array($aud) ? $aud : [$aud];
    $checks = [
        'issuer'     => ($claims['iss'] ?? '') === $d['issuer'],
        'audience'   => in_array($p['client_id'], $audiences, true)
            && (count($audiences) === 1 || ($claims['azp'] ?? '') === $p['client_id']),
        'expiry'     => (int) ($claims['exp'] ?? 0) > $now - $leeway,
        'not-before' => (int) ($claims['nbf'] ?? 0) < $now + $leeway,
        'issued-at'  => (int) ($claims['iat'] ?? 0) < $now + $leeway,
        'nonce'      => hash_equals($nonce, (string) ($claims['nonce'] ?? '')),
        'subject'    => isset($claims['sub']) && is_string($claims['sub']) && $claims['sub'] !== '',
    ];
    foreach ($checks as $name => $ok) {
        if (!$ok) {
            throw new RuntimeException("The ID token failed the $name check.");
        }
    }
    return $claims;
}

/** @return OpenSSLAsymmetricKey */
function oidc_signing_key(array $p, array $d, ?string $kid)
{
    foreach ([false, true] as $refresh) {
        $cache = storage_path('oidc_jwks_' . md5($d['jwks_uri']) . '.json');
        $jwks = null;
        if (!$refresh && is_file($cache) && filemtime($cache) > time() - 86400) {
            $jwks = json_decode((string) file_get_contents($cache), true);
        }
        if (!is_array($jwks)) {
            $jwks = http_json($d['jwks_uri'], null, [], $p['name']);
            @file_put_contents($cache, json_encode($jwks), LOCK_EX);
        }
        $candidates = array_values(array_filter($jwks['keys'] ?? [], fn ($k) => is_array($k) && ($k['kty'] ?? '') === 'RSA'
            && ($k['use'] ?? 'sig') === 'sig' && ($kid === null || ($k['kid'] ?? null) === $kid)));
        if (count($candidates) === 1 || ($kid !== null && $candidates)) {
            $key = openssl_pkey_get_public(jwk_rsa_to_pem($candidates[0]['n'] ?? '', $candidates[0]['e'] ?? ''));
            if ($key !== false) {
                return $key;
            }
        }
    }
    throw new RuntimeException("Could not find the {$p['name']} key that signed this token.");
}

/** Build a PEM public key from an RSA JSON Web Key's modulus and exponent. */
function jwk_rsa_to_pem(string $n64, string $e64): string
{
    $len = static function (int $l): string {
        if ($l < 128) {
            return chr($l);
        }
        $bytes = ltrim(pack('N', $l), "\x00");
        return chr(0x80 | strlen($bytes)) . $bytes;
    };
    $int = static function (string $bytes) use ($len): string {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $len(strlen($bytes)) . $bytes;
    };
    $rsaKey = $int(b64url_decode($n64)) . $int(b64url_decode($e64));
    $rsaKey = "\x30" . $len(strlen($rsaKey)) . $rsaKey;
    $bitString = "\x03" . $len(strlen($rsaKey) + 1) . "\x00" . $rsaKey;
    $algorithm = hex2bin('300d06092a864886f70d0101010500'); // rsaEncryption OID, NULL params
    $spki = "\x30" . $len(strlen($algorithm . $bitString)) . $algorithm . $bitString;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function oidc_email(array $claims): ?string
{
    $email = mb_strtolower(trim((string) ($claims['email'] ?? '')));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

function oidc_email_verified(array $claims): bool
{
    $v = $claims['email_verified'] ?? false;
    return $v === true || $v === 'true' || $v === 1;
}

/** Enforce 'allowed_email_domains' (e.g. only @example.com Google accounts). */
function oidc_check_domain(array $p, array $claims): void
{
    $domains = array_map('strtolower', (array) $p['allowed_email_domains']);
    if (!$domains) {
        return;
    }
    $email = oidc_email($claims);
    $domain = $email ? substr(strrchr($email, '@'), 1) : '';
    if (!oidc_email_verified($claims) || !in_array($domain, $domains, true)) {
        throw new RuntimeException("This Pillbox only accepts {$p['name']} accounts from " . implode(', ', $domains) . '.');
    }
}

/**
 * Map a provider sign-in to a Pillbox user: an existing linked identity, an account an
 * admin pre-registered by (verified) email, or a new account if auto-provisioning is on.
 * @throws RuntimeException
 */
function oidc_find_or_create_user(string $key, array $claims): array
{
    $p = oidc_provider($key) ?? throw new RuntimeException('Unknown sign-in provider.');
    oidc_check_domain($p, $claims);
    $sub = (string) $claims['sub'];
    $email = oidc_email($claims);
    $name = mb_substr((string) ($claims['name'] ?? $claims['preferred_username'] ?? $email ?? "{$p['name']} user"), 0, 150);

    $created = false;
    $user = transaction(function () use ($key, $p, $sub, $email, $name, $claims, &$created) {
        $identity = q('SELECT * FROM user_identities WHERE provider = ? AND subject = ? FOR UPDATE', [$key, $sub])->fetch();
        if (!$identity && $email !== null && oidc_email_verified($claims)) {
            $identity = q('SELECT * FROM user_identities WHERE provider = ? AND subject IS NULL AND email = ? FOR UPDATE', [$key, $email])->fetch();
            if ($identity) {
                q('UPDATE user_identities SET subject = ? WHERE id = ?', [$sub, $identity['id']]);
            }
        }

        if (!$identity) {
            // A trusted directory (auto_provision) gets accounts straight away; otherwise only while sign-ups are open.
            // The very first account becomes the administrator: only for the person who entered the setup code.
            $first = user_count() === 0;
            if ($first && !setup_unlocked()) {
                throw new RuntimeException('This Pillbox has not been set up yet. Whoever is setting it up should go to the setup page first.');
            }
            if (!$first && !$p['auto_provision'] && !signups_open()) {
                throw new RuntimeException("Your {$p['name']} account hasn't been given access to Pillbox. Ask an administrator to add you.");
            }
            $created = !$first && !$p['auto_provision'];
            if ($first) {
                setup_finished();
            }
            q("INSERT INTO users (display_name, email, auth_provider, timezone, is_admin) VALUES (?, ?, 'oidc', ?, ?)",
                [$name, $email, config('app.default_timezone'), $first ? 1 : 0]);
            $userId = (int) db()->lastInsertId();
            q('INSERT INTO user_identities (user_id, provider, subject, email) VALUES (?, ?, ?, ?)', [$userId, $key, $sub, $email]);
            $identity = ['user_id' => $userId];
        }

        $user = q('SELECT * FROM users WHERE id = ?', [$identity['user_id']])->fetch();
        if (!$user || !$user['is_active']) {
            throw new RuntimeException('Your Pillbox account has been disabled.');
        }
        q('UPDATE user_identities SET email = COALESCE(?, email), last_used_at = UTC_TIMESTAMP() WHERE provider = ? AND subject = ?', [$email, $key, $sub]);

        // Accounts that only use this provider follow its profile (and, if configured, its admin group).
        if ($user['auth_provider'] === 'oidc') {
            $isAdmin = (int) $user['is_admin'];
            $claim = (string) $p['admin_claim'];
            if ($claim !== '' && array_key_exists($claim, $claims)) {
                $isAdmin = array_intersect((array) $claims[$claim], (array) $p['admin_values']) ? 1 : 0;
            }
            q('UPDATE users SET display_name = ?, email = COALESCE(?, email), is_admin = ? WHERE id = ?', [$name, $email, $isAdmin, $user['id']]);
            $user = q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
        }
        return $user;
    });
    // A sign-up (not a trusted directory): maybe waiting for approval; tell the admins.
    return $created ? signup_created($user, 'oidc:' . $key) : $user;
}

/**
 * Link a provider account to an existing Pillbox user (started from the Account page).
 * An empty account auto-created earlier for the same provider account is removed.
 * @throws RuntimeException
 */
function oidc_link_account(int $userId, string $key, array $claims): void
{
    $p = oidc_provider($key) ?? throw new RuntimeException('Unknown sign-in provider.');
    oidc_check_domain($p, $claims);
    $sub = (string) $claims['sub'];
    $email = oidc_email($claims);

    transaction(function () use ($userId, $key, $p, $sub, $email) {
        $existing = q('SELECT * FROM user_identities WHERE provider = ? AND subject = ? FOR UPDATE', [$key, $sub])->fetch();
        if ($existing && (int) $existing['user_id'] !== $userId) {
            $other = q('SELECT * FROM users WHERE id = ?', [$existing['user_id']])->fetch();
            $hasData = (int) q(
                'SELECT (SELECT COUNT(*) FROM medications WHERE user_id = ?) + (SELECT COUNT(*) FROM health_readings WHERE user_id = ?)
                      + (SELECT COUNT(*) FROM custom_metrics WHERE user_id = ?) + (SELECT COUNT(*) FROM user_identities WHERE user_id = ?) - 1',
                [$other['id'], $other['id'], $other['id'], $other['id']]
            )->fetchColumn() > 0;
            if ($other['auth_provider'] !== 'oidc' || $other['is_admin'] || $other['password_hash'] !== null || $hasData) {
                throw new RuntimeException("That {$p['name']} account is already used by another Pillbox account ({$other['display_name']}). "
                    . 'Ask an administrator to delete or unlink that account first.');
            }
            q('DELETE FROM users WHERE id = ?', [$other['id']]);
        }
        // One account per provider per user: replace any earlier link.
        q('DELETE FROM user_identities WHERE user_id = ? AND provider = ?', [$userId, $key]);
        q('INSERT INTO user_identities (user_id, provider, subject, email, last_used_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())', [$userId, $key, $sub, $email]);
    });
}

/** The user's linked (or pre-registered) provider identities, keyed by provider. */
function user_identities(int $userId): array
{
    $rows = [];
    foreach (q('SELECT * FROM user_identities WHERE user_id = ? ORDER BY provider', [$userId]) as $row) {
        $rows[$row['provider']] = $row;
    }
    return $rows;
}

/** How many ways can this user sign in? Used to stop anyone unlinking their last one. */
function sign_in_method_count(array $user): int
{
    $count = ($user['password_hash'] !== null && local_login_enabled()) ? 1 : 0;
    $count += ($user['entra_oid'] !== null && entra_enabled()) ? 1 : 0;
    foreach (user_identities((int) $user['id']) as $provider => $identity) {
        $count += ($identity['subject'] !== null && oidc_provider($provider)) ? 1 : 0;
    }
    return $count;
}

function oidc_logout_url(string $key): ?string
{
    $p = oidc_provider($key);
    if (!$p || !$p['single_logout']) {
        return null;
    }
    $d = oidc_discovery($p);
    if (empty($d['end_session_endpoint'])) {
        return null;
    }
    $params = ['client_id' => $p['client_id'], 'post_logout_redirect_uri' => preg_replace('#[^/]*$#', 'login.php', $p['redirect_uri'])];
    if (!empty($_SESSION['oidc_id_token'])) {
        $params['id_token_hint'] = $_SESSION['oidc_id_token'];
    }
    $sep = str_contains($d['end_session_endpoint'], '?') ? '&' : '?';
    return $d['end_session_endpoint'] . $sep . http_build_query($params);
}
