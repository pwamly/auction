<?php
/**
 * JSON endpoints for the live bid panel.
 *
 * GET  api?action=auction_state&id=N   current bid, counts, closing time (public; bidder names need login)
 * POST api?action=bid&id=N             {amount, acceptTerms}  (participants; X-CSRF-Token header)
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function auction_state(array $a, ?array $user): array
{
    $summary = auction_bid_summary($a['id']);
    $highest = $summary['highest'];
    $loggedIn = $user !== null;
    return [
        'phase'         => auction_phase($a),
        'currentBid'    => (float) ($highest['amount'] ?? $a['starting_price']),
        'minNextBid'    => min_next_bid_cents($a, $highest) / 100,
        'bids'          => $summary['bids'],
        'bidders'       => $summary['bidders'],
        // Q14: participants see the bidder name; anonymous visitors don't.
        'leader'        => $highest && $loggedIn ? display_name($highest) : null,
        'youLead'       => $highest && $user && (int) $highest['user_id'] === $user['id'],
        'reserveMet'    => $a['reserve_price'] === null ? null : reserve_met($a, $highest),
        'opensAt'       => to_ms($a['opens_at']),
        'closesAt'      => to_ms($a['closes_at']),
        'termsAccepted' => $user ? (bool) db_value('SELECT 1 FROM terms_acceptances WHERE auction_id = ? AND user_id = ?', [$a['id'], $user['id']]) : false,
    ];
}

try {
    maybe_run_tasks();
    $action = $_GET['action'] ?? '';
    $id = get_int('id');
    $a = $id ? load_auction($id) : null;
    if (!$a || !is_public_auction($a)) {
        json_response(404, ['error' => 'Auction not found.']);
    }
    $user = current_user();

    if ($action === 'auction_state') {
        json_response(200, auction_state($a, $user));
    }

    if ($action === 'bid') {
        if (!is_post()) {
            json_response(405, ['error' => 'Method not allowed.']);
        }
        verify_csrf();
        if (!$user) {
            json_response(401, ['error' => 'Your session has ended. Please log in again.']);
        }
        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($input)) {
            json_response(400, ['error' => 'Invalid request.']);
        }
        try {
            $result = place_bid($user, $a['id'], (string) ($input['amount'] ?? ''), !empty($input['acceptTerms']));
        } catch (AuctionError $e) {
            json_response(409, ['error' => $e->getMessage(), 'state' => auction_state(load_auction($a['id']), $user)] + $e->extra);
        }
        json_response(201, [
            'ok'         => true,
            'extendedTo' => $result['extended_to'] ? to_ms($result['extended_to']) : null,
            'state'      => auction_state(load_auction($a['id']), $user),
        ]);
    }

    json_response(404, ['error' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[auction api] ' . $e);
    json_response(500, ['error' => 'Something went wrong on the server. Try again.']);
}
