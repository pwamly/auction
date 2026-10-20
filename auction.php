<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
maybe_run_tasks();

$user = current_user();
$id = get_int('id');
$a = $id ? load_auction($id) : null;
$preview = $a && !is_public_auction($a) && is_staff($user);
if (!$a || (!is_public_auction($a) && !$preview)) {
    render_error_page('Auction not found', 'This auction does not exist or is not published.', 404);
}

$phase = auction_phase($a);
$summary = auction_bid_summary($a['id']);
$highest = $summary['highest'];
$photos = auction_files($a['id'], 'photo');
$documents = auction_files($a['id'], 'document');
$history = bid_history($a['id'], 50);
$extensions = db_all('SELECT * FROM auction_extensions WHERE auction_id = ? ORDER BY id DESC', [$a['id']]);
$termsAccepted = $user ? (bool) db_value('SELECT 1 FROM terms_acceptances WHERE auction_id = ? AND user_id = ?', [$a['id'], $user['id']]) : false;
$offer = in_array($a['status'], CLOSED_STATUSES, true) ? current_offer($a['id']) : null;
$showNames = $user !== null; // Q14: bidder names for logged-in participants and staff
$youLead = $highest && $user && (int) $highest['user_id'] === $user['id'];

page_header($a['title'], 'public', 'auctions');
?>
<?php if ($preview): ?>
  <div class="flash flash-info">Staff preview — this auction is <b><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?></b> and not visible to the public. <a href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>">Back to back office</a></div>
<?php endif; ?>

<div class="page-head">
  <div>
    <div class="small muted"><?= h($a['reference']) ?><?php if ($a['repeat_of']): ?> · Re-offered item<?php endif; ?></div>
    <h1><?= h($a['title']) ?></h1>
  </div>
  <?= status_badge($a) ?>
</div>

<div class="detail-grid">
  <div>
    <div data-gallery>
      <div class="gallery-main">
        <?php if ($photos): ?><img src="<?= h(file_url($photos[0])) ?>" alt="<?= h($photos[0]['original_name']) ?>"><?php else: ?>No photos provided<?php endif; ?>
      </div>
      <?php if (count($photos) > 1): ?>
        <div class="gallery-thumbs">
          <?php foreach ($photos as $i => $p): ?>
            <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-src="<?= h(file_url($p)) ?>" data-alt="<?= h($p['original_name']) ?>" aria-label="Show photo <?= $i + 1 ?>">
              <img src="<?= h(file_url($p)) ?>" alt="" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <dl class="facts">
      <div><dt>Quantity</dt><dd><?= h($a['quantity']) ?></dd></div>
      <div><dt>Location</dt><dd><?= h($a['location']) ?></dd></div>
      <div><dt>Starting price</dt><dd class="tabular"><?= h(money($a['starting_price'])) ?></dd></div>
      <div><dt>Minimum increment</dt><dd class="tabular"><?= h(money($a['min_increment'])) ?></dd></div>
      <div><dt>Reserve price</dt><dd><?= $a['reserve_price'] === null ? 'None' : 'Yes (not disclosed)' ?></dd></div>
      <div><dt>Opens</dt><dd><?= h(fmt_dt($a['opens_at'])) ?></dd></div>
      <div><dt>Closes</dt><dd><?= h(fmt_dt($a['closes_at'])) ?><?php if ($a['closes_at'] !== $a['original_closes_at']): ?><br><span class="small muted">originally <?= h(fmt_dt($a['original_closes_at'])) ?></span><?php endif; ?></dd></div>
    </dl>

    <div class="section">
      <h2>Description</h2>
      <div class="prose"><?= h($a['description']) ?></div>
    </div>

    <?php if ($documents): ?>
    <div class="section">
      <h2>Supporting documents</h2>
      <ul class="doc-list">
        <?php foreach ($documents as $d): ?>
          <li><a href="<?= h(file_url($d)) ?>"><?= h($d['original_name']) ?></a><span class="muted small"><?= h(human_size((int) $d['size_bytes'])) ?></span></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="section" id="terms">
      <h2>Terms &amp; conditions</h2>
      <div class="prose"><?= h($a['terms']) ?></div>
    </div>

    <div class="section">
      <h2>Bid history</h2>
      <?php if (!$history): ?>
        <p class="muted">No bids yet.</p>
      <?php else: ?>
        <?php if (!$showNames): ?><p class="small muted"><a href="<?= h(url('login')) ?>">Log in</a> to see bidder names.</p><?php endif; ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Time</th><th>Bidder</th><th class="num">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($history as $b): ?>
            <tr>
              <td class="nowrap"><?= h(fmt_dt($b['created_at'], 'd M Y, H:i:s')) ?></td>
              <td><?= $showNames ? h(display_name($b)) . ($user && (int) $b['user_id'] === $user['id'] ? ' <span class="muted">(you)</span>' : '') : 'Bidder' ?></td>
              <td class="num"><?= h(money($b['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($summary['bids'] > count($history)): ?><p class="small muted">Showing the latest <?= count($history) ?> of <?= $summary['bids'] ?> bids.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($extensions): ?>
        <h3 style="margin-top:18px">Closing time changes</h3>
        <ul class="timeline">
          <?php foreach ($extensions as $x): ?>
            <li><span class="when"><?= h(fmt_dt($x['created_at'])) ?></span> — extended to <b><?= h(fmt_dt($x['new_closes_at'])) ?></b> (<?= h($x['kind'] === 'auto' ? 'late bid' : $x['reason']) ?>)</li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <aside class="bid-panel" data-bid-panel data-auction-id="<?= $a['id'] ?>" data-phase="<?= h($phase) ?>">
    <?php if (in_array($phase, ['live', 'upcoming', 'suspended', 'closing'], true)): ?>
      <div class="label" data-current-label><?= $summary['bids'] ? 'Current highest bid' : 'Starting price' ?></div>
      <div class="current tabular" data-current><?= h(money($highest['amount'] ?? $a['starting_price'])) ?></div>
      <div class="small muted">Leader: <span data-leader><?= $highest ? ($showNames ? h(display_name($highest)) . ($youLead ? ' (you)' : '') : 'Log in to see') : 'No bids yet' ?></span></div>
      <div class="stats">
        <span>Bids: <b data-bids><?= $summary['bids'] ?></b></span>
        <span>Bidders: <b data-bidders><?= $summary['bidders'] ?></b></span>
      </div>
      <div class="countdown" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div>
      <?php if ($a['reserve_price'] !== null): ?>
        <div class="notice <?= reserve_met($a, $highest) ? 'good' : '' ?>" data-reserve><?= reserve_met($a, $highest) ? 'Reserve price met' : 'Reserve price not yet met' ?></div>
      <?php endif; ?>

      <?php if ($phase === 'suspended'): ?>
        <div class="notice alert"><b>Bidding is suspended.</b><br><?= h($a['status_reason']) ?></div>
      <?php elseif ($phase === 'closing'): ?>
        <div class="notice">Bidding has closed. The result is being processed.</div>
      <?php elseif (!$user): ?>
        <div class="notice">
          <a class="btn-primary" href="<?= h(url('register')) ?>">Register to bid</a>
          <a class="btn-secondary" href="<?= h(url('login')) ?>">Log in</a>
        </div>
      <?php elseif (!is_participant($user)): ?>
        <div class="notice">Staff accounts cannot place bids.</div>
      <?php elseif ($phase === 'upcoming'): ?>
        <div class="notice">Bidding opens <?= h(fmt_dt($a['opens_at'])) ?>. You'll get a notification when it opens.</div>
      <?php else: ?>
        <div class="notice good" data-you-lead <?= $youLead ? '' : 'hidden' ?>>You are the highest bidder.</div>
        <div style="margin-top:14px">
          <label class="field-label" for="bidAmount">Your bid (minimum <span data-min><?= h(money(cents_to_decimal(min_next_bid_cents($a, $highest)))) ?></span>)</label>
          <div class="bid-form">
            <input class="input" id="bidAmount" name="amount" type="text" inputmode="decimal" autocomplete="off" placeholder="<?= h(money(cents_to_decimal(min_next_bid_cents($a, $highest)))) ?> or more">
            <button class="btn-primary" type="button" data-bid-submit>Place bid</button>
          </div>
          <div data-terms-wrap <?= $termsAccepted ? 'hidden' : '' ?>>
            <label class="checkbox" style="margin-top:10px"><input type="checkbox" name="accept_terms"> <span>I have read and accept the <a href="#terms">terms &amp; conditions</a> of this auction.</span></label>
          </div>
          <div class="bid-msg" data-bid-msg role="status"></div>
          <p class="small muted" style="margin:6px 0 0">Bids are binding and cannot be edited or withdrawn. A bid in the last <?= ANTI_SNIPING_WINDOW_MIN ?> minutes extends closing by <?= ANTI_SNIPING_EXTENSION_MIN ?> minutes.</p>
        </div>
      <?php endif; ?>

    <?php else: /* closed, cancelled, unsold, or preview statuses */ ?>
      <div class="label">Result</div>
      <?php if ($a['status'] === 'cancelled'): ?>
        <div class="current">Cancelled</div>
        <div class="notice alert"><?= h($a['status_reason']) ?></div>
      <?php elseif ($a['status'] === 'unsold'): ?>
        <div class="current">Not sold</div>
        <div class="notice"><?= h(UNSOLD_REASON_LABELS[$a['unsold_reason']] ?? '') ?>. The item will be offered again in a new auction.</div>
      <?php elseif ($a['status'] === 'pending_winner_approval'): ?>
        <div class="current tabular"><?= h(money($highest['amount'] ?? null)) ?></div>
        <div class="notice">Bidding closed. The result is awaiting confirmation by <?= h(APP_ORG) ?>.</div>
      <?php elseif ($offer && in_array($a['status'], ['awaiting_payment', 'payment_overdue', 'completed'], true)): ?>
        <div class="current tabular"><?= h(money($offer['amount'])) ?></div>
        <div class="small">Winner: <b><?= h(display_name($offer)) ?></b></div>
      <?php else: ?>
        <div class="current"><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?></div>
      <?php endif; ?>
      <div class="stats" style="margin-top:10px">
        <span>Bids: <b><?= $summary['bids'] ?></b></span>
        <span>Participants: <b><?= $summary['bidders'] ?></b></span>
      </div>
      <?php if ($user && $offer && (int) $offer['user_id'] === $user['id'] && in_array($offer['status'], ['approved', 'paid'], true)): ?>
        <div class="notice good">You won this auction. See <a href="<?= h(url('account')) ?>">My account</a> for payment details.</div>
      <?php endif; ?>
    <?php endif; ?>
  </aside>
</div>
<?php page_footer();
