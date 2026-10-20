<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(home_for(current_user()));
}

$email = '';
$error = null;
if (is_post()) {
    verify_csrf();
    $email = post_str('email', 190);
    [$user, $error] = check_password_login($email, (string) ($_POST['password'] ?? ''));
    if ($user) {
        if (is_staff($user)) {
            // Q32: staff must pass a second factor before the session is complete.
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user_id'] = $user['id'];
            $_SESSION['pending_2fa_since'] = time();
            $_SESSION['pending_2fa_failures'] = 0;
            redirect('two_factor');
        }
        complete_login($user);
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        if ($next && str_starts_with($next, APP_BASE . '/') && !$user['must_change_password']) {
            header('Location: ' . $next);
            exit;
        }
        redirect(home_for($user));
    }
}

page_header('Log in');
?>
<div class="form-card form-narrow">
  <h1>Log in</h1>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field" style="margin-top:14px">
      <label for="email">Email</label>
      <input id="email" name="email" type="email" value="<?= h($email) ?>" autocomplete="username" autofocus>
    </div>
    <div class="field" style="margin-top:12px">
      <label for="password">Password</label>
      <input id="password" name="password" type="password" autocomplete="current-password">
    </div>
    <?php if ($error): ?><div class="field-error" style="margin-top:10px"><?= h($error) ?></div><?php endif; ?>
    <div class="form-actions">
      <button class="btn-primary" type="submit" style="width:100%">Log in</button>
    </div>
  </form>
  <p class="small muted" style="margin-top:18px;border-top:1px dashed var(--line);padding-top:14px">
    New participant? <a href="<?= h(url('register')) ?>">Register to bid</a>.<br>
    Staff accounts are created by the system administrator and use two-factor authentication.
  </p>
</div>
<?php page_footer();
