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
        'suspend' => 'auction.control', 'resume' => 'auction.control', 'extend' => 'auction.control', 'cancel' => 'auction.control',
        'approve_winner' => 'winner.approve', 'reject_winner' => 'winner.approve', 'resolve_overdue' => 'winner.approve',
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
    redirect('admin/auction_view?id=' . $a['id']);
}

$phase = auction_phase($a);
$summary = auction_bid_summary($a['id']);
$highest = $summary['highest'];
$bids = db_all(
    'SELECT b.*, u.full_name, u.email, u.phone, p.company_name, p.participant_type
     FROM bids b JOIN users u ON u.id = b.user_id LEFT JOIN participants p ON p.user_id = b.user_id
     WHERE b.auction_id = ? ORDER BY b.id DESC',
    [$a['id']]
);
$offers = db_all(
    'SELECT o.*, u.full_name, u.email, u.phone, p.company_name, p.participant_type, p.nida_number, p.tin, p.brela_reg_no, p.address,
            d.full_name AS decided_by_name, r.full_name AS recorded_by_name
     FROM winner_offers o JOIN users u ON u.id = o.user_id LEFT JOIN participants p ON p.user_id = o.user_id
     LEFT JOIN users d ON d.id = o.decided_by LEFT JOIN users r ON r.id = o.payment_recorded_by
     WHERE o.auction_id = ? ORDER BY o.id DESC',
    [$a['id']]
);
$offer = $offers[0] ?? null;
$extensions = db_all('SELECT x.*, u.full_name FROM auction_extensions x LEFT JOIN users u ON u.id = x.created_by WHERE x.auction_id = ? ORDER BY x.id DESC', [$a['id']]);
$activity = db_all(
    'SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id
     WHERE l.entity_type = "auction" AND l.entity_id = ? AND l.action <> "bid_submitted" ORDER BY l.id DESC LIMIT 100',
    [$a['id']]
);
$creator = db_one('SELECT full_name FROM users WHERE id = ?', [$a['created_by']]);
$approver = $a['approved_by'] ? db_one('SELECT full_name FROM users WHERE id = ?', [$a['approved_by']]) : null;
$repeats = db_all('SELECT id, reference, status FROM auctions WHERE repeat_of = ?', [$a['id']]);
$original = $a['repeat_of'] ? db_one('SELECT id, reference FROM auctions WHERE id = ?', [$a['repeat_of']]) : null;

$reasonField = static fn(string $label = 'Reason (required, recorded in the audit trail)') =>
    '<div class="field"><label>' . h($label) . '</label><textarea name="reason" required minlength="5" style="min-height:70px"></textarea></div>';

page_header($a['reference'] . ' ' . $a['title'], 'admin', 'auctions');
?>
<div class="page-head">
  <div>
    <div class="small muted"><?= h($a['reference']) ?> · created by <?= h($creator['full_name'] ?? '?') ?> on <?= h(fmt_dt($a['created_at'])) ?></div>
    <h1><?= h($a['title']) ?></h1>
    <p><?= status_badge($a) ?> <span class="muted"><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?><?= $a['unsold_reason'] ? ' — ' . h(UNSOLD_REASON_LABELS[$a['unsold_reason']]) : '' ?></span></p>
  </div>
  <div class="form-actions" style="margin:0">
    <a class="btn-secondary btn-sm" href="<?= h(url('auction?id=' . $a['id'])) ?>"><?= is_public_auction($a) ? 'Public page' : 'Preview' ?></a>
    <?php if (in_array($a['status'], EDITABLE_STATUSES, true) && can('auction.create')): ?>
      <a class="btn-primary btn-sm" href="<?= h(url('admin/auction_edit?id=' . $a['id'])) ?>">Edit</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($a['status_reason'] && in_array($a['status'], ['rejected', 'suspended', 'cancelled'], true)): ?>
  <div class="flash flash-error"><b><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?>:</b> <?= h($a['status_reason']) ?></div>
<?php endif; ?>
<?php if ($original): ?><div class="flash flash-info">Repeat of <a href="<?= h(url('admin/auction_view?id=' . $original['id'])) ?>"><?= h($original['reference']) ?></a>.</div><?php endif; ?>
<?php foreach ($repeats as $r): ?><div class="flash flash-info">This item was re-offered as <a href="<?= h(url('admin/auction_view?id=' . $r['id'])) ?>"><?= h($r['reference']) ?></a> (<?= h(AUCTION_STATUS_LABELS[$r['status']]) ?>).</div><?php endforeach; ?>

<div class="stat-row">
  <div class="stat"><div class="stat-label">Highest bid</div><div class="stat-value tabular"><?= h($highest ? money($highest['amount']) : '—') ?></div></div>
  <div class="stat"><div class="stat-label">Bids / bidders</div><div class="stat-value tabular"><?= $summary['bids'] ?> / <?= $summary['bidders'] ?></div></div>
  <div class="stat"><div class="stat-label">Starting price</div><div class="stat-value tabular"><?= h(money($a['starting_price'])) ?></div></div>
  <div class="stat"><div class="stat-label">Reserve price</div><div class="stat-value tabular"><?= h($a['reserve_price'] !== null ? money($a['reserve_price']) : 'None') ?></div></div>
  <div class="stat"><div class="stat-label">Opens</div><div class="stat-value" style="font-size:15px"><?= h(fmt_dt($a['opens_at'])) ?></div></div>
  <div class="stat"><div class="stat-label">Closes</div><div class="stat-value" style="font-size:15px"><?= h(fmt_dt($a['closes_at'])) ?></div>
    <?php if (in_array($phase, ['live', 'upcoming'], true)): ?><div class="countdown small" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div><?php endif; ?></div>
</div>

<?php /* ---------------- Actions ---------------- */ ?>
<div class="action-grid">
  <?php if (in_array($a['status'], ['draft', 'rejected'], true) && can('auction.create')): ?>
    <form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="submit">
      <h3>Submit for approval</h3><p class="small muted">An administrator must approve the auction before it is published (Q3).</p>
      <button class="btn-primary">Submit for approval</button></form>
  <?php endif; ?>

  <?php if ($a['status'] === 'pending_approval' && can('auction.approve')): ?>
    <form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="approve">
      <h3>Approve &amp; publish</h3><p class="small muted">Bidding opens <?= h(fmt_dt($a['opens_at'])) ?>. All participants are notified when it opens.</p>
      <button class="btn-primary">Approve and publish</button></form>
    <details class="action"><summary>Return for changes</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><?= $reasonField('What needs to change?') ?><button class="btn-danger">Return to creator</button></form></details>
  <?php endif; ?>

  <?php if ($a['status'] === 'suspended' && can('auction.control')): ?>
    <details class="action" open><summary>Resume bidding</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resume">
        <div class="field"><label>New closing time (needed if the old one has passed)</label><input type="datetime-local" name="new_closes_at"></div>
        <?= $reasonField() ?><button class="btn-primary">Resume</button></form></details>
  <?php endif; ?>

  <?php if ($a['status'] === 'published' && can('auction.control')): ?>
    <details class="action"><summary>Suspend bidding</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><?= $reasonField() ?><button class="btn-danger">Suspend</button></form></details>
  <?php endif; ?>

  <?php if (in_array($a['status'], ['published', 'suspended'], true) && can('auction.control')): ?>
    <details class="action"><summary>Extend closing time</summary>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="extend">
        <div class="field"><label>New closing date &amp; time</label><input type="datetime-local" name="new_closes_at" required value="<?= h(to_local_input($a['closes_at'])) ?>"></div>
        <?= $reasonField() ?><button class="btn-primary">Extend</button></form></details>
  <?php endif; ?>

  <?php if ($offer && $offer['status'] === 'pending_approval' && $a['status'] === 'pending_winner_approval' && can('winner.approve')): ?>
    <div class="panel" style="grid-column:1/-1">
      <h3>Winner awaiting approval (Q19)</h3>
      <p><b><?= h(display_name($offer)) ?></b><?= $offer['participant_type'] === 'company' ? ' — contact ' . h($offer['full_name']) : '' ?> · bid <b><?= h(money($offer['amount'])) ?></b><?= (int) $offer['rank_no'] > 1 ? ' · offer #' . (int) $offer['rank_no'] . ' (next highest bidder)' : '' ?></p>
      <p class="small muted"><?= h($offer['email']) ?> · <?= h($offer['phone']) ?> · <?= $offer['participant_type'] === 'company' ? 'TIN ' . h($offer['tin']) . ' · BRELA ' . h($offer['brela_reg_no']) : 'NIDA ' . h($offer['nida_number']) ?> · <?= h($offer['address']) ?></p>
      <div class="action-grid">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="approve_winner"><input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
          <button class="btn-primary">Approve winner</button>
          <p class="small muted">The winner is notified and has <?= PAYMENT_WINDOW_HOURS ?> hours to pay.</p></form>
        <details class="action"><summary>Reject — offer to next highest bidder (Q20)</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject_winner"><input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
            <?= $reasonField('Why does this bidder not meet the conditions?') ?><button class="btn-danger">Reject winner</button></form></details>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($offer && $offer['status'] === 'approved' && in_array($a['status'], ['awaiting_payment', 'payment_overdue'], true)): ?>
    <div class="panel" style="grid-column:1/-1">
      <h3>Payment<?= $a['status'] === 'payment_overdue' ? ' — <span class="status-pill status-alert">Overdue</span>' : '' ?></h3>
      <p><b><?= h(display_name($offer)) ?></b> must pay <b><?= h(money($offer['amount'])) ?></b> by <b><?= h(fmt_dt($offer['payment_due_at'])) ?></b>.
        Control number: <span class="code"><?= h($offer['control_number'] ?: 'not issued yet') ?></span></p>
      <?php if (can('payment.record')): ?>
      <div class="action-grid">
        <form method="post" class="field"><?= csrf_field() ?><input type="hidden" name="action" value="control_number"><input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
          <label>GePG control number</label><input name="control_number" inputmode="numeric" value="<?= h($offer['control_number'] ?? '') ?>" required>
          <button class="btn-secondary" style="margin-top:8px">Save &amp; send to winner</button></form>
        <form method="post" class="field" data-confirm="Record this payment as received? The auction will be marked completed."><?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="offer_id" value="<?= (int) $offer['id'] ?>">
          <label>GePG receipt / payment reference</label><input name="payment_reference" required>
          <button class="btn-primary" style="margin-top:8px">Record payment received</button></form>
      </div>
      <?php endif; ?>
      <?php if ($a['status'] === 'payment_overdue' && can('winner.approve')): ?>
        <details class="action" open style="margin-top:14px"><summary>Management decision (Q25)</summary>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="resolve_overdue">
            <div class="field"><label>Decision</label>
              <select name="decision" required>
                <option value="offer_next">Offer to the second-highest bidder</option>
                <option value="repeat">Repeat the auction</option>
                <option value="extend">Give the winner more time</option>
              </select></div>
            <div class="field"><label>New payment deadline (only for “more time”)</label><input type="datetime-local" name="new_due_at"></div>
            <?= $reasonField() ?><button class="btn-primary">Record decision</button>
            <p class="small muted">No deposit is taken (Q8), so there is nothing to forfeit.</p></form></details>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (in_array($a['status'], CANCELLABLE_STATUSES, true) && can('auction.control')): ?>
    <details class="action"><summary>Cancel auction</summary>
      <form method="post" data-confirm="Cancel this auction? Bidders will be notified and all bids become void. This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><?= $reasonField() ?><button class="btn-danger">Cancel auction</button></form></details>
  <?php endif; ?>
</div>

<div class="section">
  <h2>Details</h2>
  <dl class="facts">
    <div><dt>Quantity</dt><dd><?= h($a['quantity']) ?></dd></div>
    <div><dt>Location</dt><dd><?= h($a['location']) ?></dd></div>
    <div><dt>Minimum increment</dt><dd><?= h(money($a['min_increment'])) ?></dd></div>
    <div><dt>Original closing</dt><dd><?= h(fmt_dt($a['original_closes_at'])) ?></dd></div>
    <div><dt>Approved by</dt><dd><?= h($approver['full_name'] ?? '—') ?><?= $a['approved_at'] ? '<br><span class="small muted">' . h(fmt_dt($a['approved_at'])) . '</span>' : '' ?></dd></div>
    <div><dt>Files</dt><dd><?= count(auction_files($a['id'], 'photo')) ?> photos, <?= count(auction_files($a['id'], 'document')) ?> documents</dd></div>
  </dl>
  <div class="prose small"><?= h($a['description']) ?></div>
</div>

<?php if ($offers): ?>
<div class="section">
  <h2>Winner &amp; payment history</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>#</th><th>Bidder</th><th class="num">Amount</th><th>Status</th><th>Decided by</th><th>Reason</th><th>Payment due</th><th>Paid</th></tr></thead>
    <tbody><?php foreach ($offers as $o): ?>
      <tr><td><?= (int) $o['rank_no'] ?></td><td><?= h(display_name($o)) ?></td><td class="num"><?= h(money($o['amount'])) ?></td>
        <td><?= h(ucfirst(str_replace('_', ' ', $o['status']))) ?></td>
        <td><?= h($o['decided_by_name'] ?? '—') ?><?= $o['decided_at'] ? '<br><span class="small muted">' . h(fmt_dt($o['decided_at'])) . '</span>' : '' ?></td>
        <td class="small"><?= h($o['decision_reason'] ?? '') ?></td>
        <td class="nowrap"><?= h(fmt_dt($o['payment_due_at'])) ?></td>
        <td><?= $o['paid_at'] ? h(fmt_dt($o['paid_at'])) . '<br><span class="small muted">ref ' . h($o['payment_reference']) . ' · ' . h($o['recorded_by_name']) . '</span>' : '—' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="section">
  <h2>Bids (<?= count($bids) ?>)</h2>
  <?php if (!$bids): ?><p class="muted">No bids.</p><?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Time</th><th>Bidder</th><th>Contact</th><th class="num">Amount</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($bids as $b): ?>
      <tr><td class="nowrap"><?= h(fmt_dt($b['created_at'], 'd M Y, H:i:s')) ?></td><td><?= h(display_name($b)) ?></td>
        <td class="small"><?= h($b['email']) ?><br><?= h($b['phone']) ?></td><td class="num"><?= h(money($b['amount'])) ?></td><td class="small muted"><?= h($b['ip']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php if ($extensions): ?>
<div class="section">
  <h2>Closing time changes</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>When</th><th>Type</th><th>From</th><th>To</th><th>By</th><th>Reason</th></tr></thead>
    <tbody><?php foreach ($extensions as $x): ?>
      <tr><td class="nowrap"><?= h(fmt_dt($x['created_at'])) ?></td><td><?= $x['kind'] === 'auto' ? 'Automatic (late bid)' : 'Manual' ?></td>
        <td class="nowrap"><?= h(fmt_dt($x['old_closes_at'])) ?></td><td class="nowrap"><?= h(fmt_dt($x['new_closes_at'])) ?></td>
        <td><?= h($x['full_name'] ?? 'System') ?></td><td class="small"><?= h($x['reason']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<div class="section">
  <h2>Activity</h2>
  <ul class="timeline">
    <?php foreach ($activity as $l): ?>
      <li><span class="when"><?= h(fmt_dt($l['created_at'], 'd M Y, H:i:s')) ?> · <?= h($l['full_name'] ?? 'System') ?></span><br><?= h($l['summary']) ?><?= $l['reason'] ? '<br><span class="small muted">Reason: ' . h($l['reason']) . '</span>' : '' ?></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php page_footer();
