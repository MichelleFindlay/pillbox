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

$soonAppointments = array_slice(appointments_between($uid, gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', strtotime('+14 days'))), 0, 3);

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
