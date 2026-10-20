<?php
declare(strict_types=1);

/** Serves auction photos and documents from storage/. Files of unpublished auctions are staff-only. */
require_once __DIR__ . '/bootstrap.php';

$id = get_int('id');
$file = $id ? db_one('SELECT f.*, a.status FROM auction_files f JOIN auctions a ON a.id = f.auction_id WHERE f.id = ?', [$id]) : null;
if (!$file || (in_array($file['status'], PRIVATE_STATUSES, true) && !is_staff(current_user()))) {
    http_response_code(404);
    exit('Not found');
}
$path = upload_dir((int) $file['auction_id']) . '/' . $file['stored_name'];
if (!is_file($path)) {
    http_response_code(404);
    exit('Not found');
}

$inline = str_starts_with($file['mime'], 'image/') || $file['mime'] === 'application/pdf';
header('Content-Type: ' . $file['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($file['original_name']));
header('Cache-Control: ' . (in_array($file['status'], PRIVATE_STATUSES, true) ? 'private, no-store' : 'public, max-age=86400'));
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
