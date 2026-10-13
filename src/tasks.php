<?php
declare(strict_types=1);

/*
 * Scheduled tasks, run by bin/cron.php (command line) or public/cron.php?token=...
 * every 5 to 15 minutes (5 is best for dose reminders): push notifications, daily summary emails,
 * appointment reminders, the update check (asking GitHub on every run, and telling admins about
 * a new version once), and tidying up.
 */

/** @return string[] what was done, for the log */
function run_scheduled_tasks(): array
{
    $lock = fopen(storage_path('cron.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['Already running.'];
    }
    $log = [];
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    foreach (['sync_connections', 'sync_weather', 'send_push_notifications', 'send_digests', 'send_appointment_reminders', 'check_for_updates', 'tidy_up'] as $task) {
        try {
            $log = [...$log, ...$task($now)];
        } catch (Throwable $e) {
            error_log("Pillbox task $task failed: $e");
            $log[] = "$task failed: " . $e->getMessage();
        }
    }
    set_current_user(null);
    unset($GLOBALS['PILLBOX_TASK_USER']);
    @file_put_contents(storage_path('cron_last.json'), json_encode(['ran_at' => $now->getTimestamp(), 'log' => $log]), LOCK_EX);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $log;
}

/** When the scheduled tasks last ran (unix time), or null. */
function tasks_last_run(): ?array
{
    $data = json_decode((string) @file_get_contents(storage_path('cron_last.json')), true);
    return is_array($data) ? $data : null;
}

/* ---------- daily summary ---------- */

function send_digests(DateTimeImmutable $now): array
{
    if (!mail_enabled()) {
        return [];
    }
    $log = [];
    foreach (q("SELECT * FROM users WHERE is_active = 1 AND is_demo = 0 AND email_digest = 1 AND email IS NOT NULL AND email <> ''") as $user) {
        set_current_user($user);
        $local = $now->setTimezone(user_tz($user));
        if ((int) $local->format('G') < (int) $user['email_digest_hour'] || $user['last_digest_on'] === $local->format('Y-m-d')) {
            continue;
        }
        q('UPDATE users SET last_digest_on = ? WHERE id = ?', [$local->format('Y-m-d'), $user['id']]);
        $digest = build_digest($user, new DateTimeImmutable($local->format('Y-m-d'), $local->getTimezone()));
        if ($digest === null) {
            $log[] = "Nothing to send to user {$user['id']} today.";
            continue;
        }
        $error = send_mail($user['email'], ...$digest);
        $log[] = $error ? "Summary to user {$user['id']} failed: $error" : "Summary sent to user {$user['id']}.";
    }
    return $log;
}

/**
 * The daily summary: today's doses, anything missed yesterday, low or expiring medicines,
 * Care items coming up, and appointments today and tomorrow. Nothing from Mind.
 * @return array{0: string, 1: string, 2: string}|null [subject, text, html], or null if there's nothing to say
 */
function build_digest(array $user, DateTimeImmutable $today): ?array
{
    $uid = (int) $user['id'];
    $tz = $today->getTimezone();
    $sections = [];

    $slots = dose_slots($user, $today, $today, true);
    if ($slots) {
        $sections['Doses today'] = array_map(fn ($s) => $s['local']->format('H:i') . '  ' . public_med_name($s['schedule'])
            . ' (' . dose_label($s['schedule']['dose_amount'], $s['schedule']['dose_unit']) . ')', $slots);
    }
    $missed = array_filter(dose_slots($user, $today->modify('-1 day'), $today->modify('-1 day'), true), fn ($s) => $s['status'] === 'missed');
    if ($missed) {
        $sections['Not logged yesterday'] = array_map(fn ($s) => $s['local']->format('H:i') . '  ' . public_med_name($s['schedule']), array_values($missed));
    }
    $meds = user_medications($uid);
    $schedules = schedules_by_medication(array_column($meds, 'id'), $today->format('Y-m-d'));
    $medNotes = [];
    foreach ($meds as $m) {
        $days = stock_days_left($m, $schedules[$m['id']] ?? []);
        if ($days !== null && $days <= (int) $m['low_stock_days']) {
            $medNotes[] = public_med_name($m) . ($m['ordered_on'] ? ': ordered ' . date('j M', strtotime($m['ordered_on'])) . ', waiting to collect' : ': running low')
                . ', about ' . (int) floor($days) . ' days left';
        }
        [$state, $text] = expiry_status($m['expiry_date'], $today);
        if ($state) {
            $medNotes[] = public_med_name($m) . ': ' . lcfirst($text);
        }
    }
    if ($medNotes) {
        $sections['Medicines'] = $medNotes;
    }
    $checks = checks_coming_up($user, user_care_areas($user), $today);
    if ($checks) {
        $sections['Coming up'] = array_map(fn ($c) => $c['check']['label'] . ': ' . lcfirst(check_due_text($c['check'], $c['due'], $today)), $checks);
    }
    $utc = new DateTimeZone('UTC');
    $appts = appointments_between($uid, $today->setTimezone($utc)->format('Y-m-d H:i:s'), $today->modify('+2 days')->setTimezone($utc)->format('Y-m-d H:i:s'));
    if ($appts) {
        $sections['Appointments'] = array_map(function ($a) use ($tz, $today) {
            $start = (new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC')))->setTimezone($tz);
            return ($start->format('Y-m-d') === $today->format('Y-m-d') ? 'Today' : 'Tomorrow') . ' ' . $start->format('H:i') . '  ' . appointment_title($a)
                . ($a['with_whom'] ? ' with ' . $a['with_whom'] : '') . ($a['location'] ? ', ' . $a['location'] : '');
        }, $appts);
    }
    if (!$sections) {
        return null;
    }

    $title = $today->format('l j F');
    $text = "Hello {$user['display_name']},\n\nHere's your Pillbox summary for $title.\n";
    $html = '<p>Hello ' . h($user['display_name']) . ',</p>';
    foreach ($sections as $heading => $lines) {
        $text .= "\n$heading\n" . implode("\n", array_map(fn ($l) => "  - $l", $lines)) . "\n";
        $html .= '<h2 style="font-size:16px;margin:20px 0 8px">' . h($heading) . '</h2><ul style="padding-left:20px;margin:0">'
            . implode('', array_map(fn ($l) => '<li style="margin:4px 0">' . h($l) . '</li>', $lines)) . '</ul>';
    }
    $link = absolute_url('index.php');
    $text .= "\nOpen Pillbox: $link\n";
    $html .= '<p style="margin-top:20px"><a href="' . h($link) . '" style="background:#4b5d3a;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Open Pillbox</a></p>';
    return ["Pillbox: $title", $text, mail_html("Your day: $title", $html)];
}

/* ---------- appointment reminders ---------- */

function send_appointment_reminders(DateTimeImmutable $now): array
{
    if (!mail_enabled()) {
        return [];
    }
    $log = [];
    $rows = q("SELECT a.*, u.id AS uid FROM appointments a JOIN users u ON u.id = a.user_id
               WHERE u.is_active = 1 AND u.is_demo = 0 AND u.email_appointments = 1 AND u.email IS NOT NULL AND u.email <> ''
                 AND a.reminder_emailed_at IS NULL AND a.starts_at > ? AND a.starts_at <= ?",
        [$now->format('Y-m-d H:i:s'), $now->modify('+8 days')->format('Y-m-d H:i:s')])->fetchAll();
    foreach ($rows as $a) {
        $minutes = (int) ($a['remind_minutes'] ?: 1440);
        $start = new DateTimeImmutable($a['starts_at'], new DateTimeZone('UTC'));
        if ($now < $start->modify("-$minutes minutes")) {
            continue;
        }
        $user = q('SELECT * FROM users WHERE id = ?', [$a['uid']])->fetch();
        set_current_user($user);
        $local = $start->setTimezone(user_tz($user));
        $when = $local->format('l j F \a\t H:i');
        $what = appointment_title($a) . ($a['with_whom'] ? ' with ' . $a['with_whom'] : '');
        $questions = q('SELECT question FROM appointment_questions WHERE appointment_id = ? AND done = 0', [$a['id']])->fetchAll(PDO::FETCH_COLUMN);
        $link = absolute_url('appointment.php?id=' . $a['id']);
        $text = "Reminder: $what, $when." . ($a['location'] ? "\nWhere: {$a['location']}" : '') . ($a['notes'] ? "\nNotes: {$a['notes']}" : '')
            . ($questions ? "\n\nQuestions you want to ask:\n" . implode("\n", array_map(fn ($q) => "  - $q", $questions)) : '') . "\n\n$link\n";
        $html = '<p><strong>' . h($what) . '</strong><br>' . h($when) . '</p>' . ($a['location'] ? '<p>Where: ' . h($a['location']) . '</p>' : '')
            . ($a['notes'] ? '<p>' . nl2br(h($a['notes'])) . '</p>' : '')
            . ($questions ? '<p>Questions you want to ask:</p><ul>' . implode('', array_map(fn ($q) => '<li>' . h($q) . '</li>', $questions)) . '</ul>' : '')
            . '<p><a href="' . h($link) . '">Open the appointment</a></p>';
        q('UPDATE appointments SET reminder_emailed_at = UTC_TIMESTAMP() WHERE id = ?', [$a['id']]);
        $error = send_mail($user['email'], "Reminder: $what, " . $local->format('D j M H:i'), $text, mail_html('Appointment reminder', $html));
        $log[] = $error ? "Reminder for appointment {$a['id']} failed: $error" : "Reminder sent for appointment {$a['id']}.";
    }
    return $log;
}

/* ---------- updates ---------- */

function check_for_updates(DateTimeImmutable $now): array
{
    $latest = latest_version(true);
    if (!update_available($latest)) {
        return $latest && $latest['error'] ? ['Update check: ' . $latest['error']] : [];
    }
    $file = storage_path('update_notified.json');
    $notified = json_decode((string) @file_get_contents($file), true)['version'] ?? null;
    if ($notified === $latest['version'] || !mail_enabled()) {
        return [];
    }
    @file_put_contents($file, json_encode(['version' => $latest['version'], 'at' => $now->getTimestamp()]), LOCK_EX);
    $log = [];
    foreach (q("SELECT * FROM users WHERE is_admin = 1 AND is_active = 1 AND email IS NOT NULL AND email <> ''") as $admin) {
        $text = "Pillbox {$latest['version']} is available (you have " . pillbox_version() . ").\n\nRelease notes: {$latest['url']}\n"
            . "\nTo update, download it and upload the files over your current ones (keep config/config.php and storage/). "
            . "The database updates itself the next time a page is opened.\n";
        $error = send_mail($admin['email'], "Pillbox {$latest['version']} is available", $text,
            mail_html("Pillbox {$latest['version']} is available", '<p>You have ' . h(pillbox_version()) . '.</p><p><a href="' . h((string) $latest['url']) . '">Release notes</a></p>'
                . '<p>To update, download it and upload the files over your current ones (keep config/config.php and storage/). The database updates itself the next time a page is opened.</p>'));
        $log[] = $error ? "Update email to admin {$admin['id']} failed: $error" : "Told admin {$admin['id']} about {$latest['version']}.";
    }
    return $log;
}

/* ---------- tidying up ---------- */

function tidy_up(DateTimeImmutable $now): array
{
    $log = [];
    q('DELETE FROM remembered_devices WHERE expires_at < UTC_TIMESTAMP()');
    q('DELETE FROM password_resets WHERE expires_at < UTC_TIMESTAMP()');
    $n = q('DELETE FROM login_attempts WHERE attempted_at < ?', [$now->modify('-30 days')->format('Y-m-d H:i:s')])->rowCount();
    if ($n) {
        $log[] = "Removed $n old sign-in attempts.";
    }
    $cutoff = $now->getTimestamp();
    foreach (['uploads' => 86400, 'sessions' => 2 * 86400] as $dir => $age) {
        foreach (glob(storage_path($dir) . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < $cutoff - $age) {
                @unlink($f);
            }
        }
    }
    $hours = max(1, (int) config('demo.hours', 2));
    $n = q('DELETE FROM users WHERE is_demo = 1 AND created_at < ?', [$now->modify("-$hours hours")->format('Y-m-d H:i:s')])->rowCount();
    if ($n) {
        $log[] = "Removed $n finished demo accounts.";
    }
    return $log;
}
