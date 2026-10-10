<?php
declare(strict_types=1);

/*
 * Fingerprint / face unlock for the Mind PIN, with WebAuthn platform authenticators
 * (Touch ID, Face ID, Windows Hello, Android). No attestation is requested; we check the
 * challenge, origin, RP ID hash, user presence and verification flags, and the signature
 * against the public key saved at registration. Needs HTTPS (or localhost).
 */

// b64url_encode() / b64url_decode() are in entra.php.

/** The relying party ID: the site's host name. */
function webauthn_rp_id(): string
{
    return (string) parse_url(absolute_url(), PHP_URL_HOST);
}

function webauthn_origin(): string
{
    $p = parse_url(absolute_url());
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
}

function webauthn_challenge(string $purpose): string
{
    $challenge = random_bytes(32);
    $_SESSION['webauthn'] = ['purpose' => $purpose, 'challenge' => b64url_encode($challenge), 'at' => time()];
    return b64url_encode($challenge);
}

/** Check clientDataJSON against the challenge we issued. @return string the raw clientDataJSON */
function webauthn_check_client_data(string $clientDataB64, string $type): string
{
    $raw = b64url_decode($clientDataB64);
    $data = json_decode($raw, true);
    $issued = $_SESSION['webauthn'] ?? null;
    unset($_SESSION['webauthn']);
    if (!is_array($data) || ($data['type'] ?? '') !== $type) {
        throw new RuntimeException('Unexpected response from the device.');
    }
    if (!$issued || $issued['at'] < time() - 300 || !hash_equals($issued['challenge'], (string) ($data['challenge'] ?? ''))) {
        throw new RuntimeException('That took too long, or was already used. Try again.');
    }
    if (($data['origin'] ?? '') !== webauthn_origin()) {
        throw new RuntimeException('The response came from a different website.');
    }
    return $raw;
}

/** Check the authenticator data's RP ID hash and that the person was present and verified. */
function webauthn_check_auth_data(string $authData): array
{
    if (strlen($authData) < 37 || !hash_equals(hash('sha256', webauthn_rp_id(), true), substr($authData, 0, 32))) {
        throw new RuntimeException('The response was for a different website.');
    }
    $flags = ord($authData[32]);
    if (!($flags & 0x01) || !($flags & 0x04)) {
        throw new RuntimeException('Your fingerprint, face or device PIN wasn\'t checked. Try again.');
    }
    return ['flags' => $flags, 'count' => unpack('N', substr($authData, 33, 4))[1]];
}

/** Registration: returns [credential id (base64url), public key PEM, sign count]. */
function webauthn_register(string $clientDataB64, string $attestationB64): array
{
    webauthn_check_client_data($clientDataB64, 'webauthn.create');
    $attestation = cbor_decode(b64url_decode($attestationB64));
    $authData = $attestation['authData'] ?? '';
    $info = webauthn_check_auth_data($authData);
    if (!($info['flags'] & 0x40)) {
        throw new RuntimeException('The device did not send a key.');
    }
    $offset = 37 + 16; // after rpIdHash, flags, count and the AAGUID
    $idLength = unpack('n', substr($authData, $offset, 2))[1];
    $credentialId = substr($authData, $offset + 2, $idLength);
    $offset += 2 + $idLength;
    $cose = cbor_decode($authData, $offset);
    return [b64url_encode($credentialId), cose_to_pem($cose), $info['count']];
}

/** Unlock: verify an assertion from one of the user's saved credentials. @return array the credential row */
function webauthn_verify(int $userId, array $response): array
{
    $cred = q('SELECT * FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?', [$userId, (string) ($response['id'] ?? '')])->fetch();
    if (!$cred) {
        throw new RuntimeException('This device isn\'t set up for unlocking. Use your PIN.');
    }
    $clientData = webauthn_check_client_data((string) ($response['clientDataJSON'] ?? ''), 'webauthn.get');
    $authData = b64url_decode((string) ($response['authenticatorData'] ?? ''));
    $info = webauthn_check_auth_data($authData);
    $signed = $authData . hash('sha256', $clientData, true);
    if (openssl_verify($signed, b64url_decode((string) ($response['signature'] ?? '')), $cred['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('That didn\'t match. Use your PIN.');
    }
    if ($info['count'] > 0 && $info['count'] <= (int) $cred['sign_count']) {
        throw new RuntimeException('This device\'s key may have been copied. Remove it and set it up again.');
    }
    q('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = UTC_TIMESTAMP() WHERE id = ?', [$info['count'], $cred['id']]);
    return $cred;
}

/* ---------- COSE keys and a small CBOR decoder ---------- */

/** EC2 P-256 (ES256) or RSA (RS256) COSE key to a PEM public key. */
function cose_to_pem(array $key): string
{
    $der = match ((int) ($key[1] ?? 0)) {
        2 => (function () use ($key) {     // EC2: -1 curve (1 = P-256), -2 x, -3 y
            if ((int) ($key[-1] ?? 0) !== 1 || strlen($key[-2] ?? '') !== 32 || strlen($key[-3] ?? '') !== 32) {
                throw new RuntimeException('Unsupported key type from the device.');
            }
            // SubjectPublicKeyInfo for id-ecPublicKey with prime256v1, then the uncompressed point.
            return hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $key[-2] . $key[-3];
        })(),
        3 => (function () use ($key) {     // RSA: -1 n, -2 e
            $int = fn (string $b) => "\x02" . der_length(strlen($b = (ord($b[0]) & 0x80 ? "\x00" . $b : $b))) . $b;
            $rsa = $int($key[-1]) . $int($key[-2]);
            $seq = "\x30" . der_length(strlen($rsa)) . $rsa;
            $bits = "\x03" . der_length(strlen($seq) + 1) . "\x00" . $seq;
            $alg = hex2bin('300d06092a864886f70d0101010500');
            return "\x30" . der_length(strlen($alg . $bits)) . $alg . $bits;
        })(),
        default => throw new RuntimeException('Unsupported key type from the device.'),
    };
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function der_length(int $n): string
{
    if ($n < 128) {
        return chr($n);
    }
    $bytes = ltrim(pack('N', $n), "\x00");
    return chr(0x80 | strlen($bytes)) . $bytes;
}

/** Decode one CBOR item from $data at $offset (advanced past it). Enough for WebAuthn. */
function cbor_decode(string $data, int &$offset = 0): mixed
{
    if ($offset >= strlen($data)) {
        throw new RuntimeException('Bad data from the device.');
    }
    $byte = ord($data[$offset++]);
    $major = $byte >> 5;
    $info = $byte & 0x1f;
    $length = match (true) {
        $info < 24 => $info,
        $info === 24 => ord($data[$offset++]),
        $info === 25 => unpack('n', substr($data, ($offset += 2) - 2, 2))[1],
        $info === 26 => unpack('N', substr($data, ($offset += 4) - 4, 4))[1],
        $info === 27 => unpack('J', substr($data, ($offset += 8) - 8, 8))[1],
        default => throw new RuntimeException('Unsupported data from the device.'),
    };
    switch ($major) {
        case 0: return $length;
        case 1: return -1 - $length;
        case 2:
        case 3:
            $value = substr($data, $offset, $length);
            $offset += $length;
            return $value;
        case 4:
            $out = [];
            for ($i = 0; $i < $length; $i++) {
                $out[] = cbor_decode($data, $offset);
            }
            return $out;
        case 5:
            $out = [];
            for ($i = 0; $i < $length; $i++) {
                $k = cbor_decode($data, $offset);
                $out[$k] = cbor_decode($data, $offset);
            }
            return $out;
        case 7:
            return match ($info) { 20 => false, 21 => true, 22, 23 => null, default => throw new RuntimeException('Unsupported data from the device.') };
    }
    throw new RuntimeException('Unsupported data from the device.');
}
