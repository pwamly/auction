<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$user = require_login();
$forced = (bool) $user['must_change_password'];
$error = null;

if (is_post()) {
    verify_csrf();
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify($current, $user['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($err = validate_password($new, (string) ($_POST['new_password_confirm'] ?? ''))) {
        $error = $err;
    } elseif (password_verify($new, $user['password_hash'])) {
        $error = 'Choose a password different from the current one.';
    } else {
        db_exec('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        audit('password_changed', $user['email'] . ' changed their password', 'user', $user['id']);
        flash('success', 'Your password has been changed.');
        redirect(is_staff($user) ? 'admin/' : 'account');
    }
}

page_header('Change password', is_staff($user) ? 'admin' : 'public');
?>
<div class="form-card form-narrow">
  <h1>Change password</h1>
  <?php if ($forced): ?><p class="notice">You are using a temporary password. Choose your own password to continue.</p><?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field" style="margin-top:12px"><label for="current_password"><?= $forced ? 'Temporary password' : 'Current password' ?></label><input id="current_password" name="current_password" type="password" autocomplete="current-password"></div>
    <div class="field" style="margin-top:12px"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password"><div class="hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</div></div>
    <div class="field" style="margin-top:12px"><label for="new_password_confirm">Confirm new password</label><input id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password"></div>
    <?php if ($error): ?><div class="field-error" style="margin-top:8px"><?= h($error) ?></div><?php endif; ?>
    <div class="form-actions"><button class="btn-primary" type="submit">Change password</button></div>
  </form>
</div>
<?php page_footer();
