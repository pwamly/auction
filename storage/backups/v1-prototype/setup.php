<?php
/**
 * One-time install: creates the database, tables, the admin account and (optionally) demo lots.
 *
 *   /opt/lampp/bin/php setup.php                      # admin password defaults to admin123
 *   /opt/lampp/bin/php setup.php --admin-pass=Secret1 --demo
 *
 * Safe to re-run: existing tables, the admin account and existing lots are left alone.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$opts = getopt('', ['admin-pass:', 'demo']);
$adminPass = $opts['admin-pass'] ?? 'admin123';
$seedDemo = array_key_exists('demo', $opts);

$server = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT),
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Database '" . DB_NAME . "' ready.\n";

$pdo = db();
$schema = preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
    $pdo->exec($sql);
}
// Upgrades for databases created by an earlier version of schema.sql.
$hasDeletedAt = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lots' AND COLUMN_NAME = 'deleted_at'"
)->fetchColumn();
if (!$hasDeletedAt) {
    $pdo->exec('ALTER TABLE lots ADD COLUMN deleted_at DATETIME(3) NULL AFTER created_at');
    echo "Added lots.deleted_at.\n";
}
echo "Tables ready.\n";

$admin = $pdo->query("SELECT id FROM users WHERE username = 'admin'")->fetch();
if ($admin) {
    $adminId = (int) $admin['id'];
    echo "Admin account already exists (password unchanged).\n";
} else {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES ('admin', ?, 'admin')");
    $stmt->execute([password_hash($adminPass, PASSWORD_DEFAULT)]);
    $adminId = (int) $pdo->lastInsertId();
    echo "Created admin account: admin / $adminPass\n";
    if ($adminPass === 'admin123') {
        echo "  WARNING: default password in use. Re-create with --admin-pass before going live.\n";
    }
}

if ($seedDemo) {
    $lotCount = (int) $pdo->query('SELECT COUNT(*) FROM lots')->fetchColumn();
    if ($lotCount > 0) {
        echo "Lots already exist; skipping demo lots.\n";
    } else {
        $demoLots = [
            ['Natural Rubber Sheets, Grade RSS3', 'Ribbed smoked sheet rubber, 5-tonne pallet, export baled.', 850, 25, 30 * 60],
            ['Reclaimed Tire Rubber Granules', 'Crumb rubber, 2–4mm grade, 10-tonne lot, suitable for surfacing.', 300, 10, 10 * 60],
            ['Raw Latex Concentrate, 60% DRC', 'Field latex concentrate, ammonia-preserved, 1,000L drum.', 500, 20, 60 * 60],
        ];
        $stmt = $pdo->prepare(
            'INSERT INTO lots (name, description, starting_price, min_increment, end_time, created_by)
             VALUES (?, ?, ?, ?, NOW(3) + INTERVAL ? SECOND, ?)'
        );
        foreach ($demoLots as [$name, $desc, $start, $inc, $secs]) {
            $stmt->execute([$name, $desc, $start, $inc, $secs, $adminId]);
        }
        echo 'Seeded ' . count($demoLots) . " demo lots.\n";
    }
}

echo "Done.\n";
