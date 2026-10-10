<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$todayYmd = local_today()->format('Y-m-d');

if (is_post() && post('action') === 'show_changes') {
    require_post();
    q('UPDATE users SET meds_show_changes = ? WHERE id = ?', [post('show') === '1' ? 1 : 0, $uid]);
    redirect('medications.php');
}
if (is_post()) {
    require_post();
    $med = find_medication((int) post('id'), $uid);
    if (!$med) {
        not_found();
    }
    switch (post('action')) {
        case 'archive':
            // Keep history; stop the medication from today.
            q('UPDATE medications SET is_active = 0, end_date = LEAST(COALESCE(end_date, ?), ?) WHERE id = ?', [$todayYmd, $todayYmd, $med['id']]);
            record_med_change($uid, (int) $med['id'], $todayYmd, 'stopped', 'Stopped');
            flash('success', $med['name'] . ' stopped. Its history is kept for your reports.');
            break;
        case 'restore':
            q('UPDATE medications SET is_active = 1, end_date = NULL WHERE id = ?', [$med['id']]);
            record_med_change($uid, (int) $med['id'], $todayYmd, 'restarted', 'Restarted');
            flash('success', $med['name'] . ' restarted.');
            break;
        case 'restock':
            // Prescription collected (add to stock) or a recount (set the exact number).
            $kind = post('kind') === 'recount' ? 'recount' : 'collected';
            $qty = post('quantity');
            if (!is_numeric($qty) || (float) $qty < 0 || (float) $qty > 100000 || ($kind === 'collected' && (float) $qty <= 0)) {
                flash('error', 'Enter how many ' . substr(dose_label(2, $med['dose_unit']), 2) . ' as a number' . ($kind === 'collected' ? ' greater than zero.' : '.'));
                redirect('medications.php#med-' . $med['id']);
            }
            $qty = (float) $qty;
            $expiry = post('expiry_date') === '' ? null : parse_date(post('expiry_date'));
            if (post('expiry_date') !== '' && !$expiry) {
                flash('error', 'Enter the expiry date as a date, or leave it empty.');
                redirect('medications.php#med-' . $med['id']);
            }
            if ($expiry) {
                q('UPDATE medications SET expiry_date = ? WHERE id = ?', [$expiry->format('Y-m-d'), $med['id']]);
            }
            $paid = $kind === 'collected' && $med['supply_type'] !== 'nhs' ? trim(post('paid')) : '';
            if ($paid !== '' && (!is_numeric($paid) || (float) $paid < 0 || (float) $paid > 99999)) {
                flash('error', 'Enter what you paid as an amount in pounds, or leave it empty.');
                redirect('medications.php#med-' . $med['id']);
            }
            if ($paid !== '' && (float) $paid > 0) {
                q('INSERT INTO prescription_payments (user_id, paid_on, items, amount, note, kind, medication_id) VALUES (?, ?, 1, ?, ?, ?, ?)',
                    [$uid, $todayYmd, round((float) $paid, 2), mb_substr($med['name'], 0, 200), $med['supply_type'], $med['id']]);
            }
            $after = transaction(function () use ($med, $kind, $qty, $uid) {
                $current = q('SELECT stock_count FROM medications WHERE id = ? FOR UPDATE', [$med['id']])->fetchColumn();
                $after = $kind === 'collected' ? (float) $current + $qty : $qty;
                q('UPDATE medications SET stock_count = ? WHERE id = ?', [$after, $med['id']]);
                q('INSERT INTO stock_events (user_id, medication_id, kind, quantity, stock_after, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$uid, $med['id'], $kind, $qty, $after]);
                return $after;
            });
            $days = stock_days_left(['stock_count' => $after] + $med, schedules_by_medication([(int) $med['id']], $todayYmd)[$med['id']] ?? []);
            flash('success', ($kind === 'collected' ? 'Added ' . dose_label($qty, $med['dose_unit']) . ' to ' : 'Stock count updated for ')
                . $med['name'] . '. You now have ' . dose_label($after, $med['dose_unit'])
                . ($days !== null ? ' (about ' . (int) floor($days) . ' days).' : '.')
                . ($expiry ? ' Expiry date set to ' . $expiry->format('j M Y') . '.' : '')
                . ($paid !== '' && (float) $paid > 0 ? ' Logged £' . number_format((float) $paid, 2) . ' paid.' : ''));
            redirect('medications.php#med-' . $med['id']);
        case 'delete':
            owner_only('Permanently deleting a medicine');
            q('DELETE FROM medications WHERE id = ?', [$med['id']]);
            flash('success', $med['name'] . ' and all of its dose history were deleted.');
            break;
    }
    redirect('medications.php');
}

$meds = user_medications($uid, false);
$schedules = schedules_by_medication(array_column($meds, 'id'), $todayYmd);
$active = array_filter($meds, fn ($m) => $m['is_active']);
$latestChanges = latest_med_changes($uid);
// Most recent collection per medication, to show and to pre-fill the next one.
$lastCollected = [];
foreach (q(
    "SELECT e.* FROM stock_events e
     JOIN (SELECT medication_id, MAX(id) AS id FROM stock_events WHERE user_id = ? AND kind = 'collected' GROUP BY medication_id) x ON x.id = e.id",
    [$uid]
) as $e) {
    $lastCollected[$e['medication_id']] = $e;
}
$stopped = array_filter($meds, fn ($m) => !$m['is_active']);
// Checks linked to a medicine (e.g. blood tests it needs).
$medChecks = [];
foreach (q('SELECT * FROM health_checks WHERE user_id = ? AND medication_id IS NOT NULL AND hidden = 0', [$uid]) as $c) {
    $medChecks[$c['medication_id']][] = $c;
}

render_header('Medications', 'meds');
?>
<div class="page-head">
    <div>
        <h1>Medications</h1>
        <form method="post" class="show-changes">
            <?= csrf_field() ?><input type="hidden" name="action" value="show_changes"><input type="hidden" name="show" value="0">
            <label class="check small"><input type="checkbox" name="show" value="1" data-autosubmit<?= checked((bool) $user['meds_show_changes']) ?>> Show dose changes</label>
            <noscript><button class="btn btn-small" type="submit">Apply</button></noscript>
        </form>
    </div>
    <span class="inline-actions">
        <a class="btn" href="<?= h(url('travel.php')) ?>">&#9992; Travel</a>
        <a class="btn btn-primary" href="<?= h(url('medication_edit.php')) ?>">+ Add medication</a>
    </span>
</div>

<?php if (!$active): ?>
<section class="card empty">
    <p>You have no current medications.</p>
    <p><a class="btn btn-primary" href="<?= h(url('medication_edit.php')) ?>">Add your first medication</a></p>
</section>
<?php endif; ?>

<div class="med-grid">
<?php foreach ($active as $m):
    $ms = $schedules[$m['id']] ?? [];
    $daysLeft = stock_days_left($m, $ms); ?>
    <article class="card med-card" id="med-<?= (int) $m['id'] ?>">
        <header class="med-head">
            <?= med_dot($m['colour']) ?>
            <div>
                <h2><?= h($m['name']) ?> <span class="muted"><?= h($m['strength']) ?></span></h2>
                <p class="muted small"><?= h(ucfirst($m['form'])) ?><?= $m['reason'] ? ' · for ' . h($m['reason']) : '' ?></p>
                <?php if ($m['supply_type'] !== 'nhs'): ?><p><span class="badge"><?= h($m['supply_type'] === 'private' ? 'Private prescription' : 'Bought') ?><?= $m['supply_price'] !== null ? ' · £' . number_format((float) $m['supply_price'], 2) : '' ?></span></p><?php endif; ?>
            </div>
        </header>
        <?php if ($m['as_needed']): ?>
            <p><span class="badge">As needed</span> <?= $m['prn_dose_amount'] ? h(dose_label($m['prn_dose_amount'], $m['dose_unit'])) . ' per dose' : '' ?>
                <?= $m['prn_max_daily'] ? '· max ' . h(dose_label($m['prn_max_daily'], $m['dose_unit'])) . ' a day' : '' ?></p>
        <?php elseif ($ms): ?>
            <ul class="schedule-list">
                <?php foreach (schedule_lines($ms, $m['dose_unit']) as $line): ?><li><?= h($line) ?></li><?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="warn small">No dose times set.</p>
        <?php endif; ?>
        <?php if ($m['instructions']): ?><p class="small"><?= h($m['instructions']) ?></p><?php endif; ?>
        <p class="muted small">
            <?php if ($m['stock_count'] !== null): ?>
                Stock: <?= h(num($m['stock_count'])) ?><?= $daysLeft !== null ? ' (~' . (int) floor($daysLeft) . ' days)' : '' ?> ·
            <?php endif; ?>
            <?php if (isset($lastCollected[$m['id']])): $lc = $lastCollected[$m['id']]; ?>
                Last collected <?= h(local_time($lc['created_at'], 'j M')) ?> (<?= h(dose_label($lc['quantity'], $m['dose_unit'])) ?>) ·
            <?php endif; ?>
            <?php [$expState, $expText] = expiry_status($m['expiry_date'], local_today()); if ($expText): ?>
                <span class="<?= $expState ? 'warn' : '' ?>"><?= $expState === 'expired' ? '&#9888; ' : '' ?><?= h($expText) ?></span> ·
            <?php endif; ?>
            Since <?= h(date('j M Y', strtotime($m['start_date']))) ?><?= $m['end_date'] ? ' until ' . h(date('j M Y', strtotime($m['end_date']))) : '' ?>
            <?= $m['prescriber'] ? ' · ' . h($m['prescriber']) : '' ?>
        </p>
        <details class="restock">
            <summary class="btn btn-small btn-primary"><?= $m['supply_type'] === 'otc' ? 'Bought more' : 'Prescription collected' ?></summary>
            <form method="post" class="restock-form">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <input type="hidden" name="action" value="restock">
                <div class="radios" role="radiogroup" aria-label="Type of update">
                    <label class="check"><input type="radio" name="kind" value="collected" checked> Add what I collected</label>
                    <label class="check"><input type="radio" name="kind" value="recount"> Set the exact number I have</label>
                </div>
                <div class="restock-row">
                    <label>How many <?= h(substr(dose_label(2, $m['dose_unit']), 2)) ?>
                        <input type="number" name="quantity" min="0" step="any" inputmode="decimal" required
                            value="<?= isset($lastCollected[$m['id']]) ? h(num($lastCollected[$m['id']]['quantity'])) : '' ?>" placeholder="e.g. 56">
                    </label>
                    <label><span>Expiry date <span class="muted small">(optional)</span></span>
                        <input type="date" name="expiry_date" value=""></label>
                    <?php if ($m['supply_type'] !== 'nhs'): ?>
                    <label><span>Paid (&pound;) <span class="muted small">(optional, for new supplies)</span></span>
                        <input type="number" name="paid" min="0" step="0.01" inputmode="decimal" value="<?= $m['supply_price'] !== null ? h(number_format((float) $m['supply_price'], 2, '.', '')) : '' ?>"></label>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Update stock</button>
                </div>
                <p class="muted small">Currently <?= $m['stock_count'] !== null ? h(dose_label($m['stock_count'], $m['dose_unit'])) : 'not tracked' ?>.</p>
            </form>
        </details>
        <?php if ($user['meds_show_changes'] && isset($latestChanges[$m['id']]) && $latestChanges[$m['id']]['kind'] !== 'started'): $lc = $latestChanges[$m['id']]; ?>
        <p class="small last-change">Changed <?= h(date('j M Y', strtotime($lc['effective_date']))) ?>: <?= h($lc['summary']) ?></p>
        <?php endif; ?>
        <?php foreach ($medChecks[$m['id']] ?? [] as $c): [$due] = check_next_due($c, $user, local_today()); $st = check_status($due, local_today()); ?>
        <p class="small<?= in_array($st, ['overdue', 'soon'], true) ? ' warn' : '' ?>">&#x1F9EA; <a href="<?= h(url('care.php?area=' . $c['area'] . '#check-' . $c['id'])) ?>"><?= h($c['label']) ?></a>:
            <?= h(lcfirst(check_due_text($c, $due, local_today()) ?: 'add when it was last done')) ?></p>
        <?php endforeach; ?>
        <?php if ($m['mental_health'] && mind_enabled($user)): ?>
        <p class="small"><a href="<?= h(url('mind_meds.php#med-' . $m['id'])) ?>">Side effects and how I'm feeling</a></p>
        <?php endif; ?>
        <footer class="med-actions">
            <a class="btn btn-small" href="<?= h(url('medication_edit.php?id=' . $m['id'])) ?>">Edit</a>
            <a class="btn btn-small" href="<?= h(url('medication_history.php?id=' . $m['id'])) ?>">History</a>
            <form method="post" data-confirm="Stop taking <?= h($m['name']) ?>? It will be removed from your daily list but its history is kept.">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                <button class="btn btn-small" name="action" value="archive">Stop</button>
            </form>
        </footer>
    </article>
<?php endforeach; ?>
</div>

<?php if ($stopped): ?>
<details class="card">
    <summary><h2>Stopped medications (<?= count($stopped) ?>)</h2></summary>
    <ul class="plain">
    <?php foreach ($stopped as $m): ?>
        <li class="logline">
            <span><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <?= h($m['strength']) ?>
                <span class="muted small"><?= $m['end_date'] ? 'stopped ' . h(date('j M Y', strtotime($m['end_date']))) : '' ?></span></span>
            <span class="inline-actions">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn-small" name="action" value="restore">Restart</button>
                </form>
                <a class="btn btn-small" href="<?= h(url('medication_history.php?id=' . $m['id'])) ?>">History</a>
                <form method="post" data-confirm="Permanently delete <?= h($m['name']) ?> and ALL of its dose history? This cannot be undone.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                    <button class="btn btn-small btn-danger" name="action" value="delete">Delete</button>
                </form>
            </span>
        </li>
    <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<?php render_footer();
