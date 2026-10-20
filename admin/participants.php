<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_staff('participant.manage');

if (is_post()) {
    verify_csrf();
    $pid = post_int('user_id') ?? 0;
    $reason = post_str('reason', 500);
    $p = db_one('SELECT u.*, pa.company_name, pa.participant_type FROM users u JOIN participants pa ON pa.user_id = u.id WHERE u.id = ?', [$pid]);
    $block = post_str('action', 20) === 'block';
    if (!$p) {
        flash('error', 'Participant not found.');
    } elseif (mb_strlen($reason) < 5) {
        flash('error', 'A reason is required (at least 5 characters).');
    } elseif ($block === ($p['status'] === 'blocked')) {
        flash('error', 'Nothing to change.');
    } else {
        db_exec('UPDATE users SET status = ?, status_reason = ? WHERE id = ?', [$block ? 'blocked' : 'active', $reason, $pid]);
        audit($block ? 'participant_blocked' : 'participant_unblocked', ($block ? 'Blocked ' : 'Unblocked ') . display_name($p) . " <{$p['email']}>", 'user', $pid, $reason);
        // Blocked users can't log in, so a block is sent by email only.
        if ($block) {
            queue_email($p['email'], $p['full_name'], 'Your account has been suspended',
                email_body($p['full_name'], "Your participant account has been suspended and you can no longer bid.\n\nReason: $reason\n\nContact " . APP_ORG . ' if you believe this is a mistake.', null));
        } else {
            notify_user($pid, 'participant_approved', 'Your account has been reactivated',
                "Your participant account is active again and you can bid on open auctions.\n\nNote: $reason", '');
        }
        flash('success', $block ? 'Participant blocked.' : 'Participant reactivated.');
    }
    redirect('admin/participants?id=' . $pid);
}

$id = get_int('id');
if ($id) {
    $p = db_one('SELECT u.*, pa.* FROM users u JOIN participants pa ON pa.user_id = u.id WHERE u.id = ?', [$id]);
    if (!$p) {
        render_error_page('Not found', 'Participant not found.', 404);
    }
    $bids = db_all(
        'SELECT a.id, a.reference, a.title, a.status, a.opens_at, a.closes_at, MAX(b.amount) AS my_max, COUNT(*) AS n
         FROM bids b JOIN auctions a ON a.id = b.auction_id WHERE b.user_id = ? GROUP BY a.id ORDER BY a.closes_at DESC',
        [$id]
    );
    $offers = db_all('SELECT o.*, a.reference, a.title FROM winner_offers o JOIN auctions a ON a.id = o.auction_id WHERE o.user_id = ? ORDER BY o.id DESC', [$id]);

    page_header(display_name($p), 'admin', 'participants');
    ?>
    <div class="page-head">
      <div><div class="small muted"><a href="<?= h(url('admin/participants')) ?>">← Participants</a></div><h1><?= h(display_name($p)) ?></h1>
        <p><?= h(ucfirst($p['participant_type'])) ?> · registered <?= h(fmt_dt($p['created_at'])) ?> · <span class="status-pill <?= $p['status'] === 'active' ? 'status-live' : 'status-alert' ?>"><?= h(ucfirst($p['status'])) ?></span></p></div>
    </div>
    <dl class="facts">
      <?php if ($p['participant_type'] === 'company'): ?>
        <div><dt>Company</dt><dd><?= h($p['company_name']) ?></dd></div>
        <div><dt>BRELA reg. no.</dt><dd><?= h($p['brela_reg_no']) ?></dd></div>
        <div><dt>Contact person</dt><dd><?= h($p['full_name']) ?></dd></div>
      <?php else: ?>
        <div><dt>Full name</dt><dd><?= h($p['full_name']) ?></dd></div>
        <div><dt>NIDA number</dt><dd class="code"><?= h($p['nida_number']) ?></dd></div>
      <?php endif; ?>
      <div><dt>TIN</dt><dd class="code"><?= h($p['tin'] ?: '—') ?></dd></div>
      <div><dt>Email</dt><dd><?= h($p['email']) ?></dd></div>
      <div><dt>Phone</dt><dd><?= h($p['phone']) ?></dd></div>
      <div><dt>Address</dt><dd><?= h($p['address']) ?></dd></div>
      <div><dt>Last login</dt><dd><?= h(fmt_dt($p['last_login_at'])) ?></dd></div>
    </dl>
    <?php if ($p['status_reason']): ?><p class="small muted">Last status change: <?= h($p['status_reason']) ?></p><?php endif; ?>

    <details class="action" style="max-width:520px">
      <summary><?= $p['status'] === 'active' ? 'Block participant' : 'Reactivate participant' ?></summary>
      <form method="post"><?= csrf_field() ?>
        <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>"><input type="hidden" name="action" value="<?= $p['status'] === 'active' ? 'block' : 'unblock' ?>">
        <div class="field"><label>Reason (recorded in the audit trail)</label><textarea name="reason" required minlength="5" style="min-height:70px"></textarea></div>
        <button class="<?= $p['status'] === 'active' ? 'btn-danger' : 'btn-primary' ?>"><?= $p['status'] === 'active' ? 'Block' : 'Reactivate' ?></button>
      </form>
    </details>

    <div class="section"><h2>Auctions bid on</h2>
      <?php if (!$bids): ?><p class="muted">No bids.</p><?php else: ?>
      <div class="table-wrap"><table class="data"><thead><tr><th>Auction</th><th>Status</th><th class="num">Highest bid</th><th class="num">Bids</th><th>Closes</th></tr></thead><tbody>
        <?php foreach ($bids as $b): ?><tr><td class="wide"><a href="<?= h(url('admin/auction_view?id=' . $b['id'])) ?>"><?= h($b['reference'] . ' ' . $b['title']) ?></a></td>
          <td><?= h(AUCTION_STATUS_LABELS[$b['status']]) ?></td><td class="num"><?= h(money($b['my_max'])) ?></td><td class="num"><?= (int) $b['n'] ?></td><td class="nowrap"><?= h(fmt_dt($b['closes_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <?php if ($offers): ?>
    <div class="section"><h2>Wins &amp; offers</h2>
      <div class="table-wrap"><table class="data"><thead><tr><th>Auction</th><th class="num">Amount</th><th>Status</th><th>Paid</th></tr></thead><tbody>
        <?php foreach ($offers as $o): ?><tr><td><?= h($o['reference'] . ' ' . $o['title']) ?></td><td class="num"><?= h(money($o['amount'])) ?></td><td><?= h(ucfirst(str_replace('_', ' ', $o['status']))) ?></td><td><?= h(fmt_dt($o['paid_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
    <?php endif; ?>
    <?php
    page_footer();
    exit;
}

$q = get_str('q');
$type = get_str('type', 20);
$where = ['1'];
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '\\%_') . '%';
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR pa.company_name LIKE ? OR pa.nida_number LIKE ? OR pa.tin LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if (in_array($type, ['individual', 'company'], true)) {
    $where[] = 'pa.participant_type = ?';
    $params[] = $type;
}
[$limit, $offset, $page] = paging(50);
$rows = db_all(
    'SELECT u.id, u.full_name, u.email, u.phone, u.status, u.created_at, pa.participant_type, pa.company_name,
            (SELECT COUNT(*) FROM bids b WHERE b.user_id = u.id) AS bid_count
     FROM users u JOIN participants pa ON pa.user_id = u.id
     WHERE ' . implode(' AND ', $where) . ' ORDER BY u.id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
    $params
);
$hasMore = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);

page_header('Participants', 'admin', 'participants');
?>
<div class="page-head"><div><h1>Participants</h1><p>Registered individuals and companies.</p></div></div>
<form class="filters" method="get">
  <div class="field"><label for="type">Type</label><select id="type" name="type"><option value="">All</option>
    <option value="individual" <?= $type === 'individual' ? 'selected' : '' ?>>Individuals</option><option value="company" <?= $type === 'company' ? 'selected' : '' ?>>Companies</option></select></div>
  <div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= h($q) ?>" placeholder="Name, company, email, phone, NIDA or TIN"></div>
  <button class="btn-secondary">Filter</button>
</form>
<?php if (!$rows): ?><div class="empty-state">No participants found.</div><?php else: ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Name</th><th>Type</th><th>Email</th><th>Phone</th><th>Registered</th><th class="num">Bids</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td class="wide"><a href="<?= h(url('admin/participants?id=' . $r['id'])) ?>"><?= h(display_name($r)) ?></a><?= $r['participant_type'] === 'company' ? '<br><span class="small muted">' . h($r['full_name']) . '</span>' : '' ?></td>
      <td><?= h(ucfirst($r['participant_type'])) ?></td><td><?= h($r['email']) ?></td><td class="nowrap"><?= h($r['phone']) ?></td>
      <td class="nowrap"><?= h(fmt_dt($r['created_at'], 'd M Y')) ?></td><td class="num"><?= (int) $r['bid_count'] ?></td>
      <td><span class="status-pill <?= $r['status'] === 'active' ? 'status-live' : 'status-alert' ?>"><?= h(ucfirst($r['status'])) ?></span></td></tr>
  <?php endforeach; ?></tbody>
</table></div>
<?= pager($page, $hasMore) ?>
<?php endif; ?>
<?php page_footer();
