<?php
declare(strict_types=1);

/** Q1 photos and supporting documents. Files live in storage/uploads (not web-reachable) and are served by file.php. */

function upload_dir(int $auctionId): string
{
    return dirname(__DIR__) . '/storage/uploads/' . $auctionId;
}

function auction_files(int $auctionId, ?string $kind = null): array
{
    $sql = 'SELECT * FROM auction_files WHERE auction_id = ?' . ($kind ? ' AND kind = ?' : '') . ' ORDER BY id';
    return db_all($sql, $kind ? [$auctionId, $kind] : [$auctionId]);
}

/** Normalises $_FILES['x'] (single or multiple) into a list of files. */
function uploaded_files(string $field): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        return $f['error'] === UPLOAD_ERR_NO_FILE ? [] : [$f];
    }
    $list = [];
    foreach ($f['name'] as $i => $name) {
        if ($f['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $list[] = ['name' => $name, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $list;
}

/** Saves uploads for an auction. Returns a list of error messages (empty on success). */
function save_uploads(int $auctionId, string $kind, array $files, int $userId): array
{
    $errors = [];
    $allowed = $kind === 'photo' ? PHOTO_TYPES : DOCUMENT_TYPES;
    $maxBytes = $kind === 'photo' ? MAX_PHOTO_BYTES : MAX_DOCUMENT_BYTES;
    $maxCount = $kind === 'photo' ? MAX_PHOTOS : MAX_DOCUMENTS;
    $existing = count(auction_files($auctionId, $kind));
    $finfo = new finfo(FILEINFO_MIME_TYPE);

    foreach ($files as $file) {
        $name = mb_substr(basename((string) $file['name']), 0, 255);
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "$name: upload failed (code {$file['error']}).";
            continue;
        }
        if ($existing >= $maxCount) {
            $errors[] = "$name: limit of $maxCount {$kind}s reached.";
            continue;
        }
        if ($file['size'] > $maxBytes) {
            $errors[] = "$name: larger than " . ($maxBytes / 1024 / 1024) . ' MB.';
            continue;
        }
        $mime = $finfo->file($file['tmp_name']) ?: '';
        if (!isset($allowed[$mime])) {
            $errors[] = "$name: file type not allowed.";
            continue;
        }
        if ($kind === 'photo' && @getimagesize($file['tmp_name']) === false) {
            $errors[] = "$name: not a valid image.";
            continue;
        }
        $dir = upload_dir($auctionId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            $errors[] = "$name: could not create upload folder.";
            continue;
        }
        $stored = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            $errors[] = "$name: could not save file.";
            continue;
        }
        db_exec(
            'INSERT INTO auction_files (auction_id, kind, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$auctionId, $kind, $name, $stored, $mime, (int) $file['size'], $userId]
        );
        $existing++;
    }
    return $errors;
}

function delete_auction_file(int $fileId, int $auctionId): ?string
{
    $file = db_one('SELECT * FROM auction_files WHERE id = ? AND auction_id = ?', [$fileId, $auctionId]);
    if (!$file) {
        return null;
    }
    db_exec('DELETE FROM auction_files WHERE id = ?', [$fileId]);
    @unlink(upload_dir($auctionId) . '/' . $file['stored_name']);
    return $file['original_name'];
}

/** Copies all files of one auction to another (used when an auction is repeated). */
function copy_auction_files(int $fromId, int $toId): void
{
    $files = auction_files($fromId);
    if (!$files) {
        return;
    }
    $dir = upload_dir($toId);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    foreach ($files as $f) {
        $stored = bin2hex(random_bytes(16)) . '.' . pathinfo($f['stored_name'], PATHINFO_EXTENSION);
        if (@copy(upload_dir($fromId) . '/' . $f['stored_name'], $dir . '/' . $stored)) {
            db_exec(
                'INSERT INTO auction_files (auction_id, kind, original_name, stored_name, mime, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$toId, $f['kind'], $f['original_name'], $stored, $f['mime'], $f['size_bytes'], $f['uploaded_by']]
            );
        }
    }
}

function file_url(array $file): string
{
    return url('file?id=' . $file['id']);
}

function human_size(int $bytes): string
{
    return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}
