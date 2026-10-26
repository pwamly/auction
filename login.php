<?php
declare(strict_types=1);

/** Handles the log-in pop-up (lib/modals.php). Staff continue to the two-factor step. */
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(home_for(current_user()));
}
if (!is_post()) {
    redirect('?modal=mLogin');
}

verify_csrf();
$email = post_str('email', 190);
[$user, $error] = check_password_login($email, (string) ($_POST['password'] ?? ''));
if (!$user) {
    stash_form('login', ['email' => $email], ['_form' => $error]);
    back_to_modal('mLogin');
}
if (is_staff($user)) {
    // Q32: staff must pass a second factor before the session is complete.
    session_regenerate_id(true);
    $_SESSION['pending_2fa_user_id'] = $user['id'];
    $_SESSION['pending_2fa_since'] = time();
    $_SESSION['pending_2fa_failures'] = 0;
    redirect('two_factor');
}
complete_login($user);
if ($user['must_change_password']) {
    redirect('change_password');
}
$next = $_SESSION['after_login'] ?? null;
unset($_SESSION['after_login']);
header('Location: ' . ($next && str_starts_with($next, APP_BASE . '/') ? $next : return_url()));
exit;
