<?php
/*
 * Private calendar feed (iCalendar) of a user's appointments, plus care routine tasks and
 * checks (on their due dates) with reminders turned on: calendar.php?token=<secret>
 * No sign-in: the secret token in the URL is the key. Users can change or turn it off
 * on the Appointments page.
 */
define('PILLBOX_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

$token = get('token');
$user = preg_match('/^[a-f0-9]{40}$/', $token)
    ? q('SELECT * FROM users WHERE calendar_token = ? AND is_active = 1', [$token])->fetch()
    : false;
if (!$user) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("This calendar link isn't valid. It may have been changed or turned off in Pillbox.\n");
}

// The last year plus everything ahead.
$appointments = appointments_between((int) $user['id'], gmdate('Y-m-d H:i:s', strtotime('-1 year')), '9999-12-31 00:00:00');
$host = parse_url(absolute_url(), PHP_URL_HOST) ?: 'pillbox';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="appointments.ics"');
header('Cache-Control: private, max-age=900');
header('X-Robots-Tag: noindex, nofollow');
$areas = routine_areas($user);
$careEvents = care_ics_events(array_filter(care_tasks((int) $user['id']), fn ($t) => in_array($t['area'], $areas, true)), $host)
    . checks_ics_events($user, array_filter(health_checks((int) $user['id']), fn ($c) => in_array($c['area'], $areas, true)), $host)
    . mind_ics_events($user, $host);
echo appointments_ics($user, $appointments, $host, $careEvents);
