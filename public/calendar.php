<?php
/*
 * Private calendar feed (iCalendar) of a user's appointments: calendar.php?token=<secret>
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
echo appointments_ics($user, $appointments, $host);
