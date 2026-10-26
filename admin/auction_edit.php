<?php
declare(strict_types=1);

/**
 * Handles the New auction / Edit auction pop-up (auction_form_modal in lib/modals.php).
 * Problems send you back to the pop-up with your entries; success opens the auction.
 */
require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_staff('auction.create');
$id = get_int('id');
$a = $id ? load_auction($id) : null;
if ($id && !$a) {
    render_error_page('Not found', 'Auction not found.', 404);
}
if ($a && !in_array($a['status'], EDITABLE_STATUSES, true)) {
    flash('error', 'This auction is ' . AUCTION_STATUS_LABELS[$a['status']] . ' and can no longer be edited.');
    redirect('admin/auction_view?id=' . $a['id']);
}
$modalId = $a ? 'mAuctionEdit' : 'mAuctionNew';
$fallback = $a ? 'admin/auction_view?id=' . $a['id'] : 'admin/auctions';

if (!is_post()) {
    redirect($fallback . ($a ? '&' : '?') . 'modal=' . $modalId);
}

verify_csrf();

// A form posted with files larger than post_max_size arrives empty.
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('error', 'The upload was too large. Upload fewer or smaller files at a time.');
    redirect($fallback);
}

if (isset($_POST['delete_file']) && $a) {
    $name = delete_auction_file((int) $_POST['delete_file'], $a['id']);
    if ($name) {
        audit('auction_updated', "Removed file $name from " . auction_label($a), 'auction', $a['id']);
    }
    back_to_modal($modalId, $fallback);
}

$v = [];
foreach (['title', 'description', 'quantity', 'location', 'starting_price', 'reserve_price', 'min_increment', 'opens_at', 'closes_at', 'terms'] as $key) {
    $v[$key] = post_str($key, 20000);
}
[$fields, $errors] = validate_auction_input($v);
if ($errors) {
    stash_form($a ? 'auction_' . $a['id'] : 'auction_new', $v, $errors);
    back_to_modal($modalId, $fallback);
}

try {
    if ($a) {
        update_auction($a['id'], $fields, $user);
        $auctionId = $a['id'];
    } else {
        $auctionId = create_auction($fields, $user);
    }
    $fileErrors = array_merge(
        save_uploads($auctionId, 'photo', uploaded_files('photos'), $user['id']),
        save_uploads($auctionId, 'document', uploaded_files('documents'), $user['id'])
    );
    $added = count(uploaded_files('photos')) + count(uploaded_files('documents')) - count($fileErrors);
    if ($added > 0) {
        audit('auction_updated', "Added $added file(s) to " . auction_label(load_auction($auctionId)), 'auction', $auctionId);
    }
    if ($fileErrors) {
        flash('error', "Some files were not saved:\n" . implode("\n", $fileErrors));
    }
    if (isset($_POST['submit_for_approval'])) {
        submit_auction($auctionId, $user);
        flash('success', 'Saved and submitted for approval.');
    } else {
        flash('success', $a ? 'Changes saved.' : 'Draft auction created. Submit it for approval when it is ready.');
    }
} catch (AuctionError $e) {
    flash('error', $e->getMessage());
}
redirect('admin/auction_view?id=' . ($auctionId ?? $a['id']));
