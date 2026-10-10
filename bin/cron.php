<?php
// Scheduled tasks from the command line. Add to crontab, every 5 to 15 minutes:
//   */10 * * * * php /path/to/pillbox/bin/cron.php
// (Or call public/cron.php?token=... from a web cron service; see config 'cron'.)
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('PILLBOX_NO_SESSION', true);
require __DIR__ . '/../src/bootstrap.php';

foreach (run_scheduled_tasks() as $line) {
    echo $line, "\n";
}
