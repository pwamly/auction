<?php
declare(strict_types=1);

/** Q32: second login step for staff (authenticator app, TOTP). First login sets it up. */
require_once __DIR__ . '/bootstrap.php';

$pendingId = $_SESSION['pending_2fa_user_id'] ?? null;
if (!$pendingId || time() - ($_SESSION['pending_2fa_since'] ?? 0) > 600) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['totp_setup_secret']);
    flash('info', 'Please log in again.');
    redirect('login');
}
$user = db_one('SELECT * FROM users WHERE id = ? AND status = "active"', [$pendingId]);
if (!$user) {
    unset($_SESSION['pending_2fa_user_id']);
    redirect('login');
}
$user['id'] = (int) $user['id'];
$enrolling = $user['totp_enabled_at'] === null || $user['totp_secret'] === null;
if ($enrolling && empty($_SESSION['totp_setup_secret'])) {
    $_SESSION['totp_setup_secret'] = totp_generate_secret();
}
$secret = $enrolling ? $_SESSION['totp_setup_secret'] : $user['totp_secret'];

$error = null;
if (is_post()) {
    verify_csrf();
    $step = totp_verify($secret, post_str('code', 20), $enrolling ? null : ($user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null));
    if ($step === null) {
        $_SESSION['pending_2fa_failures'] = ($_SESSION['pending_2fa_failures'] ?? 0) + 1;
        audit('login_failed', 'Wrong two-factor code for ' . $user['email'], 'user', $user['id'], null, $user['id']);
        if ($_SESSION['pending_2fa_failures'] >= 5) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['totp_setup_secret']);
            flash('error', 'Too many wrong codes. Log in again.');
            redirect('login');
        }
        $error = 'That code is not correct. Check the time on your phone and try the newest code.';
    } else {
        if ($enrolling) {
            db_exec('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?',
                [$secret, utc_string(utc_now()), $step, $user['id']]);
            audit('two_factor_enabled', 'Two-factor authentication set up for ' . $user['email'], 'user', $user['id'], null, $user['id']);
        } else {
            db_exec('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $user['id']]);
        }
        unset($_SESSION['totp_setup_secret'], $_SESSION['pending_2fa_since'], $_SESSION['pending_2fa_failures']);
        complete_login($user);
        redirect(home_for($user));
    }
}

page_header('Two-factor authentication');
?>
<div class="form-card form-narrow">
  <h1><?= $enrolling ? 'Set up two-factor authentication' : 'Enter your security code' ?></h1>
  <?php if ($enrolling): ?>
    <p class="muted">Staff accounts need a second step at every login. Install an authenticator app (Google Authenticator, Microsoft Authenticator, or similar), then scan this code:</p>
    <div style="text-align:center;margin:14px 0">
      <div class="qr-box" id="qr" data-uri="<?= h(totp_uri($secret, $user['email'])) ?>"></div>
    </div>
    <p class="small muted">Can't scan? Enter this key in the app manually:<br><span class="code"><?= h(trim(chunk_split($secret, 4, ' '))) ?></span></p>
  <?php else: ?>
    <p class="muted">Open your authenticator app and enter the 6-digit code for <?= h(APP_ORG . ' ' . APP_NAME) ?>.</p>
  <?php endif; ?>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="field" style="margin-top:12px">
      <label for="code">6-digit code</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus style="font-size:20px;letter-spacing:0.2em">
    </div>
    <?php if ($error): ?><div class="field-error" style="margin-top:8px"><?= h($error) ?></div><?php endif; ?>
    <div class="form-actions"><button class="btn-primary" type="submit" style="width:100%"><?= $enrolling ? 'Verify and finish setup' : 'Verify' ?></button></div>
  </form>
  <p class="small muted" style="margin-top:16px">Lost your phone? Ask an ICT administrator to reset your two-factor setup.</p>
</div>
<?php if ($enrolling): ?>
<script src="<?= h(url('assets/vendor/qrcode.js')) ?>"></script>
<script>
(function(){
  var box = document.getElementById('qr');
  var qr = qrcode(0, 'M');
  qr.addData(box.dataset.uri);
  qr.make();
  box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0, scalable: false });
})();
</script>
<?php endif; ?>
<?php page_footer();
