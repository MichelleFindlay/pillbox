<?php
declare(strict_types=1);

/*
 * Private health care (a Care category, care.php?area=privatehealth).
 * - Plans: private medical insurance, health cash plans, dental plans or self-pay, with the
 *   membership number, claims phone, cost, excess, renewal date (as a Care check, so it's on Today
 *   and in the calendar), what's covered and any yearly limits.
 * - Claims: treatment paid for privately, and what the plan paid back: how much of each yearly
 *   limit is used this policy year, and what private care is costing overall.
 * - Appointments can be marked private (appointments.funding), with the plan, pre-authorisation
 *   number and cost; afterwards they're offered as claims.
 * Also a setup step (welcome.php).
 */

const PLAN_KINDS = ['insurance' => 'Private medical insurance', 'cash_plan' => 'Health cash plan', 'dental_plan' => 'Dental plan',
    'self_pay' => 'Paying myself (self-pay)', 'other' => 'Other'];
const PLAN_COVERS = [
    'inpatient'    => 'In-patient and day-patient treatment',
    'outpatient'   => 'Out-patient consultations',
    'diagnostics'  => 'Tests and scans',
    'therapies'    => 'Physio, osteopathy and chiropractic',
    'mental'       => 'Mental health',
    'cancer'       => 'Cancer care',
    'gp'           => 'Private or online GP',
    'dental'       => 'Dental',
    'optical'      => 'Glasses, lenses and eye tests',
    'hearing'      => 'Hearing',
    'podiatry'     => 'Chiropody and podiatry',
    'prescriptions' => 'Prescription charges',
    'maternity'    => 'Pregnancy and birth',
    'complementary' => 'Complementary therapies',
    'health_check' => 'Health checks',
    'hospital_cash' => 'Hospital cash (paid per night in hospital)',
];
const PLAN_UNDERWRITING = ['moratorium' => 'Moratorium', 'fmu' => 'Full medical underwriting', 'cpme' => 'Continued personal medical exclusions', 'mhd' => 'Medical history disregarded'];
const CLAIM_STATUSES = ['to_claim' => 'To claim', 'claimed' => 'Claimed, waiting', 'paid' => 'Paid back', 'direct' => 'Paid by the plan directly',
    'rejected' => 'Not covered', 'self' => 'Paid myself (not claimable)'];

function health_plans(int $userId, bool $activeOnly = true): array
{
    return q('SELECT * FROM health_plans WHERE user_id = ?' . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY is_active DESC, provider', [$userId])->fetchAll();
}

function find_health_plan(int $id, int $userId): ?array
{
    return q('SELECT * FROM health_plans WHERE id = ? AND user_id = ?', [$id, $userId])->fetch() ?: null;
}

function plan_title(array $plan): string
{
    return trim($plan['provider'] . ($plan['plan_name'] ? ' ' . $plan['plan_name'] : ''));
}

function plan_covers(array $plan): array
{
    return array_values(array_filter(explode(',', (string) $plan['covers'])));
}

/** @return array<string, float> yearly £ limits by cover */
function plan_limits(array $plan): array
{
    $limits = json_decode((string) $plan['cover_limits'], true);
    return is_array($limits) ? array_map('floatval', $limits) : [];
}

/** What it costs a year in premiums or subscription. */
function plan_yearly_cost(array $plan): ?float
{
    return $plan['cost_amount'] === null ? null : (float) $plan['cost_amount'] * ($plan['cost_period'] === 'year' ? 1 : 12);
}

function money(float $amount): string
{
    return '£' . number_format($amount, fmod($amount, 1.0) == 0.0 ? 0 : 2);
}

/** The start of the current policy year: the last renewal anniversary, or 12 months ago. */
function plan_year_start(array $plan, DateTimeImmutable $today): DateTimeImmutable
{
    if (!$plan['renews_on']) {
        return $today->modify('-1 year')->modify('+1 day');
    }
    $d = new DateTimeImmutable($plan['renews_on'], $today->getTimezone());
    for ($i = 0; $d > $today && $i < 50; $i++) {
        $d = $d->modify('-1 year');
    }
    for ($i = 0; $d->modify('+1 year') <= $today && $i < 50; $i++) {
        $d = $d->modify('+1 year');
    }
    return $d;
}

/**
 * This policy year: what each cover has paid out, and the totals.
 * @return array{from: DateTimeImmutable, used: array<string, float>, cost: float, covered: float, waiting: float}
 */
function plan_usage(int $userId, array $plan, DateTimeImmutable $today): array
{
    $from = plan_year_start($plan, $today);
    $used = [];
    $cost = $covered = $waiting = 0.0;
    foreach (q('SELECT * FROM health_claims WHERE user_id = ? AND plan_id = ? AND treated_on >= ?', [$userId, $plan['id'], $from->format('Y-m-d')]) as $c) {
        $cost += (float) $c['cost'];
        if (in_array($c['status'], ['paid', 'direct'], true)) {
            $covered += (float) $c['covered'];
        }
        if (in_array($c['status'], ['to_claim', 'claimed'], true)) {
            $waiting += (float) ($c['covered'] ?? $c['cost']);
        }
        if (in_array($c['status'], ['claimed', 'paid', 'direct'], true) && $c['cover']) {
            $used[$c['cover']] = ($used[$c['cover']] ?? 0) + (float) ($c['covered'] ?? $c['cost']);
        }
    }
    return ['from' => $from, 'used' => $used, 'cost' => $cost, 'covered' => $covered, 'waiting' => $waiting];
}

/** The plan fields from a form (Care page or setup step). @return array{0: array, 1: string[]} */
function plan_from_post(): array
{
    $errors = [];
    $money = function (string $field, string $label) use (&$errors): ?float {
        $v = trim(str_replace(['£', ','], '', post($field)));
        if ($v === '') {
            return null;
        }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 999999) {
            $errors[] = "Enter $label as an amount in pounds, or leave it empty.";
            return null;
        }
        return round((float) $v, 2);
    };
    $provider = trim(mb_substr(post('provider'), 0, 100));
    if ($provider === '') {
        $errors[] = 'Enter who the plan is with (e.g. the insurer), or "Self-pay".';
    }
    $covers = array_values(array_intersect(array_keys(PLAN_COVERS), (array) ($_POST['covers'] ?? [])));
    $limits = [];
    foreach ($covers as $c) {
        $l = trim(str_replace(['£', ','], '', (string) ($_POST['limit'][$c] ?? '')));
        if ($l !== '' && is_numeric($l) && (float) $l > 0) {
            $limits[$c] = round((float) $l, 2);
        }
    }
    $website = trim(mb_substr(post('website'), 0, 200));
    if ($website !== '' && !preg_match('#^https?://#i', $website)) {
        $website = 'https://' . $website;
    }
    $plan = [
        'kind' => isset(PLAN_KINDS[post('kind')]) ? post('kind') : 'insurance',
        'provider' => $provider,
        'plan_name' => trim(mb_substr(post('plan_name'), 0, 100)) ?: null,
        'member_number' => trim(mb_substr(post('member_number'), 0, 60)) ?: null,
        'who_covered' => trim(mb_substr(post('who_covered'), 0, 200)) ?: null,
        'through_employer' => post('through_employer') === '1' ? 1 : 0,
        'cost_amount' => $money('cost_amount', 'the cost'),
        'cost_period' => post('cost_period') === 'year' ? 'year' : 'month',
        'excess_amount' => $money('excess_amount', 'the excess'),
        'excess_per' => post('excess_per') === 'claim' ? 'claim' : 'year',
        'renews_on' => parse_date(post('renews_on'))?->format('Y-m-d'),
        'underwriting' => isset(PLAN_UNDERWRITING[post('underwriting')]) ? post('underwriting') : null,
        'six_week' => post('six_week') === '1' ? 1 : 0,
        'hospital_list' => trim(mb_substr(post('hospital_list'), 0, 100)) ?: null,
        'claims_phone' => trim(mb_substr(post('claims_phone'), 0, 40)) ?: null,
        'website' => $website ?: null,
        'covers' => implode(',', $covers) ?: null,
        'cover_limits' => $limits ? json_encode($limits) : null,
        'exclusions' => trim(mb_substr(post('exclusions'), 0, 2000)) ?: null,
        'notes' => trim(mb_substr(post('notes'), 0, 2000)) ?: null,
    ];
    return [$plan, $errors];
}

/** Save a plan, and keep its renewal date as a Care check (on Today, and in the calendar feed). */
// The columns a plan can be saved with: only these names ever go into the SQL (values are always bound).
const PLAN_COLUMNS = ['kind', 'provider', 'plan_name', 'member_number', 'who_covered', 'through_employer', 'cost_amount', 'cost_period', 'excess_amount',
    'excess_per', 'renews_on', 'underwriting', 'six_week', 'hospital_list', 'claims_phone', 'website', 'covers', 'cover_limits', 'exclusions', 'notes'];

function save_health_plan(int $userId, array $plan, ?int $id = null): int
{
    $plan = array_intersect_key($plan, array_flip(PLAN_COLUMNS));
    if ($id) {
        q('UPDATE health_plans SET ' . implode(', ', array_map(fn ($k) => "$k = ?", array_keys($plan))) . ' WHERE id = ? AND user_id = ?', [...array_values($plan), $id, $userId]);
    } else {
        q('INSERT INTO health_plans (user_id, ' . implode(', ', array_keys($plan)) . ') VALUES (?' . str_repeat(', ?', count($plan)) . ')', [$userId, ...array_values($plan)]);
        $id = (int) db()->lastInsertId();
    }
    $kind = 'plan_' . $id;
    $check = q("SELECT id FROM health_checks WHERE user_id = ? AND area = 'privatehealth' AND kind = ?", [$userId, $kind])->fetchColumn();
    if (!$plan['renews_on']) {
        q('DELETE FROM health_checks WHERE id = ?', [(int) $check]);
    } elseif ($check) {
        q('UPDATE health_checks SET label = ?, due_date = ? WHERE id = ?', [mb_substr($plan['provider'] . ' renews', 0, 100), $plan['renews_on'], $check]);
    } else {
        q("INSERT INTO health_checks (user_id, area, kind, label, track, due_date, tip) VALUES (?, 'privatehealth', ?, ?, 'expiry', ?, ?)",
            [$userId, $kind, mb_substr($plan['provider'] . ' renews', 0, 100), $plan['renews_on'],
                "Before it renews: check the new price and what's changed, and compare. Switching can mean new exclusions for existing conditions."]);
    }
    return $id;
}

/** "Private · Bupa · pre-auth 12345 · £180" for an appointment, or '' if it's NHS. */
function appointment_funding_text(array $a, ?array $plan = null): string
{
    if (!$a['funding']) {
        return '';
    }
    return implode(' · ', array_filter(['Private', $a['funding'] === 'self' ? 'paying myself' : ($plan ? plan_title($plan) : ''),
        $plan && $plan['member_number'] && $a['funding'] === 'plan' ? 'membership ' . $plan['member_number'] : '',
        $a['preauth'] ? 'pre-authorisation ' . $a['preauth'] : '', $a['cost'] !== null ? money((float) $a['cost']) : '']));
}

function privatehealth_handle_post(array $user, DateTimeImmutable $today): never
{
    $uid = (int) $user['id'];
    $back = 'care.php?area=privatehealth';
    switch (post('action')) {
        case 'ph_plan_save':
            [$plan, $errors] = plan_from_post();
            $id = (int) post('plan_id') ?: null;
            if ($id && !find_health_plan($id, $uid)) {
                not_found();
            }
            if ($errors) {
                foreach ($errors as $e) {
                    flash('error', $e);
                }
            } else {
                save_health_plan($uid, $plan, $id);
                flash('success', plan_title($plan) . ' saved.');
            }
            redirect($back . '#ph-plans');
        case 'ph_scan':
            $result = scan_policy_from_request();
            if (is_string($result)) {
                flash('error', $result);
            } else {
                $_SESSION['ph_scan'] = $result;
            }
            redirect($back . '#ph-plans');
        case 'ph_plan_end':
            q('UPDATE health_plans SET is_active = 1 - is_active WHERE id = ? AND user_id = ?', [(int) post('plan_id'), $uid]);
            q("DELETE FROM health_checks WHERE user_id = ? AND area = 'privatehealth' AND kind = ?", [$uid, 'plan_' . (int) post('plan_id')]);
            redirect($back . '#ph-plans');
        case 'ph_plan_delete':
            q('DELETE FROM health_plans WHERE id = ? AND user_id = ?', [(int) post('plan_id'), $uid]);
            q("DELETE FROM health_checks WHERE user_id = ? AND area = 'privatehealth' AND kind = ?", [$uid, 'plan_' . (int) post('plan_id')]);
            q('UPDATE health_claims SET plan_id = NULL WHERE plan_id = ? AND user_id = ?', [(int) post('plan_id'), $uid]);
            q("UPDATE appointments SET plan_id = NULL, funding = IF(funding = 'plan', 'self', funding) WHERE plan_id = ? AND user_id = ?", [(int) post('plan_id'), $uid]);
            flash('success', 'Plan deleted.');
            redirect($back . '#ph-plans');
        case 'ph_claim_save':
            $plan = post('plan_id') !== '' ? find_health_plan((int) post('plan_id'), $uid) : null;
            $appt = post('appointment_id') !== '' ? find_appointment((int) post('appointment_id'), $uid) : null;
            $num = fn (string $f) => is_numeric($v = trim(str_replace(['£', ','], '', post($f)))) && (float) $v >= 0 ? round((float) $v, 2) : null;
            $desc = trim(mb_substr(post('description'), 0, 200)) ?: ($appt ? appointment_title($appt) : '');
            if ($desc === '') {
                flash('error', 'Say what the treatment was.');
                redirect($back . '#ph-claims');
            }
            $cols = [$plan['id'] ?? null, $appt['id'] ?? null, (parse_date(post('treated_on')) ?? $today)->format('Y-m-d'),
                isset(PLAN_COVERS[post('cover')]) ? post('cover') : null, $desc, $num('cost'), $num('covered'),
                isset(CLAIM_STATUSES[post('status')]) ? post('status') : 'to_claim', trim(mb_substr(post('reference'), 0, 60)) ?: null, trim(mb_substr(post('notes'), 0, 500)) ?: null];
            if ($id = (int) post('claim_id')) {
                q('UPDATE health_claims SET plan_id = ?, appointment_id = ?, treated_on = ?, cover = ?, description = ?, cost = ?, covered = ?, status = ?, reference = ?, notes = ? WHERE id = ? AND user_id = ?', [...$cols, $id, $uid]);
            } else {
                q('INSERT INTO health_claims (plan_id, appointment_id, treated_on, cover, description, cost, covered, status, reference, notes, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$cols, $uid]);
            }
            flash('success', 'Saved.');
            redirect(safe_return(post('return'), $back . '#ph-claims'));
        case 'ph_claim_delete':
            q('DELETE FROM health_claims WHERE id = ? AND user_id = ?', [(int) post('claim_id'), $uid]);
            redirect($back . '#ph-claims');
    }
    redirect($back);
}

/** The plan form's fields (Care page, and the setup step, where they can be hidden so aren't marked required). */
function plan_fields(?array $p, bool $required = true): void
{
    $p ??= ['kind' => 'insurance', 'cost_period' => 'month', 'excess_per' => 'year', 'through_employer' => 0, 'six_week' => 0, 'covers' => '', 'cover_limits' => null];
    $covers = plan_covers($p);
    $limits = plan_limits($p);
    $v = fn (string $k) => h((string) ($p[$k] ?? ''));
    ?>
    <div class="grid-2">
        <label>Type <select name="kind"><?php foreach (PLAN_KINDS as $k => $l): ?><option value="<?= $k ?>"<?= selected($p['kind'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Who it's with <input name="provider" maxlength="100"<?= $required ? ' required' : '' ?> placeholder="The insurer or company, or &quot;Self-pay&quot;" value="<?= $v('provider') ?>"></label>
        <label>Plan or policy name <input name="plan_name" maxlength="100" value="<?= $v('plan_name') ?>"></label>
        <label>Membership or policy number <input name="member_number" maxlength="60" autocomplete="off" value="<?= $v('member_number') ?>"></label>
        <label>Claims and pre-authorisation phone <input name="claims_phone" type="tel" maxlength="40" value="<?= $v('claims_phone') ?>"></label>
        <label>Website or app <input name="website" maxlength="200" value="<?= $v('website') ?>"></label>
        <label>Cost <span class="range-pair"><input name="cost_amount" inputmode="decimal" placeholder="£" value="<?= $p['cost_amount'] ?? null ? h(number_format((float) $p['cost_amount'], 2, '.', '')) : '' ?>">
            <select name="cost_period" aria-label="How often"><option value="month"<?= selected($p['cost_period'] === 'month') ?>>a month</option><option value="year"<?= selected($p['cost_period'] === 'year') ?>>a year</option></select></span></label>
        <label>Excess <span class="range-pair"><input name="excess_amount" inputmode="decimal" placeholder="£" value="<?= $p['excess_amount'] ?? null ? h(number_format((float) $p['excess_amount'], 2, '.', '')) : '' ?>">
            <select name="excess_per" aria-label="Excess per"><option value="year"<?= selected($p['excess_per'] === 'year') ?>>a year</option><option value="claim"<?= selected($p['excess_per'] === 'claim') ?>>per claim</option></select></span></label>
        <label>Renews on <input type="date" name="renews_on" value="<?= $v('renews_on') ?>"></label>
        <label>Who's covered <input name="who_covered" maxlength="200" placeholder="e.g. me and my partner" value="<?= $v('who_covered') ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="through_employer" value="1"<?= checked((bool) $p['through_employer']) ?>> Through my employer <span class="muted small">(usually a taxable benefit)</span></label>
    <fieldset><legend>What it covers</legend>
        <p class="muted small">Tick what's included. For a cash plan or anything with a yearly limit, add the limit to see how much is left.</p>
        <div class="cover-grid">
        <?php foreach (PLAN_COVERS as $k => $l): ?>
            <label class="check"><input type="checkbox" name="covers[]" value="<?= $k ?>"<?= checked(in_array($k, $covers, true)) ?>> <?= h($l) ?></label>
            <input name="limit[<?= $k ?>]" inputmode="decimal" class="count-input" placeholder="£ a year" aria-label="<?= h($l) ?>: yearly limit, if any" value="<?= isset($limits[$k]) ? h(number_format($limits[$k], 0, '.', '')) : '' ?>">
        <?php endforeach; ?>
        </div>
    </fieldset>
    <details class="q-edit"<?= ($p['underwriting'] ?? null) || ($p['six_week'] ?? 0) || ($p['hospital_list'] ?? null) || ($p['exclusions'] ?? null) ? ' open' : '' ?>><summary>More detail (insurance)</summary>
        <div class="grid-2">
            <label>Underwriting <select name="underwriting"><option value="">Not sure</option><?php foreach (PLAN_UNDERWRITING as $k => $l): ?><option value="<?= $k ?>"<?= selected(($p['underwriting'] ?? '') === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Hospital list <input name="hospital_list" maxlength="100" placeholder="e.g. the insurer's standard list" value="<?= $v('hospital_list') ?>"></label>
        </div>
        <label class="check"><input type="checkbox" name="six_week" value="1"<?= checked((bool) ($p['six_week'] ?? 0)) ?>> Six-week option <span class="muted small">(only covered if the NHS wait is longer than six weeks)</span></label>
        <label>Exclusions <textarea name="exclusions" rows="2" maxlength="2000" placeholder="Conditions or treatments it doesn't cover"><?= $v('exclusions') ?></textarea></label>
    </details>
    <label>Notes <textarea name="notes" rows="2" maxlength="2000"><?= $v('notes') ?></textarea></label>
    <?php
}

/** Upload a policy document (or paste its text) to fill in the plan form. $action: the form's action. */
function policy_upload_fields(string $action, bool $inWizard = false): void
{
    ?>
    <?php if (!$inWizard): ?><form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="<?= h($action) ?>"><?php endif; ?>
        <p class="small">Upload your policy schedule, membership certificate or plan summary (a PDF or Word document), and Pillbox fills in what it can find for you to check.
            It's read on this <?= h((string) config('app.name')) ?> server, then thrown away: it isn't kept or sent anywhere.</p>
        <label>Document <input type="file" name="policy_file" accept=".pdf,.docx,.txt,.htm,.html,application/pdf"></label>
        <details><summary class="small">Or paste the text instead (for a scan, or an email)</summary>
            <label><span class="sr">Policy text</span><textarea name="policy_text" rows="5" maxlength="200000" placeholder="Paste the policy details here"></textarea></label></details>
        <span class="inline-actions"><button class="btn<?= $inWizard ? '' : ' btn-primary' ?>" type="submit"<?= $inWizard ? ' name="scan" value="1" formnovalidate' : '' ?>>Read it</button></span>
    <?php if (!$inWizard): ?></form><?php endif; ?>
    <?php
}

/** The private health care sections at the top of care.php?area=privatehealth. */
function privatehealth_sections(array $user, DateTimeImmutable $today): void
{
    $uid = (int) $user['id'];
    $canEdit = share_can('care', 'edit');
    $plans = health_plans($uid, false);
    $active = array_values(array_filter($plans, fn ($p) => $p['is_active']));
    $byId = array_column($plans, null, 'id');
    $claims = q('SELECT * FROM health_claims WHERE user_id = ? ORDER BY treated_on DESC, id DESC LIMIT 50', [$uid])->fetchAll();
    $claimedAppts = array_filter(array_column($claims, 'appointment_id'));
    $upcoming = q('SELECT * FROM appointments WHERE user_id = ? AND funding IS NOT NULL AND starts_at >= UTC_TIMESTAMP() ORDER BY starts_at LIMIT 10', [$uid])->fetchAll();
    $unclaimed = array_values(array_filter(q('SELECT * FROM appointments WHERE user_id = ? AND funding IS NOT NULL AND starts_at < UTC_TIMESTAMP() AND starts_at > UTC_TIMESTAMP() - INTERVAL 1 YEAR ORDER BY starts_at DESC', [$uid])->fetchAll(),
        fn ($a) => !in_array($a['id'], $claimedAppts)));
    $toClaim = array_filter($claims, fn ($c) => $c['status'] === 'to_claim');
    $yearly = array_sum(array_map(fn ($p) => plan_yearly_cost($p) ?? 0, $active));
    $scan = $canEdit ? ($_SESSION['ph_scan'] ?? null) : null; // details read from a policy document, to check
    unset($_SESSION['ph_scan']);
    ?>
<?php if ($toClaim || $unclaimed): ?>
<p class="flash flash-info"><?= count($toClaim) + count($unclaimed) === 1 ? 'Something is' : (count($toClaim) + count($unclaimed)) . ' things are' ?> waiting to be claimed back.
    Most plans only pay claims made within a time limit (often 6 months), so claim soon.</p>
<?php endif; ?>

<section class="card" id="ph-plans">
    <h2>Your plans <?= help_link('private-health', 'your-plans', 'private health plans') ?></h2>
    <?php if (!$plans): ?><p class="small">Add your private medical insurance, health cash plan or dental plan, or note that you pay for some care yourself.
        Keep the membership number and claims phone to hand, see what's covered and what's left, and what it's all costing.</p><?php endif; ?>
    <?php foreach ($plans as $p): $u = plan_usage($uid, $p, $today); $limits = plan_limits($p); ?>
    <article class="plan<?= $p['is_active'] ? '' : ' muted' ?>">
        <h3><?= h(plan_title($p)) ?> <span class="badge"><?= h(PLAN_KINDS[$p['kind']] ?? '') ?></span><?= $p['is_active'] ? '' : ' <span class="badge">Ended</span>' ?></h3>
        <p class="small"><?= implode(' · ', array_filter([
            $p['member_number'] ? 'Membership <strong>' . h($p['member_number']) . '</strong>' : '',
            $p['claims_phone'] ? 'Claims <a href="tel:' . h(preg_replace('/[^0-9+]/', '', $p['claims_phone'])) . '">' . h($p['claims_phone']) . '</a>' : '',
            $p['website'] ? '<a href="' . h($p['website']) . '" target="_blank" rel="noopener">Website</a>' : '',
            $p['who_covered'] ? 'Covers ' . h($p['who_covered']) : '',
        ])) ?></p>
        <p class="small"><?= implode(' · ', array_filter([
            $p['cost_amount'] !== null ? '<strong>' . money((float) $p['cost_amount']) . ' a ' . ($p['cost_period'] === 'year' ? 'year' : 'month') . '</strong>' . ($p['cost_period'] === 'month' ? ' (' . money(plan_yearly_cost($p)) . ' a year)' : '') : '',
            $p['excess_amount'] !== null ? money((float) $p['excess_amount']) . ' excess ' . ($p['excess_per'] === 'claim' ? 'per claim' : 'a year') : '',
            $p['renews_on'] ? 'renews ' . h(date('j M Y', strtotime($p['renews_on']))) : '',
            $p['through_employer'] ? 'through my employer' : '',
            $p['underwriting'] ? h(PLAN_UNDERWRITING[$p['underwriting']]) . ' underwriting' : '',
            $p['six_week'] ? 'six-week option' : '',
            $p['hospital_list'] ? 'hospitals: ' . h($p['hospital_list']) : '',
        ])) ?></p>
        <?php if (plan_covers($p)): ?>
        <ul class="cover-list small">
            <?php foreach (plan_covers($p) as $c): $used = $u['used'][$c] ?? 0.0; ?>
            <li><span>&#10003; <?= h(PLAN_COVERS[$c]) ?></span><?php if (isset($limits[$c])): $left = max(0, $limits[$c] - $used); ?>
                <span class="cover-limit"><meter min="0" max="<?= h((string) $limits[$c]) ?>" value="<?= h((string) min($used, $limits[$c])) ?>"></meter>
                <?= money($used) ?> of <?= money($limits[$c]) ?> used, <strong><?= money($left) ?> left</strong></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($p['exclusions']): ?><p class="small"><strong>Not covered:</strong> <?= nl2br(h($p['exclusions'])) ?></p><?php endif; ?>
        <?php if ($p['notes']): ?><p class="small"><?= nl2br(h($p['notes'])) ?></p><?php endif; ?>
        <?php if ($u['cost'] > 0 || $u['covered'] > 0): ?>
        <p class="muted small">This policy year (from <?= h($u['from']->format('j M Y')) ?>): treatment <?= money($u['cost']) ?>, paid by the plan <?= money($u['covered']) ?><?= $u['waiting'] ? ', ' . money($u['waiting']) . ' waiting to be claimed or paid' : '' ?>.</p>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <span class="inline-actions">
            <details class="q-edit"><summary class="btn btn-small">Edit</summary>
                <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="ph_plan_save"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                    <?php plan_fields($p); ?><span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span></form>
                <form method="post" data-confirm="Delete this plan? Claims are kept, without the plan."><?= csrf_field() ?><input type="hidden" name="action" value="ph_plan_delete"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn-link danger small" type="submit">Delete plan</button></form>
            </details>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ph_plan_end"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>"><button class="btn btn-small" type="submit"><?= $p['is_active'] ? 'It has ended' : 'Still have it' ?></button></form>
        </span>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php if ($canEdit): ?>
    <?php if ($scan): ?>
    <div class="flash flash-info" id="ph-scan" role="status"><strong>Read from your document:</strong> <?= h(implode(', ', $scan['found'])) ?>.
        Check everything below against the document, and fill in anything missing, before saving. Nothing is saved yet.</div>
    <?php endif; ?>
    <details class="q-edit"<?= $plans && !$scan ? '' : ' open' ?>><summary>Add a plan</summary>
        <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="ph_plan_save"><?php plan_fields($scan['plan'] ?? null); ?>
            <span class="inline-actions"><button class="btn btn-primary" type="submit">Save plan</button></span></form>
    </details>
    <?php if (!$scan): ?><details class="q-edit"><summary>Fill in from a policy document</summary><?php policy_upload_fields('ph_scan'); ?></details><?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($upcoming): ?>
<section class="card">
    <h2>Private appointments coming up</h2>
    <ul class="plain"><?php foreach ($upcoming as $a): ?>
        <li class="logline"><span><a href="<?= h(url('appointment.php?id=' . $a['id'])) ?>"><strong><?= h(appointment_title($a)) ?></strong></a> <?= h(local_time($a['starts_at'], 'D j M, H:i')) ?>
            <br><span class="muted small"><?= h(appointment_funding_text($a, $byId[$a['plan_id']] ?? null)) ?></span>
            <?php if ($a['funding'] === 'plan' && !$a['preauth'] && ($byId[$a['plan_id']]['kind'] ?? '') === 'insurance'): ?><br><span class="warn small">No pre-authorisation number yet: most insurers need you to call before treatment.</span><?php endif; ?></span></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>

<section class="card" id="ph-claims">
    <h2>Treatment and claims <?= help_link('private-health', 'treatment-and-claims', 'claims') ?></h2>
    <?php if ($unclaimed): ?>
    <p class="small"><strong>Private appointments with nothing recorded yet:</strong></p>
    <ul class="plain"><?php foreach (array_slice($unclaimed, 0, 6) as $a): ?>
        <li class="logline"><span><?= h(local_time($a['starts_at'], 'j M Y')) ?> · <?= h(appointment_title($a)) ?><?= $a['cost'] !== null ? ' · ' . money((float) $a['cost']) : '' ?></span>
            <?php if ($canEdit): ?><a class="btn btn-small" href="<?= h(url('appointment.php?id=' . $a['id'] . '#private')) ?>">Record it</a><?php endif; ?></li>
    <?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($claims): ?>
    <div class="table-wrap"><table><thead><tr><th>Date</th><th>Treatment</th><th>Plan</th><th class="num">Cost</th><th class="num">Plan pays</th><th>Status</th><th></th></tr></thead><tbody>
        <?php foreach ($claims as $c): ?>
        <tr><td><?= h(date('j M Y', strtotime($c['treated_on']))) ?></td>
            <td><?= h($c['description']) ?><?= $c['cover'] ? '<br><span class="muted small">' . h(PLAN_COVERS[$c['cover']] ?? '') . '</span>' : '' ?><?= $c['reference'] ? '<br><span class="muted small">Ref ' . h($c['reference']) . '</span>' : '' ?></td>
            <td><?= h(isset($byId[$c['plan_id']]) ? $byId[$c['plan_id']]['provider'] : '–') ?></td>
            <td class="num"><?= $c['cost'] !== null ? money((float) $c['cost']) : '' ?></td>
            <td class="num"><?= $c['covered'] !== null ? money((float) $c['covered']) : '' ?></td>
            <td><span class="badge<?= in_array($c['status'], ['paid', 'direct'], true) ? ' badge-taken' : ($c['status'] === 'to_claim' ? ' badge-due' : '') ?>"><?= h(CLAIM_STATUSES[$c['status']] ?? $c['status']) ?></span></td>
            <td><?php if ($canEdit): ?><details class="q-edit"><summary class="btn btn-small">Edit</summary><?php claim_form($c, $plans, null, 'care.php?area=privatehealth#ph-claims'); ?>
                <form method="post" data-confirm="Delete this?"><?= csrf_field() ?><input type="hidden" name="action" value="ph_claim_delete"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>"><button class="btn-link danger small" type="submit">Delete</button></form></details><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <details class="q-edit"<?= !$claims && $plans ? ' open' : '' ?>><summary>Add treatment or a claim</summary><?php claim_form(null, $active, null, 'care.php?area=privatehealth#ph-claims'); ?></details>
    <?php endif; ?>
</section>

<?php if ($plans || $claims): $spent = (float) q("SELECT COALESCE(SUM(cost), 0) - COALESCE(SUM(CASE WHEN status IN ('paid', 'direct') THEN covered END), 0) FROM health_claims WHERE user_id = ? AND treated_on > ?",
    [$uid, $today->modify('-1 year')->format('Y-m-d')])->fetchColumn();
    $owed = (float) q("SELECT COALESCE(SUM(COALESCE(covered, cost)), 0) FROM health_claims WHERE user_id = ? AND status IN ('to_claim', 'claimed') AND treated_on > ?",
        [$uid, $today->modify('-1 year')->format('Y-m-d')])->fetchColumn(); ?>
<section class="card">
    <h2>What it costs</h2>
    <div class="tiles">
        <div class="tile"><span class="tile-label">Plans</span><span class="tile-value"><?= money($yearly) ?></span><span class="tile-meta">a year<?= $yearly ? ' (' . money($yearly / 12) . ' a month)' : '' ?></span></div>
        <div class="tile"><span class="tile-label">Treatment you paid</span><span class="tile-value"><?= money(max(0, $spent)) ?></span><span class="tile-meta">last 12 months, after claims<?= $owed > 0 ? ', ' . money($owed) . ' still to come back' : '' ?></span></div>
        <div class="tile"><span class="tile-label">Total</span><span class="tile-value"><?= money($yearly + max(0, $spent)) ?></span><span class="tile-meta">a year</span></div>
    </div>
    <?php $privateRx = array_filter(user_medications($uid), fn ($m) => $m['supply_type'] === 'private' && $m['supply_price'] !== null); ?>
    <?php if ($privateRx): ?><p class="muted small">Private prescriptions are on top: <?= h(implode(', ', array_map(fn ($m) => $m['name'] . ' ' . money((float) $m['supply_price']), $privateRx))) ?> each time (see Meds).</p><?php endif; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2>Good to know</h2>
    <ul class="small">
        <li><strong>Before treatment</strong>, call your insurer for a pre-authorisation number. Many also need a GP referral, sometimes an "open referral" letter that doesn't name a specialist.</li>
        <li><strong>Existing conditions</strong> are often not covered: with moratorium underwriting, usually for the first 2 years. Check before switching insurer.</li>
        <li><strong>Cash plans</strong> pay back a share of everyday costs (dental, glasses, physio) up to a yearly limit. Keep receipts and claim within the time limit.</li>
        <li><strong>Health insurance through work</strong> is usually a taxable benefit, shown on your P11D.</li>
        <li>You can switch between NHS and private care for different parts of your treatment. Tell your GP about private treatment and medicines, so your record stays complete.</li>
    </ul>
</section>
    <?php
}

/** Add or edit treatment / a claim. */
function claim_form(?array $c, array $plans, ?array $appt, string $return): void
{
    $c ??= ['id' => 0, 'plan_id' => $appt['plan_id'] ?? ($plans[0]['id'] ?? null), 'appointment_id' => $appt['id'] ?? null,
        'treated_on' => $appt ? local_time($appt['starts_at'], 'Y-m-d') : local_today()->format('Y-m-d'), 'cover' => null,
        'description' => $appt ? appointment_title($appt) : '', 'cost' => $appt['cost'] ?? null, 'covered' => null,
        'status' => ($appt['funding'] ?? '') === 'self' ? 'self' : 'to_claim', 'reference' => $appt['preauth'] ?? null, 'notes' => null];
    $m = fn ($v) => $v === null ? '' : h(number_format((float) $v, 2, '.', ''));
    ?>
    <form method="post" action="<?= h(url('care.php?area=privatehealth')) ?>" class="stack"><?= csrf_field() ?>
        <input type="hidden" name="action" value="ph_claim_save"><input type="hidden" name="claim_id" value="<?= (int) $c['id'] ?>">
        <input type="hidden" name="appointment_id" value="<?= h((string) ($c['appointment_id'] ?? '')) ?>"><input type="hidden" name="return" value="<?= h($return) ?>">
        <div class="grid-2">
            <label>What it was <input name="description" maxlength="200" required value="<?= h((string) $c['description']) ?>" placeholder="e.g. Physio, new glasses, MRI scan"></label>
            <label>Date <input type="date" name="treated_on" value="<?= h((string) $c['treated_on']) ?>"></label>
            <label>Plan <select name="plan_id"><option value="">None (paid myself)</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected((int) $c['plan_id'] === (int) $p['id']) ?>><?= h(plan_title($p)) ?></option><?php endforeach; ?></select></label>
            <label>Covered under <select name="cover"><option value="">&ndash;</option><?php foreach (PLAN_COVERS as $k => $l): ?><option value="<?= $k ?>"<?= selected($c['cover'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>It cost <input name="cost" inputmode="decimal" placeholder="£" value="<?= $m($c['cost']) ?>"></label>
            <label>The plan pays (or paid) <input name="covered" inputmode="decimal" placeholder="£" value="<?= $m($c['covered']) ?>"></label>
            <label>Status <select name="status"><?php foreach (CLAIM_STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= selected($c['status'] === $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
            <label>Claim or pre-authorisation number <input name="reference" maxlength="60" value="<?= h((string) ($c['reference'] ?? '')) ?>"></label>
        </div>
        <span class="inline-actions"><button class="btn btn-primary" type="submit">Save</button></span>
    </form>
    <?php
}
