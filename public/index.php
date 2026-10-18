<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
finish_courses($user);
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

// Shared access: only the parts that have been shared.
$canDoses = share_can('doses');
if (!$canDoses) {
    $slots = $missedYesterday = $prnMeds = $prnToday = $lowStock = $expiring = [];
    $total = $taken = $done = $pct = 0;
}
if (!share_can('appointments')) {
    $soonAppointments = [];
}
if (!share_can('readings')) {
    $metrics = [];
}
if (!share_area_allowed('womens')) {
    $cycleNote = null;
}

render_header('Today', 'today');
?>
<div class="page-head">
    <div>
        <h1><?= h($today->format('l j F')) ?> <?= help_link('today', '', 'Today') ?></h1>
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

<?php if (!isset($user['_acting']) && $user['is_admin'] && ($waiting = count(pending_signups()))): ?>
<div class="flash flash-info notice-row" role="status">
    <span><?= $waiting === 1 ? 'Someone has' : "$waiting people have" ?> signed up and <?= $waiting === 1 ? 'is' : 'are' ?> waiting for you to approve them.</span>
    <a class="btn btn-small" href="<?= h(url('admin.php#signups')) ?>">Review</a>
</div>
<?php endif; ?>

<?php if (!isset($user['_acting']) && ($dmProfile = diabetes_profile($uid)) && $dmProfile['sick_since']): ?>
<div class="flash flash-warning notice-row" role="status">
    <span><strong>Sick-day rules are on.</strong> Check your glucose every 2 to 4 hours<?= $dmProfile['type'] === '1' ? ', and ketones too if over 13' : '' ?>, and keep sipping fluids.</span>
    <a class="btn btn-small" href="<?= h(url('care.php?area=diabetes#dm-sick')) ?>">Sick-day rules</a>
</div>
<?php endif; ?>

<?php if (in_array('migraine', $careAreas, true) && ($ongoingHeadache = q('SELECT * FROM headache_episodes WHERE user_id = ? AND ended_at IS NULL AND started_at > ? ORDER BY started_at DESC LIMIT 1', [$uid, gmdate('Y-m-d H:i:s', time() - 3 * 86400)])->fetch())): ?>
<div class="flash flash-info notice-row" role="status">
    <span>Headache since <?= h(local_time($ongoingHeadache['started_at'], 'D H:i')) ?>.</span>
    <a class="btn btn-small" href="<?= h(url('care.php?area=migraine#mg-diary')) ?>">Update</a>
</div>
<?php endif; ?>

<?php if (in_array('migraine', $careAreas, true) && weather_enabled() && ($fallAhead = weather_fall_ahead($uid, local_today()))
    && (weather_patterns($uid, local_today())['rows']['fall']['likelier'] ?? false)): ?>
<div class="flash flash-info notice-row" role="status">
    <span>Air pressure is forecast to fall <?= $fallAhead[0] === local_today()->format('Y-m-d') ? 'today' : 'tomorrow' ?>. Your headaches have been more likely on days like this: keep your usual treatment to hand.</span>
    <a class="btn btn-small" href="<?= h(url('care.php?area=migraine#mg-weather')) ?>">Weather</a>
</div>
<?php endif; ?>

<div class="flash install-banner" hidden>
    <span>Add Pillbox to your home screen to use it like an app.</span>
    <span class="inline-actions">
        <a class="btn btn-small btn-primary" href="<?= h(url('account.php#install')) ?>">How</a>
        <button class="btn btn-small" type="button" data-dismiss-install>Not now</button>
    </span>
</div>

<?php if ($lowStock): $lastCollected = last_collected($uid); $canLog = share_can('doses', 'log'); endif; ?>
<?php foreach ($lowStock as [$m, $days]):
    $left = h(num($m['stock_count'])) . ' left (about ' . (int) floor($days) . ' day' . (floor($days) == 1 ? '' : 's') . ')';
    $hidden = csrf_field() . '<input type="hidden" name="id" value="' . (int) $m['id'] . '"><input type="hidden" name="return" value="index.php#low-' . (int) $m['id'] . '">'; ?>
<div class="flash <?= $m['ordered_on'] ? 'flash-info' : 'flash-warning' ?> low-stock" role="status" id="low-<?= (int) $m['id'] ?>">
    <ol class="steps" aria-label="Steps"><li class="<?= $m['ordered_on'] ? 'done' : 'current' ?>"><?= $m['controlled'] ? 'Ask for a prescription' : 'Order' ?></li>
        <li class="<?= $m['ordered_on'] ? 'current' : '' ?>">Collect<?= $m['controlled'] && $m['rx_written'] ? ' by ' . h((new DateTimeImmutable($m['rx_written']))->modify('+27 days')->format('j M')) : '' ?></li><li>Log the stock</li></ol>
    <?php if (!$m['ordered_on']): ?>
    <span><strong><?= h($m['name']) ?></strong> is running low: <?= $left ?>. Time to order <?= $m['supply_type'] === 'otc' ? 'more' : 'a repeat prescription' ?>.</span>
    <?php if ($canLog): ?>
    <span class="inline-actions">
        <form method="post" action="<?= h(url('medications.php')) ?>"><?= $hidden ?><input type="hidden" name="action" value="ordered">
            <button class="btn btn-small btn-primary" type="submit">Ordered</button></form>
        <a class="btn btn-small" href="<?= h(url('medications.php#med-' . $m['id'])) ?>">Collected</a>
    </span>
    <?php endif; ?>
    <?php else: ?>
    <span><strong><?= h($m['name']) ?></strong>: <?= $m['supply_type'] === 'otc' ? 'more' : 'repeat' ?> ordered <?= h(date('j M', strtotime($m['ordered_on']))) ?>, waiting to collect. <?= $left ?>.</span>
    <?php if ($canLog): ?>
    <details class="collect">
        <summary class="btn btn-small btn-primary">Collected</summary>
        <form method="post" action="<?= h(url('medications.php')) ?>" class="inline-form"><?= $hidden ?>
            <input type="hidden" name="action" value="restock"><input type="hidden" name="kind" value="collected">
            <label>How many <?= h(substr(dose_label(2, $m['dose_unit']), 2)) ?>
                <input type="number" name="quantity" min="0" step="any" inputmode="decimal" required class="count-input"
                    value="<?= isset($lastCollected[$m['id']]) ? h(num($lastCollected[$m['id']]['quantity'])) : '' ?>" placeholder="e.g. 56"></label>
            <label><span>Expiry date <span class="muted small">(optional)</span></span><input type="date" name="expiry_date"></label>
            <?php if ($m['supply_type'] !== 'nhs'): ?>
            <label>Paid (&pound;) <input type="number" name="paid" min="0" step="0.01" inputmode="decimal" class="count-input"
                value="<?= $m['supply_price'] !== null ? h(number_format((float) $m['supply_price'], 2, '.', '')) : '' ?>"></label>
            <?php endif; ?>
            <button class="btn btn-primary btn-small" type="submit">Add to stock</button>
        </form>
        <form method="post" action="<?= h(url('medications.php')) ?>"><?= $hidden ?><input type="hidden" name="action" value="unorder">
            <button class="btn-link small" type="submit">Not ordered after all</button></form>
    </details>
    <?php endif; ?>
    <?php endif; ?>
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
    <h2>Yesterday, not logged</h2>
    <p class="muted small">Took them? Log them now with the time you took them. Skipped them? That's fine too: just say so.</p>
    <ul class="dose-list">
    <?php foreach ($missedYesterday as $slot) { render_dose_item($slot, 'index.php'); } ?>
    </ul>
</section>
<?php endif; ?>

<?php if ($canDoses): ?>
<section class="card">
    <h2>Scheduled doses <?= help_link('today', 'doses', 'logging doses') ?></h2>
    <?php if (!$slots): ?>
        <p class="muted">Nothing scheduled for today. <a href="<?= h(url('medication_edit.php')) ?>">Add a medication</a> to get started.</p>
    <?php else: ?>
    <?php
    $nowLocal = new DateTimeImmutable('now', $tz);
    $nowDoses = array_filter($slots, fn ($s) => !$s['log'] && in_array($s['status'], ['due', 'missed'], true));
    $nextDose = array_values(array_filter($slots, fn ($s) => !$s['log'] && $s['status'] === 'upcoming'))[0] ?? null; ?>
    <div class="now-next">
        <div><span class="tile-label">Now</span>
            <strong><?= $nowDoses ? h(implode(', ', array_map(fn ($s) => $s['schedule']['name'], $nowDoses))) : 'Nothing to take right now' ?></strong></div>
        <div><span class="tile-label">Next</span>
            <?php if ($nextDose): $mins = (int) round(($nextDose['local']->getTimestamp() - $nowLocal->getTimestamp()) / 60); ?>
            <strong><?= h($nextDose['local']->format('H:i')) ?> <?= h($nextDose['schedule']['name']) ?></strong>
            <span class="muted small">in <?= $mins >= 60 ? intdiv($mins, 60) . ' h ' . ($mins % 60 ? ($mins % 60) . ' min' : '') : max(1, $mins) . ' min' ?></span>
            <?php else: ?><strong>Nothing else today</strong><?php endif; ?></div>
        <a class="btn btn-small" href="<?= h(url('timer.php' . ($nextDose ? '?until=' . $nextDose['local']->getTimestamp() : ''))) ?>"><?= $nextDose ? 'Timer to next dose' : 'Timer' ?></a>
    </div>
    <?= render_day_timeline($slots, $nowLocal) ?>
    <?php
    // Grouped by part of the day, each with one button for every dose in it not yet recorded.
    $blocks = [];
    foreach ($slots as $slot) {
        $blocks[dose_block($slot['local']->format('H:i'))][] = $slot;
    }
    foreach (DOSE_BLOCKS as $blockKey => $block):
        if (empty($blocks[$blockKey])) {
            continue;
        }
        $open = array_filter($blocks[$blockKey], fn ($s) => $s['log'] === null); ?>
    <div class="dose-block" id="block-<?= h($blockKey) ?>">
        <div class="dose-block-head">
            <h3><?= h($block['label']) ?></h3>
            <?php if (count($open) > 1 && share_can('doses', 'log')): ?>
            <form method="post" action="<?= h(url('dose_action.php')) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="take_block"><input type="hidden" name="return" value="index.php#block-<?= h($blockKey) ?>">
                <?php foreach ($open as $slot): ?><input type="hidden" name="slots[]" value="<?= h($slot['schedule']['id'] . '|' . $slot['utc']) ?>"><?php endforeach; ?>
                <button class="btn btn-primary btn-small" type="submit">Take all <?= h(mb_strtolower($block['label'])) ?> (<?= count($open) ?>)</button>
            </form>
            <?php endif; ?>
        </div>
        <ul class="dose-list">
            <?php
            $lastTime = null;
            foreach ($blocks[$blockKey] as $slot) {
                $time = $slot['local']->format('H:i');
                if ($time !== $lastTime) {
                    echo '<li class="dose-time">' . h($time) . '</li>';
                    $lastTime = $time;
                }
                render_dose_item($slot, 'index.php#block-' . $blockKey);
            }
            ?>
        </ul>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($prnMeds): ?>
<section class="card">
    <h2>As needed</h2>
    <ul class="dose-list">
    <?php foreach ($prnMeds as $m):
        $usedToday = $prnCounts[$m['id']] ?? 0;
        $atMax = $m['prn_max_daily'] !== null && $usedToday >= (float) $m['prn_max_daily'];
        $soon = dose_too_soon($m, utc_now()); ?>
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
                    <?php if ($soon): ?><div class="warn small">Next dose from <?= h(local_time($soon['next'], local_time($soon['next'], 'Y-m-d') === local_today()->format('Y-m-d') ? 'H:i' : 'D H:i')) ?> (at least <?= h(gap_label($soon['minutes'])) ?> between doses)</div>
                    <?php elseif ($m['min_interval_minutes']): ?><div class="muted small">At least <?= h(gap_label((int) $m['min_interval_minutes'])) ?> between doses</div><?php endif; ?>
                </div>
            </div>
            <?php if (share_can('doses', 'log')): ?>
            <form method="post" action="<?= h(url('dose_action.php')) ?>" class="dose-actions prn-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="prn">
                <input type="hidden" name="medication_id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="return" value="index.php">
                <label class="sr" for="prn-<?= (int) $m['id'] ?>">Amount</label>
                <input id="prn-<?= (int) $m['id'] ?>" class="amount" type="number" name="amount" min="0.01" step="any" value="<?= h(num($m['prn_dose_amount'] ?? 1)) ?>">
                <span class="unit"><?= h($m['dose_unit']) ?></span>
                <button class="btn btn-primary" type="submit">Log dose</button>
            </form>
            <?php endif; ?>
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

<?php if (in_array('pregnancy', $careAreas, true) && ($preg = active_pregnancy($uid))): $pregNext = pregnancy_next(pregnancy_timeline($preg, $today)); ?>
<section class="card">
    <div class="card-head">
        <h2>&#x1F930; <?= h(gestation_label($preg, $today)) ?></h2>
        <a class="btn btn-small" href="<?= h(url('pregnancy.php')) ?>">Pregnancy</a>
    </div>
    <p>Due <?= h(date('j F', strtotime($preg['due_date']))) ?><?php if ($pregNext): ?> · Next: <a href="<?= h(url('pregnancy.php#item-' . $pregNext['key'])) ?>"><?= h($pregNext['label']) ?></a>
        <span class="muted small"><?= $pregNext['state'] === 'now' ? 'now' : 'from ' . h($pregNext['from_date']->format('j M')) ?></span><?php endif; ?></p>
    <p class="small"><a href="<?= h(url('pregnancy.php#call')) ?>">Something not right? Who to call</a></p>
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
        <h2><?= $area['icon'] ?> <?= h($area['title']) ?> <span class="muted small">(<?= $careDone ?> of <?= $careDue ?> done<?= $c['streak'] > 1 && !$user['hide_streaks'] ? ' · ' . $c['streak'] . ' days in a row' : '' ?>)</span></h2>
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
<?php if (!$careTasks && !$comingUp && !$expiring && !$user['care_prompt_hidden'] && !isset($user['_acting'])): ?>
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
