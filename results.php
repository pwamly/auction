<?php
declare(strict_types=1);

/** Q26: published results — auction item, winning price, winner's name, number of participants. */
require_once __DIR__ . '/bootstrap.php';
maybe_run_tasks();

[$limit, $offset, $page] = paging(30);
$rows = db_all(
    'SELECT a.id, a.reference, a.title, a.status, a.unsold_reason, a.closes_at,
            (SELECT COUNT(DISTINCT user_id) FROM bids b WHERE b.auction_id = a.id) AS participants,
            o.amount AS winning_price, u.full_name, p.company_name, p.participant_type
     FROM auctions a
     LEFT JOIN winner_offers o ON o.id = (
         SELECT MAX(o2.id) FROM winner_offers o2 WHERE o2.auction_id = a.id AND o2.status IN ("approved", "paid"))
     LEFT JOIN users u ON u.id = o.user_id
     LEFT JOIN participants p ON p.user_id = o.user_id
     WHERE a.status IN ("awaiting_payment", "payment_overdue", "completed", "unsold")
     ORDER BY a.closes_at DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset
);
$hasMore = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

page_header('Auction results', 'public', 'results');
?>
<div class="page-head">
  <div>
    <h1>Auction results</h1>
    <p>Results are published once <?= h(APP_ORG) ?> has confirmed the winner.</p>
  </div>
</div>
<?php if (!$rows): ?>
  <div class="empty-state">No results have been published yet.</div>
<?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Reference</th><th>Auction item</th><th>Closed</th><th class="num">Winning price</th><th>Winner</th><th class="num">Participants</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nowrap"><?= h($r['reference']) ?></td>
        <td class="wide"><a href="<?= h(url('auction?id=' . $r['id'])) ?>"><?= h($r['title']) ?></a></td>
        <td class="nowrap"><?= h(fmt_dt($r['closes_at'], 'd M Y')) ?></td>
        <?php if ($r['status'] === 'unsold'): ?>
          <td class="num muted">—</td><td class="muted">Not sold (<?= h(UNSOLD_REASON_LABELS[$r['unsold_reason']] ?? '') ?>)</td>
        <?php else: ?>
          <td class="num"><b><?= h(money($r['winning_price'])) ?></b></td><td><?= h(display_name($r)) ?></td>
        <?php endif; ?>
        <td class="num"><?= (int) $r['participants'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($page, $hasMore) ?>
<?php endif; ?>
<?php page_footer();
