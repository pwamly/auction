<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/totp.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/files.php';
require_once __DIR__ . '/lib/auctions.php';
require_once __DIR__ . '/lib/tasks.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/modals.php';

if (PHP_SAPI !== 'cli') {
    start_session();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
}
