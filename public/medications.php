<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];
$todayYmd = local_today()->format('Y-m-d');

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
            flash('success', $med['name'] . ' stopped. Its history is kept for your reports.');
            break;
        case 'restore':
            q('UPDATE medications SET is_active = 1, end_date = NULL WHERE id = ?', [$med['id']]);
            flash('success', $med['name'] . ' restarted.');
            break;
        case 'delete':
            q('DELETE FROM medications WHERE id = ?', [$med['id']]);
            flash('success', $med['name'] . ' and all of its dose history were deleted.');
            break;
    }
    redirect('medications.php');
}

$meds = user_medications($uid, false);
$schedules = schedules_by_medication(array_column($meds, 'id'), $todayYmd);
$active = array_filter($meds, fn ($m) => $m['is_active']);
$stopped = array_filter($meds, fn ($m) => !$m['is_active']);

render_header('Medications', 'meds');
?>
<div class="page-head">
    <h1>Medications</h1>
    <a class="btn btn-primary" href="<?= h(url('medication_edit.php')) ?>">+ Add medication</a>
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
    <article class="card med-card">
        <header class="med-head">
            <?= med_dot($m['colour']) ?>
            <div>
                <h2><?= h($m['name']) ?> <span class="muted"><?= h($m['strength']) ?></span></h2>
                <p class="muted small"><?= h(ucfirst($m['form'])) ?><?= $m['reason'] ? ' · for ' . h($m['reason']) : '' ?></p>
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
            Since <?= h(date('j M Y', strtotime($m['start_date']))) ?><?= $m['end_date'] ? ' until ' . h(date('j M Y', strtotime($m['end_date']))) : '' ?>
            <?= $m['prescriber'] ? ' · ' . h($m['prescriber']) : '' ?>
        </p>
        <footer class="med-actions">
            <a class="btn btn-small" href="<?= h(url('medication_edit.php?id=' . $m['id'])) ?>">Edit</a>
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
