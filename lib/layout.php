<?php
declare(strict_types=1);

const ASSET_VERSION = '13';

/** Small inline SVG icons (stroke style, inherit currentColor). */
function icon(string $name): string
{
    $paths = [
        'bell'     => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'menu'     => '<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>',
        'x'        => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
        'box'      => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/>',
        'pin'      => '<path d="M12 21s-7-6.2-7-11a7 7 0 1 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'file'     => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="14 3 14 9 20 9"/>',
        'image'    => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'gavel'    => '<path d="m14 13-7.5 7.5a2.12 2.12 0 0 1-3-3L11 10"/><path d="m16 16 6-6"/><path d="m8 8 6-6"/><path d="m9 7 8 8"/><path d="m21 11-8-8"/>',
        'inbox'    => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.5 5h13l3.5 7v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6z"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'home'     => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'list'     => '<line x1="9" y1="6" x2="20" y2="6"/><line x1="9" y1="12" x2="20" y2="12"/><line x1="9" y1="18" x2="20" y2="18"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>',
        'plus'     => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'check'    => '<polyline points="20 6 9 17 4 12"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/>',
        'trophy'   => '<path d="M8 21h8"/><path d="M12 17v4"/><path d="M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3"/><path d="M7 5H4v2a3 3 0 0 0 3 3"/>',
        'card'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
        'chart'    => '<line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="11" width="3" height="7"/><rect x="11" y="7" width="3" height="11"/><rect x="16" y="13" width="3" height="5"/>',
        'shield'   => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/>',
        'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6V14"/><line x1="12" y1="17" x2="12" y2="17.01"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9"/><path d="m17 6 3 3"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.9 2z"/>',
        'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

function initials(string $name): string
{
    $parts = preg_split('/[\s@._-]+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    $first = mb_substr($parts[0] ?? '?', 0, 1);
    $second = count($parts) > 1 ? mb_substr($parts[1], 0, 1) : '';
    return mb_strtoupper($first . $second);
}

/** The NDC logo (assets/ndc-logo.png, cut from conatins_ndc_logo.png) + portal name. */
function brand_html(string $href): string
{
    return '<a class="brand" href="' . h($href) . '"><img class="brand-logo" src="' . h(url('assets/ndc-logo.png')) . '" alt="' . h(APP_ORG) . ' — National Development Corporation">'
        . '<span class="brand-sub">' . h(APP_NAME) . '</span></a>';
}

/**
 * Sidebar navigation: a list of [group title, [[key, href, label, icon, badge], ...]].
 * $area: "public" (visitors & participants) or "admin" (staff back office).
 */
function sidebar_nav(string $area, ?array $user): array
{
    if ($area === 'admin') {
        $count = static fn(string $where) => (int) db_value("SELECT COUNT(*) FROM auctions WHERE $where");
        $groups = [['', [['dashboard', 'admin/', 'Dashboard', 'home', 0]]]];
        $auctions = [['auctions', 'admin/auctions', 'All auctions', 'list', 0]];
        if (can('auction.create')) {
            $auctions[] = ['new_auction', 'admin/auctions?modal=mAuctionNew', 'New auction', 'plus', 0, 'mAuctionNew'];
        }
        if (can('auction.approve')) {
            $auctions[] = ['pending_approval', 'admin/auctions?status=pending_approval', 'Awaiting approval', 'check', $count('status = "pending_approval"')];
        }
        $auctions[] = ['live', 'admin/auctions?status=live', 'Live now', 'clock', 0];
        if (can('winner.approve')) {
            $auctions[] = ['pending_winner_approval', 'admin/auctions?status=pending_winner_approval', 'Winner approval', 'trophy', $count('status = "pending_winner_approval"')];
        }
        $auctions[] = ['payments', 'admin/auctions?status=payments', 'Payments', 'card', $count('status = "payment_overdue"')];
        $groups[] = ['Auctions', $auctions];
        $people = [];
        if (can('participant.manage')) {
            $people[] = ['participants', 'admin/participants', 'Participants', 'users', 0];
        }
        if (can('staff.manage')) {
            $people[] = ['staff', 'admin/staff', 'Staff accounts', 'shield', 0];
        }
        if ($people) {
            $groups[] = ['People', $people];
        }
        if (can('reports.view')) {
            require_once __DIR__ . '/reports.php';
            $reports = [];
            foreach (REPORTS as $key => [$label, $perm]) {
                if (can($perm)) {
                    $reports[] = ['report_' . $key, 'admin/reports?type=' . $key, $label, 'chart', 0];
                }
            }
            $groups[] = ['Reports', $reports];
        }
        $groups[] = ['', [
            ['notifications', 'notifications', 'Notifications', 'bell', $user ? unread_notification_count($user['id']) : 0],
            ['public_site', '', 'Public site', 'external', 0],
        ]];
        return $groups;
    }

    $groups = [['Auctions', [
        ['home', '', 'Home', 'home', 0],
        ['auctions_live', 'auctions?tab=live', 'Open now', 'gavel', 0],
        ['auctions_upcoming', 'auctions?tab=upcoming', 'Upcoming', 'calendar', 0],
        ['results', 'results', 'Results', 'trophy', 0],
    ]]];
    if (is_participant($user)) {
        $groups[] = ['My account', [
            ['account_bids', 'account?tab=bids', 'My bids', 'list', 0],
            ['account_wins', 'account?tab=wins', 'Won & payments', 'card', 0],
            ['notifications', 'notifications', 'Notifications', 'bell', unread_notification_count($user['id'])],
            ['account_profile', 'account?tab=profile', 'My profile', 'user', 0],
        ]];
    }
    $help = [['help', 'help', 'How to bid', 'help', 0]];
    if (is_staff($user)) {
        $help[] = ['backoffice', 'admin/', 'Back office', 'shield', 0];
    }
    $groups[] = ['', $help];
    return $groups;
}

/** <!DOCTYPE> … </head>, shared by the portal pages and the landing page. */
function page_head(string $title): void
{
    $favicon = url('assets/ndc-favicon.png');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?> · <?= h(APP_NAME) ?></title>
<link rel="icon" href="<?= h($favicon) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(url('assets/app.css')) ?>?v=<?= ASSET_VERSION ?>">
<meta name="csrf-token" content="<?= h(csrf_token()) ?>">
<meta name="server-now" content="<?= (int) floor(microtime(true) * 1000) ?>">
</head>
<?php
}

/** Page chrome: sidebar + top bar. $active is a sidebar key (see sidebar_nav). */
function page_header(string $title, string $area = 'public', string $active = ''): void
{
    $user = current_user();
    $GLOBALS['page_area'] = $area;
    $flashes = take_flashes();
    $groups = sidebar_nav($area, $user);
    $unread = $user ? unread_notification_count($user['id']) : 0;
    ?>
<?php page_head($title); ?>
<body class="area-<?= h($area) ?>">
<a class="sr-only" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-head">
      <?= brand_html(url($area === 'admin' ? 'admin/' : '')) ?>
      <button class="icon-btn sidebar-close" type="button" data-sidebar-close aria-label="Close menu"><?= icon('x') ?></button>
    </div>
    <?php if ($area === 'admin'): ?><div class="area-label">Back office</div><?php endif; ?>
    <nav class="side-nav">
      <?php foreach ($groups as [$groupTitle, $items]): ?>
        <div class="side-group">
          <?php if ($groupTitle !== ''): ?><div class="side-group-title"><?= h($groupTitle) ?></div><?php endif; ?>
          <?php foreach ($items as $item): [$key, $href, $label, $ico, $badge] = $item; $opens = $item[5] ?? null; ?>
            <a href="<?= h(url($href)) ?>" class="side-link<?= $key === $active ? ' active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?><?= $opens ? ' data-open="' . h($opens) . '"' : '' ?>>
              <?= icon($ico) ?><span><?= h($label) ?></span><?php if ($badge): ?><span class="side-badge"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot">
      <?php if ($user): ?>
        <div class="who">
          <span class="avatar" aria-hidden="true"><?= h(initials($user['full_name'])) ?></span>
          <span class="who-text"><b><?= h($user['full_name']) ?></b><span><?= h(ROLE_LABELS[$user['role']]) ?></span></span>
        </div>
        <div class="who-actions">
          <button class="btn-ghost btn-sm" type="button" data-open="mPassword"><?= icon('key') ?>Password</button>
          <form method="post" action="<?= h(url('logout')) ?>" class="inline-form"><?= csrf_field() ?><button class="btn-ghost btn-sm" type="submit"><?= icon('logout') ?>Log out</button></form>
        </div>
      <?php else: ?>
        <button class="btn-primary btn-block" type="button" data-open="mRegister">Register to bid</button>
        <button class="btn-secondary btn-block" type="button" data-open="mLogin">Log in</button>
      <?php endif; ?>
    </div>
  </aside>
  <div class="sidebar-backdrop" data-sidebar-close></div>

  <div class="main-col">
    <header class="topbar">
      <button class="icon-btn menu-toggle" type="button" data-sidebar-open aria-controls="sidebar" aria-label="Open menu"><?= icon('menu') ?></button>
      <span class="topbar-title"><?= h($title) ?></span>
      <div class="topbar-actions">
        <?php if ($user): ?>
          <a class="icon-btn notif-link" href="<?= h(url('notifications')) ?>" aria-label="Notifications<?= $unread ? ", $unread unread" : '' ?>">
            <?= icon('bell') ?><?php if ($unread): ?><span class="notif-dot"><?= $unread > 9 ? '9+' : $unread ?></span><?php endif; ?>
          </a>
          <span class="avatar avatar-sm" title="<?= h($user['full_name']) ?>"><?= h(initials($user['full_name'])) ?></span>
        <?php else: ?>
          <button class="btn-secondary btn-sm" type="button" data-open="mLogin">Log in</button>
        <?php endif; ?>
      </div>
    </header>
    <main class="page" id="main">
<?php foreach ($flashes as $f): ?>
      <div class="flash flash-<?= h($f['type']) ?>" role="status"><div><?= nl2br(h($f['message'])) ?></div><button class="flash-close" type="button" aria-label="Dismiss" data-dismiss>×</button></div>
<?php endforeach;
}

function page_footer(): void
{
    $user = current_user();
    // Pop-up forms available from the sidebar / top bar on every page.
    if (!$user) {
        echo login_modal(), register_modal();
    } elseif (!$user['must_change_password']) {
        echo password_modal();
        if (($GLOBALS['page_area'] ?? '') === 'admin' && can('auction.create') && empty($GLOBALS['skip_new_auction_modal'])) {
            echo auction_form_modal();
        }
    }
    ?>
    </main>
    <footer class="site-footer">
      <span>© <?= date('Y') ?> <?= h(APP_ORG) ?> · <?= h(APP_NAME) ?></span>
    </footer>
  </div>
</div>
<script src="<?= h(url('assets/app.js')) ?>?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
<?php
}

/** Opens a pop-up form (native <dialog>). Pair with modal_end(). Open it with a button: data-open="<id>". */
function modal_start(string $id, string $title, string $intro = '', string $variant = ''): string
{
    return '<dialog class="modal' . ($variant ? ' modal-' . h($variant) : '') . '" id="' . h($id) . '" aria-labelledby="' . h($id) . '-title">'
        . '<div class="modal-head"><h2 id="' . h($id) . '-title">' . h($title) . '</h2>'
        . '<button class="icon-btn" type="button" data-close aria-label="Close">' . icon('x') . '</button></div>'
        . '<div class="modal-body">' . ($intro !== '' ? '<p class="modal-intro">' . $intro . '</p>' : '');
}

function modal_end(): string
{
    return '</div></dialog>';
}

/** Standard footer for a form inside a modal. */
function modal_actions(string $submitLabel, string $submitClass = 'btn-primary'): string
{
    return '<div class="modal-actions"><button class="btn-ghost" type="button" data-close>Cancel</button>'
        . '<button class="' . h($submitClass) . '" type="submit">' . h($submitLabel) . '</button></div>';
}

/** Mandatory reason field (Q29). */
function reason_field(string $label = 'Reason'): string
{
    return '<div class="field"><label>' . h($label) . ' <span class="muted">(required, recorded in the history)</span></label>'
        . '<textarea name="reason" required minlength="5" rows="3"></textarea></div>';
}

function render_error_page(string $title, string $message, int $status = 403): void
{
    http_response_code($status);
    page_header($title);
    echo '<div class="empty-state">' . icon('inbox') . '<h1>' . h($title) . '</h1><p>' . h($message) . '</p><p><a class="btn-secondary" href="' . h(url('auctions')) . '">Back to auctions</a></p></div>';
    page_footer();
    exit;
}

function empty_state(string $message, string $icon = 'inbox', string $actionHtml = ''): string
{
    return '<div class="empty-state">' . icon($icon) . '<div>' . h($message) . '</div>' . ($actionHtml ? '<div style="margin-top:12px">' . $actionHtml . '</div>' : '') . '</div>';
}

function photo_placeholder(string $text = 'No photo'): string
{
    return '<div class="placeholder-art">' . icon('image') . '<span>' . h($text) . '</span></div>';
}

/** Sub-page tabs for a page: [key => label]; links keep other query parameters. */
function page_tabs(array $tabs, string $active, string $param = 'tab'): string
{
    $html = '<nav class="tabs" aria-label="Sections">';
    foreach ($tabs as $key => $label) {
        $params = array_merge($_GET, [$param => $key]);
        unset($params['page']);
        $html .= '<a href="?' . h(http_build_query($params)) . '" class="' . ($key === $active ? 'active' : '') . '"' . ($key === $active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return $html . '</nav>';
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
    $label = phase_label($phase);
    if ($phase === 'live' && from_utc($a['closes_at'])->getTimestamp() - time() < 30 * 60) {
        $class = 'ending';
        $label = 'Ending soon';
    }
    return '<span class="status-pill status-' . $class . '">' . h($label) . '</span>';
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
        $html .= '<a class="btn-secondary btn-sm" href="?' . h(http_build_query($params)) . '">← Previous</a>';
    }
    $html .= '<span class="muted">Page ' . $page . '</span>';
    if ($hasMore) {
        $params['page'] = $page + 1;
        $html .= '<a class="btn-secondary btn-sm" href="?' . h(http_build_query($params)) . '">Next →</a>';
    }
    return $html . '</nav>';
}

/**
 * Landing-page chrome: branded header + top menu (no sidebar), modelled on
 * Tanzanian public portals. The rest of the portal uses page_header().
 */
function front_header(string $title, string $active = 'home'): void
{
    $user = current_user();
    $GLOBALS['page_area'] = 'front';
    $flashes = take_flashes();
    $links = [
        'home'     => ['', 'Home', 'home'],
        'auctions' => ['auctions', 'Auctions', 'gavel'],
        'results'  => ['results', 'Results', 'trophy'],
        'help'     => ['help', 'How to bid', 'help'],
        'contact'  => ['#contact', 'Contact', 'inbox'],
    ];
    page_head($title);
    ?>
<body class="front">
<a class="sr-only" href="#main">Skip to content</a>
<header class="front-brand">
  <div class="front-wrap front-brand-inner">
    <a href="<?= h(url('')) ?>"><img class="front-logo" src="<?= h(url('assets/ndc-emblem.png')) ?>" alt="<?= h(APP_ORG) ?> logo"></a>
    <div class="front-title">
      <span>The United Republic of Tanzania</span>
      <b>National Development Corporation</b>
      <strong><?= h(APP_NAME) ?> — Online Auction Portal</strong>
    </div>
  </div>
</header>
<nav class="front-nav" aria-label="Main">
  <div class="front-wrap front-nav-inner">
    <button class="icon-btn front-menu-btn" type="button" data-front-menu aria-expanded="false" aria-controls="frontLinks" aria-label="Menu"><?= icon('menu') ?></button>
    <div class="front-links" id="frontLinks">
      <?php foreach ($links as $key => [$href, $label, $ico]): ?>
        <a href="<?= h(str_starts_with($href, '#') ? $href : url($href)) ?>" class="<?= $key === $active ? 'active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= icon($ico) ?><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="front-account">
      <?php if (!$user): ?>
        <button class="btn-ghost" type="button" data-open="mLogin"><?= icon('user') ?>Log in</button>
        <button class="btn-primary" type="button" data-open="mRegister">Register to bid</button>
      <?php elseif (is_staff($user)): ?>
        <a class="btn-primary" href="<?= h(url('admin/')) ?>"><?= icon('shield') ?>Back office</a>
      <?php else: ?>
        <a class="btn-primary" href="<?= h(url('account')) ?>"><?= icon('user') ?>My account</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<main id="main" class="front-main">
<?php foreach ($flashes as $f): ?>
  <div class="front-wrap"><div class="flash flash-<?= h($f['type']) ?>" role="status"><div><?= nl2br(h($f['message'])) ?></div><button class="flash-close" type="button" aria-label="Dismiss" data-dismiss>×</button></div></div>
<?php endforeach;
}

function front_footer(): void
{
    $user = current_user();
    if (!$user) {
        echo login_modal(), register_modal();
    } elseif (!$user['must_change_password']) {
        echo password_modal();
    }
    ?>
</main>
<footer class="front-footer">
  <div class="front-wrap front-footer-inner">
    <span>Copyright © <?= date('Y') ?> National Development Corporation (<?= h(APP_ORG) ?>). All rights reserved.</span>
    <span><a href="<?= h(url('help')) ?>">How to bid</a> · <a href="<?= h(url('results')) ?>">Results</a></span>
  </div>
</footer>
<script src="<?= h(url('assets/app.js')) ?>?v=<?= ASSET_VERSION ?>"></script>
</body>
</html>
<?php
}
