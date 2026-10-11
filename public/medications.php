<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
finish_courses($user);
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
    // Today's running-low notice posts here too, and goes back there.
    $back = safe_return(post('return'), 'medications.php#med-' . $med['id']);
    switch (post('action')) {
        case 'rx_written':
            $day = parse_date(post('date'));
            q('UPDATE medications SET rx_written = ?, ordered_on = COALESCE(ordered_on, ?) WHERE id = ?', [$day?->format('Y-m-d'), $day?->format('Y-m-d'), $med['id']]);
            flash('success', $day ? 'Prescription dated ' . $day->format('j M') . ': collect it by ' . $day->modify('+27 days')->format('j M') . '.' : 'Cleared.');
            redirect($back);
        case 'supply_note':
            if (trim(post('note')) !== '') {
                q("INSERT INTO med_notes (user_id, medication_id, kind, note, created_at) VALUES (?, ?, 'supply', ?, UTC_TIMESTAMP())",
                    [$uid, $med['id'], trim(mb_substr(post('note'), 0, 1000))]);
            }
            redirect($back);
        case 'ordered':
            q('UPDATE medications SET ordered_on = ? WHERE id = ?', [$todayYmd, $med['id']]);
            flash('success', $med['name'] . ': repeat marked as ordered. Press Collected when you pick it up.');
            redirect($back);
        case 'unorder':
            q('UPDATE medications SET ordered_on = NULL WHERE id = ?', [$med['id']]);
            flash('success', $med['name'] . ': no longer marked as ordered.');
            redirect($back);
        case 'archive':
            // Keep history; stop the medication from today.
            q('UPDATE medications SET is_active = 0, end_date = LEAST(COALESCE(end_date, ?), ?) WHERE id = ?', [$todayYmd, $todayYmd, $med['id']]);
            record_med_change($uid, (int) $med['id'], $todayYmd, 'stopped', 'Stopped');
            flash('success', $med['name'] . ' stopped. Its history is kept for your reports.');
            break;
        case 'restore':
            q("UPDATE medications SET is_active = 1, end_date = NULL, course = 'ongoing' WHERE id = ?", [$med['id']]);
            record_med_change($uid, (int) $med['id'], $todayYmd, 'restarted', 'Restarted');
            flash('success', $med['name'] . ' restarted.');
            break;
        case 'restock':
            // Prescription collected (add to stock) or a recount (set the exact number).
            $kind = post('kind') === 'recount' ? 'recount' : 'collected';
            $qty = post('quantity');
            if (!is_numeric($qty) || (float) $qty < 0 || (float) $qty > 100000 || ($kind === 'collected' && (float) $qty <= 0)) {
                flash('error', 'Enter how many ' . substr(dose_label(2, $med['dose_unit']), 2) . ' as a number' . ($kind === 'collected' ? ' greater than zero.' : '.'));
                redirect($back);
            }
            $qty = (float) $qty;
            $expiry = post('expiry_date') === '' ? null : parse_date(post('expiry_date'));
            if (post('expiry_date') !== '' && !$expiry) {
                flash('error', 'Enter the expiry date as a date, or leave it empty.');
                redirect($back);
            }
            if ($expiry) {
                q('UPDATE medications SET expiry_date = ? WHERE id = ?', [$expiry->format('Y-m-d'), $med['id']]);
            }
            $paid = $kind === 'collected' && $med['supply_type'] !== 'nhs' ? trim(post('paid')) : '';
            if ($paid !== '' && (!is_numeric($paid) || (float) $paid < 0 || (float) $paid > 99999)) {
                flash('error', 'Enter what you paid as an amount in pounds, or leave it empty.');
                redirect($back);
            }
            if ($paid !== '' && (float) $paid > 0) {
                q('INSERT INTO prescription_payments (user_id, paid_on, items, amount, note, kind, medication_id) VALUES (?, ?, 1, ?, ?, ?, ?)',
                    [$uid, $todayYmd, round((float) $paid, 2), mb_substr($med['name'], 0, 200), $med['supply_type'], $med['id']]);
            }
            $after = transaction(function () use ($med, $kind, $qty, $uid) {
                $current = q('SELECT stock_count FROM medications WHERE id = ? FOR UPDATE', [$med['id']])->fetchColumn();
                $after = $kind === 'collected' ? (float) $current + $qty : $qty;
                // Collecting a supply also ends "repeat ordered" (and a controlled drug's prescription to collect).
                q('UPDATE medications SET stock_count = ?' . ($kind === 'collected' ? ', ordered_on = NULL, rx_written = NULL' : '') . ' WHERE id = ?', [$after, $med['id']]);
                $brand = $kind === 'collected' ? (trim(mb_substr(post('brand'), 0, 100)) ?: null) : null;
                q('INSERT INTO stock_events (user_id, medication_id, kind, quantity, stock_after, brand, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                    [$uid, $med['id'], $kind, $qty, $after, $brand]);
                // A different brand or manufacturer: note it in the medicine's history (some people notice a difference).
                if ($brand !== null && mb_strtolower($brand) !== mb_strtolower((string) $med['brand'])) {
                    q('UPDATE medications SET brand = ? WHERE id = ?', [$brand, $med['id']]);
                    record_med_change($uid, (int) $med['id'], local_today()->format('Y-m-d'), 'note',
                        $med['brand'] ? "Brand changed from {$med['brand']} to $brand" : "Brand: $brand");
                }
                return $after;
            });
            $days = stock_days_left(['stock_count' => $after] + $med, schedules_by_medication([(int) $med['id']], $todayYmd)[$med['id']] ?? []);
            flash('success', ($kind === 'collected' ? 'Added ' . dose_label($qty, $med['dose_unit']) . ' to ' : 'Stock count updated for ')
                . $med['name'] . '. You now have ' . dose_label($after, $med['dose_unit'])
                . ($days !== null ? ' (about ' . (int) floor($days) . ' days).' : '.')
                . ($expiry ? ' Expiry date set to ' . $expiry->format('j M Y') . '.' : '')
                . ($paid !== '' && (float) $paid > 0 ? ' Logged £' . number_format((float) $paid, 2) . ' paid.' : ''));
            redirect($back);
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
$lastCollected = last_collected($uid);
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
        <h1>Medications <?= help_link('medicines', '', 'Medicines') ?></h1>
        <form method="post" class="show-changes">
            <?= csrf_field() ?><input type="hidden" name="action" value="show_changes"><input type="hidden" name="show" value="0">
            <label class="check small"><input type="checkbox" name="show" value="1" data-autosubmit<?= checked((bool) $user['meds_show_changes']) ?>> Show dose changes</label>
            <noscript><button class="btn btn-small" type="submit">Apply</button></noscript>
        </form>
    </div>
    <span class="inline-actions">
        <a class="btn" href="<?= h(url('travel.php')) ?>">&#9992; Travel</a>
        <a class="btn" href="<?= h(url('medication_edit.php?supplement=1')) ?>">+ Add supplement</a>
        <a class="btn btn-primary" href="<?= h(url('medication_edit.php')) ?>">+ Add medication</a>
    </span>
</div>

<?php if (!$active): ?>
<section class="card empty">
    <p>You have no current medications.</p>
    <p class="inline-actions"><a class="btn btn-primary" href="<?= h(url('medication_edit.php')) ?>">Add your first medication</a>
        <a class="btn" href="<?= h(url('medication_edit.php?supplement=1')) ?>">Add a supplement</a></p>
</section>
<?php endif; ?>

<?php
// Medicines first, then supplements under their own heading.
usort($active, fn ($a, $b) => (int) $a['is_supplement'] <=> (int) $b['is_supplement']);
$supplementsShown = false; ?>
<?php $allergies = share_area_allowed('allergies') ? user_allergies($uid, true) : [];
$allergyConflicts = allergy_conflicts($allergies, $active); ?>
<?php if ($allergies): ?>
<p class="flash flash-info notice-row"><span><strong>Allergies:</strong> <?= h(allergy_list_text($allergies)) ?></span>
    <a class="btn btn-small" href="<?= h(url('care.php?area=allergies#al-list')) ?>">Allergies</a></p>
<?php endif; ?>
<?php if ($bpWarning = bp_contraception_warning($user, $active)): ?><p class="flash flash-warning"><?= h($bpWarning) ?></p><?php endif; ?>
<?php foreach (migraine_safety_warnings($user, $active) as $warning): ?><p class="flash flash-error"><?= h($warning) ?></p><?php endforeach; ?>
<?php foreach (spacing_clashes($active, $schedules)['clashes'] as [$t, $tTime, $o, $oTime, $gap]): ?>
<p class="flash flash-warning">&#9888; <?= h($t['name']) ?> (<?= h($tTime) ?>) and <?= h($o['name']) ?> (<?= h($oTime) ?>) are only <?= h(num(round($gap, 1))) ?> hours apart.
    <?= h($o['name']) ?> can stop <?= h($t['name']) ?> being absorbed properly: try to keep them about 4 hours apart, or ask your pharmacist.</p>
<?php endforeach; ?>
<div class="med-grid">
<?php foreach ($active as $m):
    if ($m['is_supplement'] && !$supplementsShown):
        $supplementsShown = true; ?>
</div>
<h2 class="med-section" id="supplements">Supplements</h2>
<div class="med-grid">
    <?php endif;
    $ms = $schedules[$m['id']] ?? [];
    $daysLeft = stock_days_left($m, $ms); ?>
    <article class="card med-card" id="med-<?= (int) $m['id'] ?>">
        <header class="med-head">
            <?= med_dot($m['colour']) ?>
            <div>
                <h2><?= h($m['name']) ?> <span class="muted"><?= h($m['strength']) ?></span></h2>
                <p class="muted small"><?= h(ucfirst($m['form'])) ?><?= $m['reason'] ? ' · for ' . h($m['reason']) : '' ?></p>
                <?php if ($course = course_progress($m, local_today())): ?><p><span class="badge"><?= h($course) ?></span>
                    <span class="muted small"><?= $m['course'] === 'short' ? 'until ' . h(date('j M', strtotime($m['end_date']))) : h(date('j M', strtotime($m['start_date']))) ?></span></p><?php endif; ?>
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
        <?php foreach ($allergyConflicts[(int) $m['id']] ?? [] as $a): ?><p class="small warn">&#9888; <?= h(allergy_conflict_text($m, $a)) ?></p><?php endforeach; ?>
        <?php if ($m['instructions']): ?><p class="small"><?= h($m['instructions']) ?></p><?php endif; ?>
        <?php if (preg_match(HYPO_RISK_PATTERN, $m['name'])): ?><p class="small"><span class="badge badge-due">Can cause hypos</span> <a href="<?= h(url('hypo.php')) ?>">Hypo guide</a></p><?php endif; ?>
        <?php if (preg_match(SGLT2_PATTERN, $m['name'])): ?><p class="small muted">If you're unwell and not eating or drinking, your team may tell you to pause this (sick-day rules).</p><?php endif; ?>
        <?php if ($m['form'] === 'injection'): $sites = injection_site_history((int) $m['id']); $nextSite = injection_site_suggestion((int) $m['id']); ?>
        <div class="site-map small" aria-label="Injection sites">
            <?php foreach (INJECTION_SITES as $k => $l): ?>
            <span class="site<?= $k === $nextSite ? ' next' : '' ?><?= isset($sites[$k]) ? ' used' : '' ?>"><?= h($l) ?><br><span class="muted"><?= isset($sites[$k]) ? h(local_time($sites[$k], 'j M')) : '&ndash;' ?><?= $k === $nextSite ? ' · next' : '' ?></span></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p class="muted small">
            <?php if ($m['stock_count'] !== null): ?>
                Stock: <?= h(num($m['stock_count'])) ?><?= $daysLeft !== null ? ' (~' . (int) floor($daysLeft) . ' days)' : '' ?> ·
            <?php endif; ?>
            <?php if (isset($lastCollected[$m['id']])): $lc = $lastCollected[$m['id']]; ?>
                Last collected <?= h(local_time($lc['created_at'], 'j M')) ?> (<?= h(dose_label($lc['quantity'], $m['dose_unit'])) ?><?= $lc['brand'] ? ', ' . h($lc['brand']) : '' ?>) ·
            <?php endif; ?>
            <?php if ($m['ordered_on']): ?>
                <strong>Ordered <?= h(date('j M', strtotime($m['ordered_on']))) ?>, waiting to collect</strong> ·
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
                    <label><span>Brand <span class="muted small">(optional)</span></span>
                        <input name="brand" maxlength="100" value="<?= h((string) $m['brand']) ?>" class="brand-input"></label>
                    <?php if ($m['supply_type'] !== 'nhs'): ?>
                    <label><span>Paid (&pound;) <span class="muted small">(optional, for new supplies)</span></span>
                        <input type="number" name="paid" min="0" step="0.01" inputmode="decimal" value="<?= $m['supply_price'] !== null ? h(number_format((float) $m['supply_price'], 2, '.', '')) : '' ?>"></label>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="submit">Update stock</button>
                </div>
                <p class="muted small">Currently <?= $m['stock_count'] !== null ? h(dose_label($m['stock_count'], $m['dose_unit'])) : 'not tracked' ?>.</p>
            </form>
        </details>
        <?php if ($m['controlled'] || $m['stock_count'] !== null): $rx = prescription_dates($m, $ms, local_today()); ?>
        <div class="rx-dates small">
            <?php if ($rx['collect_by']): $daysLeft = (int) local_today()->diff($rx['collect_by'])->format('%r%a'); ?>
            <p class="<?= $daysLeft <= 3 ? 'warn' : '' ?>"><strong>Collect by <?= h($rx['collect_by']->format('D j M')) ?></strong> (prescription dated <?= h(date('j M', strtotime($m['rx_written']))) ?>; it can't be dispensed after 28 days)</p>
            <?php elseif ($rx['request']): ?>
            <p>Ask for your next prescription around <strong><?= h($rx['request']->format('D j M')) ?></strong><?= $rx['runs_out'] ? ' (supply lasts until about ' . h($rx['runs_out']->format('j M')) . ')' : '' ?>.</p>
            <?php endif; ?>
            <?php if ($m['controlled']): ?>
            <details class="q-edit"><summary>Pharmacy, prescription and stock notes</summary>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="rx_written">
                    <label>New prescription dated <input type="date" name="date" max="<?= h(local_today()->format('Y-m-d')) ?>" value="<?= h((string) $m['rx_written']) ?>"></label>
                    <button class="btn btn-small" type="submit">Save</button></form>
                <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="action" value="supply_note">
                    <label>Note <input name="note" maxlength="1000" placeholder="e.g. Boots out of stock; try the pharmacy on High St"></label>
                    <button class="btn btn-small" type="submit">Add</button></form>
                <?php foreach (q("SELECT * FROM med_notes WHERE medication_id = ? AND kind = 'supply' ORDER BY created_at DESC LIMIT 5", [$m['id']]) as $note): ?>
                <p class="muted"><?= h(local_time($note['created_at'], 'j M')) ?>: <?= h($note['note']) ?></p>
                <?php endforeach; ?>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <p class="small"><a href="<?= h(url('med_effects.php?id=' . $m['id'])) ?>">How it's working</a> <span class="muted">(effects, timing and side effects)</span></p>
        <form method="post" class="order-toggle"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
            <input type="hidden" name="action" value="<?= $m['ordered_on'] ? 'unorder' : 'ordered' ?>">
            <button class="btn-link small" type="submit"><?= $m['ordered_on'] ? 'Not ordered after all' : 'Mark ' . ($m['supply_type'] === 'otc' ? 'more' : 'a repeat') . ' as ordered' ?></button></form>
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
