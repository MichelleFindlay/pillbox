<?php
/*
 * Scheduled tasks for hosts without cron: a web cron service calls cron.php?token=<secret>
 * every 5 to 15 minutes. The token is set in config/config.php ('cron' => ['token' => ...]);
 * with no token set, only bin/cron.php (command line) runs them.
 */
define('PILLBOX_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
$token = (string) config('cron.token', '');
if (strlen($token) < 16 || !hash_equals($token, get('token'))) {
    http_response_code(403);
    exit("Not allowed. Set a cron token of at least 16 characters in config/config.php and use it in the address.\n");
}
foreach (run_scheduled_tasks() as $line) {
    echo $line, "\n";
}
echo "Done.\n";
