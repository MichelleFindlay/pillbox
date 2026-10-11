<?php
declare(strict_types=1);

/*
 * Gender-affirming care, and the anatomy-based parts of the app.
 * - Body inventory (Account > Health profile, optional): which organs someone has. Screening and
 *   the pregnancy pages follow it, never a gender field. NHS invitations follow the sex on the GP
 *   record, so the screening page points out gaps (e.g. cervical screening for a trans man
 *   registered as male).
 * - Hormones: one-tap set-ups in Meds (testosterone, oestrogen, progesterone, blockers), with
 *   every-N-weeks injections, injection-site rotation and private (neutral) reminders.
 * - gender.php: the care pathway (GIC waiting list, shared care, prescriber, fertility), a changes
 *   journal with the person's own fields, and progress photos. All encrypted, with its own PIN,
 *   never shared, and deleted in one go.
 */

const BODY_PARTS = [
    'cervix'   => 'Cervix',
    'uterus'   => 'Uterus (womb)',
    'ovaries'  => 'Ovaries',
    'breasts'  => 'Breasts or chest tissue',
    'prostate' => 'Prostate',
    'testes'   => 'Testes',
];
const BODY_STATES = ['yes' => 'Have', 'removed' => 'Removed (surgery)', 'no' => "Don't have"];

// One-tap gender-affirming set-ups: they open the medication form filled in (and private), to match the prescription.
// 'every' is days between doses for injections; 'days' a weekday mask (Mon 1 ... Sun 64).
const GENDER_PRESETS = [
    't_gel'         => ['label' => 'Testosterone gel', 'name' => 'Testosterone gel', 'form' => 'gel', 'unit' => 'pump', 'time' => '08:00', 'amount' => 1,
        'instructions' => 'Number of pumps as prescribed. Let it dry, and cover it, so it does not transfer to others'],
    't_short'       => ['label' => 'Testosterone injection (every 2 to 3 weeks)', 'name' => 'Testosterone injection (e.g. Sustanon)', 'form' => 'injection', 'unit' => 'injection',
        'time' => '09:00', 'every' => 21, 'instructions' => 'Every 2 to 3 weeks, as prescribed. Rotate injection sites'],
    't_long'        => ['label' => 'Testosterone injection (every 10 to 14 weeks)', 'name' => 'Testosterone injection (e.g. Nebido)', 'form' => 'injection', 'unit' => 'injection',
        'time' => '09:00', 'every' => 84, 'instructions' => 'Every 10 to 14 weeks, usually given by a nurse. Rotate injection sites'],
    'e_patch'       => ['label' => 'Oestradiol patches', 'name' => 'Oestradiol patch', 'form' => 'patch', 'unit' => 'patch', 'time' => '08:00', 'days' => 9,
        'instructions' => 'Change twice a week (e.g. Monday and Thursday)'],
    'e_gel'         => ['label' => 'Oestradiol gel', 'name' => 'Oestradiol gel', 'form' => 'gel', 'unit' => 'pump', 'time' => '08:00', 'amount' => 2,
        'instructions' => 'Number of pumps as prescribed; let it dry before dressing'],
    'e_spray'       => ['label' => 'Oestradiol spray', 'name' => 'Oestradiol spray', 'form' => 'spray', 'unit' => 'spray', 'time' => '08:00'],
    'e_tablet'      => ['label' => 'Oestradiol tablets', 'name' => 'Oestradiol', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '08:00'],
    'e_injection'   => ['label' => 'Oestradiol injection', 'name' => 'Oestradiol injection', 'form' => 'injection', 'unit' => 'injection', 'time' => '09:00', 'every' => 7,
        'instructions' => 'As prescribed (often weekly or every 2 weeks). Rotate injection sites'],
    'progesterone'  => ['label' => 'Progesterone', 'name' => 'Micronised progesterone', 'form' => 'capsule', 'unit' => 'capsule', 'time' => '22:00',
        'instructions' => 'Often taken at bedtime'],
    'gnrh_4'        => ['label' => 'Blocker injection (every 4 weeks)', 'name' => 'GnRH analogue injection', 'form' => 'injection', 'unit' => 'injection', 'time' => '09:00', 'every' => 28,
        'instructions' => 'e.g. triptorelin or leuprorelin, usually given at your GP practice'],
    'gnrh_12'       => ['label' => 'Blocker injection (every 12 weeks)', 'name' => 'GnRH analogue injection', 'form' => 'injection', 'unit' => 'injection', 'time' => '09:00', 'every' => 84,
        'instructions' => 'e.g. triptorelin or leuprorelin, usually given at your GP practice'],
    'anti_androgen' => ['label' => 'Anti-androgen tablets', 'name' => 'Anti-androgen', 'form' => 'tablet', 'unit' => 'tablet', 'time' => '08:00',
        'instructions' => 'e.g. spironolactone, cyproterone or bicalutamide, as prescribed'],
];

const INJECTION_SITES = [
    'thigh_l' => 'Left thigh', 'thigh_r' => 'Right thigh', 'glute_l' => 'Left buttock', 'glute_r' => 'Right buttock',
    'tummy_l' => 'Left of tummy', 'tummy_r' => 'Right of tummy', 'arm_l' => 'Left upper arm', 'arm_r' => 'Right upper arm',
];

const PRESCRIBERS = ['gic' => 'Gender identity clinic (GIC)', 'gp_shared' => 'GP, under shared care with a GIC', 'gp' => 'GP', 'private' => 'Private provider', 'other' => 'Other'];
const SHARED_CARE = ['none' => 'Not applicable', 'requested' => 'Asked for, waiting', 'agreed' => 'Agreed', 'declined' => 'Declined by the GP'];

/** The body inventory, or null if the person hasn't filled it in. */
function body_inventory(array $user): ?array
{
    $inv = json_decode((string) ($user['body_inventory'] ?? ''), true);
    return is_array($inv) ? $inv : null;
}

/** Does the person have this organ? null if the inventory isn't filled in. */
function body_has(array $user, string $part): ?bool
{
    $inv = body_inventory($user);
    return $inv === null ? null : (($inv[$part]['state'] ?? 'no') === 'yes');
}

/** Screening programmes that apply from the body inventory (bowel is for everyone of the right age). */
function inventory_screening(array $user, string $kind): bool
{
    $inv = body_inventory($user) ?? [];
    $state = fn (string $p) => $inv[$p]['state'] ?? 'no';
    return match ($kind) {
        'cervical' => $state('cervix') === 'yes',
        'breast' => $state('breasts') === 'yes',
        'bowel' => true,
        // AAA screening is for people assigned male at birth: a prostate or testes, now or before surgery.
        'aaa' => in_array($state('prostate'), ['yes', 'removed'], true) || in_array($state('testes'), ['yes', 'removed'], true),
        default => false,
    };
}

/** Screening that applies but may not be offered automatically, because of the sex on the GP record. */
function screening_gaps(array $user): array
{
    if (body_inventory($user) === null || !in_array($user['sex'], ['female', 'male'], true)) {
        return [];
    }
    $gaps = [];
    foreach (SCREENING_PROGRAMMES as $kind => $p) {
        if ($kind !== 'bowel' && inventory_screening($user, $kind) && !in_array($user['sex'], $p['sexes'], true)) {
            $gaps[] = $p['label'];
        }
    }
    return $gaps;
}

/**
 * The injection site to suggest next for a medicine: the least recently used of the sites the
 * person rotates between (or, with fewer than two used so far, the other side from last time).
 */
function injection_site_suggestion(int $medId): ?string
{
    $last = [];
    foreach (q("SELECT site, MAX(taken_at) AS at FROM dose_logs WHERE medication_id = ? AND site IS NOT NULL AND status = 'taken' GROUP BY site", [$medId]) as $r) {
        $last[$r['site']] = $r['at'];
    }
    if (count($last) >= 2) {
        asort($last);
        return array_key_first($last);
    }
    if ($last) {
        $site = array_key_first($last);
        return str_ends_with($site, '_l') ? substr($site, 0, -2) . '_r' : substr($site, 0, -2) . '_l';
    }
    return null;
}

/** When each site was last used for this medicine: [site => 'Y-m-d H:i:s' UTC]. */
function injection_site_history(int $medId): array
{
    $out = [];
    foreach (q("SELECT site, MAX(taken_at) AS at FROM dose_logs WHERE medication_id = ? AND site IS NOT NULL AND status = 'taken' GROUP BY site", [$medId]) as $r) {
        $out[$r['site']] = $r['at'];
    }
    return $out;
}

/* ---------- Progress photos: encrypted files in storage/private/<user>/ ---------- */

function private_photo_dir(int $userId): string
{
    $dir = storage_path('private/' . $userId);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/** Encrypt and store an uploaded image; returns its file id, or an error message. */
function store_private_photo(int $userId, array $upload): array|string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        return 'Choose a photo to upload.';
    }
    if ($upload['size'] > 12 * 1024 * 1024) {
        return 'That photo is too big (12 MB at most).';
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/heic'], true)) {
        return 'Upload a JPEG, PNG, WebP or HEIC photo.';
    }
    $id = bin2hex(random_bytes(16));
    $sealed = seal($type . "\n" . file_get_contents($upload['tmp_name']));
    if (file_put_contents(private_photo_dir($userId) . '/' . $id . '.bin', $sealed, LOCK_EX) === false) {
        return "Couldn't save the photo. Is storage/ writable?";
    }
    @unlink($upload['tmp_name']);
    return ['id' => $id, 'type' => $type];
}

/** [mime type, bytes] of a stored photo, or null. */
function read_private_photo(int $userId, string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
        return null;
    }
    $plain = unseal(@file_get_contents(private_photo_dir($userId) . '/' . $id . '.bin') ?: null);
    if ($plain === null || !str_contains($plain, "\n")) {
        return null;
    }
    [$type, $bytes] = explode("\n", $plain, 2);
    return [$type, $bytes];
}

function delete_private_photo(int $userId, string $id): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $id)) {
        @unlink(private_photo_dir($userId) . '/' . $id . '.bin');
    }
}
