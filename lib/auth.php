<?php
declare(strict_types=1);

const ROLE_LABELS = [
    'participant'     => 'Participant',
    'admin'           => 'Administrator',
    'auction_officer' => 'Auction Officer',
    'manager'         => 'Manager',
];

const STAFF_ROLES = ['admin', 'auction_officer', 'manager'];

/** Roles whose access the administrator configures (Staff accounts → Access privileges). */
const CONFIGURABLE_ROLES = ['manager', 'auction_officer'];

/** Every permission, with the label shown to the administrator. */
const PERMISSION_LABELS = [
    'auction.create'     => 'Create & edit auctions',
    'auction.approve'    => 'Approve auctions (publish or return)',
    'auction.extend'     => 'Extend closing time',
    'auction.suspend'    => 'Suspend & resume bidding',
    'auction.cancel'     => 'Cancel auctions',
    'winner.approve'     => 'Confirm or reject winners',
    'payment.record'     => 'Record payments (GePG control number & receipt)',
    'payment.decide'     => 'Decide on overdue payments',
    'participant.manage' => 'Block & reactivate participants',
    'reports.view'       => 'View reports',
    'audit.view'         => 'View history',
];

/** Only the administrator may manage staff accounts and access privileges; never assignable. */
const ADMIN_ONLY_PERMISSIONS = ['staff.manage'];

/** Starting privileges, applied by setup.php when the role_permissions table is empty. */
const DEFAULT_ROLE_PERMISSIONS = [
    'manager'         => ['auction.create', 'winner.approve', 'payment.record', 'payment.decide', 'reports.view', 'audit.view'],
    'auction_officer' => ['auction.create', 'reports.view'],
];

function is_staff(?array $user): bool
{
    return $user !== null && in_array($user['role'], STAFF_ROLES, true);
}

function is_participant(?array $user): bool
{
    return $user !== null && $user['role'] === 'participant';
}

/** role => [permission, ...] as granted by the administrator (cached per request). */
function role_permissions(bool $refresh = false): array
{
    static $map = null;
    if ($map === null || $refresh) {
        $map = array_fill_keys(CONFIGURABLE_ROLES, []);
        foreach (db_all('SELECT role, permission FROM role_permissions') as $row) {
            $map[$row['role']][] = $row['permission'];
        }
    }
    return $map;
}

function can(string $permission, ?array $user = null): bool
{
    $user = $user ?? current_user();
    if ($user === null || !is_staff($user)) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    return !in_array($permission, ADMIN_ONLY_PERMISSIONS, true)
        && in_array($permission, role_permissions()[$user['role']] ?? [], true);
}

/** Roles that hold a permission, e.g. to notify every winner approver. */
function roles_with(string $permission): array
{
    $roles = ['admin'];
    if (!in_array($permission, ADMIN_ONLY_PERMISSIONS, true)) {
        foreach (role_permissions() as $role => $perms) {
            if (in_array($permission, $perms, true)) {
                $roles[] = $role;
            }
        }
    }
    return $roles;
}

/** The logged-in user, re-read from the database on every request so blocks and role changes apply at once. */
function current_user(): ?array
{
    static $cache = null;
    static $loaded = false;
    if ($loaded) {
        return $cache;
    }
    $loaded = true;
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }
    $user = db_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user || $user['status'] !== 'active') {
        unset($_SESSION['user_id']);
        return null;
    }
    $user['id'] = (int) $user['id'];
    return $cache = $user;
}

function display_name(array $row): string
{
    if (($row['participant_type'] ?? null) === 'company' && !empty($row['company_name'])) {
        return $row['company_name'];
    }
    return $row['full_name'] ?? '';
}

// ---------------------------------------------------------------- page guards

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        flash('info', 'Please log in to continue.');
        redirect('?modal=mLogin');
    }
    if ($user['must_change_password'] && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        redirect('change_password');
    }
    return $user;
}

function require_participant(): array
{
    $user = require_login();
    if (!is_participant($user)) {
        redirect(is_staff($user) ? 'admin/' : '');
    }
    return $user;
}

function require_staff(?string $permission = null): array
{
    $user = require_login();
    if (!is_staff($user)) {
        http_response_code(403);
        exit('Staff access only.');
    }
    if ($permission !== null && !can($permission, $user)) {
        http_response_code(403);
        render_error_page('Not allowed', 'Your role (' . ROLE_LABELS[$user['role']] . ') does not have access to this page.');
    }
    return $user;
}

// ---------------------------------------------------------------- login

function login_locked_out(string $email): bool
{
    $since = utc_string(utc_now()->modify('-' . LOGIN_LOCKOUT_MINUTES . ' minutes'));
    $byEmail = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at >= ?', [$email, $since]);
    $byIp = (int) db_value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= ?', [client_ip(), $since]);
    return $byEmail >= LOGIN_MAX_FAILURES || $byIp >= LOGIN_MAX_FAILURES * 4;
}

/**
 * Checks email + password. Returns [user, null] or [null, error message].
 * Staff still need a second factor before the session is complete.
 */
function check_password_login(string $email, string $password): array
{
    $email = strtolower(trim($email));
    if ($email === '' || $password === '') {
        return [null, 'Enter your email and password.'];
    }
    if (login_locked_out($email)) {
        return [null, 'Too many failed attempts. Try again in ' . LOGIN_LOCKOUT_MINUTES . ' minutes.'];
    }
    $user = db_one('SELECT * FROM users WHERE email = ?', [$email]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        db_exec('INSERT INTO login_attempts (email, ip, created_at) VALUES (?, ?, ?)', [$email, client_ip(), utc_string(utc_now())]);
        audit('login_failed', 'Failed login for ' . $email, 'user', $user ? (int) $user['id'] : null, null, $user ? (int) $user['id'] : 0);
        return [null, 'Incorrect email or password.'];
    }
    if ($user['status'] !== 'active') {
        return [null, 'This account has been blocked. Contact ' . APP_ORG . ' for help.'];
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db_exec('DELETE FROM login_attempts WHERE email = ?', [$email]);
    $user['id'] = (int) $user['id'];
    return [$user, null];
}

function complete_login(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa_user_id']);
    $_SESSION['user_id'] = $user['id'];
    db_exec('UPDATE users SET last_login_at = ? WHERE id = ?', [utc_string(utc_now()), $user['id']]);
    audit('login', $user['email'] . ' logged in', 'user', $user['id'], null, $user['id']);
}

function home_for(array $user): string
{
    if ($user['must_change_password']) {
        return 'change_password';
    }
    return is_staff($user) ? 'admin/' : '';
}

function logout(): void
{
    $user = current_user();
    if ($user) {
        audit('logout', $user['email'] . ' logged out', 'user', $user['id']);
    }
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function validate_password(string $password, string $confirm): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if ($password !== $confirm) {
        return 'Passwords do not match.';
    }
    return null;
}
