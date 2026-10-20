<?php
/**
 * JSON API used by index.php.
 *
 * GET  api?action=session                 -> current user, CSRF token
 * GET  api?action=lots                    -> all lots with bid history
 * POST api?action=login|signup|logout
 * POST api?action=bid                     (bidder)
 * POST api?action=create_lot|delete_lot   (admin)
 * GET  api?action=report|report_csv        (admin) finished lots; filters: from, to (ms), status, q
 *
 * POST bodies are JSON and must carry the X-CSRF-Token header.
 * Every response includes serverNow (ms) so the client can sync its countdowns.
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $body = []): void
{
    http_response_code($status);
    $body['serverNow'] = server_now_ms();
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(int $status, string $message, array $extra = []): void
{
    respond($status, ['error' => $message] + $extra);
}

function require_user(?string $role = null): array
{
    $user = current_user();
    if (!$user) {
        fail(401, 'Your session has ended. Please log in again.');
    }
    if ($role !== null && $user['role'] !== $role) {
        fail(403, 'You do not have permission to do that.');
    }
    return $user;
}

function public_user(array $user): array
{
    return ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
}

/**
 * Finished lots (closed, or deleted before closing), newest first.
 * Status: sold (had bids), unsold (no bids), cancelled (deleted while still open).
 * A lot deleted after it closed keeps its sold/unsold outcome.
 *
 * Filters: from/to = finish time window in ms (the browser converts local dates),
 * status = sold|unsold|cancelled, q = text matched against product name or winner.
 */
function report_rows(array $filters): array
{
    $where = [];
    $params = [];
    $from = filter_var($filters['from'] ?? null, FILTER_VALIDATE_INT);
    $to = filter_var($filters['to'] ?? null, FILTER_VALIDATE_INT);
    if ($from !== false && $from !== null) {
        $where[] = 'r.finished_ms >= ?';
        $params[] = $from;
    }
    if ($to !== false && $to !== null) {
        $where[] = 'r.finished_ms <= ?';
        $params[] = $to;
    }
    $status = $filters['status'] ?? '';
    if (in_array($status, ['sold', 'unsold', 'cancelled'], true)) {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\\%_') . '%';
        $where[] = '(r.name LIKE ? OR r.winner LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "SELECT * FROM (
                SELECT l.id, l.name, l.starting_price, cu.username AS created_by,
                       wb.amount AS winning_bid,
                       IF(l.deleted_at IS NOT NULL AND l.deleted_at < l.end_time, NULL, wu.username) AS winner,
                       (SELECT COUNT(*) FROM bids b WHERE b.lot_id = l.id) AS bid_count,
                       (SELECT COUNT(DISTINCT b.user_id) FROM bids b WHERE b.lot_id = l.id) AS bidder_count,
                       CASE
                         WHEN l.deleted_at IS NOT NULL AND l.deleted_at < l.end_time THEN 'cancelled'
                         WHEN wb.id IS NULL THEN 'unsold'
                         ELSE 'sold'
                       END AS status,
                       ROUND(UNIX_TIMESTAMP(
                         IF(l.deleted_at IS NOT NULL AND l.deleted_at < l.end_time, l.deleted_at, l.end_time)
                       ) * 1000) AS finished_ms
                FROM lots l
                JOIN users cu ON cu.id = l.created_by
                LEFT JOIN bids wb ON wb.id = (SELECT MAX(b2.id) FROM bids b2 WHERE b2.lot_id = l.id)
                LEFT JOIN users wu ON wu.id = wb.user_id
                WHERE l.end_time <= NOW(3) OR l.deleted_at IS NOT NULL
            ) r"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY r.finished_ms DESC, r.id DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map(static function (array $r): array {
        $cancelled = $r['status'] === 'cancelled';
        return [
            'id'            => (int) $r['id'],
            'name'          => $r['name'],
            'status'        => $r['status'],
            'finishedAt'    => (int) $r['finished_ms'],
            'startingPrice' => (float) $r['starting_price'],
            // Bids on a cancelled lot are void, so it has no winner.
            'winningBid'    => $cancelled || $r['winning_bid'] === null ? null : (float) $r['winning_bid'],
            'winner'        => $cancelled ? null : $r['winner'],
            'bidCount'      => (int) $r['bid_count'],
            'bidderCount'   => (int) $r['bidder_count'],
            'createdBy'     => $r['created_by'],
        ];
    }, $stmt->fetchAll());
}

/** Stops spreadsheet apps treating a cell as a formula. */
function csv_safe($value)
{
    if (is_string($value) && $value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

try {
    start_session();
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    $input = [];
    if ($method === 'POST') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            fail(403, 'Your page is out of date. Refresh and try again.', ['csrf' => $_SESSION['csrf']]);
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            fail(400, 'Invalid request body.');
        }
    }

    $postActions = ['login', 'signup', 'logout', 'bid', 'create_lot', 'delete_lot'];
    $getActions = ['session', 'lots', 'report', 'report_csv'];
    if (in_array($action, $postActions, true) && $method !== 'POST') {
        fail(405, 'Method not allowed.');
    }
    if (!in_array($action, array_merge($postActions, $getActions), true)) {
        fail(404, 'Unknown action.');
    }

    switch ($action) {
        case 'session':
            $user = current_user();
            respond(200, ['user' => $user ? public_user($user) : null, 'csrf' => $_SESSION['csrf']]);

        case 'login':
            $username = normalize_username((string) ($input['username'] ?? ''));
            $password = (string) ($input['password'] ?? '');
            if ($username === '' || $password === '') {
                fail(422, 'Enter both a username and password.');
            }
            $stmt = db()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if (!$row || !password_verify($password, $row['password_hash'])) {
                fail(401, 'Incorrect username or password.');
            }
            if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $row['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) $row['id'], 'username' => $row['username'], 'role' => $row['role']];
            respond(200, ['user' => public_user($_SESSION['user']), 'csrf' => $_SESSION['csrf']]);

        case 'signup':
            $raw = trim((string) ($input['username'] ?? ''));
            $username = normalize_username($raw);
            $password = (string) ($input['password'] ?? '');
            $confirm = (string) ($input['confirm'] ?? '');
            if (!preg_match(USERNAME_PATTERN, $username)) {
                fail(422, 'Username must be 3–32 characters: letters, numbers, - and _.');
            }
            if (strlen($password) < PASSWORD_MIN_LENGTH) {
                fail(422, 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.');
            }
            if ($password !== $confirm) {
                fail(422, 'Passwords do not match.');
            }
            try {
                db()->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'bidder')")
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    fail(409, 'That username is already taken.');
                }
                throw $e;
            }
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int) db()->lastInsertId(), 'username' => $username, 'role' => 'bidder'];
            respond(201, ['user' => public_user($_SESSION['user']), 'csrf' => $_SESSION['csrf']]);

        case 'logout':
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            respond(200, ['user' => null, 'csrf' => $_SESSION['csrf']]);

        case 'lots':
            require_user();
            $lots = db()->query(
                'SELECT l.id, l.name, l.description, l.starting_price, l.min_increment,
                        ROUND(UNIX_TIMESTAMP(l.end_time) * 1000) AS end_ms, u.username AS created_by
                 FROM lots l JOIN users u ON u.id = l.created_by
                 WHERE l.deleted_at IS NULL
                 ORDER BY l.id'
            )->fetchAll();
            $bidsByLot = [];
            $bidRows = db()->query(
                'SELECT b.lot_id, b.amount, ROUND(UNIX_TIMESTAMP(b.created_at) * 1000) AS time_ms, u.username
                 FROM bids b
                 JOIN users u ON u.id = b.user_id
                 JOIN lots l ON l.id = b.lot_id AND l.deleted_at IS NULL
                 ORDER BY b.id'
            );
            foreach ($bidRows as $b) {
                $bidsByLot[$b['lot_id']][] = [
                    'bidder' => $b['username'],
                    'amount' => (float) $b['amount'],
                    'time'   => (int) $b['time_ms'],
                ];
            }
            $out = array_map(static fn(array $l) => [
                'id'            => (int) $l['id'],
                'name'          => $l['name'],
                'desc'          => $l['description'],
                'startingPrice' => (float) $l['starting_price'],
                'increment'     => (float) $l['min_increment'],
                'endTime'       => (int) $l['end_ms'],
                'createdBy'     => $l['created_by'],
                'bids'          => $bidsByLot[$l['id']] ?? [],
            ], $lots);
            respond(200, ['lots' => $out]);

        case 'bid':
            $user = require_user('bidder');
            $lotId = filter_var($input['lotId'] ?? null, FILTER_VALIDATE_INT);
            $amount = to_cents($input['amount'] ?? null);
            if (!$lotId) {
                fail(422, 'Unknown lot.');
            }
            if ($amount === null) {
                fail(422, 'Enter a valid bid amount.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                // Lock the lot row so concurrent bids on the same lot are processed one at a time.
                $stmt = $pdo->prepare(
                    'SELECT starting_price, min_increment, end_time > NOW(3) AS is_open
                     FROM lots WHERE id = ? AND deleted_at IS NULL FOR UPDATE'
                );
                $stmt->execute([$lotId]);
                $lot = $stmt->fetch();
                if (!$lot) {
                    $pdo->rollBack();
                    fail(404, 'This lot no longer exists.');
                }
                if (!$lot['is_open']) {
                    $pdo->rollBack();
                    fail(409, 'This lot has already closed.');
                }

                $stmt = $pdo->prepare('SELECT user_id, amount FROM bids WHERE lot_id = ? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$lotId]);
                $top = $stmt->fetch();

                if ($top && (int) $top['user_id'] === $user['id']) {
                    $pdo->rollBack();
                    fail(409, 'You already hold the highest bid on this lot.');
                }
                $minCents = $top
                    ? decimal_to_cents($top['amount']) + decimal_to_cents($lot['min_increment'])
                    : decimal_to_cents($lot['starting_price']);
                if ($amount < $minCents) {
                    $pdo->rollBack();
                    $msg = $top ? 'Someone just outbid you — minimum is now $' : 'Bid must be at least $';
                    fail(409, $msg . number_format($minCents / 100, 2), ['minBid' => $minCents / 100]);
                }

                $pdo->prepare('INSERT INTO bids (lot_id, user_id, amount) VALUES (?, ?, ?)')
                    ->execute([$lotId, $user['id'], cents_to_decimal($amount)]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            respond(201, ['ok' => true]);

        case 'create_lot':
            $user = require_user('admin');
            $name = trim((string) ($input['name'] ?? ''));
            $desc = trim((string) ($input['desc'] ?? ''));
            $start = to_cents($input['startingPrice'] ?? null);
            $inc = to_cents($input['increment'] ?? null);
            $duration = filter_var($input['duration'] ?? null, FILTER_VALIDATE_INT);

            if ($name === '') {
                fail(422, 'Give the lot a product name.');
            }
            if (mb_strlen($name) > 200) {
                fail(422, 'Product name must be 200 characters or fewer.');
            }
            if (mb_strlen($desc) > 2000) {
                fail(422, 'Description must be 2000 characters or fewer.');
            }
            if ($start === null) {
                fail(422, 'Enter a valid starting price.');
            }
            if ($inc === null || $inc <= 0) {
                fail(422, 'Enter a valid minimum increment.');
            }
            if ($duration === false || !array_key_exists($duration, LOT_DURATIONS)) {
                fail(422, 'Choose an auction length.');
            }

            db()->prepare(
                'INSERT INTO lots (name, description, starting_price, min_increment, end_time, created_by)
                 VALUES (?, ?, ?, ?, NOW(3) + INTERVAL ? SECOND, ?)'
            )->execute([
                $name,
                $desc !== '' ? $desc : 'No description provided.',
                cents_to_decimal($start),
                cents_to_decimal($inc),
                $duration,
                $user['id'],
            ]);
            respond(201, ['id' => (int) db()->lastInsertId()]);

        case 'delete_lot':
            require_user('admin');
            $lotId = filter_var($input['lotId'] ?? null, FILTER_VALIDATE_INT);
            if (!$lotId) {
                fail(422, 'Unknown lot.');
            }
            // Soft delete: the lot leaves the floor but stays in reports.
            db()->prepare('UPDATE lots SET deleted_at = NOW(3) WHERE id = ? AND deleted_at IS NULL')->execute([$lotId]);
            respond(200, ['ok' => true]);

        case 'report':
            require_user('admin');
            $rows = report_rows($_GET);
            $summary = ['lots' => count($rows), 'sold' => 0, 'unsold' => 0, 'cancelled' => 0, 'soldValue' => 0.0, 'bids' => 0];
            foreach ($rows as $r) {
                $summary[$r['status']]++;
                $summary['bids'] += $r['bidCount'];
                if ($r['status'] === 'sold') {
                    $summary['soldValue'] += $r['winningBid'];
                }
            }
            $limit = 500;
            respond(200, [
                'summary'   => $summary,
                'rows'      => array_slice($rows, 0, $limit),
                'truncated' => count($rows) > $limit,
            ]);

        case 'report_csv':
            require_user('admin');
            $rows = report_rows($_GET);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="lotline-report-' . gmdate('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
            fputcsv($out, ['Lot', 'Product', 'Status', 'Finished (UTC)', 'Opening price', 'Winning bid', 'Winner', 'Bids', 'Bidders', 'Listed by']);
            foreach ($rows as $r) {
                fputcsv($out, array_map('csv_safe', [
                    $r['id'],
                    $r['name'],
                    ucfirst($r['status']),
                    gmdate('Y-m-d H:i:s', intdiv($r['finishedAt'], 1000)),
                    number_format($r['startingPrice'], 2, '.', ''),
                    $r['winningBid'] === null ? '' : number_format($r['winningBid'], 2, '.', ''),
                    $r['winner'] ?? '',
                    $r['bidCount'],
                    $r['bidderCount'],
                    $r['createdBy'],
                ]));
            }
            fclose($out);
            exit;
    }
} catch (Throwable $e) {
    error_log('[lotline] ' . $e);
    fail(500, 'Something went wrong on the server. Try again.');
}
