<?php
/*
 * A private photo (Gender-affirming care), decrypted for its owner only while that page is
 * unlocked, never cached: photo.php?k=gender&id=<id>
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$kind = get('k') === 'gender' ? 'gender' : '';
$record = $kind && !isset($user['_acting']) ? private_record((int) $user['id'], $kind) : null;
$id = get('id');
if (!$record || !private_unlocked($record) || !in_array($id, array_column($record['data']['photos'] ?? [], 'id'), true)
    || !($photo = read_private_photo((int) $user['id'], $id))) {
    not_found();
}
[$type, $bytes] = $photo;
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: inline');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo $bytes;
