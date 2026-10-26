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
$title = $tab === 'upcoming' ? 'Upcoming auctions' : 'Open auctions';

page_header($title, 'public', $tab === 'upcoming' ? 'auctions_upcoming' : 'auctions_live');
?>
<div class="page-head">
  <div>
    <h1><?= h($title) ?></h1>
    <p><?= $tab === 'upcoming' ? 'Auctions approved by ' . h(APP_ORG) . ' that open soon.' : 'Bid now — the highest bid when the clock runs out wins.' ?></p>
  </div>
  <form class="search-bar" method="get" action="<?= h(url('auctions')) ?>" role="search">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <label class="sr-only" for="q">Search auctions</label>
    <input id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Item, location or reference">
    <button class="btn-primary" type="submit">Search</button>
  </form>
</div>

<?php if ($q !== ''): ?>
  <p class="muted" style="margin-top:-8px">Results for “<?= h($q) ?>” · <a href="<?= h(url('auctions?tab=' . $tab)) ?>">Clear</a></p>
<?php endif; ?>

<?php if (!$auctions): ?>
  <?= empty_state($q !== '' ? 'No auctions match your search.' : ($tab === 'upcoming' ? 'No upcoming auctions are scheduled yet.' : 'No auctions are open right now. Check Upcoming, or come back soon.'), 'gavel') ?>
<?php else: ?>
  <div class="card-grid">
  <?php foreach ($auctions as $a): $a['id'] = (int) $a['id']; ?>
    <a class="auction-card" href="<?= h(url(auction_link($a))) ?>">
      <div class="thumb">
        <?php if ($a['photo_id']): ?><img src="<?= h(url('file?id=' . $a['photo_id'])) ?>" alt="" loading="lazy"><?php else: ?><?= photo_placeholder() ?><?php endif; ?>
        <?= status_badge($a) ?>
      </div>
      <div class="body">
        <span class="ref"><?= h($a['reference']) ?></span>
        <h3><?= h($a['title']) ?></h3>
        <div class="meta-row">
          <span><?= icon('box') ?><?= h($a['quantity']) ?></span>
          <span><?= icon('pin') ?><?= h($a['location']) ?></span>
        </div>
        <div class="price-row">
          <div>
            <div class="label"><?= $a['bid_count'] ? 'Current bid' : 'Starting price' ?></div>
            <div class="big-amount tabular"><?= h(money($a['top_bid'] ?? $a['starting_price'])) ?></div>
            <div class="small muted"><?= (int) $a['bid_count'] ?> bid<?= $a['bid_count'] == 1 ? '' : 's' ?></div>
          </div>
          <div class="countdown" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div>
        </div>
      </div>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer();
