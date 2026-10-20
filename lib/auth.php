<?php
declare(strict_types=1);

const ROLE_LABELS = [
    'participant'     => 'Participant',
    'admin'           => 'Administrator',
    'auction_officer' => 'Auction Officer',
    'manager'         => 'Manager',
    'investment'      => 'Investment Unit',
    'ict'             => 'ICT',
    'pr'              => 'PR',
];

const STAFF_ROLES = ['admin', 'auction_officer', 'manager', 'investment', 'ict', 'pr'];

/**
 * Who may do what on the administration side. Based on Requirements_doc.docx
 * Q2, Q3, Q19, Q28, Q33; Q34 (per-unit responsibilities) was left blank, so the
 * ICT / PR / Investment rows are a proposal to confirm with the client.
 */
const PERMISSIONS = [
    'auction.create'     => ['admin', 'auction_officer', 'manager'],       // Q2
    'auction.approve'    => ['admin'],                                     // Q3
    'auction.control'    => ['admin'],                                     // Q28 suspend / cancel / extend
    'winner.approve'     => ['admin', 'manager', 'investment'],            // Q19
    'payment.record'     => ['admin', 'investment'],
    'participant.manage' => ['admin'],
    'staff.manage'       => ['admin', 'ict'],
    'reports.view'       => ['admin', 'auction_officer', 'manager', 'investment', 'ict', 'pr'], // Q27
    'audit.view'         => ['admin', 'ict', 'manager'],
];

function is_staff(?array $user): bool
{
    return $user !== null && in_array($user['role'], STAFF_ROLES, true);
}

function is_participant(?array $user): bool
{
    return $user !== null && $user['role'] === 'participant';
}

function can(string $permission, ?array $user = null): bool
{
    $user = $user ?? current_user();
    return $user !== null && in_array($user['role'], PERMISSIONS[$permission] ?? [], true);
}

/** Roles that hold a permission, e.g. to notify every winner approver. */
function roles_with(string $permission): array
{
    return PERMISSIONS[$permission] ?? [];
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
        redirect('login');
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
