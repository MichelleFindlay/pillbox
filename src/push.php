<?php
declare(strict_types=1);

/*
 * Web Push notifications (phones and computers), with no third-party library:
 * VAPID (RFC 8292) to identify this server, and aes128gcm payload encryption
 * (RFC 8188 / RFC 8291) so only the person's device can read the message. The VAPID
 * key pair is created on first use and kept in storage/vapid.json.
 */

/** This server's VAPID keys: ['private' => PEM, 'public' => base64url of the raw 65-byte point]. */
function vapid_keys(): array
{
    $file = storage_path('vapid.json');
    $keys = json_decode((string) @file_get_contents($file), true);
    if (!is_array($keys) || empty($keys['private'])) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key)['ec'];
        $keys = ['private' => $pem, 'public' => b64url_encode("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT))];
        file_put_contents($file, json_encode($keys), LOCK_EX);
        @chmod($file, 0600);
    }
    return $keys;
}

function push_supported(): bool
{
    return function_exists('openssl_pkey_derive') && function_exists('curl_init') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/** A raw uncompressed P-256 point as a PEM public key. */
function p256_point_to_pem(string $point): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function hkdf_expand_one(string $prk, string $info, int $length): string
{
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $length);
}

/**
 * Encrypt a payload for one subscription (RFC 8291, aes128gcm, a single record).
 * $asPrivate / $salt can be given for testing; normally they're random.
 */
function push_encrypt(string $payload, string $uaPublic, string $authSecret, $asPrivate = null, ?string $salt = null): string
{
    $asPrivate ??= openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $d = openssl_pkey_get_details($asPrivate)['ec'];
    $asPublic = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    $secret = openssl_pkey_derive(openssl_pkey_get_public(p256_point_to_pem($uaPublic)), $asPrivate, 32);
    if ($secret === false) {
        throw new RuntimeException('Bad subscription key.');
    }
    $salt ??= random_bytes(16);
    $prkKey = hash_hmac('sha256', $secret, $authSecret, true);
    $ikm = hkdf_expand_one($prkKey, "WebPush: info\0" . $uaPublic . $asPublic, 32);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = hkdf_expand_one($prk, "Content-Encoding: aes128gcm\0", 16);
    $nonce = hkdf_expand_one($prk, "Content-Encoding: nonce\0", 12);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** The VAPID Authorization header value for a push service. */
function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $p = parse_url($endpoint);
    $claims = ['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600,
        'sub' => filter_var((string) config('mail.from', ''), FILTER_VALIDATE_EMAIL) ? 'mailto:' . config('mail.from') : absolute_url()];
    $unsigned = b64url_encode('{"typ":"JWT","alg":"ES256"}') . '.' . b64url_encode(json_encode($claims, JSON_UNESCAPED_SLASHES));
    openssl_sign($unsigned, $der, $keys['private'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $unsigned . '.' . b64url_encode(ecdsa_der_to_raw($der)) . ', k=' . $keys['public'];
}

/** ECDSA signature: DER (from OpenSSL) to the raw r||s form JWTs use. */
function ecdsa_der_to_raw(string $der): string
{
    $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0);
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$offset + 1]);
        $int = substr($der, $offset + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $offset += 2 + $len;
    }
    return $out;
}

// Browsers' push services. The endpoint a browser gives us must be one of these (or one an admin adds
// under 'push' => ['allowed_hosts' => [...]]), so a made-up endpoint can't make the server send requests
// to other places, such as services on its own network.
const PUSH_SERVICE_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com',
    '.push.apple.com', '.notify.windows.com'];

/** The IP to connect to for a push endpoint, or null if it isn't a known push service on the public internet. */
function push_endpoint_ip(string $endpoint): ?string
{
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    $host = strtolower($p['host']);
    $allowed = false;
    foreach ([...PUSH_SERVICE_HOSTS, ...(array) config('push.allowed_hosts', [])] as $h) {
        $h = strtolower((string) $h);
        $allowed = $allowed || $host === ltrim($h, '.') || (str_starts_with($h, '.') && str_ends_with($host, $h));
    }
    if (!$allowed) {
        return null;
    }
    $ip = gethostbyname($host);
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : null;
}

/**
 * Send one notification. ['title', 'body', 'url', 'tag']
 * @return int HTTP status from the push service (201 = sent; 404 / 410 = the device has gone)
 */
function push_send(array $sub, array $message): int
{
    $ip = push_endpoint_ip($sub['endpoint']);
    if ($ip === null) {
        return 410; // not a push service we send to: treated as gone, so it's removed
    }
    $body = push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), b64url_decode($sub['p256dh']), b64url_decode($sub['auth']));
    $ch = curl_init($sub['endpoint']);
    $host = (string) parse_url($sub['endpoint'], PHP_URL_HOST);
    curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . (parse_url($sub['endpoint'], PHP_URL_PORT) ?: 443) . ':' . $ip]); // the address that was checked
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: normal',
            'Authorization: ' . vapid_header($sub['endpoint'])],
    ]);
    curl_exec($ch);
    return (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
}

/** Notify all of a user's devices; drops devices that have gone. @return int devices reached */
function push_user(array $user, array $message): int
{
    if ($user['push_private']) {
        $message = ['title' => 'Pillbox', 'body' => 'You have a reminder.', 'url' => $message['url'] ?? url('index.php'), 'tag' => $message['tag'] ?? 'pillbox'];
    }
    $reached = 0;
    foreach (q('SELECT * FROM push_subscriptions WHERE user_id = ?', [$user['id']]) as $sub) {
        try {
            $status = push_send($sub, $message);
        } catch (Throwable $e) {
            $status = 0;
        }
        if (in_array($status, [404, 410], true)) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
        } elseif ($status >= 200 && $status < 300) {
            q('UPDATE push_subscriptions SET last_used_at = UTC_TIMESTAMP(), fail_count = 0 WHERE id = ?', [$sub['id']]);
            $reached++;
        } elseif ((int) $sub['fail_count'] >= 49) {
            q('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]); // unreachable for days: give up
        } else {
            q('UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?', [$sub['id']]);
        }
    }
    return $reached;
}

/** Record that something was notified; false if it already had been (so nothing is sent twice). */
function push_once(int $userId, string $ref): bool
{
    return q('INSERT IGNORE INTO push_sent (user_id, ref, sent_at) VALUES (?, ?, UTC_TIMESTAMP())', [$userId, mb_substr($ref, 0, 100)])->rowCount() === 1;
}

/* ---------- the scheduled task ---------- */

function send_push_notifications(DateTimeImmutable $now): array
{
    if (!push_supported()) {
        return [];
    }
    $log = [];
    $users = q('SELECT DISTINCT u.* FROM users u JOIN push_subscriptions s ON s.user_id = u.id WHERE u.is_active = 1 AND u.is_demo = 0')->fetchAll();
    foreach ($users as $user) {
        set_current_user($user);
        $uid = (int) $user['id'];
        $tz = user_tz($user);
        $local = $now->setTimezone($tz);
        $today = new DateTimeImmutable($local->format('Y-m-d'), $tz);
        $window = $now->modify('-20 minutes');   // catch anything due since the last few runs
        $sent = 0;

        if ($user['push_doses']) {
            foreach (dose_slots($user, $today, $today, true) as $slot) {
                $at = $slot['local'];
                // An optional gentle follow-up if it still isn't logged a while later (Account > Display and reminders).
                $followAt = $user['dose_followup'] ? $at->modify('+' . (int) $user['dose_followup'] . ' minutes') : null;
                if (!$slot['log'] && $followAt && $followAt <= $now && $followAt >= $window
                    && push_once($uid, 'dose2|' . $slot['schedule']['id'] . '|' . $slot['utc'])) {
                    $s = $slot['schedule'];
                    $sent += push_user($user, ['title' => 'Pillbox', 'body' => $s['is_private'] ? 'Reminder' : 'Did you take your ' . $at->format('H:i') . ' ' . $s['name'] . '? Tap to log it (or skip it).',
                        'url' => url('index.php'), 'tag' => 'dose-' . $s['id']]);
                }
                if ($slot['log'] || $at > $now || $at < $window) {
                    continue;
                }
                if (push_once($uid, 'dose|' . $slot['schedule']['id'] . '|' . $slot['utc'])) {
                    $s = $slot['schedule'];
                    $sent += push_user($user, $s['is_private']
                        ? ['title' => 'Pillbox', 'body' => 'Reminder', 'url' => url('index.php'), 'tag' => 'dose-' . $s['id']]
                        : ['title' => 'Time for ' . $s['name'], 'body' => dose_label($s['dose_amount'], $s['dose_unit']) . ' ' . $s['strength'] . ' at ' . $at->format('H:i'),
                        'url' => url('index.php'), 'tag' => 'dose-' . $s['id']]);
                }
            }
        }
        // Prescriptions: time to ask for the next one, and controlled drug prescriptions about to expire uncollected.
        if ($user['push_doses'] && (int) $local->format('G') >= 9) {
            $meds = user_medications($uid);
            $medSchedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
            foreach ($meds as $m) {
                $rx = prescription_dates($m, $medSchedules[$m['id']] ?? [], $today);
                $name = $m['is_private'] ? null : $m['name'];
                if ($rx['collect_by'] && $today->diff($rx['collect_by'])->days <= 3 && $today <= $rx['collect_by'] && push_once($uid, 'rxcollect|' . $m['id'] . '|' . $m['rx_written'])) {
                    $sent += push_user($user, ['title' => 'Pillbox', 'body' => $name ? "Collect your $name prescription by " . $rx['collect_by']->format('D j M') . '.' : 'Reminder',
                        'url' => url('medications.php#med-' . $m['id']), 'tag' => 'rx-' . $m['id']]);
                } elseif (!$m['ordered_on'] && !$m['rx_written'] && $rx['request'] && $rx['request']->format('Y-m-d') === $today->format('Y-m-d')
                    && push_once($uid, 'rxrequest|' . $m['id'] . '|' . $today->format('Y-m-d'))) {
                    $sent += push_user($user, ['title' => 'Pillbox', 'body' => $name ? "Time to ask for your next $name prescription." : 'Reminder',
                        'url' => url('medications.php#med-' . $m['id']), 'tag' => 'rx-' . $m['id']]);
                }
            }
        }
        if ($user['push_care']) {
            foreach (care_tasks($uid) as $t) {
                if (!$t['remind'] || !in_array($t['area'], routine_areas($user), true) || !care_due($t, $today)) {
                    continue;
                }
                foreach (care_times($t) as $slot => $time) {
                    $at = new DateTimeImmutable($today->format('Y-m-d') . " $time", $tz);
                    if ($at <= $now && $at >= $window && push_once($uid, "care|{$t['id']}|{$today->format('Y-m-d')}|$slot")) {
                        $sent += push_user($user, ['title' => $t['area'] === 'mind' ? 'Pillbox reminder' : $t['label'],
                            'body' => $t['area'] === 'mind' ? 'You have a reminder.' : ($t['amount'] ? $t['amount'] . '. ' : '') . ($t['tip'] ?: ''), 'url' => url('index.php'), 'tag' => 'care-' . $t['id']]);
                    }
                }
            }
            if ((int) $local->format('G') >= (int) $user['email_digest_hour'] && ($coming = checks_coming_up($user, user_care_areas($user), $today))
                && push_once($uid, 'comingup|' . $today->format('Y-m-d'))) {
                $sent += push_user($user, ['title' => count($coming) . ' thing' . (count($coming) === 1 ? '' : 's') . ' coming up',
                    'body' => implode(', ', array_map(fn ($c) => $c['check']['label'], array_slice($coming, 0, 3))) . (count($coming) > 3 ? '...' : ''), 'url' => url('index.php#coming-up'), 'tag' => 'coming-up']);
            }
        }
        if ($user['push_appointments']) {
            foreach (appointments_between($uid, $now->format('Y-m-d H:i:s'), $now->modify('+8 days')->format('Y-m-d H:i:s')) as $a) {
                $start = new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC'));
                if ($now >= $start->modify('-' . (int) ($a['remind_minutes'] ?: 1440) . ' minutes') && push_once($uid, 'appt|' . $a['id'] . '|' . $a['starts_at'])) {
                    $sent += push_user($user, ['title' => appointment_title($a), 'body' => $start->setTimezone($tz)->format('D j M, H:i') . ($a['location'] ? ', ' . $a['location'] : ''),
                        'url' => url('appointment.php?id=' . $a['id']), 'tag' => 'appt-' . $a['id']]);
                }
            }
        }
        if (mind_enabled($user) && $user['mind_reminder']) {
            $at = new DateTimeImmutable($today->format('Y-m-d') . ' ' . substr($user['mind_reminder'], 0, 5), $tz);
            if ($at <= $now && $at >= $window && push_once($uid, 'checkin|' . $today->format('Y-m-d'))) {
                $sent += push_user($user, ['title' => 'Pillbox check-in', 'body' => 'A moment for a quick check-in.', 'url' => url('mind.php'), 'tag' => 'checkin']);
            }
        }
        if ($sent) {
            $log[] = "Sent $sent notification" . ($sent === 1 ? '' : 's') . " to user $uid.";
        }
    }
    $log = [...$log, ...send_private_reminders($now)];
    if ($n = send_pmdd_headsups($now)) {
        $log[] = "Sent $n cycle heads-up" . ($n === 1 ? '' : 's') . '.';
    }
    q('DELETE FROM push_sent WHERE sent_at < ?', [$now->modify('-14 days')->format('Y-m-d H:i:s')]);
    return $log;
}
