<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];

if (is_post()) {
    require_post();
    switch (post('action')) {
        case 'delete':
            $appointment = find_appointment((int) post('id'), $uid) ?? not_found();
            q('DELETE FROM appointments WHERE id = ?', [$appointment['id']]);
            flash('success', appointment_title($appointment) . ' on ' . local_time($appointment['starts_at'], 'j M') . ' deleted.');
            redirect('appointments.php');

        case 'calendar_create':
        case 'calendar_regenerate':
        case 'calendar_off':
        case 'calendar_notes':
            owner_only('Changing the calendar link');
            demo_blocked('The calendar link');
    }
    switch (post('action')) {
        case 'calendar_create':
        case 'calendar_regenerate':
            q('UPDATE users SET calendar_token = ? WHERE id = ?', [bin2hex(random_bytes(20)), $uid]);
            flash('success', post('action') === 'calendar_create'
                ? 'Your calendar link is ready. Add it to your calendar app below.'
                : 'New calendar link created. The old link has stopped working, so update it in your calendar apps.');
            redirect('appointments.php#subscribe');

        case 'calendar_off':
            q('UPDATE users SET calendar_token = NULL WHERE id = ?', [$uid]);
            flash('success', 'Your calendar link has been turned off. Calendar apps using it will stop updating.');
            redirect('appointments.php#subscribe');

        case 'calendar_notes':
            q('UPDATE users SET calendar_include_notes = ? WHERE id = ?', [isset($_POST['include_notes']) ? 1 : 0, $uid]);
            flash('success', 'Calendar link settings saved.');
            redirect('appointments.php#subscribe');
    }
    redirect('appointments.php');
}

$now = utc_now();
$upcoming = appointments_between($uid, gmdate('Y-m-d H:i:s', time() - 3 * 3600), '9999-12-31 00:00:00');
$past = array_reverse(appointments_between($uid, gmdate('Y-m-d H:i:s', strtotime('-2 years')), gmdate('Y-m-d H:i:s', time() - 3 * 3600)));
$questionCounts = appointment_question_counts(array_merge(array_column($upcoming, 'id'), array_column($past, 'id')));
$feedUrl = $user['calendar_token'] ? calendar_feed_url($user['calendar_token']) : null;

function render_appointment(array $a, bool $isPast, array $counts): void
{
    $qc = $counts[$a['id']] ?? null;
    $start = new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC'));
    $local = $start->setTimezone(user_tz());
    $end = $local->modify('+' . (int) $a['duration_minutes'] . ' minutes');
    $today = local_today();
    $days = (int) $today->diff($local->setTime(0, 0))->format('%r%a');
    $when = match (true) {
        $isPast => '',
        $days === 0 => 'Today',
        $days === 1 => 'Tomorrow',
        $days > 1 && $days < 7 => 'In ' . $days . ' days',
        default => '',
    };
    ?>
    <li class="appt<?= $isPast ? ' appt-past' : '' ?>">
        <div class="appt-date" aria-hidden="true">
            <span class="appt-month"><?= h($local->format('M')) ?></span>
            <span class="appt-day"><?= h($local->format('j')) ?></span>
            <span class="appt-dow"><?= h($local->format('D')) ?></span>
        </div>
        <div class="appt-main">
            <a class="appt-title" href="<?= h(url('appointment.php?id=' . $a['id'])) ?>"><?= h(appointment_title($a)) ?></a><?= $a['funding'] ? ' <span class="badge">Private</span>' : '' ?>
            <?php if ($when): ?><span class="badge badge-upcoming"><?= h($when) ?></span><?php endif; ?>
            <div class="small"><?= h($local->format('l j F Y')) ?>, <?= h($local->format('H:i')) ?>–<?= h($end->format('H:i')) ?></div>
            <div class="muted small">
                <?= h(implode(' · ', array_filter([
                    $a['title'] ? appointment_type_label($a['kind']) : '',   // the type is already the headline when there's no title
                    $a['with_whom'] ? 'with ' . $a['with_whom'] : '',
                    (string) $a['location'],
                ]))) ?>
            </div>
            <?php if ($a['notes']): ?><p class="appt-notes small"><?= nl2br(h($a['notes'])) ?></p><?php endif; ?>
            <p class="small appt-prep">
                <?php if ($qc): ?>
                    <a href="<?= h(url('appointment.php?id=' . $a['id'] . '#questions')) ?>"><?= $qc['total'] ?> question<?= $qc['total'] === 1 ? '' : 's' ?><?= $qc['done'] ? ' (' . $qc['done'] . ' asked)' : '' ?></a>
                <?php elseif (!$isPast): ?>
                    <a href="<?= h(url('appointment.php?id=' . $a['id'] . '#questions')) ?>">Prepare questions</a>
                <?php endif; ?>
                <?php if ($isPast): ?>
                    <a href="<?= h(url('appointment.php?id=' . $a['id'] . '#outcome')) ?>"><?= $a['outcome_notes'] ? 'What was decided' : 'Add notes from the appointment' ?></a>
                <?php endif; ?>
            </p>
        </div>
        <div class="appt-actions">
            <a class="btn btn-small" href="<?= h(url('appointment_edit.php?id=' . $a['id'])) ?>">Edit</a>
            <form method="post" data-confirm="Delete this appointment?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <button class="btn btn-small btn-danger" type="submit">Delete</button>
            </form>
        </div>
    </li>
    <?php
}

render_header('Appointments', 'appointments');
?>
<div class="page-head">
    <h1>Appointments <?= help_link('appointments', '', 'Appointments') ?></h1>
    <a class="btn btn-primary" href="<?= h(url('appointment_edit.php')) ?>">+ Add appointment</a>
</div>

<section class="card">
    <h2>Coming up</h2>
    <?php if (!$upcoming): ?>
        <p class="muted">No upcoming appointments. <a href="<?= h(url('appointment_edit.php')) ?>">Add one</a> &mdash; GP, hospital, dentist, optician, blood tests and more.</p>
    <?php else: ?>
    <ul class="appt-list">
        <?php foreach ($upcoming as $a) { render_appointment($a, false, $questionCounts); } ?>
    </ul>
    <?php endif; ?>
</section>

<?php if ($past): ?>
<details class="card">
    <summary><h2>Past appointments (<?= count($past) ?>)</h2></summary>
    <ul class="appt-list">
        <?php foreach ($past as $a) { render_appointment($a, true, $questionCounts); } ?>
    </ul>
</details>
<?php endif; ?>

<?php if (!isset($user['_acting'])): ?>
<section class="card" id="subscribe">
    <h2>Add to your calendar</h2>
    <?php if (!$feedUrl): ?>
        <p>Get a private link that shows your appointments in your phone or computer calendar &mdash; Apple Calendar, Google Calendar, Outlook, Proton Calendar and others. It updates by itself when you add or change appointments here.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="calendar_create">
            <button class="btn btn-primary" type="submit">Create my calendar link</button>
        </form>
    <?php else: ?>
        <p class="small">Subscribe to this link in your calendar app. It's unique to you: <strong>anyone who has it can see your appointments</strong>, so only share it with people you trust.</p>
        <div class="copy-row">
            <label class="sr" for="feed-url">Your calendar link</label>
            <input id="feed-url" type="text" readonly value="<?= h($feedUrl) ?>">
            <button class="btn" type="button" data-copy="#feed-url">Copy</button>
        </div>
        <div class="subscribe-buttons">
            <a class="btn btn-small" href="<?= h(preg_replace('#^https?://#', 'webcal://', $feedUrl)) ?>">Apple Calendar / Outlook</a>
            <a class="btn btn-small" href="<?= h('https://calendar.google.com/calendar/render?cid=' . rawurlencode(preg_replace('#^https?://#', 'webcal://', $feedUrl))) ?>" target="_blank" rel="noopener">Google Calendar</a>
        </div>
        <details class="small subscribe-help">
            <summary>How to add it</summary>
            <ul>
                <li><strong>iPhone/iPad:</strong> tap <em>Apple Calendar / Outlook</em> above, then <em>Subscribe</em>. Or Settings &rarr; Calendar &rarr; Accounts &rarr; Add Account &rarr; Other &rarr; Add Subscribed Calendar, and paste the link.</li>
                <li><strong>Android / Google Calendar:</strong> tap <em>Google Calendar</em> above (on a computer), or in Google Calendar on the web choose <em>Other calendars &rarr; + &rarr; From URL</em> and paste the link. It then appears on your phone too.</li>
                <li><strong>Outlook:</strong> <em>Add calendar &rarr; Subscribe from web</em>, and paste the link.</li>
                <li><strong>Proton Calendar:</strong> copy the link above, then at <a href="https://calendar.proton.me" target="_blank" rel="noopener">calendar.proton.me</a> press <em>+</em> next to <em>My calendars</em> (or <em>Settings &rarr; Calendars &rarr; Other calendars</em>), choose <em>Add calendar from URL</em>, paste the link and press <em>Add calendar</em>.
                    It has to be done on the web, then it shows in Proton's phone and desktop apps too. It counts towards the number of calendars your Proton plan allows.</li>
            </ul>
            <p>Calendar apps check for changes every few hours (Proton every 4 to 16 hours), so a new appointment can take a little while to appear.
                They fetch the link from the internet, so it only works if this Pillbox can be reached from outside your home network.</p>
        </details>
        <form method="post" class="feed-options">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="calendar_notes">
            <label class="check"><input type="checkbox" name="include_notes" value="1"<?= checked((bool) $user['calendar_include_notes']) ?>> Include my appointment notes in the calendar</label>
            <button class="btn btn-small" type="submit">Save</button>
        </form>
        <div class="feed-actions">
            <form method="post" data-confirm="Make a new link? The current link will stop working and you'll need to add the new one to your calendar apps.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="calendar_regenerate">
                <button class="btn btn-small" type="submit">Make a new link</button>
            </form>
            <form method="post" data-confirm="Turn off your calendar link? Calendar apps using it will stop showing your appointments.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="calendar_off">
                <button class="btn btn-small btn-danger" type="submit">Turn off link</button>
            </form>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php render_footer();
