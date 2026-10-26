<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
maybe_run_tasks();

$user = require_staff();

$count = static fn(string $where, array $p = []) => (int) db_value("SELECT COUNT(*) FROM auctions WHERE $where", $p);
// [label, count, list filter, visible to this user, needs action when > 0: '', 'attention' or 'alert']
$tiles = [
    ['Awaiting approval', $count('status = "pending_approval"'), 'pending_approval', can('auction.approve'), 'attention'],
    ['Winners to approve', $count('status = "pending_winner_approval"'), 'pending_winner_approval', can('winner.approve'), 'attention'],
    ['Payments overdue', $count('status = "payment_overdue"'), 'payment_overdue', can('payment.decide') || can('payment.record'), 'alert'],
    ['Awaiting payment', $count('status = "awaiting_payment"'), 'awaiting_payment', true, can('payment.record') ? 'attention' : ''],
    ['Live now', $count('status = "published" AND opens_at <= NOW(3) AND closes_at > NOW(3)'), 'live', true, ''],
    ['Upcoming', $count('status = "published" AND opens_at > NOW(3)'), 'upcoming', true, ''],
    ['Drafts & returned', $count('status IN ("draft","rejected")'), 'draft', can('auction.create'), ''],
    ['Suspended', $count('status = "suspended"'), 'suspended', true, 'alert'],
];

$needsMe = [];
if (can('auction.approve')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Approve or return" AS todo FROM auctions WHERE status = "pending_approval" ORDER BY submitted_at'));
}
if (can('winner.approve')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Approve or reject the winner" AS todo FROM auctions WHERE status = "pending_winner_approval" ORDER BY closed_at'));
}
if (can('payment.decide')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Decide on overdue payment" AS todo FROM auctions WHERE status = "payment_overdue" ORDER BY closes_at'));
}
if (can('auction.create')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, IF(status = "rejected", "Fix and resubmit", "Finish and submit") AS todo FROM auctions WHERE status IN ("draft","rejected") ORDER BY updated_at DESC LIMIT 20'));
}

page_header('Dashboard', 'admin', 'dashboard');
?>
<div class="page-head">
  <div><h1>Dashboard</h1><p><?= h(ROLE_LABELS[$user['role']]) ?> · <?= h(date('l, d M Y')) ?></p></div>
  <?php if (can('auction.create')): ?><button class="btn-primary" type="button" data-open="mAuctionNew"><?= icon('plus') ?>New auction</button><?php endif; ?>
</div>

<div class="stat-row">
  <?php foreach ($tiles as [$label, $n, $filter, $show, $urgency]): if (!$show) { continue; } ?>
    <a class="stat<?= $n === 0 ? ' zero' : ($urgency ? ' attention' . ($urgency === 'alert' ? ' alert' : '') : '') ?>" href="<?= h(url('admin/auctions?status=' . $filter)) ?>">
      <div class="stat-label"><?= h($label) ?></div><div class="stat-value tabular"><?= $n ?></div>
      <?php if ($n > 0 && $urgency): ?><div class="stat-sub">Needs action →</div><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<div class="section" style="margin-top:8px">
  <div class="section-head"><h2>Needs your attention</h2><span class="small muted"><?= count($needsMe) ?> item<?= count($needsMe) === 1 ? '' : 's' ?></span></div>
  <?php if (!$needsMe): ?>
    <?= empty_state('Nothing is waiting for you.', 'inbox') ?>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Auction</th><th>Status</th><th>To do</th><th>Closes</th></tr></thead>
      <tbody><?php foreach ($needsMe as $a): ?>
        <tr><td class="wide"><a href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>"><?= h($a['title']) ?></a><br><span class="small muted"><?= h($a['reference']) ?></span></td>
          <td><?= status_badge($a) ?></td><td><b><?= h($a['todo']) ?></b></td><td class="nowrap"><?= h(fmt_dt($a['closes_at'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php page_footer();
