<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

page_header('How to bid', 'public', 'help');
?>
<div class="page-head">
  <div><h1>How to bid</h1><p>Taking part in <?= h(APP_ORG) ?> online auctions, step by step.</p></div>
</div>

<div class="steps">
  <div class="step"><div><b>Register</b><span>Individuals register with their NIDA number; companies with their TIN and BRELA registration number. Registration is free and no deposit is required.</span></div></div>
  <div class="step"><div><b>Find an auction</b><span>Browse open and upcoming auctions. Each shows the item, quantity, location, photos, documents and the current highest bid.</span></div></div>
  <div class="step"><div><b>Accept the terms</b><span>Before your first bid on an auction you accept its terms and conditions.</span></div></div>
  <div class="step"><div><b>Bid</b><span>Each bid must beat the current highest bid by at least the minimum increment. Bids are binding and cannot be edited or withdrawn.</span></div></div>
  <div class="step"><div><b>Closing</b><span>The auction closes automatically at its closing time. A bid in the last <?= ANTI_SNIPING_WINDOW_MIN ?> minutes extends closing by <?= ANTI_SNIPING_EXTENSION_MIN ?> minutes.</span></div></div>
  <div class="step"><div><b>Win &amp; pay</b><span>After <?= h(APP_ORG) ?> confirms the result you receive a GePG control number by email and in your account. Pay within <?= PAYMENT_WINDOW_HOURS ?> hours, then collect the item.</span></div></div>
</div>

<div class="section">
  <h2>Good to know</h2>
  <ul>
    <li>All amounts are in Tanzanian Shillings (<?= h(CURRENCY) ?>) and all times are East Africa Time.</li>
    <li>Some auctions have a reserve (minimum) price. If the highest bid is below it, the item is not sold and is offered again later.</li>
    <li>If the highest bidder does not meet the conditions or does not pay in time, the item may be offered to the next highest bidder.</li>
    <li>You are notified by email and in your account when you are outbid, when an auction you bid on closes, and when you win.</li>
  </ul>
  <?php if (!current_user()): ?>
    <p><button class="btn-primary" type="button" data-open="mRegister">Register to bid</button></p>
  <?php endif; ?>
</div>
<?php page_footer();
