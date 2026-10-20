<?php
// Database connection settings. Environment variables override the XAMPP defaults.
define('DB_HOST', getenv('LOTLINE_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int) (getenv('LOTLINE_DB_PORT') ?: 3306));
define('DB_NAME', getenv('LOTLINE_DB_NAME') ?: 'lotline');
define('DB_USER', getenv('LOTLINE_DB_USER') ?: 'root');
define('DB_PASS', getenv('LOTLINE_DB_PASS') ?: '');

// Auction lengths an admin may pick, in seconds => label.
const LOT_DURATIONS = [
    60    => '1 minute (demo)',
    120   => '2 minutes (demo)',
    300   => '5 minutes (demo)',
    600   => '10 minutes (demo)',
    3600  => '1 hour',
    86400 => '1 day',
];
