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
$termsAccepted = $user ? (bool) db_value('SELECT 1 FROM terms_acceptances WHERE auction_id = ? AND user_id = ?', [$a['id'], $user['id']]) : false;
$offer = in_array($a['status'], CLOSED_STATUSES, true) ? current_offer($a['id']) : null;
$showNames = $user !== null; // bidder names for logged-in participants and staff
$youLead = $highest && $user && (int) $highest['user_id'] === $user['id'];
$minNext = min_next_bid_cents($a, $highest);

$tabs = ['details' => 'Details', 'documents' => 'Documents <span class="tab-count">' . count($documents) . '</span>',
         'terms' => 'Terms &amp; conditions', 'bids' => 'Bid history <span class="tab-count">' . $summary['bids'] . '</span>'];
$tab = get_str('tab', 20);
if (!isset($tabs[$tab])) {
    $tab = 'details';
}

page_header($a['title'], 'public', in_array($phase, ['live', 'upcoming', 'suspended', 'closing'], true) ? ($phase === 'upcoming' ? 'auctions_upcoming' : 'auctions_live') : 'results');
?>
<?php if ($preview): ?>
  <div class="flash flash-info"><div>Staff preview — this auction is <b><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?></b> and not visible to the public. <a href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>">Back to back office</a></div></div>
<?php endif; ?>

<div class="page-head">
  <div>
    <div class="crumb"><a href="<?= h(url('auctions')) ?>">Auctions</a><span>/</span><?= h($a['reference']) ?></div>
    <h1><?= h($a['title']) ?></h1>
    <div class="meta-row" style="margin-top:6px">
      <span><?= icon('box') ?><?= h($a['quantity']) ?></span>
      <span><?= icon('pin') ?><?= h($a['location']) ?></span>
      <?= status_badge($a) ?>
    </div>
  </div>
</div>

<div class="detail-grid">
  <div>
    <?= page_tabs($tabs, $tab) ?>

    <?php if ($tab === 'details'): ?>
      <div data-gallery style="margin-bottom:16px">
        <div class="gallery-main<?= $photos ? '' : ' empty' ?>">
          <?php if ($photos): ?><img src="<?= h(file_url($photos[0])) ?>" alt="<?= h($photos[0]['original_name']) ?>"><?php else: ?><?= photo_placeholder('No photos provided') ?><?php endif; ?>
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
        <div><dt>Starting price</dt><dd class="tabular"><?= h(money($a['starting_price'])) ?></dd></div>
        <div><dt>Minimum increment</dt><dd class="tabular"><?= h(money($a['min_increment'])) ?></dd></div>
        <div><dt>Reserve price</dt><dd><?= $a['reserve_price'] === null ? 'None' : 'Yes (not disclosed)' ?></dd></div>
        <div><dt>Opens</dt><dd><?= h(fmt_dt($a['opens_at'])) ?></dd></div>
        <div><dt>Closes</dt><dd><?= h(fmt_dt($a['closes_at'])) ?><?php if ($a['closes_at'] !== $a['original_closes_at']): ?><br><span class="small muted">originally <?= h(fmt_dt($a['original_closes_at'])) ?></span><?php endif; ?></dd></div>
        <div><dt>Payment</dt><dd>GePG, within <?= PAYMENT_WINDOW_HOURS ?> h</dd></div>
      </dl>
      <div class="section">
        <h2>Description</h2>
        <div class="prose"><?= h($a['description']) ?></div>
      </div>

    <?php elseif ($tab === 'documents'): ?>
      <?php if (!$documents): ?>
        <?= empty_state('No supporting documents were provided for this auction.', 'file') ?>
      <?php else: ?>
        <ul class="doc-list">
          <?php foreach ($documents as $d): ?>
            <li><a href="<?= h(file_url($d)) ?>"><?= icon('file') ?><?= h($d['original_name']) ?></a><span class="muted small"><?= h(human_size((int) $d['size_bytes'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php elseif ($tab === 'terms'): ?>
      <div class="prose"><?= h($a['terms']) ?></div>
      <?php if ($termsAccepted): ?><p class="notice good">✓ You have accepted these terms.</p><?php endif; ?>

    <?php else: /* bids */ ?>
      <?php $history = bid_history($a['id'], 100); $extensions = db_all('SELECT * FROM auction_extensions WHERE auction_id = ? ORDER BY id DESC', [$a['id']]); ?>
      <?php if (!$history): ?>
        <?= empty_state('No bids yet.', 'gavel') ?>
      <?php else: ?>
        <?php if (!$showNames): ?><p class="small muted"><a href="#" data-open="mLogin">Log in</a> to see bidder names.</p><?php endif; ?>
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Time</th><th>Bidder</th><th class="num">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($history as $i => $b): ?>
            <tr>
              <td class="nowrap"><?= h(fmt_dt($b['created_at'], 'd M Y, H:i:s')) ?></td>
              <td><?= $showNames ? h(display_name($b)) . ($user && (int) $b['user_id'] === $user['id'] ? ' <span class="muted">(you)</span>' : '') : 'Bidder' ?><?= $i === 0 ? ' <span class="status-pill status-live">Highest</span>' : '' ?></td>
              <td class="num"><?= $i === 0 ? '<b>' . h(money($b['amount'])) . '</b>' : h(money($b['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($summary['bids'] > count($history)): ?><p class="small muted">Showing the latest <?= count($history) ?> of <?= $summary['bids'] ?> bids.</p><?php endif; ?>
      <?php endif; ?>
      <?php if ($extensions): ?>
        <div class="section">
          <h2>Closing time changes</h2>
          <ul class="timeline">
            <?php foreach ($extensions as $x): ?>
              <li><span class="when"><?= h(fmt_dt($x['created_at'])) ?></span><br>Extended to <b><?= h(fmt_dt($x['new_closes_at'])) ?></b> (<?= h($x['kind'] === 'auto' ? 'late bid' : $x['reason']) ?>)</li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <aside class="bid-panel" data-bid-panel data-auction-id="<?= $a['id'] ?>" data-phase="<?= h($phase) ?>">
    <?php if (in_array($phase, ['live', 'upcoming', 'suspended', 'closing'], true)): ?>
      <div class="label" data-current-label><?= $summary['bids'] ? 'Current highest bid' : 'Starting price' ?></div>
      <div class="current tabular" data-current><?= h(money($highest['amount'] ?? $a['starting_price'])) ?></div>
      <div class="leader">Leader: <span data-leader><?= $highest ? ($showNames ? h(display_name($highest)) . ($youLead ? ' (you)' : '') : 'log in to see') : 'no bids yet' ?></span></div>
      <div class="stats">
        <div><b data-bids class="tabular"><?= $summary['bids'] ?></b><span>Bids</span></div>
        <div><b data-bidders class="tabular"><?= $summary['bidders'] ?></b><span>Bidders</span></div>
      </div>
      <div class="clock"><?= icon('clock') ?><div class="countdown" data-countdown data-opens="<?= to_ms($a['opens_at']) ?>" data-closes="<?= to_ms($a['closes_at']) ?>"></div></div>
      <?php if ($a['reserve_price'] !== null): ?>
        <div class="notice <?= reserve_met($a, $highest) ? 'good' : '' ?>" data-reserve><?= reserve_met($a, $highest) ? 'Reserve price met' : 'Reserve price not yet met' ?></div>
      <?php endif; ?>

      <?php if ($phase === 'suspended'): ?>
        <div class="notice alert"><b>Bidding is suspended.</b><br><?= h($a['status_reason']) ?></div>
      <?php elseif ($phase === 'closing'): ?>
        <div class="notice">Bidding has closed. The result is being processed.</div>
      <?php elseif (!$user): ?>
        <div class="notice">
          <div style="margin-bottom:6px">Register or log in to bid.</div>
          <button class="btn-primary" type="button" data-open="mRegister">Register to bid</button>
          <button class="btn-secondary" type="button" data-open="mLogin">Log in</button>
        </div>
      <?php elseif (!is_participant($user)): ?>
        <div class="notice">Staff accounts cannot place bids.</div>
      <?php elseif ($phase === 'upcoming'): ?>
        <div class="notice">Bidding opens <?= h(fmt_dt($a['opens_at'])) ?>. You'll get a notification when it opens.</div>
      <?php else: ?>
        <div class="notice good" data-you-lead <?= $youLead ? '' : 'hidden' ?>>✓ You are the highest bidder.</div>
        <div style="margin-top:16px">
          <label class="field-label" for="bidAmount">Your bid · minimum <span data-min><?= h(money(cents_to_decimal($minNext))) ?></span></label>
          <div class="input-group">
            <span class="prefix"><?= h(CURRENCY) ?></span>
            <input id="bidAmount" name="amount" type="text" inputmode="numeric" autocomplete="off" placeholder="<?= h(number_format($minNext / 100)) ?>" data-min-value="<?= $minNext / 100 ?>">
          </div>
          <button class="btn-primary btn-lg btn-block" type="button" data-bid-submit style="margin-top:12px">Place bid</button>
          <div class="bid-msg" data-bid-msg role="status" aria-live="polite"></div>
          <p class="small muted" style="margin:4px 0 0">A bid in the last <?= ANTI_SNIPING_WINDOW_MIN ?> minutes extends closing by <?= ANTI_SNIPING_EXTENSION_MIN ?> minutes.</p>
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
        <div class="leader">Winner: <b><?= h(display_name($offer)) ?></b></div>
      <?php else: ?>
        <div class="current"><?= h(AUCTION_STATUS_LABELS[$a['status']]) ?></div>
      <?php endif; ?>
      <div class="stats">
        <div><b class="tabular"><?= $summary['bids'] ?></b><span>Bids</span></div>
        <div><b class="tabular"><?= $summary['bidders'] ?></b><span>Participants</span></div>
      </div>
      <?php if ($user && $offer && (int) $offer['user_id'] === $user['id'] && in_array($offer['status'], ['approved', 'paid'], true)): ?>
        <div class="notice good">You won this auction. See <a href="<?= h(url('account?tab=wins')) ?>">Won &amp; payments</a> for payment details.</div>
      <?php endif; ?>
    <?php endif; ?>
  </aside>
</div>

<?php if ($user && is_participant($user) && $phase === 'live'): ?>
  <?= modal_start('bidConfirm', 'Confirm your bid', 'Bids are binding and cannot be edited or withdrawn.') ?>
    <div class="label">You are bidding</div>
    <div class="confirm-amount tabular" data-confirm-amount></div>
    <div class="small muted"><?= h($a['reference'] . ' · ' . $a['title']) ?></div>
    <div data-terms-wrap <?= $termsAccepted ? 'hidden' : '' ?> style="margin-top:14px">
      <div class="field-label">Terms &amp; conditions of this auction</div>
      <div class="terms-box"><?= h($a['terms']) ?></div>
      <label class="checkbox"><input type="checkbox" name="accept_terms"> <span>I have read and accept these terms and conditions.</span></label>
    </div>
    <div class="field-error" data-dialog-error></div>
    <div class="modal-actions">
      <button class="btn-ghost" type="button" data-close>Cancel</button>
      <button class="btn-primary" type="button" data-bid-confirm>Confirm bid</button>
    </div>
  <?= modal_end() ?>
<?php endif; ?>
<?php page_footer();
