<?php
declare(strict_types=1);

/*
 * Regression test: medicines on the Migraine page follow the sharing permissions.
 *
 *   php tests/sharing_medicines_test.php
 *
 * Uses the database in config/config.php, inside a transaction that is rolled back at the end,
 * so nothing is left behind (still, run it against a test database, not a live one).
 *
 * Three viewers of the same owner's headache diary:
 *   - the owner: every medicine, private ones included
 *   - a sharee with Medicines = see: medicines, but never private ones
 *   - a sharee with Medicines = no access: no medicine names at all (not even typed-in ones),
 *     no acute-medicine counts, and no overuse warnings
 */

define('PILLBOX_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}

/** The owner as seen by a sharee with these section permissions (as require_login() would set it up). */
function as_sharee(array $owner, array $sharee, array $sections): array
{
    $perms = share_permissions(['permissions' => json_encode($sections + ['care_hidden' => [], 'journal' => false])]);
    return $owner + ['_acting' => ['by' => $sharee, 'perms' => $perms, 'share_id' => 0]];
}

/** Everything a viewer gets from this surface: the diary's medicine names, the day counts, and the rendered page. */
function migraine_view(array $viewer, int $ownerId, DateTimeImmutable $today): array
{
    set_current_user($viewer);
    $names = [];
    foreach (headache_episodes($ownerId, gmdate('Y-m-d H:i:s', time() - 30 * 86400)) as $e) {
        foreach ($e['meds'] as $m) {
            $names[] = $m['name'];
        }
    }
    $days = headache_days($ownerId, $today->modify('-29 days'), $today);
    $_SESSION = []; // the page has forms (CSRF tokens) but there's no session on the command line
    ob_start();
    migraine_sections($viewer, $today);
    $html = (string) ob_get_clean();
    return ['names' => $names, 'days' => $days, 'warnings' => overuse_warnings($days), 'html' => $html];
}

$db = db();
$db->beginTransaction();
try {
    $mkUser = function (string $username) {
        q("INSERT INTO users (username, display_name, auth_provider, timezone, onboarded_at, care_areas) VALUES (?, ?, 'local', 'Europe/London', UTC_TIMESTAMP(), 'migraine')",
            ['test-' . $username . '-' . bin2hex(random_bytes(4)), 'Test ' . $username]);
        return q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
    };
    $owner = $mkUser('owner');
    $sharee = $mkUser('sharee');
    $ownerId = (int) $owner['id'];
    set_current_user($owner);
    $today = local_today();

    // Two as-needed medicines: one ordinary, one private. Plus a medicine typed into the diary.
    $mkMed = function (string $name, int $private) use ($ownerId) {
        q("INSERT INTO medications (user_id, name, form, dose_unit, colour, as_needed, prn_dose_amount, is_private, start_date) VALUES (?, ?, 'tablet', 'tablet', 'grey', 1, 1, ?, CURDATE())",
            [$ownerId, $name, $private]);
        return (int) db()->lastInsertId();
    };
    $public = $mkMed('Sumatriptan', 0);
    $private = $mkMed('Zolmitriptan', 1);
    $at = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
    q('INSERT INTO headache_episodes (user_id, started_at, ended_at, severity, migraine) VALUES (?, ?, ?, 2, 1)', [$ownerId, $at, $at]);
    $episode = (int) db()->lastInsertId();
    foreach ([[$public, 'Sumatriptan'], [$private, 'Zolmitriptan'], [null, 'Paracetamol']] as [$medId, $name]) {
        q('INSERT INTO episode_meds (episode_id, medication_id, name, taken_at) VALUES (?, ?, ?, ?)', [$episode, $medId, $name, $at]);
    }
    // As-needed doses logged on Today: these count towards acute-medicine days too.
    foreach ([$public, $private] as $medId) {
        q("INSERT INTO dose_logs (user_id, medication_id, status, amount, taken_at) VALUES (?, ?, 'taken', 1, ?)", [$ownerId, $medId, gmdate('Y-m-d H:i:s', time() - 5 * 86400)]);
    }
    $base = ['readings' => 'none', 'appointments' => 'none', 'care' => 'view', 'mind' => 'none', 'reports' => 'none'];

    echo "Owner\n";
    $v = migraine_view($owner, $ownerId, $today);
    check(in_array('Sumatriptan', $v['names'], true) && in_array('Paracetamol', $v['names'], true), 'sees medicines, including ones typed in');
    check(in_array('Zolmitriptan', $v['names'], true), 'sees their own private medicine');
    check($v['days']['meds_shared'] === true && $v['days']['acute'] === 2, 'acute-medicine days counted (2: the headache day and the dose day)');
    check(str_contains($v['html'], 'Sumatriptan') && str_contains($v['html'], 'Zolmitriptan'), 'page shows the medicine names');

    echo "Sharee with Medicines = see\n";
    $v = migraine_view(as_sharee($owner, $sharee, ['doses' => 'view'] + $base), $ownerId, $today);
    check(in_array('Sumatriptan', $v['names'], true) && in_array('Paracetamol', $v['names'], true), 'sees medicines, including ones typed in');
    check(!in_array('Zolmitriptan', $v['names'], true), 'never sees the private medicine');
    check($v['days']['meds_shared'] === true && $v['days']['acute'] === 2, 'acute-medicine days counted, without the private medicine (2: its dose day was also a headache day)');
    check(str_contains($v['html'], 'Sumatriptan') && !str_contains($v['html'], 'Zolmitriptan'), 'page shows medicine names, but not the private one');

    echo "Sharee with Medicines = no access\n";
    $v = migraine_view(as_sharee($owner, $sharee, ['doses' => 'none'] + $base), $ownerId, $today);
    check($v['names'] === [], 'no medicines from the diary at all, not even typed-in ones');
    check($v['days']['meds_shared'] === false && $v['days']['acute'] === null && array_filter($v['days']['by_class'], fn ($n) => $n !== null) === [], 'no acute-medicine counts');
    check($v['days']['headache'] === 1 && $v['days']['migraine'] === 1, 'still sees headache and migraine days (Care)');
    check($v['warnings'] === [], 'no overuse warnings');
    check(!str_contains($v['html'], 'Sumatriptan') && !str_contains($v['html'], 'Zolmitriptan') && !str_contains($v['html'], 'Paracetamol'), 'page shows no medicine names');
} finally {
    $db->rollBack();
    set_current_user(null);
}

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
