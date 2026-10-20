<?php
declare(strict_types=1);

/**
 * Shared page chrome. $area is "public" (participants & visitors) or "admin" (staff back office).
 */
function page_header(string $title, string $area = 'public', string $active = ''): void
{
    $user = current_user();
    $unread = $user ? unread_notification_count($user['id']) : 0;
    $flashes = take_flashes();

    $nav = [];
    if ($area === 'admin') {
        $nav['dashboard'] = ['admin/', 'Dashboard'];
        $nav['auctions'] = ['admin/auctions', 'Auctions'];
        if (can('participant.manage')) {
            $nav['participants'] = ['admin/participants', 'Participants'];
        }
        if (can('staff.manage')) {
            $nav['staff'] = ['admin/staff', 'Staff'];
        }
        if (can('reports.view')) {
            $nav['reports'] = ['admin/reports', 'Reports'];
        }
    } else {
        $nav['auctions'] = ['', 'Auctions'];
        $nav['results'] = ['results', 'Results'];
        if (is_participant($user)) {
            $nav['account'] = ['account', 'My account'];
        }
        if (is_staff($user)) {
            $nav['backoffice'] = ['admin/', 'Back office'];
        }
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> · <?= h(APP_ORG . ' ' . APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=Archivo+Expanded:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(url('assets/app.css')) ?>?v=3">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="server-now" content="<?= (int) floor(microtime(true) * 1000) ?>">
</head>
<body class="area-<?= h($area) ?>">
<header class="site-header">
  <div class="header-inner">
    <div class="brand">
      <a class="wordmark" href="<?= h(url($area === 'admin' ? 'admin/' : '')) ?>"><?= h(substr(APP_NAME, 0, 3)) ?><span><?= h(substr(APP_NAME, 3)) ?></span></a>
      <span class="brand-sub"><?= h(APP_ORG) ?> <?= $area === 'admin' ? 'Auction back office' : 'Online Auction' ?></span>
    </div>
    <nav class="main-nav" aria-label="Main">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= h(url($href)) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="session-box">
      <?php if ($user): ?>
        <a class="notif-link<?= $active === 'notifications' ? ' active' : '' ?>" href="<?= h(url('notifications')) ?>" title="Notifications">
          Notifications<?php if ($unread): ?> <span class="notif-count"><?= $unread ?></span><?php endif; ?>
        </a>
        <span class="who"><?= h($user['full_name']) ?><span class="role-tag"><?= h(ROLE_LABELS[$user['role']]) ?></span></span>
        <?php if ($area === 'admin'): ?><a class="small-link" href="<?= h(url('')) ?>">View site</a><?php endif; ?>
        <form method="post" action="<?= h(url('logout')) ?>" class="inline-form"><?= csrf_field() ?><button class="logout-btn" type="submit">Log out</button></form>
      <?php else: ?>
        <a class="btn-secondary btn-sm" href="<?= h(url('login')) ?>">Log in</a>
        <a class="btn-primary btn-sm" href="<?= h(url('register')) ?>">Register to bid</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<main class="page">
<?php foreach ($flashes as $f): ?>
  <div class="flash flash-<?= h($f['type']) ?>" role="status"><?= nl2br(h($f['message'])) ?></div>
<?php endforeach;
}

function page_footer(): void
{
    ?>
</main>
<footer class="site-footer">
  <?= h(APP_ORG) ?> Online Auction · All times are East Africa Time (<?= h(APP_TIMEZONE) ?>) · Amounts in <?= h(CURRENCY) ?>
</footer>
<script src="<?= h(url('assets/app.js')) ?>?v=3"></script>
</body>
</html>
<?php
}

function render_error_page(string $title, string $message, int $status = 403): void
{
    http_response_code($status);
    page_header($title);
    echo '<div class="empty-state"><h1>' . h($title) . '</h1><p>' . h($message) . '</p><p><a href="' . h(url('')) . '">Back to auctions</a></p></div>';
    page_footer();
    exit;
}

/** Coloured status pill with a text label (never colour alone). */
function status_badge(array $a): string
{
    $phase = auction_phase($a);
    $class = [
        'live' => 'live', 'closing' => 'ending', 'upcoming' => 'upcoming',
        'pending_approval' => 'pending', 'pending_winner_approval' => 'pending', 'awaiting_payment' => 'pending',
        'payment_overdue' => 'alert', 'suspended' => 'alert', 'cancelled' => 'alert', 'rejected' => 'alert',
        'completed' => 'done', 'unsold' => 'muted', 'draft' => 'muted',
    ][$phase] ?? 'muted';
    if ($phase === 'live' && from_utc($a['closes_at'])->getTimestamp() - time() < 30 * 60) {
        $class = 'ending';
    }
    return '<span class="status-pill status-' . $class . '">' . h(phase_label($phase)) . '</span>';
}

function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="field-error">' . h($errors[$key]) . '</div>' : '';
}

function old(array $values, string $key, string $default = ''): string
{
    return h($values[$key] ?? $default);
}

/** Paged list helper: returns [limit, offset, page]. */
function paging(int $perPage = 50): array
{
    $page = max(1, get_int('page') ?? 1);
    return [$perPage, ($page - 1) * $perPage, $page];
}

function pager(int $page, bool $hasMore): string
{
    if ($page === 1 && !$hasMore) {
        return '';
    }
    $params = $_GET;
    $html = '<nav class="pager">';
    if ($page > 1) {
        $params['page'] = $page - 1;
        $html .= '<a href="?' . h(http_build_query($params)) . '">← Previous</a>';
    }
    $html .= '<span>Page ' . $page . '</span>';
    if ($hasMore) {
        $params['page'] = $page + 1;
        $html .= '<a href="?' . h(http_build_query($params)) . '">Next →</a>';
    }
    return $html . '</nav>';
}
