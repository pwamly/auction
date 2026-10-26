<?php
declare(strict_types=1);

/** Landing page. The auction catalogue is auctions.php. */
require_once __DIR__ . '/bootstrap.php';
maybe_run_tasks();

$user = current_user();
$liveCount = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status IN ("published","suspended") AND opens_at <= NOW(3)');
$upcomingCount = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status IN ("published","suspended") AND opens_at > NOW(3)');
$closingToday = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status = "published" AND opens_at <= NOW(3) AND closes_at > NOW(3) AND closes_at <= NOW(3) + INTERVAL 24 HOUR');
$resultsCount = (int) db_value('SELECT COUNT(*) FROM auctions WHERE status IN ("awaiting_payment","payment_overdue","completed","unsold")');
$featured = db_all(
    "SELECT a.*,
            (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS top_bid,
            (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.id) AS bid_count,
            (SELECT f.id FROM auction_files f WHERE f.auction_id = a.id AND f.kind = 'photo' ORDER BY f.id LIMIT 1) AS photo_id
     FROM auctions a
     WHERE a.status = 'published' AND a.opens_at <= NOW(3) AND a.closes_at > NOW(3)
     ORDER BY a.closes_at LIMIT 6"
);

$faqs = [
    'How do I register?' =>
        'Click "Register to bid" and choose whether you are an individual or a company. Individuals give their NIDA number; companies give their company name, TIN and BRELA registration number. Registration is free and you can bid straight away.',
    'How do I place a bid?' =>
        'Open an auction, enter an amount at least the minimum shown and click "Place bid". Before your first bid on an auction you accept its terms and conditions. You confirm every bid before it is sent.',
    'Can I change or withdraw a bid?' =>
        'No. Bids are binding and cannot be edited or withdrawn, so check the amount before you confirm.',
    'What happens if someone bids just before closing?' =>
        'A bid in the last ' . ANTI_SNIPING_WINDOW_MIN . ' minutes extends the closing time by ' . ANTI_SNIPING_EXTENSION_MIN . ' minutes, so every bidder has a fair chance to respond.',
    'How do I pay if I win?' =>
        'After ' . APP_ORG . ' confirms the result you receive a GePG control number by email and in your account. Pay within ' . PAYMENT_WINDOW_HOURS . ' hours using any GePG channel (bank or mobile money).',
    'How will I know if I am outbid or win?' =>
        'You are notified by email and under Notifications in your account when you are outbid, when an auction you bid on closes, and when you win.',
];

front_header('Welcome', 'home');
?>
<section class="hero-band">
  <div class="front-wrap hero-inner">
    <span class="hero-kicker"><?= h(APP_ORG) ?> Online Auction</span>
    <h1>Welcome to the <span><?= h(APP_NAME) ?></span> portal</h1>
    <p>Bid online for assets, equipment and commodity lots offered by <?= h(APP_ORG) ?>. Open, timed bidding — the highest bid when the clock runs out wins.</p>
    <div class="hero-actions">
      <a class="btn-primary btn-lg" href="<?= h(url('auctions')) ?>"><?= icon('gavel') ?>Browse auctions</a>
      <?php if (!$user): ?>
        <button class="btn-secondary btn-lg" type="button" data-open="mRegister"><?= icon('user') ?>Register to bid</button>
      <?php elseif (is_participant($user)): ?>
        <a class="btn-secondary btn-lg" href="<?= h(url('account')) ?>"><?= icon('list') ?>My bids</a>
      <?php else: ?>
        <a class="btn-secondary btn-lg" href="<?= h(url('admin/')) ?>"><?= icon('shield') ?>Back office</a>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="front-section">
  <div class="front-wrap">
    <div class="count-grid">
      <a class="count-card" href="<?= h(url('auctions?tab=live')) ?>"><span>Open now</span><b class="tabular"><?= $liveCount ?></b></a>
      <a class="count-card" href="<?= h(url('auctions?tab=live')) ?>"><span>Closing in 24 hours</span><b class="tabular"><?= $closingToday ?></b></a>
      <a class="count-card" href="<?= h(url('auctions?tab=upcoming')) ?>"><span>Upcoming</span><b class="tabular"><?= $upcomingCount ?></b></a>
      <a class="count-card" href="<?= h(url('results')) ?>"><span>Results published</span><b class="tabular"><?= $resultsCount ?></b></a>
    </div>
  </div>
</section>

<section class="front-section">
  <div class="front-wrap">
    <h2 class="front-h2">How to take part</h2>
    <div class="steps">
      <div class="step"><div><b>Create your account</b><span>Register as an individual (NIDA) or a company (TIN &amp; BRELA). Free, no deposit.</span></div></div>
      <div class="step"><div><b>Find an auction</b><span>See photos, documents, location and the current highest bid.</span></div></div>
      <div class="step"><div><b>Place your bid</b><span>Accept the auction's terms, then bid at least the minimum increment above the current bid.</span></div></div>
      <div class="step"><div><b>Win &amp; pay via GePG</b><span>Once <?= h(APP_ORG) ?> confirms the result, pay with your GePG control number within <?= PAYMENT_WINDOW_HOURS ?> hours.</span></div></div>
    </div>
  </div>
</section>

<section class="front-section">
  <div class="front-wrap">
    <div class="front-h2-row"><h2 class="front-h2">Closing soon</h2><a href="<?= h(url('auctions')) ?>">View all auctions →</a></div>
    <?php if (!$featured): ?>
      <?= empty_state('No auctions are open right now. See upcoming auctions or come back soon.', 'gavel', '<a class="btn-secondary" href="' . h(url('auctions?tab=upcoming')) . '">Upcoming auctions</a>') ?>
    <?php else: ?>
      <div class="card-grid">
      <?php foreach ($featured as $a): $a['id'] = (int) $a['id']; ?>
        <a class="auction-card" href="<?= h(url(auction_link($a))) ?>">
          <div class="thumb">
            <?php if ($a['photo_id']): ?><img src="<?= h(url('file?id=' . $a['photo_id'])) ?>" alt="" loading="lazy"><?php else: ?><?= photo_placeholder() ?><?php endif; ?>
            <?= status_badge($a) ?>
          </div>
          <div class="body">
            <span class="ref"><?= h($a['reference']) ?></span>
            <h3><?= h($a['title']) ?></h3>
            <div class="meta-row"><span><?= icon('box') ?><?= h($a['quantity']) ?></span><span><?= icon('pin') ?><?= h($a['location']) ?></span></div>
            <div class="price-row">
              <div>
                <div class="label"><?= $a['bid_count'] ? 'Current bid' : 'Starting price' ?></div>
                <div class="big-amount tabular"><?= h(money($a['top_bid'] ?? $a['starting_price'])) ?></div>
              </div>
              <div class="countdown" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="front-section faq-band">
  <div class="front-wrap">
    <h2 class="front-h2 center">Frequently asked questions</h2>
    <div class="faq">
      <?php $first = true; foreach ($faqs as $q => $answer): ?>
        <details<?= $first ? ' open' : '' ?>><summary><?= h($q) ?></summary><p><?= h($answer) ?></p></details>
      <?php $first = false; endforeach; ?>
    </div>
  </div>
</section>

<section class="front-section" id="contact">
  <div class="front-wrap">
    <h2 class="front-h2 center">Contact us</h2>
    <div class="contact-band">
      <div><span class="contact-icon"><?= icon('phone') ?></span><b>Call us</b><span><?= h(CONTACT_PHONE) ?></span></div>
      <div><span class="contact-icon"><?= icon('mail') ?></span><b>Email us</b><span><?= h(CONTACT_EMAIL) ?></span></div>
      <div><span class="contact-icon"><?= icon('pin') ?></span><b>Our location</b><span><?= h(CONTACT_ADDRESS) ?></span></div>
      <div><span class="contact-icon"><?= icon('clock') ?></span><b>Working hours</b><span><?= h(CONTACT_HOURS) ?></span></div>
    </div>
  </div>
</section>
<?php front_footer();
