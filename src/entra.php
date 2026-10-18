<?php
declare(strict_types=1);

/*
 * Microsoft Entra ID sign-in (OpenID Connect authorization code flow with PKCE).
 * No external libraries: the ID token's RS256 signature is checked against
 * Microsoft's published signing certificates.
 */

function entra_enabled(): bool
{
    return config('entra.enabled') && config('entra.client_id') !== '' && config('entra.client_secret') !== ''
        && config('entra.redirect_uri') !== '';
}

function entra_authority(): string
{
    return 'https://login.microsoftonline.com/' . rawurlencode((string) config('entra.tenant_id', 'organizations'));
}

function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    return $decoded === false ? '' : $decoded;
}

function entra_begin_login(): never
{
    $state = b64url_encode(random_bytes(32));
    $nonce = b64url_encode(random_bytes(32));
    $verifier = b64url_encode(random_bytes(48));
    $_SESSION['entra'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'ts' => time()];

    $params = [
        'client_id'             => config('entra.client_id'),
        'response_type'         => 'code',
        'redirect_uri'          => config('entra.redirect_uri'),
        // 'query' keeps the callback a top-level GET so the SameSite=Lax session cookie is sent.
        'response_mode'         => 'query',
        'scope'                 => 'openid profile email',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => b64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];
    header('Location: ' . entra_authority() . '/oauth2/v2.0/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}

/**
 * Handle the redirect back from Microsoft. Returns validated ID token claims.
 * @throws RuntimeException with a user-presentable message
 */
function entra_handle_callback(array $query): array
{
    $pending = $_SESSION['entra'] ?? null;
    unset($_SESSION['entra']);

    if (isset($query['error'])) {
        throw new RuntimeException('Microsoft sign-in was not completed: ' . ($query['error_description'] ?? $query['error']));
    }
    if (!is_array($pending) || time() - $pending['ts'] > 600) {
        throw new RuntimeException('The sign-in attempt expired. Please try again.');
    }
    if (!hash_equals($pending['state'], (string) ($query['state'] ?? ''))) {
        throw new RuntimeException('The sign-in response did not match this browser session. Please try again.');
    }
    $code = (string) ($query['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Microsoft did not return an authorisation code.');
    }

    $tokens = entra_http(entra_authority() . '/oauth2/v2.0/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => config('entra.client_id'),
        'client_secret' => config('entra.client_secret'),
        'code'          => $code,
        'redirect_uri'  => config('entra.redirect_uri'),
        'code_verifier' => $pending['verifier'],
        'scope'         => 'openid profile email',
    ]);
    if (empty($tokens['id_token']) || !is_string($tokens['id_token'])) {
        throw new RuntimeException('Microsoft did not return an ID token.');
    }
    return entra_validate_id_token($tokens['id_token'], $pending['nonce']);
}

function entra_http(string $url, ?array $form = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($body === false) {
        throw new RuntimeException('Could not contact Microsoft: ' . curl_error($ch));
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Unexpected response from Microsoft (HTTP $status).");
    }
    if ($status >= 400) {
        log_line('Entra error: ' . $body);
        throw new RuntimeException('Microsoft rejected the sign-in: ' . ($data['error_description'] ?? $data['error'] ?? "HTTP $status"));
    }
    return $data;
}

function entra_jwks(bool $refresh): array
{
    $cache = storage_path('entra_jwks_' . md5(config('entra.tenant_id') . config('entra.client_id')) . '.json');
    if (!$refresh && is_file($cache) && filemtime($cache) > time() - 86400) {
        $data = json_decode((string) file_get_contents($cache), true);
        if (is_array($data)) {
            return $data;
        }
    }
    $data = entra_http(entra_authority() . '/discovery/v2.0/keys?appid=' . rawurlencode(config('entra.client_id')));
    @file_put_contents($cache, json_encode($data), LOCK_EX);
    return $data;
}

/** @return OpenSSLAsymmetricKey */
function entra_signing_key(string $kid)
{
    // Try the cache first, then refetch once in case Microsoft has rotated keys.
    foreach ([false, true] as $refresh) {
        foreach (entra_jwks($refresh)['keys'] ?? [] as $jwk) {
            if (($jwk['kid'] ?? null) === $kid && !empty($jwk['x5c'][0])) {
                $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($jwk['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
                $key = openssl_pkey_get_public($pem);
                if ($key !== false) {
                    return $key;
                }
            }
        }
    }
    throw new RuntimeException('Could not find the Microsoft key that signed this token.');
}

function entra_validate_id_token(string $jwt, string $nonce): array
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
    if (($header['alg'] ?? '') !== 'RS256') {
        throw new RuntimeException('Unsupported token signature algorithm.');
    }

    $key = entra_signing_key((string) ($header['kid'] ?? ''));
    if (openssl_verify("$header64.$payload64", b64url_decode($signature64), $key, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('The ID token signature is invalid.');
    }

    $now = time();
    $leeway = 300;
    $tid = (string) ($claims['tid'] ?? '');
    $checks = [
        'audience' => ($claims['aud'] ?? '') === config('entra.client_id'),
        'expiry'   => (int) ($claims['exp'] ?? 0) > $now - $leeway,
        'not-before' => (int) ($claims['nbf'] ?? 0) < $now + $leeway,
        'nonce'    => hash_equals($nonce, (string) ($claims['nonce'] ?? '')),
        'issuer'   => ($claims['iss'] ?? '') === "https://login.microsoftonline.com/$tid/v2.0",
        'subject'  => !empty($claims['oid']),
    ];
    foreach ($checks as $name => $ok) {
        if (!$ok) {
            throw new RuntimeException("The ID token failed the $name check.");
        }
    }
    if (!entra_tenant_allowed($tid)) {
        throw new RuntimeException('Your organisation is not permitted to use this Pillbox.');
    }
    return $claims;
}

function entra_tenant_allowed(string $tid): bool
{
    $allowed = (array) config('entra.allowed_tenants', []);
    $configured = (string) config('entra.tenant_id');
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $configured)) {
        $allowed[] = $configured;
    }
    foreach ($allowed as $tenant) {
        if ($tenant === '*' || strcasecmp($tenant, $tid) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Map validated claims to a Pillbox user, linking a pre-registered account or
 * creating one if auto-provisioning is on.
 * @throws RuntimeException
 */
function entra_find_or_create_user(array $claims): array
{
    $oid = strtolower((string) $claims['oid']);
    $tid = strtolower((string) $claims['tid']);
    $email = mb_strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? '')) ?: null;
    $name = (string) ($claims['name'] ?? $email ?? 'Microsoft user');

    $user = q('SELECT * FROM users WHERE entra_tid = ? AND entra_oid = ?', [$tid, $oid])->fetch();

    if (!$user && $email !== null) {
        // An admin may have pre-registered this person by email.
        $user = q(
            "SELECT * FROM users WHERE auth_provider = 'entra' AND entra_oid IS NULL AND email = ?",
            [$email]
        )->fetch();
        if ($user) {
            q('UPDATE users SET entra_oid = ?, entra_tid = ? WHERE id = ?', [$oid, $tid, $user['id']]);
        }
    }

    $signup = false;
    if (!$user) {
        // A trusted tenant (auto_provision) gets accounts straight away; otherwise only while sign-ups are open.
        $signup = !config('entra.auto_provision');
        // The very first account becomes the administrator: only for the person who entered the setup code.
        $first = user_count() === 0;
        if ($first && !setup_unlocked()) {
            throw new RuntimeException('This Pillbox has not been set up yet. Whoever is setting it up should go to the setup page first.');
        }
        if ($first) {
            $signup = false;
            setup_finished();
        }
        if ($signup && !signups_open()) {
            throw new RuntimeException('Your Microsoft account has not been given access to Pillbox. Ask an administrator to add you.');
        }
        q(
            "INSERT INTO users (display_name, email, auth_provider, entra_oid, entra_tid, timezone, is_admin)
             VALUES (?, ?, 'entra', ?, ?, ?, ?)",
            [mb_substr($name, 0, 150), $email, $oid, $tid, config('app.default_timezone'), $first ? 1 : 0]
        );
        $user = q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
        if ($signup) {
            $user = signup_created($user, 'entra');
        }
    }

    if (!$user['is_active']) {
        throw new RuntimeException('Your Pillbox account has been disabled.');
    }

    // A local account with a linked Microsoft account keeps its own name and admin
    // status; those are managed in Pillbox, not synced from Microsoft.
    if ($user['auth_provider'] !== 'entra') {
        return $user;
    }

    $isAdmin = (int) $user['is_admin'];
    $role = (string) config('entra.admin_role', '');
    if ($role !== '' && isset($claims['roles']) && is_array($claims['roles'])) {
        $isAdmin = in_array($role, $claims['roles'], true) ? 1 : 0;
    }
    q('UPDATE users SET display_name = ?, email = ?, is_admin = ? WHERE id = ?', [mb_substr($name, 0, 150), $email, $isAdmin, $user['id']]);

    return q('SELECT * FROM users WHERE id = ?', [$user['id']])->fetch();
}

/**
 * Attach a Microsoft account to an existing (local) Pillbox user, so they can sign in
 * either way. If that Microsoft account was already auto-created as a separate, empty
 * Pillbox account, the empty one is removed.
 * @throws RuntimeException
 */
function entra_link_account(int $userId, array $claims): void
{
    $oid = strtolower((string) $claims['oid']);
    $tid = strtolower((string) $claims['tid']);

    transaction(function () use ($userId, $oid, $tid, $claims) {
        $other = q('SELECT * FROM users WHERE entra_tid = ? AND entra_oid = ? FOR UPDATE', [$tid, $oid])->fetch();
        if ($other && (int) $other['id'] !== $userId) {
            $hasData = (int) q(
                'SELECT (SELECT COUNT(*) FROM medications WHERE user_id = ?)
                      + (SELECT COUNT(*) FROM health_readings WHERE user_id = ?)
                      + (SELECT COUNT(*) FROM custom_metrics WHERE user_id = ?)',
                [$other['id'], $other['id'], $other['id']]
            )->fetchColumn() > 0;
            if ($other['auth_provider'] !== 'entra' || $other['is_admin'] || $hasData) {
                throw new RuntimeException('That Microsoft account is already used by another Pillbox account ('
                    . $other['display_name'] . '). Ask an administrator to delete or unlink that account first.');
            }
            q('DELETE FROM users WHERE id = ?', [$other['id']]);
        }
        $email = mb_strtolower((string) ($claims['email'] ?? $claims['preferred_username'] ?? '')) ?: null;
        q('UPDATE users SET entra_oid = ?, entra_tid = ?, email = COALESCE(email, ?) WHERE id = ?', [$oid, $tid, $email, $userId]);
    });
}

function entra_logout_url(): string
{
    $base = (string) config('entra.redirect_uri');
    $loginUrl = preg_replace('#[^/]*$#', 'login.php', $base);
    return entra_authority() . '/oauth2/v2.0/logout?post_logout_redirect_uri=' . rawurlencode($loginUrl);
}
