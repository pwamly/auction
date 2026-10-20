<?php
declare(strict_types=1);

/**
 * Q27 management reports. Each report returns columns, rows and summary figures;
 * admin/reports.php renders them as a table or CSV.
 *
 * Column types: text, money, datetime, date, int.
 */

const REPORTS = [
    'summary'      => ['Auction summary', 'reports.view', 'Every auction closing in the period, with its result.'],
    'participants' => ['Participants', 'reports.view', 'Participants registered in the period and their activity.'],
    'bids'         => ['Bidding history', 'reports.view', 'Every bid placed in the period.'],
    'winners'      => ['Winners', 'reports.view', 'Winners approved in the period.'],
    'payments'     => ['Payments', 'reports.view', 'Payment status of every approved winner with a deadline in the period.'],
    'cancelled'    => ['Cancelled auctions', 'reports.view', 'Auctions cancelled in the period, with the reason.'],
    'revenue'      => ['Revenue', 'reports.view', 'Payments received, by month.'],
    'audit'        => ['Audit trail', 'audit.view', 'Every recorded action in the period.'],
];

/** [fromUtc, toUtc] from local YYYY-MM-DD inputs (either may be null). */
function report_range(string $from, string $to): array
{
    $tz = new DateTimeZone(APP_TIMEZONE);
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
    return [$f ? utc_string($f) : null, $t ? utc_string($t->modify('+1 day')) : null];
}

function range_sql(string $column, array $range, array &$params): string
{
    $sql = [];
    if ($range[0]) {
        $sql[] = "$column >= ?";
        $params[] = $range[0];
    }
    if ($range[1]) {
        $sql[] = "$column < ?";
        $params[] = $range[1];
    }
    return $sql ? ' AND ' . implode(' AND ', $sql) : '';
}

function like_sql(array $columns, string $q, array &$params): string
{
    if ($q === '') {
        return '';
    }
    $like = '%' . addcslashes($q, '\\%_') . '%';
    foreach ($columns as $_) {
        $params[] = $like;
    }
    return ' AND (' . implode(' OR ', array_map(static fn($c) => "$c LIKE ?", $columns)) . ')';
}

const NAME_SQL = 'IF(p.participant_type = "company" AND p.company_name IS NOT NULL, p.company_name, u.full_name)';

function build_report(string $type, array $range, string $q, string $extra): array
{
    $params = [];
    switch ($type) {
        case 'summary':
            $where = 'a.status NOT IN ("draft","pending_approval","rejected")' . range_sql('a.closes_at', $range, $params) . like_sql(['a.title', 'a.reference', 'a.location'], $q, $params);
            if ($extra !== '' && isset(AUCTION_STATUS_LABELS[$extra])) {
                $where .= ' AND a.status = ?';
                $params[] = $extra;
            }
            $rows = db_all(
                "SELECT a.reference, a.title, a.status, a.opens_at, a.closes_at, a.starting_price, a.reserve_price,
                        (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS highest,
                        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bids,
                        (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.auction_id = a.id) AS bidders,
                        (SELECT " . NAME_SQL . " FROM winner_offers o JOIN users u ON u.id = o.user_id LEFT JOIN participants p ON p.user_id = o.user_id
                         WHERE o.auction_id = a.id AND o.status IN ('approved','paid') ORDER BY o.id DESC LIMIT 1) AS winner,
                        (SELECT amount FROM winner_offers o WHERE o.auction_id = a.id AND o.status IN ('approved','paid') ORDER BY o.id DESC LIMIT 1) AS final_price
                 FROM auctions a WHERE $where ORDER BY a.closes_at DESC",
                $params
            );
            foreach ($rows as &$r) {
                $r['status'] = AUCTION_STATUS_LABELS[$r['status']];
            }
            unset($r);
            $sold = array_filter($rows, static fn($r) => $r['final_price'] !== null);
            return [
                'columns' => [['reference', 'Reference'], ['title', 'Item'], ['status', 'Status'], ['opens_at', 'Opens', 'datetime'], ['closes_at', 'Closes', 'datetime'],
                              ['starting_price', 'Starting', 'money'], ['reserve_price', 'Reserve', 'money'], ['highest', 'Highest bid', 'money'],
                              ['bids', 'Bids', 'int'], ['bidders', 'Bidders', 'int'], ['winner', 'Winner'], ['final_price', 'Final price', 'money']],
                'rows' => $rows,
                'summary' => [
                    ['Auctions', count($rows)],
                    ['With approved winner', count($sold)],
                    ['Value of winning bids', money(cents_to_decimal(array_sum(array_map(static fn($r) => decimal_to_cents($r['final_price']), $sold))))],
                    ['Bids placed', array_sum(array_column($rows, 'bids'))],
                ],
            ];

        case 'participants':
            $where = '1' . range_sql('u.created_at', $range, $params) . like_sql(['u.full_name', 'u.email', 'p.company_name', 'p.nida_number', 'p.tin'], $q, $params);
            if (in_array($extra, ['individual', 'company'], true)) {
                $where .= ' AND p.participant_type = ?';
                $params[] = $extra;
            }
            $rows = db_all(
                "SELECT u.full_name, p.participant_type, p.company_name, p.nida_number, p.tin, p.brela_reg_no, u.email, u.phone, p.address, u.created_at, u.status,
                        (SELECT COUNT(DISTINCT auction_id) FROM bids b WHERE b.user_id = u.id) AS auctions,
                        (SELECT COUNT(*) FROM bids b WHERE b.user_id = u.id) AS bids,
                        (SELECT COUNT(*) FROM winner_offers o WHERE o.user_id = u.id AND o.status IN ('approved','paid')) AS wins
                 FROM users u JOIN participants p ON p.user_id = u.id WHERE $where ORDER BY u.created_at DESC",
                $params
            );
            foreach ($rows as &$r) {
                $r['participant_type'] = ucfirst($r['participant_type']);
                $r['status'] = ucfirst($r['status']);
            }
            unset($r);
            return [
                'columns' => [['full_name', 'Name / contact'], ['participant_type', 'Type'], ['company_name', 'Company'], ['nida_number', 'NIDA'], ['tin', 'TIN'],
                              ['brela_reg_no', 'BRELA'], ['email', 'Email'], ['phone', 'Phone'], ['address', 'Address'], ['created_at', 'Registered', 'datetime'],
                              ['status', 'Status'], ['auctions', 'Auctions', 'int'], ['bids', 'Bids', 'int'], ['wins', 'Wins', 'int']],
                'rows' => $rows,
                'summary' => [
                    ['Participants', count($rows)],
                    ['Individuals', count(array_filter($rows, static fn($r) => $r['participant_type'] === 'Individual'))],
                    ['Companies', count(array_filter($rows, static fn($r) => $r['participant_type'] === 'Company'))],
                    ['Have bid', count(array_filter($rows, static fn($r) => $r['bids'] > 0))],
                ],
            ];

        case 'bids':
            $where = '1' . range_sql('b.created_at', $range, $params) . like_sql(['a.reference', 'a.title', 'u.full_name', 'p.company_name'], $q, $params);
            $rows = db_all(
                'SELECT b.created_at, a.reference, a.title, ' . NAME_SQL . ' AS bidder, p.participant_type, b.amount, b.ip
                 FROM bids b JOIN auctions a ON a.id = b.auction_id JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
                 WHERE ' . $where . ' ORDER BY b.id DESC',
                $params
            );
            return [
                'columns' => [['created_at', 'Time', 'datetime'], ['reference', 'Reference'], ['title', 'Item'], ['bidder', 'Bidder'], ['participant_type', 'Type'], ['amount', 'Amount', 'money'], ['ip', 'IP address']],
                'rows' => $rows,
                'summary' => [['Bids', count($rows)], ['Auctions', count(array_unique(array_column($rows, 'reference')))], ['Bidders', count(array_unique(array_column($rows, 'bidder')))]],
            ];

        case 'winners':
            $where = 'o.status IN ("approved","paid","defaulted")' . range_sql('o.decided_at', $range, $params) . like_sql(['a.reference', 'a.title', 'u.full_name', 'p.company_name'], $q, $params);
            $rows = db_all(
                'SELECT a.reference, a.title, ' . NAME_SQL . ' AS winner, u.email, u.phone, o.amount, o.rank_no, d.full_name AS approved_by, o.decided_at, o.status
                 FROM winner_offers o JOIN auctions a ON a.id = o.auction_id JOIN users u ON u.id = o.user_id
                 LEFT JOIN participants p ON p.user_id = o.user_id LEFT JOIN users d ON d.id = o.decided_by
                 WHERE ' . $where . ' ORDER BY o.decided_at DESC',
                $params
            );
            foreach ($rows as &$r) {
                $r['status'] = ['approved' => 'Payment pending', 'paid' => 'Paid', 'defaulted' => 'Defaulted'][$r['status']];
                $r['rank_no'] = (int) $r['rank_no'] === 1 ? 'Highest bidder' : 'Next bidder (#' . $r['rank_no'] . ')';
            }
            unset($r);
            return [
                'columns' => [['reference', 'Reference'], ['title', 'Item'], ['winner', 'Winner'], ['email', 'Email'], ['phone', 'Phone'], ['amount', 'Winning bid', 'money'],
                              ['rank_no', 'Awarded as'], ['approved_by', 'Approved by'], ['decided_at', 'Approved at', 'datetime'], ['status', 'Payment']],
                'rows' => $rows,
                'summary' => [['Winners', count($rows)], ['Total value', money(cents_to_decimal(array_sum(array_map(static fn($r) => decimal_to_cents($r['amount']), $rows))))]],
            ];

        case 'payments':
            $where = 'o.status IN ("approved","paid","defaulted")' . range_sql('o.payment_due_at', $range, $params) . like_sql(['a.reference', 'a.title', 'u.full_name', 'p.company_name', 'o.control_number', 'o.payment_reference'], $q, $params);
            $rows = db_all(
                'SELECT a.reference, a.title, ' . NAME_SQL . ' AS winner, o.amount, o.control_number, o.payment_due_at, o.status, a.status AS auction_status,
                        o.paid_at, o.payment_reference, r.full_name AS recorded_by
                 FROM winner_offers o JOIN auctions a ON a.id = o.auction_id JOIN users u ON u.id = o.user_id
                 LEFT JOIN participants p ON p.user_id = o.user_id LEFT JOIN users r ON r.id = o.payment_recorded_by
                 WHERE ' . $where . ' ORDER BY o.payment_due_at DESC',
                $params
            );
            $totals = ['Paid' => 0, 'Pending' => 0, 'Overdue' => 0, 'Defaulted' => 0];
            foreach ($rows as &$r) {
                $r['payment_status'] = $r['status'] === 'paid' ? 'Paid' : ($r['status'] === 'defaulted' ? 'Defaulted' : ($r['auction_status'] === 'payment_overdue' ? 'Overdue' : 'Pending'));
                $totals[$r['payment_status']] += decimal_to_cents($r['amount']);
            }
            unset($r);
            if ($extra !== '' && isset($totals[$extra])) {
                $rows = array_values(array_filter($rows, static fn($r) => $r['payment_status'] === $extra));
            }
            return [
                'columns' => [['reference', 'Reference'], ['title', 'Item'], ['winner', 'Winner'], ['amount', 'Amount', 'money'], ['control_number', 'Control no.'],
                              ['payment_due_at', 'Due', 'datetime'], ['payment_status', 'Status'], ['paid_at', 'Paid at', 'datetime'], ['payment_reference', 'Receipt / ref.'], ['recorded_by', 'Recorded by']],
                'rows' => $rows,
                'summary' => array_map(static fn($k) => [$k, money(cents_to_decimal($totals[$k]))], array_keys($totals)),
            ];

        case 'cancelled':
            $where = 'a.status = "cancelled"' . range_sql('a.cancelled_at', $range, $params) . like_sql(['a.reference', 'a.title', 'a.status_reason'], $q, $params);
            $rows = db_all(
                'SELECT a.reference, a.title, a.cancelled_at, c.full_name AS cancelled_by, a.status_reason, a.opens_at, a.closes_at,
                        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bids
                 FROM auctions a LEFT JOIN users c ON c.id = a.cancelled_by WHERE ' . $where . ' ORDER BY a.cancelled_at DESC',
                $params
            );
            return [
                'columns' => [['reference', 'Reference'], ['title', 'Item'], ['cancelled_at', 'Cancelled at', 'datetime'], ['cancelled_by', 'Cancelled by'],
                              ['status_reason', 'Reason'], ['opens_at', 'Was opening', 'datetime'], ['closes_at', 'Was closing', 'datetime'], ['bids', 'Bids voided', 'int']],
                'rows' => $rows,
                'summary' => [['Cancelled auctions', count($rows)], ['Bids voided', array_sum(array_column($rows, 'bids'))]],
            ];

        case 'revenue':
            $where = 'o.status = "paid"' . range_sql('o.paid_at', $range, $params);
            $rows = db_all(
                "SELECT DATE_FORMAT(CONVERT_TZ(o.paid_at, '+00:00', '+03:00'), '%Y-%m') AS month, COUNT(*) AS auctions, SUM(o.amount) AS revenue,
                        SUM(a.starting_price) AS starting_total
                 FROM winner_offers o JOIN auctions a ON a.id = o.auction_id WHERE $where GROUP BY month ORDER BY month DESC",
                $params
            );
            $outstanding = db_value('SELECT COALESCE(SUM(amount), 0) FROM winner_offers WHERE status = "approved"');
            $total = array_sum(array_map(static fn($r) => decimal_to_cents($r['revenue']), $rows));
            return [
                'columns' => [['month', 'Month'], ['auctions', 'Auctions paid', 'int'], ['revenue', 'Revenue', 'money'], ['starting_total', 'Sum of starting prices', 'money']],
                'rows' => $rows,
                'summary' => [['Revenue in period', money(cents_to_decimal($total))], ['Paid auctions', array_sum(array_column($rows, 'auctions'))], ['Outstanding now (approved, unpaid)', money($outstanding)]],
            ];

        case 'audit':
            $where = '1' . range_sql('l.created_at', $range, $params) . like_sql(['l.summary', 'l.reason', 'u.full_name', 'u.email'], $q, $params);
            if ($extra !== '' && isset(AUDIT_ACTIONS[$extra])) {
                $where .= ' AND l.action = ?';
                $params[] = $extra;
            }
            $rows = db_all(
                'SELECT l.created_at, COALESCE(u.full_name, "System") AS user_name, u.role, l.action, l.summary, l.reason, l.ip
                 FROM audit_log l LEFT JOIN users u ON u.id = l.user_id WHERE ' . $where . ' ORDER BY l.id DESC LIMIT 20000',
                $params
            );
            foreach ($rows as &$r) {
                $r['action'] = AUDIT_ACTIONS[$r['action']] ?? $r['action'];
                $r['role'] = $r['role'] ? ROLE_LABELS[$r['role']] : '';
            }
            unset($r);
            return [
                'columns' => [['created_at', 'Time', 'datetime'], ['user_name', 'User'], ['role', 'Role'], ['action', 'Action'], ['summary', 'Details'], ['reason', 'Reason'], ['ip', 'IP address']],
                'rows' => $rows,
                'summary' => [['Entries', count($rows)]],
            ];
    }
    throw new InvalidArgumentException('Unknown report');
}

function format_report_cell($value, string $type): string
{
    if ($value === null || $value === '') {
        return '';
    }
    switch ($type) {
        case 'money':
            return money($value);
        case 'datetime':
            return fmt_dt($value);
        case 'date':
            return fmt_dt($value, 'd M Y');
        default:
            return (string) $value;
    }
}
