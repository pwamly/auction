<?php
/**
 * Scheduler entry point. Add to crontab (crontab -e):
 *
 *   * * * * * /opt/lampp/bin/php /opt/lampp/htdocs/auction/cron.php >> /opt/lampp/htdocs/auction/storage/cron.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$result = run_scheduled_tasks();
if ($result === null) {
    echo gmdate('c') . " another run is in progress\n";
    exit;
}
if ($result['opened'] || $result['closed'] || $result['overdue'] || $result['email']['sent'] || $result['email']['failed']) {
    echo gmdate('c') . ' ' . json_encode($result) . "\n";
}
