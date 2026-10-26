<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
maybe_run_tasks();

$user = require_staff();
$id = get_int('id');
$a = $id ? load_auction($id) : null;
if (!$a) {
    render_error_page('Not found', 'Auction not found.', 404);
}

if (is_post()) {
    verify_csrf();
    $action = post_str('action', 40);
    $reason = post_str('reason', 500);
    $offerId = post_int('offer_id') ?? 0;
    $need = [
        'submit' => 'auction.create', 'approve' => 'auction.approve', 'reject' => 'auction.approve',
        'suspend' => 'auction.suspend', 'resume' => 'auction.suspend', 'extend' => 'auction.extend', 'cancel' => 'auction.cancel',
        'approve_winner' => 'winner.approve', 'reject_winner' => 'winner.approve', 'resolve_overdue' => 'payment.decide',
        'control_number' => 'payment.record', 'record_payment' => 'payment.record',
    ][$action] ?? null;
    try {
        if ($need === null || !can($need, $user)) {
            throw new AuctionError('You do not have permission to do that.');
        }
        $newClose = post_str('new_closes_at', 30) !== '' ? parse_local_input(post_str('new_closes_at', 30)) : null;
        switch ($action) {
            case 'submit':         submit_auction($a['id'], $user); $msg = 'Submitted for approval.'; break;
            case 'approve':        approve_auction($a['id'], $user); $msg = 'Approved and published.'; break;
            case 'reject':         reject_auction($a['id'], $user, $reason); $msg = 'Returned to the creator for changes.'; break;
            case 'suspend':        suspend_auction($a['id'], $user, $reason); $msg = 'Auction suspended. Bidders have been notified.'; break;
            case 'resume':         resume_auction($a['id'], $user, $reason, $newClose); $msg = 'Auction resumed.'; break;
            case 'extend':
                if ($newClose === null) {
                    throw new AuctionError('Choose the new closing date and time.');
                }
                extend_auction($a['id'], $user, $newClose, $reason); $msg = 'Closing time extended.'; break;
            case 'cancel':         cancel_auction($a['id'], $user, $reason); $msg = 'Auction cancelled.'; break;
            case 'approve_winner': approve_winner($offerId, $user); $msg = 'Winner approved. They have ' . PAYMENT_WINDOW_HOURS . ' hours to pay.'; break;
            case 'reject_winner':  reject_winner($offerId, $user, $reason); $msg = 'Winner rejected. The next highest bidder (if any) is now awaiting approval.'; break;
            case 'control_number': set_control_number($offerId, $user, post_str('control_number', 40)); $msg = 'Control number saved and sent to the winner.'; break;
            case 'record_payment': record_payment($offerId, $user, post_str('payment_reference', 100)); $msg = 'Payment recorded. Auction completed.'; break;
            case 'resolve_overdue':
                $due = post_str('new_due_at', 30) !== '' ? parse_local_input(post_str('new_due_at', 30)) : null;
                resolve_overdue_payment($a['id'], $user, post_str('decision', 20), $reason, $due); $msg = 'Decision recorded.'; break;
        }
        flash('success', $msg);
    } catch (AuctionError $e) {
        flash('error', $e->getMessage());
    }
    $back = post_str('return_tab', 20);
    redirect('admin/auction_view?id=' . $a['id'] . ($back !== '' ? '&tab=' . rawurlencode($back) : ''));
}

$phase = auction_phase($a);
$summary = auction_bid_summary($a['id']);
$highest = $summary['highest'];
$offers = db_all(
    'SELECT o.*, u.full_name, u.email, u.phone, p.company_name, p.participant_type, p.nida_number, p.tin, p.brela_reg_no, p.address,
            d.full_name AS decided_by_name, r.full_name AS recorded_by_name
     FROM winner_offers o JOIN users u ON u.id = o.user_id LEFT JOIN participants p ON p.user_id = o.user_id
     LEFT JOIN users d ON d.id = o.decided_by LEFT JOIN users r ON r.id = o.payment_recorded_by
     WHERE o.auction_id = ? ORDER BY o.id DESC',
    [$a['id']]
);
$offer = $offers[0] ?? null;
$creator = db_one('SELECT full_name FROM users WHERE id = ?', [$a['created_by']]);
$approver = $a['approved_by'] ? db_one('SELECT full_name FROM users WHERE id = ?', [$a['approved_by']]) : null;
$repeats = db_all('SELECT id, reference, status FROM auctions WHERE repeat_of = ?', [$a['id']]);
$original = $a['repeat_of'] ? db_one('SELECT id, reference FROM auctions WHERE id = ?', [$a['repeat_of']]) : null;

$tabs = [
    'overview' => 'Overview',
    'bids'     => 'Bids <span class="tab-count">' . $summary['bids'] . '</span>',
    'winner'   => 'Winner &amp; payment',
    'activity' => 'Activity',
];
$tab = get_str('tab', 20);
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}

// What this user can do right now. Each action is a pop-up form.
$offerPending = $offer && $offer['status'] === 'pending_approval' && $a['status'] === 'pending_winner_approval';
$offerUnpaid = $offer && $offer['status'] === 'approved' && in_array($a['status'], ['awaiting_payment', 'payment_overdue'], true);
$can = [
    'submit'          => in_array($a['status'], ['draft', 'rejected'], true) && can('auction.create'),
    'approve'         => $a['status'] === 'pending_approval' && can('auction.approve'),
    'resume'          => $a['status'] === 'suspended' && can('auction.suspend'),
    'suspend'         => $a['status'] === 'published' && can('auction.suspend'),
    'extend'          => in_array($a['status'], ['published', 'suspended'], true) && can('auction.extend'),
    'cancel'          => in_array($a['status'], CANCELLABLE_STATUSES, true) && can('auction.cancel'),
    'winner'          => $offerPending && can('winner.approve'),
    'payment'         => $offerUnpaid && can('payment.record'),
    'overdue'         => $offerUnpaid && $a['status'] === 'payment_overdue' && can('payment.decide'),
];

// Lifecycle progress.
$stages = ['Draft', 'Approval', 'Live bidding', 'Winner approval', 'Payment', 'Completed'];
$stageIndex = [
    'draft' => 0, 'rejected' => 1, 'pending_approval' => 1, 'published' => 2, 'suspended' => 2,
    'pending_winner_approval' => 3, 'unsold' => 3, 'awaiting_payment' => 4, 'payment_overdue' => 4, 'completed' => 6,
][$a['status']] ?? 0;
if ($a['status'] === 'cancelled') {
    $stageIndex = $a['closed_at'] ? 3 : ($a['approved_at'] ? 2 : ($a['submitted_at'] ? 1 : 0));
}
$stopped = in_array($a['status'], ['rejected', 'suspended', 'unsold', 'payment_overdue', 'cancelled'], true);
$stopLabel = ['rejected' => 'Returned', 'suspended' => 'Suspended', 'unsold' => 'Not sold', 'payment_overdue' => 'Overdue', 'cancelled' => 'Cancelled'][$a['status']] ?? null;

$hidden = csrf_field() . '<input type="hidden" name="return_tab" value="' . h($tab) . '">';
$form = static fn(string $action, string $extra = '') => '<form method="post">' . $hidden . '<input type="hidden" name="action" value="' . $action . '">' . $extra;

page_header($a['reference'] . ' ' . $a['title'], 'admin', 'auctions');
?>
<div class="page-head">
  <div>
    <div class="crumb"><a href="<?= h(url('admin/auctions')) ?>">Auctions</a><span>/</span><?= h($a['reference']) ?></div>
    <h1><?= h($a['title']) ?></h1>
    <div class="meta-row" style="margin-top:4px"><?= status_badge($a) ?>
      <?php if ($a['unsold_reason']): ?><span><?= h(UNSOLD_REASON_LABELS[$a['unsold_reason']]) ?></span><?php endif; ?>
      <span>Created by <?= h($creator['full_name'] ?? '?') ?>, <?= h(fmt_dt($a['created_at'], 'd M Y')) ?></span></div>
  </div>
  <div class="head-actions">
    <a class="btn-secondary btn-sm" href="<?= h(url('auction?id=' . $a['id'])) ?>"><?= icon('external') ?><?= is_public_auction($a) ? 'Public page' : 'Preview' ?></a>
    <?php if (in_array($a['status'], EDITABLE_STATUSES, true) && can('auction.create')): ?>
      <button class="btn-secondary btn-sm" type="button" data-open="mAuctionEdit">Edit</button>
    <?php endif; ?>
    <?php if ($can['extend']): ?><button class="btn-secondary btn-sm" type="button" data-open="mExtend">Extend</button><?php endif; ?>
    <?php if ($can['suspend']): ?><button class="btn-secondary btn-sm" type="button" data-open="mSuspend">Suspend</button><?php endif; ?>
    <?php if ($can['cancel']): ?><button class="btn-danger-outline btn-sm" type="button" data-open="mCancel">Cancel auction</button><?php endif; ?>
  </div>
</div>

<?= page_tabs($tabs, $tab) ?>

<?php if ($tab === 'overview'): ?>
  <ol class="stepper" aria-label="Auction progress">
    <?php foreach ($stages as $i => $label): $cls = $i < $stageIndex ? 'done' : ($i === $stageIndex ? ($stopped ? 'stopped' : 'current') : ''); ?>
      <li class="<?= $cls ?>"<?= $i === $stageIndex ? ' aria-current="step"' : '' ?>><?= h($i === $stageIndex && $stopLabel ? $stopLabel : $label) ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($a['status_reason'] && in_array($a['status'], ['rejected', 'suspended', 'cancelled'], true)): ?>
    <div class="flash flash-error"><div><b><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?>:</b> <?= h($a['status_reason']) ?></div></div>
  <?php endif; ?>
  <?php if ($original): ?><div class="flash flash-info"><div>Repeat of <a href="<?= h(url('admin/auction_view?id=' . $original['id'])) ?>"><?= h($original['reference']) ?></a>.</div></div><?php endif; ?>
  <?php foreach ($repeats as $r): ?><div class="flash flash-info"><div>Re-offered as <a href="<?= h(url('admin/auction_view?id=' . $r['id'])) ?>"><?= h($r['reference']) ?></a> (<?= h(AUCTION_STATUS_LABELS[$r['status']]) ?>).</div></div><?php endforeach; ?>

  <?php /* The one decision this stage needs, if this user can make it. */ ?>
  <?php if ($can['submit']): ?>
    <div class="panel next-step"><div class="eyebrow">Next step</div><h3>Submit for approval</h3>
      <p class="small muted">An administrator must approve the auction before it is published.</p>
      <button class="btn-primary" type="button" data-open="mSubmit">Submit for approval</button></div>
  <?php elseif ($can['approve']): ?>
    <div class="panel next-step"><div class="eyebrow">Next step</div><h3>Approve or return this auction</h3>
      <p class="small muted">Once approved it is published; bidding opens <?= h(fmt_dt($a['opens_at'])) ?>.</p>
      <div class="btn-row"><button class="btn-primary" type="button" data-open="mApprove">Approve &amp; publish</button>
        <button class="btn-secondary" type="button" data-open="mReturn">Return for changes</button></div></div>
  <?php elseif ($can['resume']): ?>
    <div class="panel next-step alert"><div class="eyebrow">Next step</div><h3>Bidding is suspended</h3>
      <button class="btn-primary" type="button" data-open="mResume">Resume bidding</button></div>
  <?php elseif ($can['winner']): ?>
    <div class="panel next-step"><div class="eyebrow">Next step</div><h3>Approve the winner</h3>
      <p><b><?= h(display_name($offer)) ?></b> · bid <b><?= h(money($offer['amount'])) ?></b><?= (int) $offer['rank_no'] > 1 ? ' · <span class="status-pill status-pending">Next highest bidder</span>' : '' ?></p>
      <div class="btn-row"><button class="btn-primary" type="button" data-open="mApproveWinner">Approve winner</button>
        <button class="btn-secondary" type="button" data-open="mRejectWinner">Reject &amp; offer to next bidder</button></div></div>
  <?php elseif ($offerUnpaid): ?>
    <div class="panel next-step<?= $a['status'] === 'payment_overdue' ? ' alert' : '' ?>"><div class="eyebrow">Next step</div>
      <h3><?= $a['status'] === 'payment_overdue' ? 'Payment overdue' : 'Waiting for payment' ?></h3>
      <p><b><?= h(display_name($offer)) ?></b> must pay <b><?= h(money($offer['amount'])) ?></b> by <b><?= h(fmt_dt($offer['payment_due_at'])) ?></b>.
        GePG control number: <span class="code"><?= h($offer['control_number'] ?: 'not issued yet') ?></span></p>
      <div class="btn-row">
        <?php if ($can['payment']): ?>
          <button class="btn-secondary" type="button" data-open="mControl"><?= $offer['control_number'] ? 'Change control number' : 'Enter control number' ?></button>
          <button class="btn-primary" type="button" data-open="mPaid">Record payment</button>
        <?php endif; ?>
        <?php if ($can['overdue']): ?><button class="btn-primary" type="button" data-open="mOverdue">Management decision</button><?php endif; ?>
      </div></div>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat"><div class="stat-label">Highest bid</div><div class="stat-value money tabular"><?= h($highest ? money($highest['amount']) : '—') ?></div>
      <?php if ($highest): ?><div class="stat-sub"><?= h(display_name($highest)) ?></div><?php endif; ?></div>
    <div class="stat"><div class="stat-label">Bids · bidders</div><div class="stat-value tabular"><?= $summary['bids'] ?> · <?= $summary['bidders'] ?></div></div>
    <div class="stat"><div class="stat-label">Starting price</div><div class="stat-value money tabular"><?= h(money($a['starting_price'])) ?></div></div>
    <div class="stat"><div class="stat-label">Reserve price</div><div class="stat-value money tabular"><?= h($a['reserve_price'] !== null ? money($a['reserve_price']) : 'None') ?></div>
      <?php if ($a['reserve_price'] !== null && $highest): ?><div class="stat-sub"><?= reserve_met($a, $highest) ? 'Met' : 'Not met' ?></div><?php endif; ?></div>
    <div class="stat"><div class="stat-label">Opens</div><div class="stat-value date"><?= h(fmt_dt($a['opens_at'])) ?></div></div>
    <div class="stat"><div class="stat-label">Closes</div><div class="stat-value date"><?= h(fmt_dt($a['closes_at'])) ?></div>
      <?php if (in_array($phase, ['live', 'upcoming'], true)): ?><div class="countdown small" style="text-align:left" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div><?php endif; ?></div>
  </div>

  <dl class="facts">
    <div><dt>Quantity</dt><dd><?= h($a['quantity']) ?></dd></div>
    <div><dt>Location</dt><dd><?= h($a['location']) ?></dd></div>
    <div><dt>Minimum increment</dt><dd><?= h(money($a['min_increment'])) ?></dd></div>
    <div><dt>Original closing</dt><dd><?= h(fmt_dt($a['original_closes_at'])) ?></dd></div>
    <div><dt>Approved by</dt><dd><?= h($approver['full_name'] ?? '—') ?><?= $a['approved_at'] ? '<br><span class="small muted">' . h(fmt_dt($a['approved_at'])) . '</span>' : '' ?></dd></div>
    <div><dt>Files</dt><dd><?= count(auction_files($a['id'], 'photo')) ?> photos, <?= count(auction_files($a['id'], 'document')) ?> documents</dd></div>
  </dl>
  <div class="section"><h2>Description</h2><div class="prose"><?= h($a['description']) ?></div></div>

<?php elseif ($tab === 'bids'): ?>
  <?php
  $bids = db_all(
      'SELECT b.*, u.full_name, u.email, u.phone, p.company_name, p.participant_type
       FROM bids b JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
       WHERE b.auction_id = ? ORDER BY b.id DESC',
      [$a['id']]
  );
  $extensions = db_all('SELECT x.*, u.full_name FROM auction_extensions x LEFT JOIN users u ON u.id = x.created_by WHERE x.auction_id = ? ORDER BY x.id DESC', [$a['id']]);
  ?>
  <?php if (!$bids): ?>
    <?= empty_state('No bids yet.', 'gavel') ?>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Time</th><th>Bidder</th><th>Contact</th><th class="num">Amount</th><th>IP</th></tr></thead>
      <tbody><?php foreach ($bids as $i => $b): ?>
        <tr><td class="nowrap"><?= h(fmt_dt($b['created_at'], 'd M Y, H:i:s')) ?></td>
          <td><?= h(display_name($b)) ?><?= $i === 0 ? ' <span class="status-pill status-live">Highest</span>' : '' ?></td>
          <td class="small"><?= h($b['email']) ?><br><?= h($b['phone']) ?></td><td class="num"><?= h(money($b['amount'])) ?></td><td class="small muted"><?= h($b['ip']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?php if ($extensions): ?>
    <div class="section"><h2>Closing time changes</h2>
      <div class="table-wrap"><table class="data">
        <thead><tr><th>When</th><th>Type</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
        <tbody><?php foreach ($extensions as $x): ?>
          <tr><td class="nowrap"><?= h(fmt_dt($x['created_at'])) ?></td><td><?= $x['kind'] === 'auto' ? 'Automatic (late bid)' : 'Manual' ?></td>
            <td class="nowrap"><?= h(fmt_dt($x['old_closes_at'])) ?></td><td class="nowrap"><?= h(fmt_dt($x['new_closes_at'])) ?></td>
            <td><?= h($x['full_name'] ?? 'System') ?></td><td class="small"><?= h($x['reason']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div></div>
  <?php endif; ?>

<?php elseif ($tab === 'winner'): ?>
  <?php if (!$offers): ?>
    <?= empty_state(in_array($a['status'], ['draft', 'pending_approval', 'rejected', 'published', 'suspended'], true) ? 'The winner is decided after the auction closes.' : 'There was no winner for this auction.', 'trophy') ?>
  <?php else: ?>
    <?php if ($offer && in_array($offer['status'], ['pending_approval', 'approved', 'paid'], true)): ?>
      <div class="panel">
        <div class="eyebrow">Current winner</div>
        <h3><?= h(display_name($offer)) ?> · <?= h(money($offer['amount'])) ?></h3>
        <dl class="facts" style="margin-top:10px">
          <?php if ($offer['participant_type'] === 'company'): ?>
            <div><dt>Contact person</dt><dd><?= h($offer['full_name']) ?></dd></div>
            <div><dt>TIN</dt><dd><?= h($offer['tin']) ?></dd></div>
            <div><dt>BRELA</dt><dd><?= h($offer['brela_reg_no']) ?></dd></div>
          <?php else: ?>
            <div><dt>NIDA</dt><dd><?= h($offer['nida_number']) ?></dd></div>
          <?php endif; ?>
          <div><dt>Email</dt><dd><?= h($offer['email']) ?></dd></div>
          <div><dt>Phone</dt><dd><?= h($offer['phone']) ?></dd></div>
          <div><dt>Address</dt><dd><?= h($offer['address']) ?></dd></div>
          <div><dt>GePG control no.</dt><dd class="code"><?= h($offer['control_number'] ?: '—') ?></dd></div>
          <div><dt>Payment due</dt><dd><?= h(fmt_dt($offer['payment_due_at'])) ?></dd></div>
        </dl>
      </div>
    <?php endif; ?>
    <div class="section"><h2>History</h2>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>#</th><th>Bidder</th><th class="num">Amount</th><th>Status</th><th>Decided by</th><th>Reason</th><th>Paid</th></tr></thead>
      <tbody><?php foreach ($offers as $o): ?>
        <tr><td><?= (int) $o['rank_no'] ?></td><td><?= h(display_name($o)) ?></td><td class="num"><?= h(money($o['amount'])) ?></td>
          <td><?= h(ucfirst(str_replace('_', ' ', $o['status']))) ?></td>
          <td><?= h($o['decided_by_name'] ?? '—') ?><?= $o['decided_at'] ? '<br><span class="small muted">' . h(fmt_dt($o['decided_at'])) . '</span>' : '' ?></td>
          <td class="small"><?= h($o['decision_reason'] ?? '') ?></td>
          <td><?= $o['paid_at'] ? h(fmt_dt($o['paid_at'])) . '<br><span class="small muted">ref ' . h($o['payment_reference']) . ' · ' . h($o['recorded_by_name']) . '</span>' : '—' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div></div>
  <?php endif; ?>

<?php else: /* activity */ ?>
  <?php $activity = db_all(
      'SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id
       WHERE l.entity_type = "auction" AND l.entity_id = ? ORDER BY l.id DESC LIMIT 200',
      [$a['id']]
  ); ?>
  <ul class="timeline">
    <?php foreach ($activity as $l): ?>
      <li><span class="when"><?= h(fmt_dt($l['created_at'], 'd M Y, H:i:s')) ?> · <?= h($l['full_name'] ?? 'System') ?> · <?= h(AUDIT_ACTIONS[$l['action']] ?? $l['action']) ?></span><br><?= h($l['summary']) ?><?= $l['reason'] ? '<br><span class="small muted">Reason: ' . h($l['reason']) . '</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php /* ---------------- Pop-up forms ---------------- */ ?>
<?php if (in_array($a['status'], EDITABLE_STATUSES, true) && can('auction.create')): ?><?= auction_form_modal($a) ?><?php endif; ?>
<?php if ($can['submit']): ?>
  <?= modal_start('mSubmit', 'Submit for approval', 'An administrator will review it. You can still edit it until it is approved.') ?>
    <?= $form('submit') ?><?= modal_actions('Submit for approval') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['approve']): ?>
  <?= modal_start('mApprove', 'Approve and publish', 'The auction becomes public. Bidding opens <b>' . h(fmt_dt($a['opens_at'])) . '</b> and closes <b>' . h(fmt_dt($a['closes_at'])) . '</b>. All participants are notified when it opens.') ?>
    <?= $form('approve') ?><?= modal_actions('Approve & publish') ?></form><?= modal_end() ?>
  <?= modal_start('mReturn', 'Return for changes', 'The creator is notified with your reason and can edit and resubmit.') ?>
    <?= $form('reject') ?><?= reason_field('What needs to change?') ?><?= modal_actions('Return to creator') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['resume']): ?>
  <?= modal_start('mResume', 'Resume bidding', 'Bidders are notified. If the closing time has passed or is very close, set a new one.') ?>
    <?= $form('resume') ?><div class="field"><label>New closing time <span class="muted">(optional)</span></label><input type="datetime-local" name="new_closes_at"></div>
    <?= reason_field() ?><?= modal_actions('Resume bidding') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['suspend']): ?>
  <?= modal_start('mSuspend', 'Suspend bidding', 'No bids are accepted while suspended. Bidders are notified with the reason.', 'danger') ?>
    <?= $form('suspend') ?><?= reason_field() ?><?= modal_actions('Suspend', 'btn-danger') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['extend']): ?>
  <?= modal_start('mExtend', 'Extend closing time', 'Currently closes <b>' . h(fmt_dt($a['closes_at'])) . '</b>. Bidders are notified.') ?>
    <?= $form('extend') ?><div class="field"><label>New closing date &amp; time</label><input type="datetime-local" name="new_closes_at" required value="<?= h(to_local_input($a['closes_at'])) ?>"></div>
    <?= reason_field() ?><?= modal_actions('Extend') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['cancel']): ?>
  <?= modal_start('mCancel', 'Cancel auction', 'This cannot be undone. All bids become void and bidders are notified with the reason.', 'danger') ?>
    <?= $form('cancel') ?><?= reason_field() ?><?= modal_actions('Cancel auction', 'btn-danger') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['winner']): ?>
  <?= modal_start('mApproveWinner', 'Approve winner', '<b>' . h(display_name($offer)) . '</b> wins with <b>' . h(money($offer['amount'])) . '</b>. They are notified and have ' . PAYMENT_WINDOW_HOURS . ' hours to pay.') ?>
    <p class="small muted"><?= h($offer['email']) ?> · <?= h($offer['phone']) ?> · <?= $offer['participant_type'] === 'company' ? 'TIN ' . h($offer['tin']) . ' · BRELA ' . h($offer['brela_reg_no']) : 'NIDA ' . h($offer['nida_number']) ?></p>
    <?= $form('approve_winner', '<input type="hidden" name="offer_id" value="' . (int) $offer['id'] . '">') ?><?= modal_actions('Approve winner') ?></form><?= modal_end() ?>
  <?= modal_start('mRejectWinner', 'Reject winner', 'The item is offered to the next highest bidder. If nobody is left, the auction is repeated.', 'danger') ?>
    <?= $form('reject_winner', '<input type="hidden" name="offer_id" value="' . (int) $offer['id'] . '">') ?><?= reason_field('Why does this bidder not meet the conditions?') ?><?= modal_actions('Reject winner', 'btn-danger') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['payment']): ?>
  <?= modal_start('mControl', 'GePG control number', 'The winner receives the control number by email and in their account.') ?>
    <?= $form('control_number', '<input type="hidden" name="offer_id" value="' . (int) $offer['id'] . '">') ?>
    <div class="field"><label>Control number</label><input name="control_number" inputmode="numeric" required value="<?= h($offer['control_number'] ?? '') ?>"></div>
    <?= modal_actions('Save & send to winner') ?></form><?= modal_end() ?>
  <?= modal_start('mPaid', 'Record payment received', 'Confirm that <b>' . h(money($offer['amount'])) . '</b> was received from <b>' . h(display_name($offer)) . '</b>. The auction is then completed.') ?>
    <?= $form('record_payment', '<input type="hidden" name="offer_id" value="' . (int) $offer['id'] . '">') ?>
    <div class="field"><label>GePG receipt / payment reference</label><input name="payment_reference" required></div>
    <?= modal_actions('Record payment') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php if ($can['overdue']): ?>
  <?= modal_start('mOverdue', 'Overdue payment — management decision', 'The winner did not pay within ' . PAYMENT_WINDOW_HOURS . ' hours. No deposit is taken, so there is nothing to forfeit.') ?>
    <?= $form('resolve_overdue') ?>
    <div class="field"><label>Decision</label>
      <select name="decision" required>
        <option value="offer_next">Offer to the second-highest bidder</option>
        <option value="repeat">Repeat the auction</option>
        <option value="extend">Give the winner more time</option>
      </select></div>
    <div class="field"><label>New payment deadline <span class="muted">(only for “more time”)</span></label><input type="datetime-local" name="new_due_at"></div>
    <?= reason_field() ?><?= modal_actions('Record decision') ?></form><?= modal_end() ?>
<?php endif; ?>
<?php page_footer();
