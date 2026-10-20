<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = require_participant();
$profile = db_one('SELECT * FROM participants WHERE user_id = ?', [$user['id']]);

$won = db_all(
    'SELECT o.*, a.reference, a.title, a.id AS auction_id, a.status AS auction_status
     FROM winner_offers o JOIN auctions a ON a.id = o.auction_id
     WHERE o.user_id = ? AND o.status IN ("pending_approval", "approved", "paid", "defaulted")
     ORDER BY o.id DESC',
    [$user['id']]
);
$bidOn = db_all(
    'SELECT a.*, my.my_max, my.my_bids,
            (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS top_bid
     FROM (SELECT auction_id, MAX(amount) AS my_max, COUNT(*) AS my_bids FROM bids WHERE user_id = ? GROUP BY auction_id) my
     JOIN auctions a ON a.id = my.auction_id
     ORDER BY a.closes_at DESC LIMIT 100',
    [$user['id']]
);

const OFFER_LABELS = [
    'pending_approval' => 'Highest bid — awaiting confirmation',
    'approved'         => 'Won — payment due',
    'paid'             => 'Won — paid',
    'defaulted'        => 'Award withdrawn (not paid)',
];

page_header('My account', 'public', 'account');
?>
<div class="page-head">
  <div><h1>My account</h1><p><?= h(display_name($profile + $user)) ?></p></div>
  <a class="btn-secondary btn-sm" href="<?= h(url('change_password')) ?>">Change password</a>
</div>

<?php if ($won): ?>
<div class="section" style="margin-top:0">
  <h2>Auctions won</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Auction</th><th class="num">Amount</th><th>Status</th><th>Payment due</th><th>GePG control number</th><th>Receipt</th></tr></thead>
    <tbody>
    <?php foreach ($won as $o): ?>
      <tr>
        <td class="wide"><a href="<?= h(url('auction?id=' . $o['auction_id'])) ?>"><?= h($o['reference'] . ' ' . $o['title']) ?></a></td>
        <td class="num"><b><?= h(money($o['amount'])) ?></b></td>
        <td><?= h(OFFER_LABELS[$o['status']]) ?><?= $o['status'] === 'approved' && $o['auction_status'] === 'payment_overdue' ? ' <span class="status-pill status-alert">Overdue</span>' : '' ?></td>
        <td class="nowrap"><?= h(fmt_dt($o['payment_due_at'])) ?></td>
        <td class="code"><?= $o['control_number'] ? h($o['control_number']) : ($o['status'] === 'approved' ? '<span class="muted">Will be sent to you</span>' : '—') ?></td>
        <td><?= $o['status'] === 'paid' ? h($o['payment_reference']) . '<br><span class="small muted">' . h(fmt_dt($o['paid_at'])) . '</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="small muted">Payment is made through GePG using the control number <?= h(APP_ORG) ?> sends you. You have <?= PAYMENT_WINDOW_HOURS ?> hours from confirmation to pay.</p>
</div>
<?php endif; ?>

<div class="section">
  <h2>My bids</h2>
  <?php if (!$bidOn): ?>
    <p class="muted">You haven't bid yet. <a href="<?= h(url('')) ?>">Browse open auctions</a>.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Auction</th><th>Status</th><th class="num">My highest bid</th><th class="num">Current / final bid</th><th class="num">My bids</th><th>Closes</th></tr></thead>
    <tbody>
    <?php foreach ($bidOn as $a): $a['id'] = (int) $a['id']; $leading = decimal_to_cents($a['my_max']) === decimal_to_cents($a['top_bid']); ?>
      <tr>
        <td class="wide"><a href="<?= h(url(auction_link($a))) ?>"><?= h($a['reference'] . ' ' . $a['title']) ?></a></td>
        <td><?= status_badge($a) ?><?php if (auction_phase($a) === 'live'): ?> <span class="small <?= $leading ? '' : 'muted' ?>"><?= $leading ? 'You lead' : 'Outbid' ?></span><?php endif; ?></td>
        <td class="num"><?= h(money($a['my_max'])) ?></td>
        <td class="num"><?= h(money($a['top_bid'])) ?></td>
        <td class="num"><?= (int) $a['my_bids'] ?></td>
        <td class="nowrap"><?= h(fmt_dt($a['closes_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="section">
  <h2>My details</h2>
  <dl class="facts">
    <div><dt>Participant type</dt><dd><?= h(ucfirst($profile['participant_type'])) ?></dd></div>
    <?php if ($profile['participant_type'] === 'company'): ?>
      <div><dt>Company</dt><dd><?= h($profile['company_name']) ?></dd></div>
      <div><dt>BRELA reg. no.</dt><dd><?= h($profile['brela_reg_no']) ?></dd></div>
    <?php else: ?>
      <div><dt>NIDA number</dt><dd><?= h($profile['nida_number']) ?></dd></div>
    <?php endif; ?>
    <div><dt>TIN</dt><dd><?= h($profile['tin'] ?: '—') ?></dd></div>
    <div><dt><?= $profile['participant_type'] === 'company' ? 'Contact person' : 'Full name' ?></dt><dd><?= h($user['full_name']) ?></dd></div>
    <div><dt>Email</dt><dd><?= h($user['email']) ?></dd></div>
    <div><dt>Phone</dt><dd><?= h($user['phone']) ?></dd></div>
    <div><dt>Address</dt><dd><?= h($profile['address']) ?></dd></div>
  </dl>
  <p class="small muted">To correct these details, contact <?= h(APP_ORG) ?>.</p>
</div>
<?php page_footer();
