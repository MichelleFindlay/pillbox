<?php
/*
 * Push notifications for this device: turn on (save the browser's subscription), turn
 * off, or send a test. JSON in and out; used by app.js ([data-push] on the Account page).
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
if ($user['is_demo'] || !push_supported()) {
    $reply(['error' => $user['is_demo'] ? 'Notifications are switched off in the demo.' : "This server can't send notifications (PHP's OpenSSL or curl is missing)."], 400);
}
$uid = (int) $user['id'];
$sub = (array) ($input['subscription'] ?? []);
$endpoint = (string) ($sub['endpoint'] ?? '');

switch ($input['action'] ?? '') {
    case 'key':
        $reply(['key' => vapid_keys()['public']]);
    case 'subscribe':
        $p256dh = (string) ($sub['keys']['p256dh'] ?? '');
        $auth = (string) ($sub['keys']['auth'] ?? '');
        if (push_endpoint_ip($endpoint) === null || strlen(b64url_decode($p256dh)) !== 65 || strlen(b64url_decode($auth)) !== 16) {
            $reply(['error' => 'The browser sent an unexpected subscription.'], 400);
        }
        q('INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth, label) VALUES (?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), label = VALUES(label), fail_count = 0',
            [$uid, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, mb_substr(trim((string) ($input['label'] ?? '')), 0, 100) ?: 'This device']);
        $reply(['ok' => true]);
    case 'unsubscribe':
        q('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$uid, hash('sha256', $endpoint)]);
        $reply(['ok' => true]);
    case 'test':
        $n = push_user($user, ['title' => 'Pillbox', 'body' => 'Notifications are working on this device.', 'url' => url('account.php#notifications'), 'tag' => 'test']);
        $reply($n ? ['ok' => true] : ['error' => "The notification couldn't be delivered. Try turning notifications off and on again."], $n ? 200 : 502);
}
$reply(['error' => 'Unknown request.'], 400);
