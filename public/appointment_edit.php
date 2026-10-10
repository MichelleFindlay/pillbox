<?php
require __DIR__ . '/../src/bootstrap.php';

$user = require_login();
$uid = (int) $user['id'];

$id = (int) get('id');
$appointment = $id ? (find_appointment($id, $uid) ?? not_found()) : null;

$form = $appointment ? [
    'kind'             => $appointment['kind'],
    'title'            => (string) $appointment['title'],
    'date'             => local_time($appointment['starts_at'], 'Y-m-d'),
    'time'             => local_time($appointment['starts_at'], 'H:i'),
    'duration_minutes' => (string) $appointment['duration_minutes'],
    'with_whom'        => (string) $appointment['with_whom'],
    'location'         => (string) $appointment['location'],
    'notes'            => (string) $appointment['notes'],
    'remind_minutes'   => (string) (int) $appointment['remind_minutes'],
] : [
    'kind' => isset(APPOINTMENT_TYPES[get('kind')]) ? get('kind') : 'gp', 'title' => '', 'date' => local_today()->modify('+1 day')->format('Y-m-d'), 'time' => '09:00',
    'duration_minutes' => '15', 'with_whom' => '', 'location' => '', 'notes' => '', 'remind_minutes' => '1440',
];
$errors = [];

if (is_post()) {
    require_post();
    foreach (array_keys($form) as $field) {
        $form[$field] = post($field);
    }
    if (!isset(APPOINTMENT_TYPES[$form['kind']])) {
        $errors[] = 'Choose the type of appointment.';
    }
    $startsAt = local_to_utc($form['date'] . ' ' . $form['time']);
    if (!parse_date($form['date']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $form['time']) || $startsAt === null) {
        $errors[] = 'Enter the date and time of the appointment.';
    }
    if (!isset(APPOINTMENT_DURATIONS[(int) $form['duration_minutes']])) {
        $form['duration_minutes'] = '30';
    }
    if (!isset(APPOINTMENT_REMINDERS[(int) $form['remind_minutes']])) {
        $form['remind_minutes'] = '0';
    }
    foreach (['title' => 150, 'with_whom' => 150, 'location' => 255, 'notes' => 5000] as $field => $max) {
        if (mb_strlen($form[$field]) > $max) {
            $errors[] = 'That ' . str_replace('_', ' ', $field) . " is too long (max $max characters).";
        }
    }

    if (!$errors) {
        $values = [$form['kind'], $form['title'] ?: null, $startsAt, (int) $form['duration_minutes'], $form['with_whom'] ?: null,
            $form['location'] ?: null, $form['notes'] ?: null, (int) $form['remind_minutes'] ?: null];
        if ($appointment) {
            q('UPDATE appointments SET kind = ?, title = ?, starts_at = ?, duration_minutes = ?, with_whom = ?, location = ?, notes = ?, remind_minutes = ?
               WHERE id = ? AND user_id = ?', [...$values, $appointment['id'], $uid]);
        } else {
            q('INSERT INTO appointments (kind, title, starts_at, duration_minutes, with_whom, location, notes, remind_minutes, user_id)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$values, $uid]);
        }
        $label = $form['title'] ?: appointment_type_label($form['kind']);
        flash('success', $label . ' on ' . local_time($startsAt, 'l j F \a\t H:i') . ($appointment ? ' updated.' : ' added.'));
        redirect('appointments.php');
    }
}

render_header($appointment ? 'Edit appointment' : 'Add appointment', 'appointments');
?>
<div class="page-head">
    <h1><?= $appointment ? 'Edit appointment' : 'Add appointment' ?></h1>
    <a class="btn" href="<?= h(url('appointments.php')) ?>">Cancel</a>
</div>
<?= form_errors($errors) ?>

<form method="post" class="card stack">
    <?= csrf_field() ?>
    <div class="grid-2">
        <label>Type of appointment
            <select name="kind" required>
                <?php foreach (APPOINTMENT_TYPES as $key => $label): ?><option value="<?= h($key) ?>"<?= selected($form['kind'] === $key) ?>><?= h($label) ?></option><?php endforeach; ?>
            </select>
        </label>
        <label><span>Title <span class="muted small">(optional, e.g. "Diabetes review")</span></span>
            <input name="title" maxlength="150" value="<?= h($form['title']) ?>"></label>
    </div>
    <div class="grid-3">
        <label>Date <input type="date" name="date" required value="<?= h($form['date']) ?>"></label>
        <label>Time <input type="time" name="time" required value="<?= h($form['time']) ?>"></label>
        <label>How long
            <select name="duration_minutes">
                <?php foreach (APPOINTMENT_DURATIONS as $mins => $label): ?><option value="<?= $mins ?>"<?= selected((int) $form['duration_minutes'] === $mins) ?>><?= h($label) ?></option><?php endforeach; ?>
            </select>
        </label>
    </div>
    <div class="grid-2">
        <label><span>With <span class="muted small">(optional)</span></span>
            <input name="with_whom" maxlength="150" value="<?= h($form['with_whom']) ?>" placeholder="e.g. Dr Patel"></label>
        <label><span>Where <span class="muted small">(optional)</span></span>
            <input name="location" maxlength="255" value="<?= h($form['location']) ?>" placeholder="e.g. Riverside Surgery, Room 4"></label>
    </div>
    <label><span>Notes <span class="muted small">(optional: questions to ask, things to bring, fasting, ...)</span></span>
        <textarea name="notes" rows="3" maxlength="5000"><?= h($form['notes']) ?></textarea></label>
    <label class="narrow">Reminder in your calendar app
        <select name="remind_minutes">
            <?php foreach (APPOINTMENT_REMINDERS as $mins => $label): ?><option value="<?= $mins ?>"<?= selected((int) $form['remind_minutes'] === $mins) ?>><?= h($label) ?></option><?php endforeach; ?>
        </select>
    </label>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit"><?= $appointment ? 'Save changes' : 'Add appointment' ?></button>
        <a class="btn" href="<?= h(url('appointments.php')) ?>">Cancel</a>
    </div>
</form>
<?php render_footer();
