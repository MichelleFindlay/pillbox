<?php
declare(strict_types=1);

/*
 * Two-factor sign-in, alongside security keys (webauthn.php): a 6-digit code from an
 * authenticator app (TOTP, RFC 6238), confirmed once with a code before it's actually asked for
 * at sign-in. More than one authenticator app is allowed per account (totp_credentials; e.g. a
 * phone and a spare), tried in turn against a code. Backup codes (users.totp_backup_codes) are
 * shared across all of an account's authenticator apps, for when none of them are to hand.
 */

require_once APP_ROOT . '/vendor/qrcode-generator/qrcode.php';

const TOTP_BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const TOTP_BACKUP_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // no 0/O/1/I/L: easy to tell apart when read out or copied

function totp_base32_encode(string $bytes): string
{
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function totp_base32_decode(string $b32): string
{
    $bits = '';
    foreach (str_split(strtoupper($b32)) as $char) {
        $pos = strpos(TOTP_BASE32_ALPHABET, $char);
        if ($pos !== false) {
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $bytes .= chr(bindec($byte));
        }
    }
    return $bytes;
}

function totp_generate_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/** The 6-digit code for $secret (base32) at a given time (RFC 6238: HOTP-SHA1, 30s steps). */
function totp_code(string $secret, ?int $time = null): string
{
    $counter = intdiv($time ?? time(), 30);
    $hash = hash_hmac('sha1', pack('N2', 0, $counter), totp_base32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $value = (ord($hash[$offset]) & 0x7f) << 24
        | (ord($hash[$offset + 1]) & 0xff) << 16
        | (ord($hash[$offset + 2]) & 0xff) << 8
        | (ord($hash[$offset + 3]) & 0xff);
    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Does $code match $secret at the current time, +/- 30s of drift? No replay tracking: for confirming a freshly-added secret, not for signing in. */
function totp_code_matches(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', (string) $code);
    if ($code === '') {
        return false;
    }
    $now = intdiv(time(), 30);
    for ($step = $now - 1; $step <= $now + 1; $step++) {
        if (hash_equals(totp_code($secret, $step * 30), $code)) {
            return true;
        }
    }
    return false;
}

/** This account's confirmed authenticator apps, with secret decrypted ready to use. */
function totp_credentials(int $userId): array
{
    $rows = q('SELECT * FROM totp_credentials WHERE user_id = ? AND confirmed_at IS NOT NULL ORDER BY id', [$userId])->fetchAll();
    foreach ($rows as &$row) {
        $row['secret'] = (string) unseal($row['secret']);
    }
    return $rows;
}

/** The account's one in-progress (unconfirmed) authenticator app setup, if any, decrypted ready to use. */
function totp_pending_credential(int $userId, ?int $id = null): ?array
{
    $cred = $id !== null
        ? q('SELECT * FROM totp_credentials WHERE id = ? AND user_id = ? AND confirmed_at IS NULL', [$id, $userId])->fetch()
        : q('SELECT * FROM totp_credentials WHERE user_id = ? AND confirmed_at IS NULL ORDER BY id DESC LIMIT 1', [$userId])->fetch();
    if (!$cred) {
        return null;
    }
    $cred['secret'] = (string) unseal($cred['secret']);
    return $cred;
}

function has_totp_enabled(int $userId): bool
{
    return (bool) q('SELECT 1 FROM totp_credentials WHERE user_id = ? AND confirmed_at IS NOT NULL LIMIT 1', [$userId])->fetch();
}

/**
 * Check a 6-digit code against any of the account's confirmed authenticator apps, allowing
 * +/- 30s of clock drift. Updates that one's last_step on success, so the same code (or an
 * older one) can't be used twice. @return array|null the credential it matched, or null
 */
function totp_verify_code(int $userId, string $code): ?array
{
    $code = preg_replace('/\D/', '', (string) $code);
    if ($code === '') {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach (totp_credentials($userId) as $cred) {
        $last = $cred['last_step'] !== null ? (int) $cred['last_step'] : null;
        for ($step = $now - 1; $step <= $now + 1; $step++) {
            if ($last !== null && $step <= $last) {
                continue;
            }
            if (hash_equals(totp_code($cred['secret'], $step * 30), $code)) {
                q('UPDATE totp_credentials SET last_step = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$step, $cred['id']]);
                return $cred;
            }
        }
    }
    return null;
}

/** otpauth:// URI for the QR code / manual entry in an authenticator app. */
function totp_provisioning_uri(string $secret, string $label, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($label)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/** $data as a scannable QR code, drawn as an inline SVG (so the secret never leaves the page). */
function totp_qr_svg(string $data, int $scale = 6): string
{
    $qr = QRCode::getMinimumQRCode($data, QR_ERROR_CORRECT_LEVEL_M);
    $count = $qr->getModuleCount();
    $size = $count * $scale;
    $rects = '';
    for ($row = 0; $row < $count; $row++) {
        for ($col = 0; $col < $count; $col++) {
            if ($qr->isDark($row, $col)) {
                $rects .= '<rect x="' . ($col * $scale) . '" y="' . ($row * $scale) . '" width="' . $scale . '" height="' . $scale . '"/>';
            }
        }
    }
    // Always black on white, whatever the site's colour theme: scanners need real contrast, not a themed one.
    return '<svg class="totp-qr" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size
        . '" role="img" aria-label="QR code for the authenticator app"><rect width="' . $size . '" height="' . $size . '" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
}

/** @return list<string> $count one-time backup codes (e.g. "7F3K-9QXR"), shown once. */
function totp_generate_backup_codes(int $count = 10): array
{
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= TOTP_BACKUP_ALPHABET[random_int(0, strlen(TOTP_BACKUP_ALPHABET) - 1)];
        }
        $codes[] = substr($code, 0, 4) . '-' . substr($code, 4);
    }
    return $codes;
}

/** Only the hashes are stored (like a password): $codes is never saved in the clear. */
function totp_hash_backup_codes(array $codes): string
{
    return json_encode(array_map(fn ($c) => ['hash' => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), 'used_at' => null], $codes));
}

/** Check a backup code and consume it (each works once). */
function totp_verify_backup_code(array $user, string $code): bool
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    $stored = json_decode((string) $user['totp_backup_codes'], true);
    if ($code === '' || !is_array($stored)) {
        return false;
    }
    foreach ($stored as $i => $entry) {
        if (($entry['used_at'] ?? null) === null && password_verify($code, $entry['hash'])) {
            $stored[$i]['used_at'] = gmdate('Y-m-d H:i:s');
            q('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [json_encode($stored), $user['id']]);
            return true;
        }
    }
    return false;
}
