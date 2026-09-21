#!/usr/bin/env php
<?php
/** Process pending and stale transactional email notifications from cron. */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/Database.php';
require_once dirname(__DIR__) . '/includes/NotificationService.php';

$limit = 50;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--limit=([1-9][0-9]{0,2})$/', $argument, $matches) === 1) {
        $limit = min(500, (int) $matches[1]);
        continue;
    }

    fwrite(STDERR, "Usage: process-notification-outbox.php [--limit=1..500]\n");
    exit(2);
}

if (!defined('MAIL_CONFIGURED') || !MAIL_CONFIGURED) {
    fwrite(STDERR, "Transactional email is not configured; queued notifications were left pending.\n");
    exit(1);
}

$processed = 0;
$sent = 0;
$retained = 0;
$workerId = 'cli-' . getmypid() . '-' . bin2hex(random_bytes(8));

try {
    $db = new Database();
    $notifications = new NotificationService($db);

    while ($processed < $limit) {
        $result = $notifications->dispatchNext($workerId);
        if ($result === null) {
            break;
        }

        $processed++;
        if ($result) {
            $sent++;
        } else {
            $retained++;
        }
    }
} catch (Throwable $exception) {
    error_log('The notification outbox worker stopped because of an internal error.');
    fwrite(STDERR, "Notification worker stopped; review the protected server error log.\n");
    exit(1);
}

fwrite(
    STDOUT,
    'Notification worker complete: processed=' . $processed
        . ' sent=' . $sent
        . ' retained_for_retry=' . $retained . "\n"
);
exit(0);
