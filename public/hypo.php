<?php
/*
 * The hypo guide: what to do when glucose is under 4 mmol/L ("4 is the floor"), with a
 * 15-minute timer to recheck, and a button to log it. One tap from Diabetes and Today.
 */
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];

if (is_post()) {
    require_post();
    $g = is_numeric(post('glucose')) && (float) post('glucose') >= 0.5 && (float) post('glucose') <= 40 ? (float) post('glucose') : null;
    q('INSERT INTO hypo_events (user_id, at, glucose, treated_with, notes) VALUES (?, UTC_TIMESTAMP(), ?, ?, ?)',
        [$uid, $g, trim(mb_substr(post('treated_with'), 0, 150)) ?: null, trim(mb_substr(post('notes'), 0, 500)) ?: null]);
    if ($g !== null && readings_writable()) { // the glucose also goes in Readings, if this person may add readings
        q("INSERT INTO health_readings (user_id, metric, value1, context, notes, recorded_at) VALUES (?, 'blood_glucose', ?, 'Hypo symptoms', 'Hypo', UTC_TIMESTAMP())", [$uid, $g]);
    }
    flash('success', 'Logged. Once you feel better, have a snack with longer-acting carbohydrate if your next meal is more than an hour away.');
    redirect('hypo.php');
}

render_header('Hypo guide', 'care');
?>
<section class="card hypo">
    <h1>Glucose under 4? Treat it now</h1>
    <ol class="hypo-steps">
        <li><strong>Have 15 to 20 g of fast-acting sugar</strong>, for example 4 or 5 glucose tablets, about 150 to 200 ml of a non-diet fizzy drink or pure fruit juice, or 5 jelly babies (check the labels).
            Sit down if you can.</li>
        <li><strong>Wait 15 minutes</strong>, then check again. <button class="btn btn-small btn-primary" type="button" data-timer-quick="900">Start a 15-minute timer</button></li>
        <li><strong>Still under 4? Repeat</strong> the sugar and check again after another 15 minutes. If it's still low after 3 tries, or you feel worse, get help: call your diabetes team or 999.</li>
        <li><strong>Back above 4:</strong> if your next meal is more than an hour away, have a snack with longer-acting carbohydrate (a slice of toast, a sandwich or some biscuits).</li>
    </ol>
    <section class="card timer" data-timer hidden>
        <div class="timer-ring" aria-hidden="true">
            <svg viewBox="0 0 120 120"><circle class="timer-track" cx="60" cy="60" r="52"/><circle class="timer-fill" cx="60" cy="60" r="52" pathLength="100" stroke-dasharray="100 100" data-timer-ring/></svg>
            <span class="timer-time" data-timer-time>15:00</span>
        </div>
        <p class="sr" aria-live="polite" data-timer-status></p>
        <div hidden><button type="button" data-timer-set="900"></button><input data-timer-custom></div>
        <span class="inline-actions"><button class="btn btn-primary" type="button" data-timer-start>Start</button><button class="btn" type="button" data-timer-pause hidden>Pause</button>
            <button class="btn" type="button" data-timer-reset>Reset</button></span>
    </section>
    <div class="flash flash-error">
        <strong>If someone is unconscious, having a fit, or can't swallow safely:</strong> don't give anything by mouth. Put them in the recovery position and call 999.
        Give a glucagon injection only if you've been trained to.
    </div>
    <p class="small">Driving? Stop safely, take the key out of the ignition and move to the passenger seat. Wait 45 minutes after your glucose is back to normal before driving again.</p>
    <details class="q-edit"><summary>Log this hypo</summary>
        <form method="post" class="stack"><?= csrf_field() ?>
            <div class="grid-2">
                <label>Glucose (mmol/L) <input type="number" name="glucose" min="0.5" max="40" step="0.1" class="count-input"></label>
                <label>Treated with <input name="treated_with" maxlength="150" placeholder="e.g. 5 glucose tablets"></label>
            </div>
            <label>What might have caused it <input name="notes" maxlength="500" placeholder="e.g. exercise, missed snack, more insulin than usual"></label>
            <span class="inline-actions"><button class="btn btn-primary btn-small" type="submit">Log it</button></span>
        </form>
    </details>
</section>
<?php render_footer();
