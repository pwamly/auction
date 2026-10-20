<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
maybe_run_tasks();

$user = require_staff();

$count = static fn(string $where, array $p = []) => (int) db_value("SELECT COUNT(*) FROM auctions WHERE $where", $p);
$tiles = [
    ['Awaiting approval', $count('status = "pending_approval"'), 'pending_approval', can('auction.approve')],
    ['Winners to approve', $count('status = "pending_winner_approval"'), 'pending_winner_approval', can('winner.approve')],
    ['Payments overdue', $count('status = "payment_overdue"'), 'payment_overdue', can('winner.approve') || can('payment.record')],
    ['Awaiting payment', $count('status = "awaiting_payment"'), 'awaiting_payment', true],
    ['Live now', $count('status = "published" AND opens_at <= NOW(3) AND closes_at > NOW(3)'), 'live', true],
    ['Upcoming', $count('status = "published" AND opens_at > NOW(3)'), 'upcoming', true],
    ['Drafts & returned', $count('status IN ("draft","rejected")'), 'draft', can('auction.create')],
    ['Suspended', $count('status = "suspended"'), 'suspended', true],
];

$needsMe = [];
if (can('auction.approve')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Approve or return" AS todo FROM auctions WHERE status = "pending_approval" ORDER BY submitted_at'));
}
if (can('winner.approve')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Approve or reject the winner" AS todo FROM auctions WHERE status = "pending_winner_approval" ORDER BY closed_at'));
    $needsMe = array_merge($needsMe, db_all('SELECT *, "Decide on overdue payment" AS todo FROM auctions WHERE status = "payment_overdue" ORDER BY closes_at'));
}
if (can('auction.create')) {
    $needsMe = array_merge($needsMe, db_all('SELECT *, IF(status = "rejected", "Fix and resubmit", "Finish and submit") AS todo FROM auctions WHERE status IN ("draft","rejected") ORDER BY updated_at DESC LIMIT 20'));
}
$recent = can('audit.view')
    ? db_all('SELECT l.*, u.full_name FROM audit_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 12')
    : [];

page_header('Dashboard', 'admin', 'dashboard');
?>
<div class="page-head">
  <div><h1>Dashboard</h1><p>Signed in as <?= h(ROLE_LABELS[$user['role']]) ?>.</p></div>
  <?php if (can('auction.create')): ?><a class="btn-primary" href="<?= h(url('admin/auction_edit')) ?>">+ New auction</a><?php endif; ?>
</div>

<div class="stat-row">
  <?php foreach ($tiles as [$label, $n, $filter, $show]): if (!$show) { continue; } ?>
    <a class="stat" href="<?= h(url('admin/auctions?status=' . $filter)) ?>"><div class="stat-label"><?= h($label) ?></div><div class="stat-value tabular"><?= $n ?></div></a>
  <?php endforeach; ?>
</div>

<div class="section" style="margin-top:8px">
  <h2>Needs your attention</h2>
  <?php if (!$needsMe): ?>
    <p class="muted">Nothing waiting for you.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Auction</th><th>Status</th><th>To do</th><th>Closes</th></tr></thead>
      <tbody><?php foreach ($needsMe as $a): ?>
        <tr><td class="wide"><a href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>"><?= h($a['reference'] . ' ' . $a['title']) ?></a></td>
          <td><?= status_badge($a) ?></td><td><?= h($a['todo']) ?></td><td class="nowrap"><?= h(fmt_dt($a['closes_at'])) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php if ($recent): ?>
<div class="section">
  <h2>Recent activity</h2>
  <ul class="timeline">
    <?php foreach ($recent as $l): ?>
      <li><span class="when"><?= h(fmt_dt($l['created_at'], 'd M, H:i:s')) ?> · <?= h($l['full_name'] ?? 'System') ?> · <?= h(AUDIT_ACTIONS[$l['action']] ?? $l['action']) ?></span><br><?= h($l['summary']) ?></li>
    <?php endforeach; ?>
  </ul>
  <p class="small"><a href="<?= h(url('admin/reports?type=audit')) ?>">Full audit trail →</a></p>
</div>
<?php endif; ?>
<?php page_footer();
