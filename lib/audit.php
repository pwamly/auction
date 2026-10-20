<?php
declare(strict_types=1);

/** Q31 audit-trail actions and their report labels. */
const AUDIT_ACTIONS = [
    'login'                   => 'User login',
    'login_failed'            => 'Failed login',
    'logout'                  => 'Logout',
    'two_factor_enabled'      => 'Two-factor enabled',
    'two_factor_reset'        => 'Two-factor reset',
    'password_changed'        => 'Password changed',
    'participant_registered'  => 'Participant registration',
    'participant_blocked'     => 'Participant rejected/blocked',
    'participant_unblocked'   => 'Participant approved/unblocked',
    'staff_created'           => 'Staff account created',
    'staff_updated'           => 'Staff account changed',
    'auction_created'         => 'Auction creation',
    'auction_updated'         => 'Auction change',
    'auction_submitted'       => 'Auction submitted for approval',
    'auction_approved'        => 'Auction approval',
    'auction_rejected'        => 'Auction rejected',
    'auction_suspended'       => 'Auction suspended',
    'auction_resumed'         => 'Auction resumed',
    'auction_cancelled'       => 'Auction cancellation',
    'auction_extended'        => 'Auction extension',
    'auction_auto_extended'   => 'Automatic extension (late bid)',
    'auction_closed'          => 'Auction closed',
    'auction_repeated'        => 'Auction repeated',
    'terms_accepted'          => 'Terms accepted',
    'bid_submitted'           => 'Bid submitted',
    'winner_approved'         => 'Winner approval',
    'winner_rejected'         => 'Winner rejected',
    'winner_offered_next'     => 'Offered to next bidder',
    'payment_control_number'  => 'Payment control number set',
    'payment_recorded'        => 'Payment status: paid',
    'payment_overdue'         => 'Payment status: overdue',
    'payment_deadline_extended' => 'Payment deadline extended',
    'payment_defaulted'       => 'Payment status: defaulted',
    'report_exported'         => 'Report exported',
];

/**
 * Records an audit-trail entry. $userId defaults to the logged-in user; pass 0 for
 * actions taken by the system (scheduled closing, automatic extension, etc.).
 */
function audit(string $action, string $summary, ?string $entityType = null, ?int $entityId = null, ?string $reason = null, ?int $userId = null): void
{
    if ($userId === null) {
        $userId = current_user()['id'] ?? 0;
    }
    db_exec(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, summary, reason, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$userId ?: null, $action, $entityType, $entityId, mb_substr($summary, 0, 500), $reason !== null ? mb_substr($reason, 0, 500) : null, PHP_SAPI === 'cli' ? 'cli' : client_ip()]
    );
}
