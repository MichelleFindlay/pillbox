<?php
/*
 * Gender-affirming care: the care pathway, fertility, a changes journal with the person's own
 * fields, and progress photos. One encrypted record (src/pathway.php helpers) with its own PIN,
 * never shared; photos are encrypted files in storage/private/. Hormones live in Meds (one-tap
 * set-ups below), blood results in Blood tests. The tab title is just "Private".
 */
require __DIR__ . '/../src/bootstrap.php';

const GENDER_KIND = 'gender';
const GENDER_FIELD_SUGGESTIONS = ['Voice', 'Chest', 'Body hair', 'Facial hair', 'Skin', 'Fat distribution', 'Energy', 'Libido', 'Dysphoria', 'Euphoria', 'Mood'];

$user = require_login();
if (isset($user['_acting'])) {
    redirect('index.php');
}
$uid = (int) $user['id'];
$today = local_today();
$todayYmd = $today->format('Y-m-d');
$record = private_record($uid, GENDER_KIND);
$errors = [];

if (is_post()) {
    require_post();
    $action = post('action');
    if ($action === 'start' && !$record) {
        $pin = (string) ($_POST['pin'] ?? '');
        if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
            $errors[] = 'A PIN is 4 to 8 digits (or leave it empty).';
        } else {
            private_record_save($uid, GENDER_KIND, ['pathway' => [], 'prescriber' => [], 'shared_care' => [], 'fertility' => [],
                'fields' => ['Voice', 'Dysphoria', 'Mood'], 'journal' => [], 'photos' => [], 'reminders' => []]);
            if ($pin !== '') {
                q('UPDATE private_records SET pin_hash = ? WHERE user_id = ? AND kind = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid, GENDER_KIND]);
                $_SESSION['gender_unlocked'] = time();
            }
            redirect('gender.php');
        }
    } elseif ($action === 'unlock' && $record) {
        if (login_is_throttled('#gender' . $uid)) {
            $errors[] = 'Too many tries. Please wait ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } elseif ($record['pin_hash'] && password_verify((string) ($_POST['pin'] ?? ''), $record['pin_hash'])) {
            $_SESSION['gender_unlocked'] = time();
            q('DELETE FROM login_attempts WHERE username = ?', ['#gender' . $uid]);
            redirect('gender.php');
        } else {
            q('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, UTC_TIMESTAMP())', [client_ip(), '#gender' . $uid]);
            $errors[] = "That PIN isn't right.";
        }
    } elseif ($action === 'delete_all' && $record) {
        foreach ($record['data']['photos'] ?? [] as $ph) {
            delete_private_photo($uid, $ph['id']);
        }
        private_record_delete($uid, GENDER_KIND);
        if (post('switch_off') === '1') {
            q('UPDATE users SET care_areas = ? WHERE id = ?', [implode(',', array_diff(user_care_areas($user), ['gender'])), $uid]);
        }
        flash('success', 'Deleted. Nothing from this page is left in Pillbox (medicines and blood results are kept in Meds and Blood tests).');
        redirect(post('switch_off') === '1' ? 'care.php' : 'gender.php');
    } elseif ($record && private_unlocked($record)) {
        $data = $record['data'];
        $anchor = '';
        $date = fn (string $f) => ($d = parse_date(post($f))) ? $d->format('Y-m-d') : null;
        switch ($action) {
            case 'lock':
                unset($_SESSION['gender_unlocked']);
                redirect('care.php');
            case 'pin':
                $pin = (string) ($_POST['pin'] ?? '');
                if ($pin === '') {
                    q('UPDATE private_records SET pin_hash = NULL WHERE user_id = ? AND kind = ?', [$uid, GENDER_KIND]);
                    flash('success', 'PIN removed.');
                } elseif (preg_match('/^\d{4,8}$/', $pin)) {
                    q('UPDATE private_records SET pin_hash = ? WHERE user_id = ? AND kind = ?', [password_hash($pin, PASSWORD_DEFAULT), $uid, GENDER_KIND]);
                    $_SESSION['gender_unlocked'] = time();
                    flash('success', 'PIN set. This page locks after ' . PATHWAY_UNLOCK_MINUTES . ' minutes without use.');
                } else {
                    flash('error', 'A PIN is 4 to 8 digits.');
                }
                redirect('gender.php#privacy');
            case 'care':
                $data['pathway'] = ['clinic' => trim(mb_substr(post('clinic'), 0, 150)) ?: null, 'referred' => $date('referred'), 'first_seen' => $date('first_seen'),
                    'notes' => trim(mb_substr(post('pathway_notes'), 0, 1000)) ?: null];
                $data['prescriber'] = ['type' => isset(PRESCRIBERS[post('prescriber_type')]) ? post('prescriber_type') : null,
                    'name' => trim(mb_substr(post('prescriber_name'), 0, 150)) ?: null, 'contact' => trim(mb_substr(post('prescriber_contact'), 0, 150)) ?: null];
                $data['shared_care'] = ['status' => isset(SHARED_CARE[post('shared_status')]) ? post('shared_status') : null,
                    'gp' => trim(mb_substr(post('shared_gp'), 0, 150)) ?: null, 'renewal' => $date('shared_renewal')];
                // A reminder a month before the shared care agreement needs renewing.
                if ($data['shared_care']['renewal']) {
                    $at = local_to_utc((new DateTimeImmutable($data['shared_care']['renewal']))->modify('-30 days')->format('Y-m-d') . ' 09:00');
                    $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => ($r['ref'] ?? '') !== 'shared-care'));
                    if ($at && $at > utc_now()) {
                        pathway_add_reminder($data, $at, 'Shared care agreement renewal in a month', 'shared-care');
                    }
                }
                $anchor = '#care';
                break;
            case 'fertility':
                $data['fertility'] = ['discussed' => post('discussed') === '1', 'note' => trim(mb_substr(post('note'), 0, 1000)) ?: null, 'remind' => $date('remind')];
                $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => ($r['ref'] ?? '') !== 'fertility'));
                if ($data['fertility']['remind'] && !$data['fertility']['discussed'] && ($at = local_to_utc($data['fertility']['remind'] . ' 09:00')) && $at > utc_now()) {
                    pathway_add_reminder($data, $at, 'Talk about fertility preservation', 'fertility');
                }
                $anchor = '#fertility';
                break;
            case 'fields':
                $fields = array_values(array_unique(array_filter(array_map(fn ($f) => trim(mb_substr((string) $f, 0, 40)), [...(array) ($_POST['fields'] ?? []), post('new_field')]))));
                $data['fields'] = array_slice($fields, 0, 20);
                $anchor = '#journal';
                break;
            case 'journal':
                $values = [];
                foreach ($data['fields'] ?? [] as $i => $f) {
                    $v = trim(mb_substr((string) ($_POST['v'][$i] ?? ''), 0, 300));
                    if ($v !== '') {
                        $values[$f] = $v;
                    }
                }
                if ($values || trim(post('note')) !== '') {
                    $data['journal'][] = ['id' => bin2hex(random_bytes(6)), 'date' => $date('date') ?? $todayYmd, 'values' => $values, 'note' => trim(mb_substr(post('note'), 0, 3000)) ?: null];
                }
                $anchor = '#journal';
                break;
            case 'photo':
                $stored = store_private_photo($uid, $_FILES['photo'] ?? []);
                if (is_string($stored)) {
                    flash('error', $stored);
                } else {
                    $data['photos'][] = ['id' => $stored['id'], 'date' => $date('date') ?? $todayYmd, 'note' => trim(mb_substr(post('note'), 0, 200)) ?: null];
                }
                $anchor = '#photos';
                break;
            case 'remove':
                $id = post('id');
                foreach ($data['photos'] ?? [] as $ph) {
                    if ($ph['id'] === $id) {
                        delete_private_photo($uid, $id);
                    }
                }
                $data['photos'] = array_values(array_filter($data['photos'] ?? [], fn ($e) => $e['id'] !== $id));
                $data['journal'] = array_values(array_filter($data['journal'] ?? [], fn ($e) => $e['id'] !== $id));
                $anchor = '#' . (post('list') === 'photos' ? 'photos' : 'journal');
                break;
        }
        private_record_save($uid, GENDER_KIND, $data);
        redirect('gender.php' . $anchor);
    } else {
        redirect('gender.php');
    }
}

render_header('Private', 'care');
?>
<div class="page-head">
    <div>
        <p class="muted small"><a href="<?= h(url('care.php')) ?>">Care</a> &rsaquo;</p>
        <h1>&#x1F98B; Gender-affirming care <?= help_link('gender-affirming-care', '', 'this page') ?></h1>
    </div>
    <?php if ($record && $record['pin_hash'] && private_unlocked($record)): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lock"><button class="btn btn-small" type="submit">Lock now</button></form>
    <?php endif; ?>
</div>
<?= form_errors($errors) ?>

<?php if (!$record): ?>
<section class="card">
    <h2>Private by design</h2>
    <ul>
        <li>Everything on this page is encrypted, including photos, and can have its own PIN.</li>
        <li>It's never shared with anyone you share Pillbox with, and never appears on Today, in emails, reports or data downloads.</li>
        <li>Reminders only ever say "You have a reminder". Tick <strong>Private</strong> on your hormones in Meds for the same.</li>
        <li>You can delete it all in one go, at any time.</li>
    </ul>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="start">
        <label class="narrow"><span>PIN <span class="muted small">(optional, 4 to 8 digits)</span></span>
            <input type="password" name="pin" inputmode="numeric" pattern="\d{4,8}" autocomplete="new-password"></label>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Start</button></span>
    </form>
</section>

<?php elseif (!private_unlocked($record)): ?>
<section class="card auth-card">
    <h2>Locked</h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="unlock">
        <label>PIN <input type="password" name="pin" inputmode="numeric" autocomplete="current-password" required autofocus></label>
        <button class="btn btn-primary btn-block" type="submit">Unlock</button>
    </form>
    <details class="q-edit"><summary>Forgotten the PIN?</summary>
        <p class="small">The only way back in is to delete everything on this page and start again.</p>
        <form method="post" data-confirm="Delete everything on this page, including photos? This can't be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
            <button class="btn btn-danger btn-small" type="submit">Delete everything</button></form>
    </details>
</section>

<?php else:
    $data = $record['data'];
    $p = $data['pathway'] ?? [];
    $rx = $data['prescriber'] ?? [];
    $sc = $data['shared_care'] ?? [];
    $fert = $data['fertility'] ?? [];
    $waited = fn (string $from, ?string $to) => (function ($d) { return ($d->y ? $d->y . ' year' . ($d->y === 1 ? '' : 's') . ' ' : '') . $d->m . ' month' . ($d->m === 1 ? '' : 's'); })(
        (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to ?? 'today')));
    $hormones = array_filter(user_medications($uid), fn ($m) => $m['reason'] === 'Gender-affirming hormones' || preg_match('/testosteron|oestr|estradiol|progesteron|gnrh|triptorelin|leuprorelin|spironolactone|cyproterone|bicalutamide|finasteride/i', $m['name'])); ?>

<section class="card" id="care">
    <h2>Your care</h2>
    <div class="tiles">
        <?php if (!empty($p['referred'])): ?>
        <div class="tile"><span class="tile-label"><?= !empty($p['first_seen']) ? 'Waited' : 'Waiting' ?> for <?= h($p['clinic'] ?: 'the clinic') ?></span>
            <span class="tile-value small-value"><?= h($waited($p['referred'], $p['first_seen'] ?? null)) ?></span>
            <span class="tile-meta">referred <?= h(date('j M Y', strtotime($p['referred']))) ?><?= !empty($p['first_seen']) ? ', first seen ' . h(date('j M Y', strtotime($p['first_seen']))) : '' ?></span></div>
        <?php endif; ?>
        <?php if (!empty($rx['type'])): ?>
        <div class="tile"><span class="tile-label">Prescribed by</span><span class="tile-value small-value"><?= h(explode(',', PRESCRIBERS[$rx['type']])[0]) ?></span>
            <span class="tile-meta"><?= h((string) ($rx['name'] ?? '')) ?></span></div>
        <?php endif; ?>
        <?php if (!empty($sc['status']) && $sc['status'] !== 'none'): ?>
        <div class="tile"><span class="tile-label">Shared care</span><span class="tile-value small-value"><?= h(SHARED_CARE[$sc['status']]) ?></span>
            <span class="tile-meta"><?= !empty($sc['renewal']) ? 'renew by ' . h(date('j M Y', strtotime($sc['renewal']))) : h((string) ($sc['gp'] ?? '')) ?></span></div>
        <?php endif; ?>
    </div>
    <?php if (!empty($p['notes'])): ?><p class="small"><?= nl2br(h($p['notes'])) ?></p><?php endif; ?>
    <details class="q-edit"<?= empty($p) && empty($rx) ? ' open' : '' ?>><summary>Clinic, prescriber and shared care</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="care">
            <div class="grid-2">
                <label>Gender identity clinic <input name="clinic" maxlength="150" value="<?= h((string) ($p['clinic'] ?? '')) ?>"></label>
                <label>Referred on <input type="date" name="referred" max="<?= h($todayYmd) ?>" value="<?= h((string) ($p['referred'] ?? '')) ?>"></label>
                <label><span>First appointment <span class="muted small">(when you're seen)</span></span><input type="date" name="first_seen" value="<?= h((string) ($p['first_seen'] ?? '')) ?>"></label>
                <label>Prescribed by <select name="prescriber_type"><option value="">&ndash;</option><?php foreach (PRESCRIBERS as $k => $l): ?><option value="<?= $k ?>"<?= selected(($rx['type'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>Prescriber's name <input name="prescriber_name" maxlength="150" value="<?= h((string) ($rx['name'] ?? '')) ?>"></label>
                <label>Contact <input name="prescriber_contact" maxlength="150" value="<?= h((string) ($rx['contact'] ?? '')) ?>"></label>
                <label>Shared care agreement <select name="shared_status"><option value="">&ndash;</option><?php foreach (SHARED_CARE as $k => $l): ?><option value="<?= $k ?>"<?= selected(($sc['status'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
                <label>GP <input name="shared_gp" maxlength="150" value="<?= h((string) ($sc['gp'] ?? '')) ?>"></label>
                <label><span>Renewal date <span class="muted small">(reminder a month before)</span></span><input type="date" name="shared_renewal" value="<?= h((string) ($sc['renewal'] ?? '')) ?>"></label>
            </div>
            <label>Notes <textarea name="pathway_notes" rows="2" maxlength="1000"><?= h((string) ($p['notes'] ?? '')) ?></textarea></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save</button></span>
        </form>
    </details>
    <p class="small">Having a hard time? Your safety plan and people to talk to are in <a href="<?= h(url('help.php')) ?>">Help</a>;
        trans-led support includes <a href="https://www.mermaidsuk.org.uk" target="_blank" rel="noopener">Mermaids</a> (young people and families) and <a href="https://switchboard.lgbt" target="_blank" rel="noopener">Switchboard</a> (LGBTQIA+ helpline).</p>
    <p class="small"><a href="<?= h(url('appointment_edit.php?kind=gic')) ?>">Add an appointment</a> (GIC, GP, endocrinology, surgery, blood tests) ·
        <a href="<?= h(url('care.php?area=bloods#labs')) ?>">Blood results and target ranges</a> ·
        <a href="<?= h(url('care.php?area=sharps')) ?>">Needles and sharps bin supplies</a></p>
</section>

<section class="card" id="hormones">
    <h2>Hormones</h2>
    <?php if ($hormones): ?>
    <ul class="plain"><?php foreach ($hormones as $m): $ms = schedules_by_medication([(int) $m['id']], $todayYmd)[$m['id']] ?? []; ?>
        <li class="logline"><span><?= med_dot($m['colour']) ?> <strong><?= h($m['name']) ?></strong> <?= h((string) $m['strength']) ?>
            <span class="muted small"><?= h(medication_regimen($m, $ms)) ?><?= $m['stock_count'] !== null ? ' · stock ' . h(num($m['stock_count'])) : '' ?><?= $m['is_private'] ? ' · private' : '' ?></span></span>
            <a class="btn btn-small" href="<?= h(url('medications.php#med-' . $m['id'])) ?>">Open</a></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <p class="small">Add <?= $hormones ? 'another' : 'yours' ?> (opens the medication form filled in and marked private; set your own dose, including low or micro doses):</p>
    <div class="preset-list">
        <?php foreach (GENDER_PRESETS as $key => $preset): ?><a class="btn btn-small" href="<?= h(url('medication_edit.php?preset=' . $key)) ?>">+ <?= h($preset['label']) ?></a><?php endforeach; ?>
    </div>
    <p class="muted small">Injections can repeat every few days or weeks, and the Take button suggests the next injection site. Supply problems happen: keep stock up to date and use "Ordered" on the running-low notice.</p>
</section>

<section class="card" id="fertility">
    <h2>Fertility</h2>
    <p class="small">Hormones and some surgery can affect fertility. It's worth talking about options such as storing eggs or sperm before, or early in, treatment.</p>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="fertility">
        <label class="check"><input type="checkbox" name="discussed" value="1"<?= checked(!empty($fert['discussed'])) ?>> I've talked about fertility preservation</label>
        <div class="grid-2">
            <label><span>Remind me on <span class="muted small">(optional)</span></span><input type="date" name="remind" value="<?= h((string) ($fert['remind'] ?? '')) ?>"></label>
            <label>Notes <input name="note" maxlength="1000" value="<?= h((string) ($fert['note'] ?? '')) ?>"></label>
        </div>
        <span class="inline-actions"><button class="btn btn-small" type="submit">Save</button></span>
    </form>
</section>

<section class="card" id="journal">
    <h2>Changes journal</h2>
    <p class="muted small">Your own fields: whatever matters to you. <a href="<?= h(url('mind_check.php')) ?>">Mood check-ins</a> are in Mind.</p>
    <?php $fields = $data['fields'] ?? []; if ($fields): ?>
    <details class="q-edit" open><summary>New entry</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="journal">
            <div class="grid-2">
                <label>Date <input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
                <?php foreach ($fields as $i => $f): ?><label><?= h($f) ?> <input name="v[<?= $i ?>]" maxlength="300"></label><?php endforeach; ?>
            </div>
            <label>Notes <textarea name="note" rows="3" maxlength="3000"></textarea></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Save entry</button></span>
        </form>
    </details>
    <?php endif; ?>
    <details class="q-edit"><summary>Choose fields</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="fields">
            <div class="checks"><?php foreach (array_unique([...$fields, ...GENDER_FIELD_SUGGESTIONS]) as $f): ?>
                <label class="check"><input type="checkbox" name="fields[]" value="<?= h($f) ?>"<?= checked(in_array($f, $fields, true)) ?>> <?= h($f) ?></label>
            <?php endforeach; ?></div>
            <label class="narrow">Add your own <input name="new_field" maxlength="40"></label>
            <span class="inline-actions"><button class="btn btn-small" type="submit">Save fields</button></span>
        </form>
    </details>
    <?php $journal = $data['journal'] ?? []; usort($journal, fn ($a, $b) => $b['date'] <=> $a['date']); if ($journal): ?>
    <ul class="plain"><?php foreach (array_slice($journal, 0, 60) as $e): ?>
        <li class="logline"><span><strong><?= h(date('j M Y', strtotime($e['date']))) ?></strong>
            <?php foreach ($e['values'] as $f => $v): ?> · <?= h($f) ?>: <?= h($v) ?><?php endforeach; ?>
            <?php if ($e['note']): ?><br><span class="small"><?= nl2br(h($e['note'])) ?></span><?php endif; ?></span>
            <form method="post" data-confirm="Delete this entry?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="list" value="journal"><input type="hidden" name="id" value="<?= h($e['id']) ?>"><button class="btn-link danger small" type="submit">Delete</button></form></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
</section>

<section class="card" id="photos">
    <h2>Progress photos</h2>
    <p class="muted small">Encrypted and kept only in Pillbox. They're never in backups, data downloads or shared views.</p>
    <form method="post" enctype="multipart/form-data" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="photo">
        <label>Photo <input type="file" name="photo" accept="image/*" required></label>
        <label>Date <input type="date" name="date" max="<?= h($todayYmd) ?>" value="<?= h($todayYmd) ?>"></label>
        <label>Note <input name="note" maxlength="200"></label>
        <button class="btn btn-small" type="submit">Add</button>
    </form>
    <?php $photos = $data['photos'] ?? []; usort($photos, fn ($a, $b) => $b['date'] <=> $a['date']); if ($photos): ?>
    <div class="photo-grid"><?php foreach ($photos as $ph): ?>
        <figure>
            <a href="<?= h(url('photo.php?k=gender&id=' . $ph['id'])) ?>" target="_blank" rel="noopener"><img src="<?= h(url('photo.php?k=gender&id=' . $ph['id'])) ?>" alt="Photo from <?= h(date('j M Y', strtotime($ph['date']))) ?>" loading="lazy"></a>
            <figcaption class="small"><?= h(date('j M Y', strtotime($ph['date']))) ?><?= $ph['note'] ? ' · ' . h($ph['note']) : '' ?>
                <form method="post" data-confirm="Delete this photo?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="list" value="photos"><input type="hidden" name="id" value="<?= h($ph['id']) ?>"><button class="btn-link danger small" type="submit">Delete</button></form></figcaption>
        </figure>
    <?php endforeach; ?></div>
    <?php endif; ?>
</section>

<section class="card" id="privacy">
    <h2>Privacy</h2>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="pin">
        <label><?= $record['pin_hash'] ? 'New PIN' : 'PIN' ?> <span class="muted small">(4 to 8 digits<?= $record['pin_hash'] ? '; empty removes it' : '' ?>)</span>
            <input type="password" name="pin" inputmode="numeric" autocomplete="new-password"></label>
        <button class="btn btn-small" type="submit"><?= $record['pin_hash'] ? 'Change PIN' : 'Set a PIN' ?></button>
    </form>
    <p class="small">More in <a href="<?= h(url('account.php#privacy')) ?>">Account &rsaquo; Privacy</a>: hide the screen when switching apps, and a disguised name and icon on your home screen.</p>
    <form method="post" class="stack" data-confirm="Delete everything on this page, including photos? This can't be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
        <label class="check"><input type="checkbox" name="switch_off" value="1" checked> Also remove this page from my Care categories</label>
        <span class="inline-actions"><button class="btn btn-danger" type="submit">Delete everything</button></span>
        <p class="muted small">Your medicines and blood results stay in Meds and Blood tests: delete those there if you want them gone too.</p>
    </form>
</section>
<?php endif; ?>
<?php render_footer();
