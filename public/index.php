<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$tz = user_tz();
$today = local_today();
$utc = new DateTimeZone('UTC');
$dayStartUtc = $today->setTimezone($utc)->format('Y-m-d H:i:s');
$dayEndUtc = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');

$slots = dose_slots($user, $today, $today, true);
$yesterday = $today->modify('-1 day');
$missedYesterday = array_filter(dose_slots($user, $yesterday, $yesterday, true), fn ($s) => $s['status'] === 'missed');

$meds = user_medications($uid);
$schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
$prnMeds = array_filter($meds, fn ($m) => $m['as_needed']);
$prnToday = prn_logs($uid, $dayStartUtc, $dayEndUtc);
$prnCounts = [];
foreach ($prnToday as $log) {
    $prnCounts[$log['medication_id']] = ($prnCounts[$log['medication_id']] ?? 0) + (float) $log['amount'];
}

$lowStock = [];
foreach ($meds as $m) {
    $days = stock_days_left($m, $schedules[$m['id']] ?? []);
    if ($days !== null && $days <= (int) $m['low_stock_days']) {
        $lowStock[] = [$m, $days];
    }
}

// Medicines past or near their expiry date.
$expiring = [];
foreach ($meds as $m) {
    [$state, $text] = expiry_status($m['expiry_date'], $today);
    if ($state) {
        $expiring[] = [$m, $state, $text];
    }
}

$soonAppointments = array_slice(appointments_between($uid, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', strtotime('+14 days'))), 0, 3);

// Care: routine tasks due today, grouped by area, and checks coming up, for the categories switched on.
$careAreas = routine_areas($user);
$careTasks = care_tasks($uid);
$careLogs = $careTasks ? care_logs($uid, $today->format('Y-m-d'), $today->format('Y-m-d')) : [];
$careToday = [];
if (in_array('screening', $careAreas, true)) {
    sync_screening($user);
}
$comingUp = checks_coming_up($user, $careAreas, $today);
$cycleNote = in_array('womens', $careAreas, true) ? cycle_today_note($user, $today) : null;
foreach ($careAreas as $key) {
    $areaTasks = array_values(array_filter($careTasks, fn ($t) => $t['area'] === $key));
    $due = array_values(array_filter($areaTasks, fn ($t) => care_due($t, $today)));
    if ($due) {
        $careToday[$key] = ['due' => $due, 'progress' => care_day_progress($due, $careLogs, $today), 'streak' => care_streak($uid, $areaTasks, $today)];
    }
}

$metrics = enabled_metrics($user);
$latest = [];
if ($metrics) {
    $in = implode(',', array_fill(0, count($metrics), '?'));
    $rows = q(
        "SELECT r.* FROM health_readings r
         JOIN (SELECT metric, MAX(recorded_at) AS latest FROM health_readings WHERE user_id = ? AND metric IN ($in) GROUP BY metric) x
           ON x.metric = r.metric AND x.latest = r.recorded_at
         WHERE r.user_id = ?",
        [$uid, ...array_keys($metrics), $uid]
    )->fetchAll();
    foreach ($rows as $r) {
        $latest[$r['metric']] = $r;
    }
}

$done = count(array_filter($slots, fn ($s) => in_array($s['status'], ['taken', 'skipped'], true)));
$taken = count(array_filter($slots, fn ($s) => $s['status'] === 'taken'));
$total = count($slots);
$pct = $total ? (int) round(100 * $done / $total) : 0;

render_header('Today', 'today');
?>
<div class="page-head">
    <div>
        <h1><?= h($today->format('l j F')) ?></h1>
        <p class="muted"><?= $total ? "$taken of $total scheduled doses taken" : 'No scheduled doses today' ?></p>
    </div>
    <?php if ($total): ?>
    <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>" aria-label="Doses dealt with today">
        <svg viewBox="0 0 36 36" aria-hidden="true">
            <circle class="track" cx="18" cy="18" r="15.9"/>
            <circle class="fill" cx="18" cy="18" r="15.9" pathLength="100" stroke-dasharray="<?= $pct ?> 100"/>
        </svg>
        <span><?= $pct ?>%</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($trip = active_trip($user)): ?>
<div class="flash travel-banner" role="status">
    <span>&#9992; <strong>Travel mode:</strong> times are shown in <?= h(tz_place($trip['destination_tz'])) ?> time (<?= h(tz_offset_label($trip['destination_tz'])) ?>)
        until you get back on <?= h(date('D j M', strtotime($trip['end_date']))) ?>. Doses: <?= h(mb_strtolower(TRAVEL_MODES[$trip['mode']][0])) ?>.</span>
    <a class="btn btn-small" href="<?= h(url('travel.php')) ?>">Travel settings</a>
</div>
<?php endif; ?>

<div class="flash install-banner" hidden>
    <span>Add Pillbox to your home screen to use it like an app.</span>
    <span class="inline-actions">
        <a class="btn btn-small btn-primary" href="<?= h(url('account.php#install')) ?>">How</a>
        <button class="btn btn-small" type="button" data-dismiss-install>Not now</button>
    </span>
</div>

<?php foreach ($lowStock as [$m, $days]): ?>
<div class="flash flash-warning" role="status">
    <strong><?= h($m['name']) ?></strong> is running low: <?= h(num($m['stock_count'])) ?> left (about <?= (int) floor($days) ?> day<?= floor($days) == 1 ? '' : 's' ?>). Time to order a repeat prescription.
    <a href="<?= h(url('medications.php#med-' . $m['id'])) ?>">Collected it?</a>
</div>
<?php endforeach; ?>

<?php if ($soonAppointments): ?>
<section class="card">
    <div class="card-head">
        <h2>Upcoming appointments</h2>
        <a class="btn btn-small" href="<?= h(url('appointments.php')) ?>">All appointments</a>
    </div>
    <ul class="plain">
        <?php foreach ($soonAppointments as $a): $local = local_time($a['starts_at'], 'Y-m-d'); ?>
        <li class="logline">
            <span><strong><?= h($local === $today->format('Y-m-d') ? 'Today' : ($local === $today->modify('+1 day')->format('Y-m-d') ? 'Tomorrow' : local_time($a['starts_at'], 'D j M'))) ?>,
                <?= h(local_time($a['starts_at'], 'H:i')) ?></strong> &middot; <a href="<?= h(url('appointment.php?id=' . $a['id'])) ?>"><?= h(appointment_title($a)) ?></a>
                <span class="muted small"><?= $a['with_whom'] ? 'with ' . h($a['with_whom']) : '' ?><?= $a['location'] ? ' · ' . h($a['location']) : '' ?></span></span>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($missedYesterday): ?>
<section class="card">
    <h2>Missed yesterday</h2>
    <ul class="dose-list">
    <?php foreach ($missedYesterday as $slot) { render_dose_item($slot, 'index.php'); } ?>
    </ul>
</section>
<?php endif; ?>

<section class="card">
    <h2>Scheduled doses</h2>
    <?php if (!$slots): ?>
        <p class="muted">Nothing scheduled for today. <a href="<?= h(url('medication_edit.php')) ?>">Add a medication</a> to get started.</p>
    <?php else: ?>
    <ul class="dose-list">
        <?php
        $lastTime = null;
        foreach ($slots as $slot) {
            $time = $slot['local']->format('H:i');
            if ($time !== $lastTime) {
                echo '<li class="dose-time">' . h($time) . '</li>';
                $lastTime = $time;
            }
            render_dose_item($slot, 'index.php');
        }
        ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($prnMeds): ?>
<section class="card">
    <h2>As needed</h2>
    <ul class="dose-list">
    <?php foreach ($prnMeds as $m):
        $usedToday = $prnCounts[$m['id']] ?? 0;
        $atMax = $m['prn_max_daily'] !== null && $usedToday >= (float) $m['prn_max_daily']; ?>
        <li class="dose-item">
            <div class="dose-main">
                <?= med_dot($m['colour']) ?>
                <div>
                    <strong><?= h($m['name']) ?></strong> <?= h($m['strength']) ?>
                    <div class="muted small">
                        <?= $usedToday ? h(dose_label($usedToday, $m['dose_unit'])) . ' today' : 'None today' ?>
                        <?= $m['prn_max_daily'] !== null ? ' · max ' . h(dose_label($m['prn_max_daily'], $m['dose_unit'])) . ' a day' : '' ?>
                        <?= $m['instructions'] ? ' · ' . h($m['instructions']) : '' ?>
                    </div>
                    <?php if ($atMax): ?><div class="warn small">Daily maximum reached &mdash; check with your doctor or pharmacist before taking more.</div><?php endif; ?>
                </div>
            </div>
            <form method="post" action="<?= h(url('dose_action.php')) ?>" class="dose-actions prn-form"<?= $atMax ? ' data-confirm="You have reached the daily maximum for this medicine. Log another dose anyway?"' : '' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="prn">
                <input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="return" value="index.php">
                <label class="sr" for="prn-<?= (int) $m['id'] ?>">Amount</label>
                <input id="prn-<?= (int) $m['id'] ?>" class="amount" type="number" name="amount" min="0.01" step="any" value="<?= h(num($m['prn_dose_amount'] ?? 1)) ?>">
                <span class="unit"><?= h($m['dose_unit']) ?></span>
                <button class="btn btn-primary" type="submit">Log dose</button>
            </form>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php if ($prnToday): ?>
    <h3>Logged today</h3>
    <ul class="plain">
        <?php foreach ($prnToday as $log): ?>
        <li class="logline">
            <span><?= h(local_time($log['taken_at'], 'H:i')) ?> · <?= h($log['name']) ?> · <?= h(dose_label($log['amount'], $log['dose_unit'])) ?></span>
            <form method="post" action="<?= h(url('dose_action.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="undo">
                <input type="hidden" name="log_id" value="<?= (int) $log['id'] ?>">
                <input type="hidden" name="return" value="index.php">
                <button class="btn-link" type="submit">Undo</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($comingUp || $cycleNote || $expiring): ?>
<section class="card" id="coming-up">
    <div class="card-head">
        <h2>Coming up</h2>
        <a class="btn btn-small" href="<?= h(url('care.php')) ?>">Care</a>
    </div>
    <ul class="dose-list">
        <?php foreach ($expiring as [$m, $state, $text]): ?>
        <li class="dose-item">
            <div class="dose-main">
                <?= med_dot($m['colour']) ?>
                <div>
                    <strong><?= h($m['name']) ?></strong> <?= h($m['strength']) ?>
                    <div class="small warn"><?= h($text) ?><?= $state === 'expired' ? '. Check with a pharmacist before using it, and take expired medicines back to a pharmacy.' : '' ?></div>
                </div>
            </div>
            <div class="dose-actions"><a class="btn btn-small" href="<?= h(url('medications.php#med-' . $m['id'])) ?>">New supply</a></div>
        </li>
        <?php endforeach; ?>
        <?php if ($cycleNote): ?>
        <li class="dose-item">
            <div class="dose-main"><div><?= CARE_AREAS['womens']['icon'] ?> <strong><?= h($cycleNote) ?></strong></div></div>
            <div class="dose-actions"><a class="btn btn-small" href="<?= h(url('care.php?area=womens#cycle')) ?>">Log a period</a></div>
        </li>
        <?php endif; ?>
        <?php foreach ($comingUp as $c): $chk = $c['check']; ?>
        <li class="dose-item">
            <div class="dose-main">
                <div>
                    <?= CARE_AREAS[$chk['area']]['icon'] ?> <a href="<?= h(url('care.php?area=' . $chk['area'] . '#check-' . $chk['id'])) ?>"><strong><?= h($chk['label']) ?></strong></a>
                    <div class="small<?= $c['status'] === 'overdue' ? ' warn' : ' muted' ?>"><?= h(check_due_text($chk, $c['due'], $today)) ?></div>
                </div>
            </div>
            <?php if (!in_array($chk['track'], ['due', 'kit'], true)): ?>
            <div class="dose-actions"><a class="btn btn-small" href="<?= h(url('care.php?area=' . $chk['area'] . '#check-' . $chk['id'])) ?>">Update</a></div>
            <?php else: ?>
            <form method="post" action="<?= h(url('care.php')) ?>" class="dose-actions">
                <?= csrf_field() ?><input type="hidden" name="action" value="check_done"><input type="hidden" name="check_id" value="<?= (int) $chk['id'] ?>">
                <input type="hidden" name="date" value="<?= h($today->format('Y-m-d')) ?>"><input type="hidden" name="return" value="index.php#coming-up">
                <button class="btn btn-small" type="submit" aria-label="<?= h($chk['label']) ?>: done today"><?= $chk['track'] === 'kit' ? 'Checked today' : 'Done today' ?></button>
            </form>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php foreach ($careToday as $key => $c): $area = CARE_AREAS[$key]; [$careDone, $careDue] = $c['progress']; ?>
<section class="card" id="care-<?= h($key) ?>">
    <div class="card-head">
        <h2><?= $area['icon'] ?> <?= h($area['title']) ?> <span class="muted small">(<?= $careDone ?> of <?= $careDue ?> done<?= $c['streak'] > 1 ? ' · ' . $c['streak'] . ' days in a row' : '' ?>)</span></h2>
        <span class="inline-actions">
            <?php if ($key === 'sleep' && !sleep_nights($uid, $today)): ?>
            <a class="btn btn-small btn-primary" href="<?= h(url('care.php?area=sleep#log')) ?>">Log last night</a>
            <?php endif; ?>
            <a class="btn btn-small" href="<?= h(url('care.php?area=' . $key)) ?>">Routine</a>
        </span>
    </div>
    <ul class="dose-list">
    <?php foreach ($c['due'] as $t):
        $times = care_times($t);
        $doneSlots = array_filter(array_keys($times), fn ($slot) => care_done($careLogs, $t, $today, $slot)); ?>
        <li class="dose-item<?= count($doneSlots) === count($times) ? ' status-taken' : '' ?>">
            <div class="dose-main">
                <div>
                    <strong><?= h($t['label']) ?></strong><?= $t['amount'] ? ' <span class="amount-chip">' . h($t['amount']) . '</span>' : '' ?>
                    <?php if ($t['tip']): ?><div class="muted small"><?= h($t['tip']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="dose-actions">
                <?php foreach ($times as $slot => $time):
                    $done = in_array($slot, $doneSlots, true);
                    $name = count($times) === 1 ? ($done ? 'Done' : 'Mark done') : care_time_label($time); ?>
                <form method="post" action="<?= h(url('care.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="date" value="<?= h($today->format('Y-m-d')) ?>"><input type="hidden" name="slot" value="<?= (int) $slot ?>">
                    <input type="hidden" name="done" value="<?= $done ? '0' : '1' ?>"><input type="hidden" name="return" value="index.php#care-<?= h($key) ?>">
                    <button class="btn btn-small<?= $done ? ' btn-primary' : '' ?>" type="submit" aria-pressed="<?= $done ? 'true' : 'false' ?>"
                        title="<?= h($time) ?>" aria-label="<?= h($t['label'] . ', ' . $time . ($done ? ', done' : '')) ?>"><?= $done ? '&#10003; ' : '' ?><?= h($name) ?></button>
                </form>
                <?php endforeach; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>
</section>
<?php endforeach; ?>
<?php if (!$careTasks && !$comingUp && !$expiring && !$user['care_prompt_hidden']): ?>
<section class="card care-invite">
    <div class="card-head">
        <h2>Daily care routines</h2>
        <form method="post" action="<?= h(url('care.php')) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="hide_prompt"><input type="hidden" name="return" value="index.php">
            <button class="btn btn-small" type="submit">Not now</button>
        </form>
    </div>
    <p class="muted small">Tick off daily routines like brushing, physio exercises or a regular bedtime, and keep track of check-ups, screening and vaccinations.</p>
    <span class="inline-actions">
        <a class="btn btn-small btn-primary" href="<?= h(url('care.php')) ?>">Set up care</a>
        <a class="btn btn-small" href="<?= h(url('account.php#health-profile')) ?>">Choose categories</a>
    </span>
</section>
<?php endif; ?>

<?php if ($metrics): ?>
<section class="card">
    <div class="card-head">
        <h2>Health readings</h2>
        <a class="btn btn-small" href="<?= h(url('readings.php')) ?>">All readings</a>
    </div>
    <div class="tiles">
        <?php foreach ($metrics as $key => $def): ?>
        <a class="tile" href="<?= h(url('readings.php?metric=' . urlencode($key))) ?>">
            <span class="tile-label"><?= h($def['label']) ?></span>
            <?php if (isset($latest[$key])): ?>
                <span class="tile-value"><?= h(format_reading($def, $latest[$key])) ?></span>
                <span class="tile-meta"><?= h(local_time($latest[$key]['recorded_at'], 'j M, H:i')) ?></span>
            <?php else: ?>
                <span class="tile-value muted">—</span>
                <span class="tile-meta">Tap to record</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php
render_footer();
