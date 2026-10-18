<?php
/*
 * WebAuthn endpoints. JSON in and out; used by app.js:
 * - [data-webauthn-register] / [data-webauthn-unlock]: a passkey that unlocks Mind (the Mind
 *   privacy card, mind_unlock.php). Platform authenticators only.
 * - [data-webauthn-register-key] / [data-webauthn-login]: a security key (e.g. a YubiKey) as a
 *   second step after the account password (Account > Password & security, login_2fa.php).
 */
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$reply = function (array $data, int $status = 200): never {
    http_response_code($status);
    exit(json_encode($data));
};
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string) ($input['csrf'] ?? ''))) {
    $reply(['error' => 'Not allowed. Reload the page and try again.'], 403);
}
$action = (string) ($input['action'] ?? '');

// Signing in: the password was already correct, but this account also needs its security key
// before the session is granted. There's no signed-in user yet, so this is scoped by the
// pending sign-in that login.php left in the session instead.
if ($action === 'login_options' || $action === 'login_finish') {
    $pending = $_SESSION['pending_2fa'] ?? null;
    if (!$pending || $pending['at'] < time() - 300) {
        unset($_SESSION['pending_2fa']);
        $reply(['error' => 'That took too long. Sign in again.'], 400);
    }
    $uid = (int) $pending['uid'];
    try {
        if ($action === 'login_options') {
            $ids = q("SELECT credential_id FROM webauthn_credentials WHERE user_id = ? AND purpose = 'login'", [$uid])->fetchAll(PDO::FETCH_COLUMN);
            $reply([
                'challenge' => webauthn_challenge('login'),
                'rpId' => webauthn_rp_id(),
                'allowCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $ids),
                'userVerification' => 'discouraged',
                'timeout' => 60000,
            ]);
        }
        webauthn_verify($uid, (array) ($input['credential'] ?? []), 'login');
        $signingIn = q('SELECT * FROM users WHERE id = ? AND is_active = 1 AND pending_approval = 0', [$uid])->fetch();
        if (!$signingIn) {
            throw new RuntimeException('This account is no longer available.');
        }
        unset($_SESSION['pending_2fa']);
        $_SESSION['remember_device'] = !empty($pending['remember']);
        login_user($signingIn);
        $next = url(safe_return($_SESSION['after_login'] ?? null));
        unset($_SESSION['after_login']);
        $reply(['ok' => true, 'next' => $next]);
    } catch (RuntimeException $e) {
        $reply(['error' => $e->getMessage()], 400);
    }
}

$user = current_user();
if (!$user || isset($user['_acting'])) {
    $reply(['error' => 'Not allowed. Reload the page and try again.'], 403);
}
$uid = (int) $user['id'];

try {
    switch ($action) {
        case 'register_options':
            $purpose = ($input['purpose'] ?? '') === 'login' ? 'login' : 'mind';
            if ($purpose === 'mind' && (!$user['mind_pin_hash'] || !mind_unlocked($user) || $user['is_demo'])) {
                $reply(['error' => 'Set a PIN for Mind first.'], 400);
            }
            if ($purpose === 'login') {
                if (!can_add_login_2fa($user)) {
                    $reply(['error' => 'Add a password, or link Microsoft or another sign-in, first.'], 400);
                }
                if (!step_up_confirmed() && !verify_step_up_password($user, (string) ($input['password'] ?? ''))) {
                    $reply(['error' => "That's not your current password, or you've tried too many times."], 400);
                }
            }
            $existing = q('SELECT credential_id FROM webauthn_credentials WHERE user_id = ? AND purpose = ?', [$uid, $purpose])->fetchAll(PDO::FETCH_COLUMN);
            $reply([
                'challenge' => webauthn_challenge('register'),
                'rp' => ['name' => (string) config('app.name', 'Pillbox'), 'id' => webauthn_rp_id()],
                'user' => ['id' => b64url_encode(hash('sha256', 'pillbox-user-' . $uid, true)), 'name' => (string) ($user['username'] ?: $user['email']), 'displayName' => $user['display_name']],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
                'authenticatorSelection' => $purpose === 'mind'
                    ? ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'discouraged']
                    : ['userVerification' => 'discouraged', 'residentKey' => 'discouraged'],
                'attestation' => 'none',
                'timeout' => 60000,
                'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $existing),
            ]);
        case 'register_finish':
            $purpose = ($input['purpose'] ?? '') === 'login' ? 'login' : 'mind';
            if ($purpose === 'mind' && (!$user['mind_pin_hash'] || !mind_unlocked($user))) {
                $reply(['error' => 'Set a PIN for Mind first.'], 400);
            }
            if ($purpose === 'login') {
                if (!can_add_login_2fa($user)) {
                    $reply(['error' => 'Add a password, or link Microsoft or another sign-in, first.'], 400);
                }
                if (!step_up_confirmed() && !verify_step_up_password($user, (string) ($input['password'] ?? ''))) {
                    $reply(['error' => "That's not your current password, or you've tried too many times."], 400);
                }
            }
            [$credentialId, $pem, $count] = webauthn_register((string) ($input['clientDataJSON'] ?? ''), (string) ($input['attestationObject'] ?? ''), $purpose);
            $label = trim(mb_substr((string) ($input['label'] ?? ''), 0, 100)) ?: 'This device';
            q('INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, label, purpose) VALUES (?, ?, ?, ?, ?, ?)', [$uid, $credentialId, $pem, $count, $label, $purpose]);
            flash('success', $purpose === 'mind' ? 'Passkey added. You can now unlock Mind with it on this device.' : 'Security key added. It will be asked for after your password when you sign in.');
            $reply(['ok' => true]);
        case 'unlock_options':
            $ids = q("SELECT credential_id FROM webauthn_credentials WHERE user_id = ? AND purpose = 'mind'", [$uid])->fetchAll(PDO::FETCH_COLUMN);
            if (!$ids) {
                $reply(['error' => 'No device is set up. Use your PIN.'], 400);
            }
            $reply([
                'challenge' => webauthn_challenge('unlock'),
                'rpId' => webauthn_rp_id(),
                'allowCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $ids),
                'userVerification' => 'required',
                'timeout' => 60000,
            ]);
        case 'unlock_finish':
            webauthn_verify($uid, (array) ($input['credential'] ?? []), 'mind');
            unset($_SESSION['mind_pin_tries']);
            $_SESSION['mind_unlocked_at'] = time();
            $reply(['ok' => true, 'next' => url(safe_return((string) ($input['return'] ?? ''), 'mind.php'))]);
    }
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 400);
}
$reply(['error' => 'Unknown request.'], 400);
