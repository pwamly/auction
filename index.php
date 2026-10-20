<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
maybe_run_tasks();

$tab = get_str('tab') === 'upcoming' ? 'upcoming' : 'live';
$q = get_str('q');

$params = [];
$where = 'a.status IN ("published", "suspended")';
$where .= $tab === 'upcoming' ? ' AND a.opens_at > NOW(3)' : ' AND a.opens_at <= NOW(3)';
if ($q !== '') {
    $where .= ' AND (a.title LIKE ? OR a.description LIKE ? OR a.location LIKE ? OR a.reference LIKE ?)';
    $like = '%' . addcslashes($q, '\\%_') . '%';
    array_push($params, $like, $like, $like, $like);
}
$auctions = db_all(
    "SELECT a.*,
            (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS top_bid,
            (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count,
            (SELECT f.id FROM auction_files f WHERE f.auction_id = a.id AND f.kind = 'photo' ORDER BY f.id LIMIT 1) AS photo_id
     FROM auctions a WHERE $where
     ORDER BY " . ($tab === 'upcoming' ? 'a.opens_at' : 'a.closes_at') . ' LIMIT 200',
    $params
);
$liveCount = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status IN ("published","suspended") AND opens_at <= NOW(3)');
$upcomingCount = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status IN ("published","suspended") AND opens_at > NOW(3)');

page_header('Auctions', 'public', 'auctions');
?>
<div class="page-head">
  <div>
    <h1>Online auctions</h1>
    <p>Open, timed bidding on <?= h(APP_ORG) ?> assets and commodity lots. The highest bid when the clock runs out wins.</p>
  </div>
  <form class="filters" method="get" action="<?= h(url('')) ?>" style="margin:0">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Item, location or reference"></div>
    <button class="btn-secondary" type="submit">Search</button>
  </form>
</div>

<div class="tabs">
  <a href="<?= h(url('?tab=live')) ?>" class="<?= $tab === 'live' ? 'active' : '' ?>">Open now (<?= $liveCount ?>)</a>
  <a href="<?= h(url('?tab=upcoming')) ?>" class="<?= $tab === 'upcoming' ? 'active' : '' ?>">Upcoming (<?= $upcomingCount ?>)</a>
  <a href="<?= h(url('results')) ?>">Results</a>
</div>

<?php if (!$auctions): ?>
  <div class="empty-state"><?= $q !== '' ? 'No auctions match your search.' : ($tab === 'upcoming' ? 'No upcoming auctions are scheduled yet.' : 'No auctions are open right now. See the upcoming tab or check back soon.') ?></div>
<?php else: ?>
  <div class="card-grid">
  <?php foreach ($auctions as $a): $a['id'] = (int) $a['id']; ?>
    <a class="auction-card" href="<?= h(url(auction_link($a))) ?>">
      <div class="thumb">
        <?php if ($a['photo_id']): ?><img src="<?= h(url('file?id=' . $a['photo_id'])) ?>" alt="" loading="lazy"><?php else: ?>No photo<?php endif; ?>
      </div>
      <div class="body">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
          <span class="ref"><?= h($a['reference']) ?></span><?= status_badge($a) ?>
        </div>
        <h3><?= h($a['title']) ?></h3>
        <div class="meta"><?= h($a['quantity']) ?> · <?= h($a['location']) ?></div>
        <div class="price-row">
          <div>
            <div class="label"><?= $a['bid_count'] ? 'Current bid' : 'Starting price' ?></div>
            <div class="big-amount tabular"><?= h(money($a['top_bid'] ?? $a['starting_price'])) ?></div>
            <div class="meta"><?= (int) $a['bid_count'] ?> bid<?= $a['bid_count'] == 1 ? '' : 's' ?></div>
          </div>
          <div class="countdown" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
