<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
maybe_run_tasks();

$user = require_staff();
$status = get_str('status', 40);
$q = get_str('q');

$filters = [
    ''                        => ['All', '1'],
    'live'                    => ['Live', 'a.status = "published" AND a.opens_at <= NOW(3) AND a.closes_at > NOW(3)'],
    'upcoming'                => ['Upcoming', 'a.status = "published" AND a.opens_at > NOW(3)'],
    'pending_approval'        => ['Awaiting approval', 'a.status = "pending_approval"'],
    'draft'                   => ['Drafts & returned', 'a.status IN ("draft","rejected")'],
    'pending_winner_approval' => ['Winner approval', 'a.status = "pending_winner_approval"'],
    'awaiting_payment'        => ['Awaiting payment', 'a.status = "awaiting_payment"'],
    'payment_overdue'         => ['Payment overdue', 'a.status = "payment_overdue"'],
    'suspended'               => ['Suspended', 'a.status = "suspended"'],
    'finished'                => ['Completed / unsold', 'a.status IN ("completed","unsold")'],
    'cancelled'               => ['Cancelled', 'a.status = "cancelled"'],
];
if (!isset($filters[$status])) {
    $status = '';
}
$where = $filters[$status][1];
$params = [];
if ($q !== '') {
    $where .= ' AND (a.title LIKE ? OR a.reference LIKE ? OR a.location LIKE ?)';
    $like = '%' . addcslashes($q, '\\%_') . '%';
    array_push($params, $like, $like, $like);
}
[$limit, $offset, $page] = paging(50);
$rows = db_all(
    "SELECT a.*, u.full_name AS creator,
            (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS top_bid,
            (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count
     FROM auctions a JOIN users u ON u.id = a.created_by
     WHERE $where ORDER BY a.id DESC LIMIT " . ($limit + 1) . " OFFSET $offset",
    $params
);
$hasMore = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

page_header('Auctions', 'admin', 'auctions');
?>
<div class="page-head">
  <div><h1>Auctions</h1></div>
  <?php if (can('auction.create')): ?><a class="btn-primary" href="<?= h(url('admin/auction_edit')) ?>">+ New auction</a><?php endif; ?>
</div>

<form class="filters" method="get">
  <div class="field"><label for="status">Status</label>
    <select id="status" name="status" onchange="this.form.submit()">
      <?php foreach ($filters as $key => [$label]): ?><option value="<?= h($key) ?>" <?= $key === $status ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select></div>
  <div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Title, reference or location"></div>
  <button class="btn-secondary" type="submit">Filter</button>
</form>

<?php if (!$rows): ?>
  <div class="empty-state">No auctions found.</div>
<?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Reference</th><th>Item</th><th>Status</th><th>Opens</th><th>Closes</th><th class="num">Highest bid</th><th class="num">Bids</th><th>Created by</th></tr></thead>
    <tbody><?php foreach ($rows as $a): ?>
      <tr>
        <td class="nowrap"><a href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>"><?= h($a['reference']) ?></a></td>
        <td class="wide"><?= h($a['title']) ?></td>
        <td><?= status_badge($a) ?><br><span class="small muted"><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?></span></td>
        <td class="nowrap"><?= h(fmt_dt($a['opens_at'])) ?></td>
        <td class="nowrap"><?= h(fmt_dt($a['closes_at'])) ?></td>
        <td class="num"><?= h(money($a['top_bid'])) ?></td>
        <td class="num"><?= (int) $a['bid_count'] ?></td>
        <td><?= h($a['creator']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= pager($page, $hasMore) ?>
<?php endif; ?>
<?php page_footer();
