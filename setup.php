<?php
/**
 * Installs or upgrades the database. Safe to re-run.
 *
 *   /opt/lampp/bin/php setup.php --admin-email=you@ndc.go.tz --admin-name="Jane Doe" [--admin-pass=...] [--demo]
 *
 * The first administrator gets a temporary password (printed once) and must change it
 * and set up two-factor authentication at first login. --demo adds sample auctions.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$opts = getopt('', ['admin-email:', 'admin-name:', 'admin-pass:', 'demo']);

$server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$server->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "Database '" . DB_NAME . "' ready.\n";

$schema = preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/schema.sql'));
foreach (array_filter(array_map('trim', explode(';', $schema))) as $sql) {
    db()->exec($sql);
}
// Upgrade: only three staff roles remain (admin, auction_officer, manager).
$removed = (int) db_value("SELECT COUNT(*) FROM users WHERE role IN ('investment','ict','pr')");
if ($removed > 0) {
    fwrite(STDERR, "$removed user(s) still have the removed roles investment/ict/pr. Change their role first.\n");
    exit(1);
}
db()->exec("ALTER TABLE users MODIFY role ENUM('participant','admin','auction_officer','manager') NOT NULL");

// Default access privileges (the administrator can change them under Staff accounts).
if ((int) db_value('SELECT COUNT(*) FROM role_permissions') === 0) {
    $stmt = db()->prepare('INSERT INTO role_permissions (role, permission) VALUES (?, ?)');
    foreach (DEFAULT_ROLE_PERMISSIONS as $role => $perms) {
        foreach ($perms as $perm) {
            $stmt->execute([$role, $perm]);
        }
    }
    echo "Default access privileges set.\n";
}
echo "Tables ready.\n";

foreach (['storage/uploads', 'storage/mail'] as $dir) {
    if (!is_dir(__DIR__ . '/' . $dir)) {
        mkdir(__DIR__ . '/' . $dir, 0775, true);
    }
}

$adminCount = (int) db_value('SELECT COUNT(*) FROM users WHERE role = "admin"');
if ($adminCount === 0) {
    $email = strtolower($opts['admin-email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "No administrator exists yet. Re-run with --admin-email=... (and optionally --admin-name, --admin-pass).\n");
        exit(1);
    }
    $pass = $opts['admin-pass'] ?? bin2hex(random_bytes(6));
    db_exec('INSERT INTO users (email, password_hash, role, full_name, must_change_password) VALUES (?, ?, "admin", ?, 1)',
        [$email, password_hash($pass, PASSWORD_DEFAULT), $opts['admin-name'] ?? 'Administrator']);
    audit('staff_created', "Initial administrator $email created by setup", 'user', (int) db()->lastInsertId(), null, 0);
    echo "Created administrator $email with temporary password: $pass\n";
    echo "  They must change it and set up two-factor authentication at first login.\n";
} else {
    echo "Administrator account(s) already exist ($adminCount).\n";
}

if (array_key_exists('demo', $opts)) {
    if ((int) db_value('SELECT COUNT(*) FROM auctions') > 0) {
        echo "Auctions already exist; skipping demo data.\n";
    } else {
        $admin = db_one('SELECT * FROM users WHERE role = "admin" ORDER BY id LIMIT 1');
        $admin['id'] = (int) $admin['id'];
        $now = utc_now()->setTimezone(new DateTimeZone(APP_TIMEZONE));
        $terms = "1. The highest bid when the auction closes wins, subject to approval by NDC.\n"
            . "2. Bids are binding and cannot be edited or withdrawn.\n"
            . "3. The winner must pay the full amount through GePG within 48 hours of being confirmed.\n"
            . "4. Items are sold as-is. Inspection at the stated location can be arranged before the auction closes.\n"
            . "5. The buyer collects the item at their own cost within 14 days of payment.";
        $demo = [
            ['Natural Rubber Sheets, Grade RSS3', '5-tonne pallet of ribbed smoked sheet rubber, export baled.', '5 tonnes', 'NDC Warehouse, Dar es Salaam', 8500000, 9000000, 50000, '-1 hour', '+2 days', '#1E7A37'],
            ['Toyota Land Cruiser GX (2014)', 'Station wagon, diesel, 210,000 km. Service records available. Minor body scratches.', '1 vehicle', 'NDC Head Office, Dar es Salaam', 45000000, null, 500000, '-30 minutes', '+3 days', '#0B6FA4'],
            ['Office Furniture Lot', '24 office desks, 30 chairs and 6 filing cabinets in good condition.', '60 items', 'NDC Arusha Office', 1500000, 2000000, 5, '+1 day', '+4 days', '#C99A1E'],
        ];
        foreach ($demo as [$title, $desc, $qty, $loc, $start, $reserve, $inc, $opens, $closes, $color]) {
            $id = create_auction([
                'title' => $title, 'description' => $desc, 'quantity' => $qty, 'location' => $loc,
                'starting_price' => cents_to_decimal($start * 100), 'reserve_price' => $reserve ? cents_to_decimal($reserve * 100) : null,
                'min_increment' => cents_to_decimal($inc * 100),
                'opens_at' => utc_string($now->modify($opens)), 'closes_at' => utc_string($now->modify($closes)), 'terms' => $terms,
            ], $admin);
            // A simple generated placeholder photo.
            $img = imagecreatetruecolor(800, 500);
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
            imagestring($img, 5, 30, 230, $title, imagecolorallocate($img, 255, 255, 255));
            $dir = upload_dir($id);
            mkdir($dir, 0775, true);
            $stored = bin2hex(random_bytes(16)) . '.jpg';
            imagejpeg($img, "$dir/$stored", 85);
            db_exec('INSERT INTO auction_files (auction_id, kind, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, "photo", ?, ?, "image/jpeg", ?, ?)',
                [$id, 'photo.jpg', $stored, filesize("$dir/$stored"), $admin['id']]);
            db_exec('UPDATE auctions SET status = "published", submitted_at = ?, approved_by = ?, approved_at = ? WHERE id = ?',
                [utc_string(utc_now()), $admin['id'], utc_string(utc_now()), $id]);
            audit('auction_approved', "Demo auction $title published by setup", 'auction', $id, null, 0);
        }
        echo 'Created ' . count($demo) . " demo auctions (2 open now, 1 upcoming).\n";
    }
}
echo "Done.\n";
