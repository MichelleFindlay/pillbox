<?php
/*
 * Fingerprint / face unlock for Mind (WebAuthn). JSON in and out; used by app.js
 * ([data-webauthn-register] on the Mind privacy card, [data-webauthn-unlock] on mind_unlock.php).
 */
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json');
$user = current_user();
$input = json_decode((string) file_get_contents('php://input'), true) ?: [];
$reply = function (array $data, int $status = 200): never {
    http_response_code($status);
    exit(json_encode($data));
};
if (!$user || isset($user['_acting']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals(csrf_token(), (string) ($input['csrf'] ?? ''))) {
    $reply(['error' => 'Not allowed. Reload the page and try again.'], 403);
}
$uid = (int) $user['id'];

try {
    switch ($input['action'] ?? '') {
        case 'register_options':
            if (!$user['mind_pin_hash'] || !mind_unlocked($user) || $user['is_demo']) {
                $reply(['error' => 'Set a PIN for Mind first.'], 400);
            }
            $existing = q('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_COLUMN);
            $reply([
                'challenge' => webauthn_challenge('register'),
                'rp' => ['name' => (string) config('app.name', 'Pillbox'), 'id' => webauthn_rp_id()],
                'user' => ['id' => b64url_encode(hash('sha256', 'pillbox-user-' . $uid, true)), 'name' => (string) ($user['username'] ?: $user['email']), 'displayName' => $user['display_name']],
                'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
                'authenticatorSelection' => ['authenticatorAttachment' => 'platform', 'userVerification' => 'required', 'residentKey' => 'discouraged'],
                'attestation' => 'none',
                'timeout' => 60000,
                'excludeCredentials' => array_map(fn ($id) => ['type' => 'public-key', 'id' => $id], $existing),
            ]);
        case 'register_finish':
            if (!$user['mind_pin_hash'] || !mind_unlocked($user)) {
                $reply(['error' => 'Set a PIN for Mind first.'], 400);
            }
            [$credentialId, $pem, $count] = webauthn_register((string) ($input['clientDataJSON'] ?? ''), (string) ($input['attestationObject'] ?? ''));
            $label = trim(mb_substr((string) ($input['label'] ?? ''), 0, 100)) ?: 'This device';
            q('INSERT INTO webauthn_credentials (user_id, credential_id, public_key, sign_count, label) VALUES (?, ?, ?, ?, ?)', [$uid, $credentialId, $pem, $count, $label]);
            flash('success', 'Fingerprint or face unlock is set up on this device.');
            $reply(['ok' => true]);
        case 'unlock_options':
            $ids = q('SELECT credential_id FROM webauthn_credentials WHERE user_id = ?', [$uid])->fetchAll(PDO::FETCH_COLUMN);
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
            webauthn_verify($uid, (array) ($input['credential'] ?? []));
            unset($_SESSION['mind_pin_tries']);
            $_SESSION['mind_unlocked_at'] = time();
            $reply(['ok' => true, 'next' => url(safe_return((string) ($input['return'] ?? ''), 'mind.php'))]);
    }
} catch (RuntimeException $e) {
    $reply(['error' => $e->getMessage()], 400);
}
$reply(['error' => 'Unknown request.'], 400);
