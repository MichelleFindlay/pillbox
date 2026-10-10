<?php
/*
 * Travel mode: plan trips across time zones and choose how dose times follow you.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$home = home_tz($user);
$homeToday = (new DateTimeImmutable('now', $home))->format('Y-m-d');
$errors = [];
$form = ['label' => '', 'destination_tz' => '', 'start_date' => '', 'end_date' => '', 'mode' => 'local', 'shift_hours' => '2'];

if (is_post()) {
    require_post();
    $plan = post('id') !== '' ? q('SELECT * FROM travel_plans WHERE id = ? AND user_id = ?', [(int) post('id'), $uid])->fetch() : null;
    switch (post('action')) {
        case 'delete':
            if ($plan) {
                q('DELETE FROM travel_plans WHERE id = ?', [$plan['id']]);
                flash('success', 'Trip removed. Doses are back on home time for those dates.');
            }
            redirect('travel.php');
        case 'end_now':
            if ($plan) {
                q('UPDATE travel_plans SET end_date = ? WHERE id = ?', [max($plan['start_date'], $homeToday), $plan['id']]);
                flash('success', 'Welcome home! Doses go back to home time from tomorrow' . ($plan['mode'] === 'gradual' ? ', shifting back gradually.' : '.'));
            }
            redirect('travel.php');
        case 'add':
            foreach (array_keys($form) as $f) {
                $form[$f] = post($f);
            }
            if (!in_array($form['destination_tz'], DateTimeZone::listIdentifiers(), true)) {
                $errors[] = 'Choose the time zone you are travelling to.';
            } elseif ($form['destination_tz'] === $home->getName()) {
                $errors[] = "That's your home time zone, so there's nothing to change.";
            }
            if (!parse_date($form['start_date']) || !parse_date($form['end_date'])) {
                $errors[] = 'Enter the dates you leave and get back.';
            } elseif ($form['end_date'] < $form['start_date']) {
                $errors[] = 'The return date is before the leaving date.';
            } elseif ((new DateTimeImmutable($form['start_date']))->diff(new DateTimeImmutable($form['end_date']))->days > 366) {
                $errors[] = 'Trips can be up to a year long.';
            } elseif (q('SELECT 1 FROM travel_plans WHERE user_id = ? AND start_date <= ? AND end_date >= ?', [$uid, $form['end_date'], $form['start_date']])->fetch()) {
                $errors[] = 'That overlaps another trip. Change or remove the other trip first.';
            }
            if (!isset(TRAVEL_MODES[$form['mode']])) {
                $form['mode'] = 'local';
            }
            $form['shift_hours'] = (string) min(4, max(1, (int) $form['shift_hours']));
            if (!$errors) {
                q('INSERT INTO travel_plans (user_id, label, destination_tz, start_date, end_date, mode, shift_hours) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$uid, mb_substr($form['label'], 0, 100) ?: null, $form['destination_tz'], $form['start_date'], $form['end_date'], $form['mode'], (int) $form['shift_hours']]);
                flash('success', 'Trip to ' . tz_place($form['destination_tz']) . ' saved. Check the dose times below.');
                redirect('travel.php');
            }
            break;
    }
}

$plans = array_values(array_filter(user_travel_plans($uid, true), fn ($p) => $p['end_date'] >= (new DateTimeImmutable($homeToday))->modify('-14 days')->format('Y-m-d')));

/** Dose times for each day around a trip, for the preview table. */
function trip_preview(array $user, array $plan, DateTimeZone $home): array
{
    $schedules = q('SELECT s.*, m.name, m.dose_unit FROM medication_schedules s JOIN medications m ON m.id = s.medication_id
                    WHERE m.user_id = ? AND m.is_active = 1 AND m.as_needed = 0 ORDER BY s.dose_time, m.name', [$user['id']])->fetchAll();
    $dest = new DateTimeZone($plan['destination_tz']);
    $plans = user_travel_plans((int) $user['id']);
    $first = (new DateTimeImmutable($plan['start_date']))->modify('-1 day');
    $returnDays = $plan['mode'] === 'gradual' ? 1 + (int) ceil(13 / max(1, (int) $plan['shift_hours'])) : 2;
    $last = min((new DateTimeImmutable($plan['end_date']))->modify("+$returnDays days"), $first->modify('+21 days'));
    $rows = [];
    for ($d = $first; $d <= $last; $d = $d->modify('+1 day')) {
        $ymd = $d->format('Y-m-d');
        $where = $ymd >= $plan['start_date'] && $ymd <= $plan['end_date'] ? $dest : $home;
        $doses = [];
        foreach ($schedules as $s) {
            if (((int) $s['days_mask'] & weekday_bit($d)) === 0 || ($s['valid_from'] && $s['valid_from'] > $ymd) || ($s['valid_to'] && $s['valid_to'] < $ymd)) {
                continue;
            }
            [$hh, $mm] = array_map('intval', explode(':', $s['dose_time']));
            [$moment] = dose_moment($ymd, $hh, $mm, $home, $plans);
            $doses[] = ['name' => $s['name'], 'amount' => dose_label($s['dose_amount'], $s['dose_unit']),
                'local' => $moment->setTimezone($where)->format('H:i') . ($moment->setTimezone($where)->format('Y-m-d') !== $ymd ? ' (' . $moment->setTimezone($where)->format('D') . ')' : ''),
                'home' => $moment->setTimezone($home)->format('H:i')];
        }
        $rows[] = ['date' => $d, 'away' => $where === $dest, 'doses' => $doses];
    }
    return $rows;
}

$zones = [];
foreach (DateTimeZone::listIdentifiers() as $tz) {
    $zones[explode('/', $tz)[0]][] = $tz;
}

render_header('Travel', 'meds');
?>
<div class="page-head">
    <h1>&#9992; Travel mode</h1>
    <a class="btn btn-small" href="<?= h(url('medications.php')) ?>">&larr; Medications</a>
</div>

<p class="flash flash-warning">If any of your medicines are <strong>time-critical</strong> (for example insulin, contraceptive pills, or medicines for epilepsy,
    Parkinson's or HIV), check with your doctor or pharmacist how to adjust them before you travel.</p>

<?php foreach ($plans as $p):
    $isActive = $p['start_date'] <= $homeToday && $p['end_date'] >= $homeToday;
    $isPast = $p['end_date'] < $homeToday; ?>
<section class="card trip<?= $isActive ? ' trip-active' : '' ?>">
    <div class="card-head">
        <h2><?= h($p['label'] ?: 'Trip to ' . tz_place($p['destination_tz'])) ?>
            <?php if ($isActive): ?><span class="badge badge-upcoming">Now</span><?php elseif ($isPast): ?><span class="badge">Finished</span><?php endif; ?></h2>
        <span class="inline-actions">
            <?php if ($isActive): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="end_now"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-small btn-primary" type="submit">I'm home</button></form>
            <?php endif; ?>
            <form method="post" data-confirm="Remove this trip?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-small btn-danger" type="submit">Remove</button></form>
        </span>
    </div>
    <p><?= h(tz_place($p['destination_tz'])) ?> time (<?= h(tz_offset_label($p['destination_tz'])) ?>) &middot;
        <?= h(date('D j M', strtotime($p['start_date']))) ?> to <?= h(date('D j M Y', strtotime($p['end_date']))) ?> &middot;
        <strong><?= h(TRAVEL_MODES[$p['mode']][0]) ?></strong><?= $p['mode'] === 'gradual' ? ' (' . (int) $p['shift_hours'] . ' hours a day)' : '' ?></p>
    <details<?= $isPast ? '' : ' open' ?>>
        <summary>Dose times day by day</summary>
        <div class="table-wrap">
        <table class="compact trip-preview">
            <thead><tr><th>Day</th><th>Where</th><th>Doses (local time)</th></tr></thead>
            <tbody>
            <?php foreach (trip_preview($user, $p, $home) as $row): ?>
                <tr class="<?= $row['away'] ? 'away' : '' ?>">
                    <td><?= h($row['date']->format('D j M')) ?></td>
                    <td><?= $row['away'] ? h(tz_place($p['destination_tz'])) : 'Home' ?></td>
                    <td><?php foreach ($row['doses'] as $d): ?>
                        <div><strong><?= h($d['local']) ?></strong> <?= h($d['name']) ?> <span class="muted small"><?= h($d['amount']) ?><?= $row['away'] ? ' · ' . h($d['home']) . ' home time' : '' ?></span></div>
                    <?php endforeach; ?><?= $row['doses'] ? '' : '<span class="muted small">No scheduled doses</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </details>
</section>
<?php endforeach; ?>

<section class="card">
    <h2>Plan a trip</h2>
    <p class="muted small">Your home time zone is <?= h(tz_place($home->getName())) ?> (<?= h(tz_offset_label($home->getName())) ?>).
        While you're away, Pillbox shows times in local time.</p>
    <?= form_errors($errors) ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="grid-2">
            <label>Where are you going? <span class="muted small">(time zone)</span>
                <select name="destination_tz" id="destination_tz" required>
                    <option value="">Choose&hellip;</option>
                    <?php foreach ($zones as $region => $list): ?>
                    <optgroup label="<?= h($region) ?>">
                        <?php foreach ($list as $tz): ?><option value="<?= h($tz) ?>"<?= selected($form['destination_tz'] === $tz) ?>><?= h(tz_place($tz)) ?> (<?= h(tz_offset_label($tz)) ?>)</option><?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <button class="btn-link small" type="button" data-use-device-tz="#destination_tz" hidden>Use this phone's current time zone</button>
            </label>
            <label><span>Name <span class="muted small">(optional)</span></span> <input name="label" maxlength="100" value="<?= h($form['label']) ?>" placeholder="e.g. Japan holiday"></label>
            <label>First day away <input type="date" name="start_date" required value="<?= h($form['start_date']) ?>"></label>
            <label>Day you get back <input type="date" name="end_date" required value="<?= h($form['end_date']) ?>"></label>
        </div>
        <fieldset>
            <legend>How should dose times change?</legend>
            <div class="mode-cards">
                <?php foreach (TRAVEL_MODES as $key => [$label, $help]): ?>
                <label class="mode-card"><input type="radio" name="mode" value="<?= h($key) ?>" id="mode-<?= h($key) ?>"<?= checked($form['mode'] === $key) ?>>
                    <span><strong><?= h($label) ?></strong><span class="muted small"><?= h($help) ?></span></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <label class="narrow" data-show-when="mode-gradual">Move dose times by
            <select name="shift_hours">
                <?php foreach ([1, 2, 3, 4] as $hrs): ?><option value="<?= $hrs ?>"<?= selected((int) $form['shift_hours'] === $hrs) ?>><?= $hrs ?> hour<?= $hrs > 1 ? 's' : '' ?> a day</option><?php endforeach; ?>
            </select>
        </label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save trip</button></span>
    </form>
</section>
<?php render_footer();
