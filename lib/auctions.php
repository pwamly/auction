<?php
declare(strict_types=1);

/**
 * Auction lifecycle (Requirements_doc.docx):
 *
 *   draft ─submit─> pending_approval ─approve─> published ─(closing time)─> closed:
 *     ↑                  │ reject                  │ suspend/resume            ├─ no bids / reserve not met ─> unsold (+ repeat draft, Q30)
 *     └──── rejected <───┘                         │ extend, cancel            └─ pending_winner_approval ─approve─> awaiting_payment ─paid─> completed
 *                                                                                    │ reject ─> next highest bidder (Q20)    │ 48 h passed (Q23)
 *                                                                                    └─ nobody left ─> unsold + repeat        └─> payment_overdue ─(management decision, Q25)
 *
 * Any non-final auction can be cancelled by an administrator with a reason (Q28/Q29).
 */

const AUCTION_STATUS_LABELS = [
    'draft'                   => 'Draft',
    'pending_approval'        => 'Awaiting approval',
    'rejected'                => 'Returned for changes',
    'published'               => 'Published',
    'suspended'               => 'Suspended',
    'cancelled'               => 'Cancelled',
    'pending_winner_approval' => 'Winner awaiting approval',
    'awaiting_payment'        => 'Awaiting payment',
    'payment_overdue'         => 'Payment overdue',
    'completed'               => 'Completed (paid)',
    'unsold'                  => 'Not sold',
];

const UNSOLD_REASON_LABELS = [
    'no_bids'            => 'No bids received',
    'reserve_not_met'    => 'Reserve price not reached',
    'no_eligible_winner' => 'No eligible winner',
    'not_paid'           => 'Winner did not pay',
];

const PHASE_LABELS = [
    'upcoming' => 'Upcoming',
    'live'     => 'Live',
    'closing'  => 'Closing…',
];

/** Statuses that are never shown on the public site. */
const PRIVATE_STATUSES = ['draft', 'pending_approval', 'rejected'];
const EDITABLE_STATUSES = ['draft', 'rejected', 'pending_approval'];
const CANCELLABLE_STATUSES = ['draft', 'pending_approval', 'rejected', 'published', 'suspended', 'pending_winner_approval', 'awaiting_payment', 'payment_overdue'];
const CLOSED_STATUSES = ['pending_winner_approval', 'awaiting_payment', 'payment_overdue', 'completed', 'unsold'];

class AuctionError extends RuntimeException
{
    public array $extra;

    public function __construct(string $message, array $extra = [])
    {
        parent::__construct($message);
        $this->extra = $extra;
    }
}

// ---------------------------------------------------------------- reading

function load_auction(int $id, bool $forUpdate = false): ?array
{
    $row = db_one('SELECT * FROM auctions WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''), [$id]);
    if ($row) {
        $row['id'] = (int) $row['id'];
    }
    return $row;
}

/** For a published auction: upcoming, live, or closing (time is up, closing not yet processed). Otherwise the status. */
function auction_phase(array $a): string
{
    if ($a['status'] !== 'published') {
        return $a['status'];
    }
    $now = utc_now();
    if ($now < from_utc($a['opens_at'])) {
        return 'upcoming';
    }
    return $now < from_utc($a['closes_at']) ? 'live' : 'closing';
}

function phase_label(string $phase): string
{
    return PHASE_LABELS[$phase] ?? AUCTION_STATUS_LABELS[$phase] ?? $phase;
}

function is_public_auction(array $a): bool
{
    return !in_array($a['status'], PRIVATE_STATUSES, true);
}

/** Highest bid with bidder name, bid count and number of distinct bidders (Q14). */
function auction_bid_summary(int $auctionId): array
{
    $highest = db_one(
        'SELECT b.id, b.amount, b.user_id, b.created_at, u.full_name, p.company_name, p.participant_type
         FROM bids b JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
         WHERE b.auction_id = ? ORDER BY b.amount DESC, b.id ASC LIMIT 1',
        [$auctionId]
    );
    $counts = db_one('SELECT COUNT(*) AS bids, COUNT(DISTINCT user_id) AS bidders FROM bids WHERE auction_id = ?', [$auctionId]);
    return [
        'highest'  => $highest,
        'bids'     => (int) $counts['bids'],
        'bidders'  => (int) $counts['bidders'],
    ];
}

function bid_history(int $auctionId, int $limit = 100): array
{
    return db_all(
        'SELECT b.id, b.amount, b.user_id, b.created_at, u.full_name, p.company_name, p.participant_type
         FROM bids b JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
         WHERE b.auction_id = ? ORDER BY b.id DESC LIMIT ' . (int) $limit,
        [$auctionId]
    );
}

function min_next_bid_cents(array $a, ?array $highest): int
{
    return $highest
        ? decimal_to_cents($highest['amount']) + decimal_to_cents($a['min_increment'])
        : decimal_to_cents($a['starting_price']);
}

function reserve_met(array $a, ?array $highest): bool
{
    if ($highest === null) {
        return false;
    }
    return $a['reserve_price'] === null || decimal_to_cents($highest['amount']) >= decimal_to_cents($a['reserve_price']);
}

function current_offer(int $auctionId): ?array
{
    return db_one(
        'SELECT o.*, u.full_name, u.email, u.phone, p.company_name, p.participant_type
         FROM winner_offers o JOIN users u ON u.id = o.user_id LEFT JOIN participants p ON p.user_id = o.user_id
         WHERE o.auction_id = ? ORDER BY o.id DESC LIMIT 1',
        [$auctionId]
    );
}

function auction_bidder_ids(int $auctionId): array
{
    return array_map('intval', array_column(db_all('SELECT DISTINCT user_id FROM bids WHERE auction_id = ?', [$auctionId]), 'user_id'));
}

function auction_link(array $a): string
{
    return 'auction?id=' . $a['id'];
}

function auction_label(array $a): string
{
    return ($a['reference'] ?? '#' . $a['id']) . ' ' . $a['title'];
}

function notify_bidders(array $a, string $type, string $title, string $body, array $exceptUserIds = []): void
{
    foreach (auction_bidder_ids($a['id']) as $uid) {
        if (!in_array($uid, $exceptUserIds, true)) {
            notify_user($uid, $type, $title, $body, auction_link($a));
        }
    }
}

// ---------------------------------------------------------------- create / edit (Q1, Q2)

/** Validates the auction form. Returns [fields for the DB, errors]. */
function validate_auction_input(array $in): array
{
    $errors = [];
    $f = [];
    foreach (['title' => 200, 'quantity' => 100, 'location' => 200] as $key => $max) {
        $f[$key] = trim((string) ($in[$key] ?? ''));
        if ($f[$key] === '') {
            $errors[$key] = 'Required.';
        } elseif (mb_strlen($f[$key]) > $max) {
            $errors[$key] = "At most $max characters.";
        }
    }
    foreach (['description', 'terms'] as $key) {
        $f[$key] = trim((string) ($in[$key] ?? ''));
        if ($f[$key] === '') {
            $errors[$key] = 'Required.';
        } elseif (mb_strlen($f[$key]) > 20000) {
            $errors[$key] = 'Too long.';
        }
    }

    $start = parse_money((string) ($in['starting_price'] ?? ''));
    if ($start === null || $start <= 0) {
        $errors['starting_price'] = 'Enter an amount greater than zero.';
    }
    $increment = parse_money((string) ($in['min_increment'] ?? ''));
    if ($increment === null || $increment <= 0) {
        $errors['min_increment'] = 'Enter an amount greater than zero.';
    }
    $reserveRaw = trim((string) ($in['reserve_price'] ?? ''));
    $reserve = null;
    if ($reserveRaw !== '') {
        $reserve = parse_money($reserveRaw);
        if ($reserve === null || $reserve <= 0) {
            $errors['reserve_price'] = 'Enter a valid amount, or leave empty for no reserve.';
        } elseif ($start !== null && $reserve < $start) {
            $errors['reserve_price'] = 'Reserve price cannot be below the starting price.';
        }
    }
    $f['starting_price'] = $start !== null ? cents_to_decimal($start) : null;
    $f['min_increment'] = $increment !== null ? cents_to_decimal($increment) : null;
    $f['reserve_price'] = $reserve !== null ? cents_to_decimal($reserve) : null;

    $f['opens_at'] = parse_local_input((string) ($in['opens_at'] ?? ''));
    $f['closes_at'] = parse_local_input((string) ($in['closes_at'] ?? ''));
    if ($f['opens_at'] === null) {
        $errors['opens_at'] = 'Choose an opening date and time.';
    }
    if ($f['closes_at'] === null) {
        $errors['closes_at'] = 'Choose a closing date and time.';
    } elseif ($f['opens_at'] !== null && from_utc($f['closes_at']) <= from_utc($f['opens_at'])->modify('+5 minutes')) {
        $errors['closes_at'] = 'Closing must be at least 5 minutes after opening.';
    }
    return [$f, $errors];
}

function create_auction(array $fields, array $user): int
{
    return db_transaction(function () use ($fields, $user) {
        db_exec(
            'INSERT INTO auctions (title, description, quantity, location, starting_price, reserve_price, min_increment,
                                   opens_at, closes_at, original_closes_at, terms, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)',
            [$fields['title'], $fields['description'], $fields['quantity'], $fields['location'], $fields['starting_price'],
             $fields['reserve_price'], $fields['min_increment'], $fields['opens_at'], $fields['closes_at'], $fields['closes_at'],
             $fields['terms'], $user['id']]
        );
        $id = (int) db()->lastInsertId();
        $ref = 'AUC-' . from_utc(utc_string(utc_now()))->format('Y') . '-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
        db_exec('UPDATE auctions SET reference = ? WHERE id = ?', [$ref, $id]);
        audit('auction_created', "Created $ref {$fields['title']}", 'auction', $id);
        return $id;
    });
}

function update_auction(int $id, array $fields, array $user): void
{
    db_transaction(function () use ($id, $fields, $user) {
        $a = load_auction($id, true);
        if (!$a || !in_array($a['status'], EDITABLE_STATUSES, true)) {
            throw new AuctionError('This auction can no longer be edited.');
        }
        $changed = [];
        foreach ($fields as $key => $value) {
            $old = $a[$key];
            $same = in_array($key, ['opens_at', 'closes_at'], true)
                ? to_ms($old) === to_ms($value)
                : (string) $old === (string) $value;
            if (!$same) {
                $changed[] = $key;
            }
        }
        db_exec(
            'UPDATE auctions SET title = ?, description = ?, quantity = ?, location = ?, starting_price = ?, reserve_price = ?,
                    min_increment = ?, opens_at = ?, closes_at = ?, original_closes_at = ?, terms = ? WHERE id = ?',
            [$fields['title'], $fields['description'], $fields['quantity'], $fields['location'], $fields['starting_price'],
             $fields['reserve_price'], $fields['min_increment'], $fields['opens_at'], $fields['closes_at'], $fields['closes_at'],
             $fields['terms'], $id]
        );
        if ($changed) {
            audit('auction_updated', 'Changed ' . implode(', ', $changed) . ' on ' . auction_label($a), 'auction', $id);
        }
    });
}

// ---------------------------------------------------------------- approval (Q3)

function submit_auction(int $id, array $user): void
{
    db_transaction(function () use ($id, $user) {
        $a = load_auction($id, true);
        if (!$a || !in_array($a['status'], ['draft', 'rejected'], true)) {
            throw new AuctionError('Only drafts or returned auctions can be submitted.');
        }
        if (from_utc($a['closes_at']) <= utc_now()) {
            throw new AuctionError('The closing time has already passed. Edit the dates first.');
        }
        db_exec('UPDATE auctions SET status = "pending_approval", submitted_at = ?, status_reason = NULL WHERE id = ?', [utc_string(utc_now()), $id]);
        audit('auction_submitted', 'Submitted ' . auction_label($a) . ' for approval', 'auction', $id);
        notify_staff('auction.approve', 'auction_pending_approval', 'Auction awaiting approval: ' . $a['title'],
            auction_label($a) . ' was submitted by ' . $user['full_name'] . ' and needs approval before it is published.',
            'admin/auction_view?id=' . $id);
    });
}

function approve_auction(int $id, array $admin): void
{
    db_transaction(function () use ($id, $admin) {
        $a = load_auction($id, true);
        if (!$a || $a['status'] !== 'pending_approval') {
            throw new AuctionError('Only auctions awaiting approval can be approved.');
        }
        if (from_utc($a['closes_at']) <= utc_now()) {
            throw new AuctionError('The closing time has already passed. Return it for changes instead.');
        }
        db_exec('UPDATE auctions SET status = "published", approved_by = ?, approved_at = ?, status_reason = NULL WHERE id = ?',
            [$admin['id'], utc_string(utc_now()), $id]);
        audit('auction_approved', 'Approved and published ' . auction_label($a), 'auction', $id);
        if ((int) $a['created_by'] !== $admin['id']) {
            notify_user((int) $a['created_by'], 'auction_approved', 'Auction approved: ' . $a['title'],
                auction_label($a) . ' was approved and is now published. Bidding opens ' . fmt_dt($a['opens_at']) . '.',
                'admin/auction_view?id=' . $id);
        }
    });
    process_openings();
}

function reject_auction(int $id, array $admin, string $reason): void
{
    require_reason($reason);
    db_transaction(function () use ($id, $admin, $reason) {
        $a = load_auction($id, true);
        if (!$a || $a['status'] !== 'pending_approval') {
            throw new AuctionError('Only auctions awaiting approval can be returned.');
        }
        db_exec('UPDATE auctions SET status = "rejected", status_reason = ? WHERE id = ?', [$reason, $id]);
        audit('auction_rejected', 'Returned ' . auction_label($a) . ' for changes', 'auction', $id, $reason);
        notify_user((int) $a['created_by'], 'auction_rejected', 'Auction returned for changes: ' . $a['title'],
            auction_label($a) . " was not approved.\n\nReason: $reason", 'admin/auction_edit?id=' . $id);
    });
}

function require_reason(string $reason): void
{
    if (mb_strlen(trim($reason)) < 5) {
        throw new AuctionError('A reason is required (at least 5 characters).');
    }
}

// ---------------------------------------------------------------- suspend / resume / cancel / extend (Q28, Q29)

function suspend_auction(int $id, array $admin, string $reason): void
{
    require_reason($reason);
    db_transaction(function () use ($id, $reason) {
        $a = load_auction($id, true);
        if (!$a || $a['status'] !== 'published') {
            throw new AuctionError('Only published auctions can be suspended.');
        }
        db_exec('UPDATE auctions SET status = "suspended", status_reason = ? WHERE id = ?', [$reason, $id]);
        audit('auction_suspended', 'Suspended ' . auction_label($a), 'auction', $id, $reason);
        notify_bidders($a, 'auction_suspended', 'Auction suspended: ' . $a['title'],
            'Bidding on ' . auction_label($a) . " has been suspended.\n\nReason: $reason");
    });
}

function resume_auction(int $id, array $admin, string $reason, ?string $newClosesAt): void
{
    require_reason($reason);
    db_transaction(function () use ($id, $admin, $reason, $newClosesAt) {
        $a = load_auction($id, true);
        if (!$a || $a['status'] !== 'suspended') {
            throw new AuctionError('Only suspended auctions can be resumed.');
        }
        $closes = $a['closes_at'];
        if ($newClosesAt !== null) {
            if (from_utc($newClosesAt) <= utc_now()->modify('+5 minutes')) {
                throw new AuctionError('The new closing time must be at least 5 minutes from now.');
            }
            record_extension($a, $newClosesAt, 'manual', $reason, $admin['id']);
            $closes = $newClosesAt;
        } elseif (from_utc($closes) <= utc_now()->modify('+5 minutes')) {
            throw new AuctionError('The closing time has passed or is too close. Set a new closing time to resume.');
        }
        db_exec('UPDATE auctions SET status = "published", status_reason = NULL, closes_at = ? WHERE id = ?', [$closes, $id]);
        audit('auction_resumed', 'Resumed ' . auction_label($a) . ', closing ' . fmt_dt($closes), 'auction', $id, $reason);
        notify_bidders($a, 'auction_resumed', 'Auction resumed: ' . $a['title'],
            'Bidding on ' . auction_label($a) . ' has resumed. It now closes ' . fmt_dt($closes) . ".\n\nReason: $reason");
    });
}

function extend_auction(int $id, array $admin, string $newClosesAt, string $reason): void
{
    require_reason($reason);
    db_transaction(function () use ($id, $admin, $newClosesAt, $reason) {
        $a = load_auction($id, true);
        if (!$a || !in_array($a['status'], ['published', 'suspended'], true)) {
            throw new AuctionError('Only published or suspended auctions can be extended.');
        }
        if (from_utc($newClosesAt) <= from_utc($a['closes_at'])) {
            throw new AuctionError('The new closing time must be later than the current one (' . fmt_dt($a['closes_at']) . ').');
        }
        if (from_utc($newClosesAt) <= utc_now()) {
            throw new AuctionError('The new closing time must be in the future.');
        }
        record_extension($a, $newClosesAt, 'manual', $reason, $admin['id']);
        db_exec('UPDATE auctions SET closes_at = ? WHERE id = ?', [$newClosesAt, $id]);
        audit('auction_extended', 'Extended ' . auction_label($a) . ' from ' . fmt_dt($a['closes_at']) . ' to ' . fmt_dt($newClosesAt), 'auction', $id, $reason);
        notify_bidders($a, 'auction_extended', 'Auction extended: ' . $a['title'],
            auction_label($a) . ' now closes ' . fmt_dt($newClosesAt) . ".\n\nReason: $reason");
    });
}

function cancel_auction(int $id, array $admin, string $reason): void
{
    require_reason($reason);
    db_transaction(function () use ($id, $admin, $reason) {
        $a = load_auction($id, true);
        if (!$a || !in_array($a['status'], CANCELLABLE_STATUSES, true)) {
            throw new AuctionError('This auction cannot be cancelled.');
        }
        db_exec('UPDATE auctions SET status = "cancelled", status_reason = ?, cancelled_by = ?, cancelled_at = ? WHERE id = ?',
            [$reason, $admin['id'], utc_string(utc_now()), $id]);
        db_exec('UPDATE winner_offers SET status = "rejected", decision_reason = ?, decided_by = ?, decided_at = ?
                 WHERE auction_id = ? AND status IN ("pending_approval", "approved")',
            ['Auction cancelled: ' . $reason, $admin['id'], utc_string(utc_now()), $id]);
        audit('auction_cancelled', 'Cancelled ' . auction_label($a) . ' (was ' . AUCTION_STATUS_LABELS[$a['status']] . ')', 'auction', $id, $reason);
        notify_bidders($a, 'auction_cancelled', 'Auction cancelled: ' . $a['title'],
            auction_label($a) . " has been cancelled. Any bids on it are void.\n\nReason: $reason");
    });
}

function record_extension(array $a, string $newClosesAt, string $kind, string $reason, ?int $userId, ?int $bidId = null): void
{
    db_exec(
        'INSERT INTO auction_extensions (auction_id, kind, old_closes_at, new_closes_at, reason, bid_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$a['id'], $kind, $a['closes_at'], $newClosesAt, $reason, $bidId, $userId]
    );
}

// ---------------------------------------------------------------- bidding (Q9, Q11-Q16)

/**
 * Places a bid. Throws AuctionError with a user-facing message if it isn't allowed.
 * The auction row is locked, so simultaneous bids are handled one at a time.
 * Returns ['bid_id' => int, 'extended_to' => ?string].
 */
function place_bid(array $user, int $auctionId, string $amountRaw, bool $acceptTerms): array
{
    if (!is_participant($user)) {
        throw new AuctionError('Only registered participants can bid.');
    }
    $amount = parse_money($amountRaw);
    if ($amount === null || $amount <= 0) {
        throw new AuctionError('Enter a valid bid amount.');
    }

    return db_transaction(function () use ($user, $auctionId, $amount, $acceptTerms) {
        $a = db_one(
            'SELECT a.*, a.opens_at <= NOW(3) AS has_opened, a.closes_at > NOW(3) AS not_closed,
                    TIMESTAMPDIFF(SECOND, NOW(3), a.closes_at) AS seconds_left, NOW(3) AS db_now
             FROM auctions a WHERE a.id = ? FOR UPDATE',
            [$auctionId]
        );
        if (!$a || !is_public_auction($a)) {
            throw new AuctionError('Auction not found.');
        }
        $a['id'] = (int) $a['id'];
        if ($a['status'] === 'suspended') {
            throw new AuctionError('Bidding on this auction is suspended.');
        }
        if ($a['status'] !== 'published' || !$a['not_closed']) {
            throw new AuctionError('This auction has closed.');
        }
        if (!$a['has_opened']) {
            throw new AuctionError('Bidding opens ' . fmt_dt($a['opens_at']) . '.');
        }

        // Q9: terms must be accepted once per auction before the first bid.
        $accepted = db_value('SELECT 1 FROM terms_acceptances WHERE auction_id = ? AND user_id = ?', [$a['id'], $user['id']]);
        if (!$accepted) {
            if (!$acceptTerms) {
                throw new AuctionError('You must accept the terms and conditions of this auction before bidding.', ['termsRequired' => true]);
            }
            db_exec('INSERT INTO terms_acceptances (auction_id, user_id, ip) VALUES (?, ?, ?)', [$a['id'], $user['id'], client_ip()]);
            audit('terms_accepted', $user['full_name'] . ' accepted the terms of ' . auction_label($a), 'auction', $a['id']);
        }

        $highest = auction_bid_summary($a['id'])['highest'];
        if ($highest && (int) $highest['user_id'] === $user['id']) {
            throw new AuctionError('You already hold the highest bid on this auction.');
        }
        $min = min_next_bid_cents($a, $highest);
        if ($amount < $min) {
            throw new AuctionError(
                ($highest ? 'Someone has bid already — the minimum is now ' : 'The minimum bid is ') . money(cents_to_decimal($min)) . '.',
                ['minBid' => $min / 100]
            );
        }

        db_exec('INSERT INTO bids (auction_id, user_id, amount, ip) VALUES (?, ?, ?, ?)', [$a['id'], $user['id'], cents_to_decimal($amount), client_ip()]);
        $bidId = (int) db()->lastInsertId();
        $amountText = money(cents_to_decimal($amount));
        audit('bid_submitted', $user['full_name'] . " bid $amountText on " . auction_label($a), 'auction', $a['id']);

        // Q16: a bid shortly before closing extends the auction.
        $extendedTo = null;
        if ((int) $a['seconds_left'] < ANTI_SNIPING_WINDOW_MIN * 60) {
            $newClose = utc_string((new DateTimeImmutable($a['db_now'], new DateTimeZone('UTC')))->modify('+' . ANTI_SNIPING_EXTENSION_MIN . ' minutes'));
            if (from_utc($newClose) > from_utc($a['closes_at'])) {
                $reason = 'Bid in the last ' . ANTI_SNIPING_WINDOW_MIN . ' minutes';
                record_extension($a, $newClose, 'auto', $reason, null, $bidId);
                db_exec('UPDATE auctions SET closes_at = ? WHERE id = ?', [$newClose, $a['id']]);
                audit('auction_auto_extended', auction_label($a) . ' extended to ' . fmt_dt($newClose) . ' by a late bid', 'auction', $a['id'], $reason, 0);
                $extendedTo = $newClose;
            }
        }

        $closingNote = $extendedTo ? "\n\nThe auction was extended by " . ANTI_SNIPING_EXTENSION_MIN . ' minutes and now closes ' . fmt_dt($extendedTo) . '.' : '';
        notify_user($user['id'], 'bid_submitted', 'Bid received: ' . $a['title'],
            "Your bid of $amountText on " . auction_label($a) . ' was received and is currently the highest.' . $closingNote, auction_link($a));
        if ($highest) {
            notify_user((int) $highest['user_id'], 'outbid', 'You have been outbid: ' . $a['title'],
                'Your bid of ' . money($highest['amount']) . ' on ' . auction_label($a) . " is no longer the highest. The current bid is $amountText." . $closingNote,
                auction_link($a));
        }
        return ['bid_id' => $bidId, 'extended_to' => $extendedTo];
    });
}

// ---------------------------------------------------------------- closing (Q15, Q17, Q30)

function process_openings(): int
{
    $due = db_all('SELECT * FROM auctions WHERE status = "published" AND opening_notified_at IS NULL AND opens_at <= NOW(3) AND closes_at > NOW(3)');
    foreach ($due as $a) {
        $a['id'] = (int) $a['id'];
        if (db_exec('UPDATE auctions SET opening_notified_at = ? WHERE id = ? AND opening_notified_at IS NULL', [utc_string(utc_now()), $a['id']]) === 0) {
            continue;
        }
        notify_all_participants('auction_opened', 'Auction now open: ' . $a['title'],
            auction_label($a) . ' is open for bidding. Starting price ' . money($a['starting_price']) . ', closes ' . fmt_dt($a['closes_at']) . '.',
            auction_link($a));
    }
    return count($due);
}

function finalize_due_auctions(): int
{
    $ids = array_column(db_all('SELECT id FROM auctions WHERE status = "published" AND closes_at <= NOW(3)'), 'id');
    foreach ($ids as $id) {
        finalize_auction((int) $id);
    }
    return count($ids);
}

function finalize_auction(int $id): void
{
    db_transaction(function () use ($id) {
        $a = db_one('SELECT * FROM auctions WHERE id = ? AND status = "published" AND closes_at <= NOW(3) FOR UPDATE', [$id]);
        if (!$a) {
            return;
        }
        $a['id'] = (int) $a['id'];
        $summary = auction_bid_summary($id);
        $highest = $summary['highest'];
        db_exec('UPDATE auctions SET closed_at = NOW(3) WHERE id = ?', [$id]);

        if ($highest === null || !reserve_met($a, $highest)) {
            $reason = $highest === null ? 'no_bids' : 'reserve_not_met';
            db_exec('UPDATE auctions SET status = "unsold", unsold_reason = ? WHERE id = ?', [$reason, $id]);
            audit('auction_closed', auction_label($a) . ' closed: ' . UNSOLD_REASON_LABELS[$reason], 'auction', $id, null, 0);
            if ($highest) {
                notify_bidders($a, 'auction_closed', 'Auction closed: ' . $a['title'],
                    auction_label($a) . ' has closed. The reserve price was not reached, so the item was not sold. It will be offered again in a new auction.');
            }
            create_repeat_auction($a, UNSOLD_REASON_LABELS[$reason]);
            return;
        }

        create_offer($a, $highest, 1);
        db_exec('UPDATE auctions SET status = "pending_winner_approval" WHERE id = ?', [$id]);
        audit('auction_closed', auction_label($a) . ' closed. Highest bid ' . money($highest['amount']) . ' by ' . display_name($highest), 'auction', $id, null, 0);

        notify_user((int) $highest['user_id'], 'auction_closed', 'Auction closed — you are the highest bidder: ' . $a['title'],
            auction_label($a) . ' has closed and your bid of ' . money($highest['amount']) . ' is the highest. '
            . APP_ORG . ' will confirm the result shortly; you will then have ' . PAYMENT_WINDOW_HOURS . ' hours to pay.',
            auction_link($a));
        notify_bidders($a, 'bid_unsuccessful', 'Auction closed — bid unsuccessful: ' . $a['title'],
            auction_label($a) . ' has closed. Your bid was not the highest. Thank you for participating.',
            [(int) $highest['user_id']]);
        notify_staff('winner.approve', 'winner_pending', 'Winner awaiting approval: ' . $a['title'],
            auction_label($a) . ' closed with a highest bid of ' . money($highest['amount']) . ' by ' . display_name($highest) . '. Please approve or reject the winner.',
            'admin/auction_view?id=' . $id);
    });
}

function create_offer(array $a, array $bid, int $rank): void
{
    db_exec('INSERT INTO winner_offers (auction_id, user_id, bid_id, amount, rank_no) VALUES (?, ?, ?, ?, ?)',
        [$a['id'], $bid['user_id'], $bid['id'], $bid['amount'], $rank]);
}

/** Q30 / Q25: puts the same item up again as a new draft for staff to reschedule and resubmit. */
function create_repeat_auction(array $a, string $why): int
{
    $origin = from_utc($a['opens_at']);
    $duration = max(3600, from_utc($a['original_closes_at'])->getTimestamp() - $origin->getTimestamp());
    $opens = utc_now()->setTimezone(new DateTimeZone(APP_TIMEZONE))->modify('+1 day')->setTime((int) date('G'), 0);
    $closes = $opens->modify("+$duration seconds");

    db_exec(
        'INSERT INTO auctions (title, description, quantity, location, starting_price, reserve_price, min_increment,
                               opens_at, closes_at, original_closes_at, terms, status, repeat_of, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)',
        [$a['title'], $a['description'], $a['quantity'], $a['location'], $a['starting_price'], $a['reserve_price'], $a['min_increment'],
         utc_string($opens), utc_string($closes), utc_string($closes), $a['terms'], $a['id'], $a['created_by']]
    );
    $newId = (int) db()->lastInsertId();
    $ref = 'AUC-' . $opens->format('Y') . '-' . str_pad((string) $newId, 4, '0', STR_PAD_LEFT);
    db_exec('UPDATE auctions SET reference = ? WHERE id = ?', [$ref, $newId]);
    copy_auction_files($a['id'], $newId);
    audit('auction_repeated', auction_label($a) . " repeated as draft $ref ($why)", 'auction', $newId, $why, 0);

    $body = auction_label($a) . " was not sold ($why). A new draft auction $ref has been created with the same details. "
        . 'Review the dates and prices, then submit it for approval.';
    notify_user((int) $a['created_by'], 'auction_repeated', 'Repeat auction created: ' . $a['title'], $body, 'admin/auction_edit?id=' . $newId);
    return $newId;
}

// ---------------------------------------------------------------- winner approval (Q17-Q20)

function load_offer_for_update(int $offerId): array
{
    $offer = db_one('SELECT * FROM winner_offers WHERE id = ? FOR UPDATE', [$offerId]);
    if (!$offer) {
        throw new AuctionError('Winner record not found.');
    }
    $a = load_auction((int) $offer['auction_id'], true);
    return [$offer, $a];
}

function approve_winner(int $offerId, array $staff): void
{
    db_transaction(function () use ($offerId, $staff) {
        [$offer, $a] = load_offer_for_update($offerId);
        if ($offer['status'] !== 'pending_approval' || $a['status'] !== 'pending_winner_approval') {
            throw new AuctionError('This winner is not awaiting approval.');
        }
        $due = utc_now()->modify('+' . PAYMENT_WINDOW_HOURS . ' hours');
        db_exec('UPDATE winner_offers SET status = "approved", decided_by = ?, decided_at = ?, payment_due_at = ? WHERE id = ?',
            [$staff['id'], utc_string(utc_now()), utc_string($due), $offerId]);
        db_exec('UPDATE auctions SET status = "awaiting_payment" WHERE id = ?', [$a['id']]);
        $winner = db_one('SELECT u.full_name, p.company_name, p.participant_type FROM users u LEFT JOIN participants p ON p.user_id = u.id WHERE u.id = ?', [$offer['user_id']]);
        audit('winner_approved', 'Approved ' . display_name($winner) . ' as winner of ' . auction_label($a) . ' at ' . money($offer['amount']), 'auction', $a['id']);
        notify_user((int) $offer['user_id'], 'winning', 'Congratulations — you won: ' . $a['title'],
            'You have won ' . auction_label($a) . ' with your bid of ' . money($offer['amount']) . ".\n\n"
            . 'Please pay within ' . PAYMENT_WINDOW_HOURS . ' hours, by ' . fmt_dt(utc_string($due)) . '. '
            . 'Payment is made through GePG; ' . APP_ORG . ' will send you a payment control number.',
            'account');
    });
}

function reject_winner(int $offerId, array $staff, string $reason): void
{
    require_reason($reason);
    db_transaction(function () use ($offerId, $staff, $reason) {
        [$offer, $a] = load_offer_for_update($offerId);
        if ($offer['status'] !== 'pending_approval' || $a['status'] !== 'pending_winner_approval') {
            throw new AuctionError('This winner is not awaiting approval.');
        }
        db_exec('UPDATE winner_offers SET status = "rejected", decided_by = ?, decided_at = ?, decision_reason = ? WHERE id = ?',
            [$staff['id'], utc_string(utc_now()), $reason, $offerId]);
        audit('winner_rejected', 'Rejected highest bidder for ' . auction_label($a), 'auction', $a['id'], $reason);
        notify_user((int) $offer['user_id'], 'bid_unsuccessful', 'Auction result: ' . $a['title'],
            'Your bid on ' . auction_label($a) . " could not be accepted.\n\nReason: $reason", auction_link($a));
        offer_to_next_bidder($a, 'no_eligible_winner');
    });
}

/** Q20 / Q25: offer the item to the highest remaining bidder (one offer per participant). */
function offer_to_next_bidder(array $a, string $unsoldReason): ?int
{
    $offered = array_map('intval', array_column(db_all('SELECT user_id FROM winner_offers WHERE auction_id = ?', [$a['id']]), 'user_id'));
    $next = db_one(
        'SELECT b.id, b.user_id, b.amount, u.full_name, p.company_name, p.participant_type
         FROM bids b JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
         WHERE b.auction_id = ? AND u.status = "active"' . ($offered ? ' AND b.user_id NOT IN (' . placeholders($offered) . ')' : '') . '
         ORDER BY b.amount DESC, b.id ASC LIMIT 1',
        array_merge([$a['id']], $offered)
    );
    if (!$next || !reserve_met($a, $next)) {
        db_exec('UPDATE auctions SET status = "unsold", unsold_reason = ? WHERE id = ?', [$unsoldReason, $a['id']]);
        audit('auction_closed', auction_label($a) . ' not sold: ' . UNSOLD_REASON_LABELS[$unsoldReason], 'auction', $a['id'], null);
        create_repeat_auction($a, UNSOLD_REASON_LABELS[$unsoldReason]);
        return null;
    }
    create_offer($a, $next, count($offered) + 1);
    db_exec('UPDATE auctions SET status = "pending_winner_approval" WHERE id = ?', [$a['id']]);
    audit('winner_offered_next', auction_label($a) . ' offered to next highest bidder ' . display_name($next) . ' at ' . money($next['amount']), 'auction', $a['id']);
    notify_user((int) $next['user_id'], 'offer_next', 'Auction result update: ' . $a['title'],
        'The highest bidder for ' . auction_label($a) . ' did not meet the required conditions. Your bid of ' . money($next['amount'])
        . ' is now the highest eligible bid. ' . APP_ORG . ' will confirm the result shortly.', auction_link($a));
    notify_staff('winner.approve', 'winner_pending', 'Winner awaiting approval: ' . $a['title'],
        auction_label($a) . ' has been offered to the next highest bidder, ' . display_name($next) . ' at ' . money($next['amount']) . '. Please approve or reject.',
        'admin/auction_view?id=' . $a['id']);
    return (int) $next['user_id'];
}

// ---------------------------------------------------------------- payment (Q23-Q25)

function set_control_number(int $offerId, array $staff, string $controlNumber): void
{
    $controlNumber = preg_replace('/\s+/', '', $controlNumber);
    if (!preg_match('/^\d{6,30}$/', $controlNumber)) {
        throw new AuctionError('A GePG control number is digits only (6–30).');
    }
    db_transaction(function () use ($offerId, $staff, $controlNumber) {
        [$offer, $a] = load_offer_for_update($offerId);
        if ($offer['status'] !== 'approved') {
            throw new AuctionError('A control number can only be set for an approved winner who has not paid.');
        }
        db_exec('UPDATE winner_offers SET control_number = ? WHERE id = ?', [$controlNumber, $offerId]);
        audit('payment_control_number', "Control number $controlNumber issued for " . auction_label($a), 'auction', $a['id']);
        notify_user((int) $offer['user_id'], 'payment_control_number', 'Payment control number: ' . $a['title'],
            'Pay ' . money($offer['amount']) . ' for ' . auction_label($a) . " through GePG using control number $controlNumber. "
            . 'Payment is due by ' . fmt_dt($offer['payment_due_at']) . '.', 'account');
    });
}

function record_payment(int $offerId, array $staff, string $reference): void
{
    $reference = trim($reference);
    if ($reference === '') {
        throw new AuctionError('Enter the payment receipt / reference number.');
    }
    db_transaction(function () use ($offerId, $staff, $reference) {
        [$offer, $a] = load_offer_for_update($offerId);
        if ($offer['status'] !== 'approved' || !in_array($a['status'], ['awaiting_payment', 'payment_overdue'], true)) {
            throw new AuctionError('Payment can only be recorded for an approved winner who has not paid.');
        }
        db_exec('UPDATE winner_offers SET status = "paid", paid_at = ?, payment_reference = ?, payment_recorded_by = ? WHERE id = ?',
            [utc_string(utc_now()), mb_substr($reference, 0, 100), $staff['id'], $offerId]);
        db_exec('UPDATE auctions SET status = "completed" WHERE id = ?', [$a['id']]);
        audit('payment_recorded', 'Payment of ' . money($offer['amount']) . " recorded for " . auction_label($a) . " (ref $reference)", 'auction', $a['id']);
        notify_user((int) $offer['user_id'], 'payment_received', 'Payment received: ' . $a['title'],
            'We have received your payment of ' . money($offer['amount']) . ' for ' . auction_label($a) . " (reference $reference). "
            . APP_ORG . ' will contact you about collection.', 'account');
    });
}

/** Run by the scheduler: winners past their payment deadline need a management decision (Q25). */
function process_overdue_payments(): int
{
    $rows = db_all(
        'SELECT o.id AS offer_id, a.id FROM winner_offers o JOIN auctions a ON a.id = o.auction_id
         WHERE o.status = "approved" AND a.status = "awaiting_payment" AND o.payment_due_at <= NOW()'
    );
    foreach ($rows as $row) {
        db_transaction(function () use ($row) {
            $a = load_auction((int) $row['id'], true);
            if ($a['status'] !== 'awaiting_payment') {
                return;
            }
            $offer = db_one('SELECT * FROM winner_offers WHERE id = ?', [$row['offer_id']]);
            db_exec('UPDATE auctions SET status = "payment_overdue" WHERE id = ?', [$a['id']]);
            audit('payment_overdue', 'Payment deadline passed for ' . auction_label($a), 'auction', $a['id'], null, 0);
            notify_user((int) $offer['user_id'], 'payment_overdue', 'Payment deadline passed: ' . $a['title'],
                'The ' . PAYMENT_WINDOW_HOURS . '-hour payment period for ' . auction_label($a) . ' has ended without payment being recorded. '
                . 'Contact ' . APP_ORG . ' immediately if you have already paid.', 'account');
            notify_staff('winner.approve', 'payment_overdue', 'Decision needed — payment overdue: ' . $a['title'],
                'The winner of ' . auction_label($a) . ' has not paid within ' . PAYMENT_WINDOW_HOURS . ' hours. '
                . 'Decide whether to offer it to the next bidder, repeat the auction, or allow more time.', 'admin/auction_view?id=' . $a['id']);
        });
    }
    return count($rows);
}

/** Q25 management decision on an overdue payment: offer_next | repeat | extend. */
function resolve_overdue_payment(int $auctionId, array $staff, string $decision, string $reason, ?string $newDueAt = null): void
{
    require_reason($reason);
    db_transaction(function () use ($auctionId, $staff, $decision, $reason, $newDueAt) {
        $a = load_auction($auctionId, true);
        if (!$a || $a['status'] !== 'payment_overdue') {
            throw new AuctionError('This auction has no overdue payment.');
        }
        $offer = db_one('SELECT * FROM winner_offers WHERE auction_id = ? AND status = "approved" ORDER BY id DESC LIMIT 1 FOR UPDATE', [$auctionId]);
        if (!$offer) {
            throw new AuctionError('No approved winner found.');
        }
        if ($decision === 'extend') {
            if ($newDueAt === null || from_utc($newDueAt) <= utc_now()) {
                throw new AuctionError('Choose a new payment deadline in the future.');
            }
            db_exec('UPDATE winner_offers SET payment_due_at = ? WHERE id = ?', [$newDueAt, $offer['id']]);
            db_exec('UPDATE auctions SET status = "awaiting_payment" WHERE id = ?', [$auctionId]);
            audit('payment_deadline_extended', 'Payment deadline for ' . auction_label($a) . ' moved to ' . fmt_dt($newDueAt), 'auction', $auctionId, $reason);
            notify_user((int) $offer['user_id'], 'payment_deadline_extended', 'Payment deadline extended: ' . $a['title'],
                'You now have until ' . fmt_dt($newDueAt) . ' to pay ' . money($offer['amount']) . ' for ' . auction_label($a) . '.', 'account');
            return;
        }
        if (!in_array($decision, ['offer_next', 'repeat'], true)) {
            throw new AuctionError('Unknown decision.');
        }
        db_exec('UPDATE winner_offers SET status = "defaulted", decision_reason = ?, decided_by = ?, decided_at = ? WHERE id = ?',
            [$reason, $staff['id'], utc_string(utc_now()), $offer['id']]);
        audit('payment_defaulted', 'Winner of ' . auction_label($a) . ' marked as defaulted (' . ($decision === 'repeat' ? 'repeat auction' : 'offer to next bidder') . ')', 'auction', $auctionId, $reason);
        notify_user((int) $offer['user_id'], 'payment_defaulted', 'Award withdrawn: ' . $a['title'],
            'Because payment was not received in time, your award of ' . auction_label($a) . " has been withdrawn.\n\nReason: $reason", 'account');
        if ($decision === 'offer_next') {
            offer_to_next_bidder($a, 'not_paid');
        } else {
            db_exec('UPDATE auctions SET status = "unsold", unsold_reason = "not_paid" WHERE id = ?', [$auctionId]);
            create_repeat_auction($a, UNSOLD_REASON_LABELS['not_paid']);
        }
    });
}
