<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = require_participant();
$tab = get_str('tab', 20);
if (!in_array($tab, ['bids', 'wins', 'profile'], true)) {
    $tab = 'bids';
}

const OFFER_LABELS = [
    'pending_approval' => 'Highest bid — awaiting confirmation',
    'approved'         => 'Won — payment due',
    'paid'             => 'Won — paid',
    'defaulted'        => 'Award withdrawn (not paid)',
];

$titles = ['bids' => 'My bids', 'wins' => 'Won & payments', 'profile' => 'My profile'];
page_header($titles[$tab], 'public', 'account_' . $tab);
?>
<div class="page-head">
  <div><h1><?= h($titles[$tab]) ?></h1>
    <p><?= ['bids' => 'Every auction you have bid on.', 'wins' => 'Auctions you won, with payment details.', 'profile' => 'The details you registered with.'][$tab] ?></p></div>
</div>

<?php if ($tab === 'bids'): ?>
  <?php $bidOn = db_all(
      'SELECT a.*, my.my_max, my.my_bids,
              (SELECT MAX(amount) FROM bids b WHERE b.auction_id = a.id) AS top_bid
       FROM (SELECT auction_id, MAX(amount) AS my_max, COUNT(*) AS my_bids FROM bids WHERE user_id = ? GROUP BY auction_id) my
       JOIN auctions a ON a.id = my.auction_id
       ORDER BY a.closes_at DESC LIMIT 200',
      [$user['id']]
  ); ?>
  <?php if (!$bidOn): ?>
    <?= empty_state("You haven't bid yet.", 'gavel', '<a class="btn-primary" href="' . h(url('auctions')) . '">Browse open auctions</a>') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Auction</th><th>Status</th><th class="num">My highest bid</th><th class="num">Current / final bid</th><th class="num">My bids</th><th>Closes</th></tr></thead>
    <tbody>
    <?php foreach ($bidOn as $a): $a['id'] = (int) $a['id']; $leading = decimal_to_cents($a['my_max']) === decimal_to_cents($a['top_bid']); ?>
      <tr>
        <td class="wide"><a href="<?= h(url(auction_link($a))) ?>"><?= h($a['title']) ?></a><br><span class="small muted"><?= h($a['reference']) ?></span></td>
        <td><?= status_badge($a) ?><?php if (auction_phase($a) === 'live'): ?><br><span class="small <?= $leading ? '' : 'muted' ?>"><?= $leading ? 'You lead' : 'Outbid' ?></span><?php endif; ?></td>
        <td class="num"><?= h(money($a['my_max'])) ?></td>
        <td class="num"><?= h(money($a['top_bid'])) ?></td>
        <td class="num"><?= (int) $a['my_bids'] ?></td>
        <td class="nowrap"><?= h(fmt_dt($a['closes_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>

<?php elseif ($tab === 'wins'): ?>
  <?php $won = db_all(
      'SELECT o.*, a.reference, a.title, a.id AS auction_id, a.status AS auction_status
       FROM winner_offers o JOIN auctions a ON a.id = o.auction_id
       WHERE o.user_id = ? AND o.status IN ("pending_approval", "approved", "paid", "defaulted")
       ORDER BY o.id DESC',
      [$user['id']]
  ); ?>
  <?php if (!$won): ?>
    <?= empty_state('No wins yet. When you win an auction, the payment details appear here.', 'trophy') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Auction</th><th class="num">Amount</th><th>Status</th><th>Pay by</th><th>GePG control number</th><th>Receipt</th></tr></thead>
    <tbody>
    <?php foreach ($won as $o): ?>
      <tr>
        <td class="wide"><a href="<?= h(url('auction?id=' . $o['auction_id'])) ?>"><?= h($o['title']) ?></a><br><span class="small muted"><?= h($o['reference']) ?></span></td>
        <td class="num"><b><?= h(money($o['amount'])) ?></b></td>
        <td><?= h(OFFER_LABELS[$o['status']]) ?><?= $o['status'] === 'approved' && $o['auction_status'] === 'payment_overdue' ? ' <span class="status-pill status-alert">Overdue</span>' : '' ?></td>
        <td class="nowrap"><?= h(fmt_dt($o['payment_due_at'])) ?></td>
        <td class="code"><?= $o['control_number'] ? h($o['control_number']) : ($o['status'] === 'approved' ? '<span class="muted">Will be sent to you</span>' : '—') ?></td>
        <td><?= $o['status'] === 'paid' ? h($o['payment_reference']) . '<br><span class="small muted">' . h(fmt_dt($o['paid_at'])) . '</span>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <p class="small muted">Pay through GePG using the control number <?= h(APP_ORG) ?> sends you, within <?= PAYMENT_WINDOW_HOURS ?> hours of confirmation.</p>

<?php else: ?>
  <?php $profile = db_one('SELECT * FROM participants WHERE user_id = ?', [$user['id']]); ?>
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
  <p class="small muted">To correct these details, contact <?= h(APP_ORG) ?>. <a href="#" data-open="mPassword">Change password</a></p>
<?php endif; ?>
<?php page_footer();
