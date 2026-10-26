<?php
declare(strict_types=1);

/** Q21: website / user-account notifications (the same messages are also emailed). */
require_once __DIR__ . '/bootstrap.php';

$user = require_login();

if (is_post()) {
    verify_csrf();
    db_exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [utc_string(utc_now()), $user['id']]);
    redirect('notifications');
}

[$limit, $offset, $page] = paging(30);
$items = db_all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset, [$user['id']]);
$hasMore = count($items) > $limit;
$items = array_slice($items, 0, $limit);
$unread = unread_notification_count($user['id']);

page_header('Notifications', is_staff($user) ? 'admin' : 'public', 'notifications');
// Opening the page marks what is shown as read; the highlight below reflects the state before.
if ($items) {
    db_exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id >= ?', [utc_string(utc_now()), $user['id'], end($items)['id']]);
}
?>
<div class="page-head">
  <div><h1>Notifications</h1><p><?= $unread ?> unread</p></div>
  <?php if ($unread): ?>
    <form method="post"><?= csrf_field() ?><button class="btn-secondary btn-sm" type="submit">Mark all as read</button></form>
  <?php endif; ?>
</div>
<?php if (!$items): ?>
  <?= empty_state('No notifications yet. You will be notified here and by email about your bids and auctions.', 'bell') ?>
<?php else: ?>
  <div class="notif-list">
  <?php foreach ($items as $n): ?>
    <div class="notif-item <?= $n['read_at'] ? 'read' : 'unread' ?>">
      <span class="dot" aria-hidden="true"></span>
      <div>
        <div class="notif-title"><?= $n['link'] !== null && $n['link'] !== '' ? '<a href="' . h(url($n['link'])) . '">' . h($n['title']) . '</a>' : h($n['title']) ?></div>
        <div class="prose small"><?= h($n['body']) ?></div>
        <div class="small muted"><?= h(fmt_dt($n['created_at'])) ?><?= $n['read_at'] ? '' : ' · new' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <?= pager($page, $hasMore) ?>
<?php endif; ?>
<?php page_footer();
