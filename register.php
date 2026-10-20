<?php
declare(strict_types=1);

/** Q4/Q5: individuals and companies register to participate. Q6: no verification step before bidding. */
require_once __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(home_for(current_user()));
}

function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/\D/', '', $raw);
    if (preg_match('/^0([67]\d{8})$/', $digits, $m) || preg_match('/^255([67]\d{8})$/', $digits, $m)) {
        return '+255' . $m[1];
    }
    // Foreign numbers in international format.
    return str_starts_with(trim($raw), '+') && strlen($digits) >= 9 && strlen($digits) <= 15 ? '+' . $digits : null;
}

$v = [];
$errors = [];
if (is_post()) {
    verify_csrf();
    foreach (['participant_type', 'full_name', 'email', 'phone', 'address', 'nida_number', 'tin', 'company_name', 'brela_reg_no'] as $key) {
        $v[$key] = post_str($key, 300);
    }
    $type = in_array($v['participant_type'], ['individual', 'company'], true) ? $v['participant_type'] : null;
    if (!$type) {
        $errors['participant_type'] = 'Choose individual or company.';
    }
    if (mb_strlen($v['full_name']) < 3) {
        $errors['full_name'] = 'Enter the full name.';
    }
    $email = strtolower($v['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$email])) {
        $errors['email'] = 'An account with this email already exists. Log in instead.';
    }
    $phone = normalize_phone($v['phone']);
    if ($phone === null) {
        $errors['phone'] = 'Enter a valid phone number, e.g. 0712 345 678 or +255712345678.';
    }
    if (mb_strlen($v['address']) < 5) {
        $errors['address'] = 'Enter a physical or postal address.';
    }
    $nida = preg_replace('/\D/', '', $v['nida_number']);
    $tin = preg_replace('/\D/', '', $v['tin']);
    if ($type === 'individual' && strlen($nida) !== 20) {
        $errors['nida_number'] = 'A NIDA number has 20 digits.';
    }
    if ($tin !== '' && strlen($tin) !== 9) {
        $errors['tin'] = 'A TIN has 9 digits.';
    }
    if ($type === 'company') {
        if ($tin === '') {
            $errors['tin'] = 'TIN is required for companies.';
        }
        if (mb_strlen($v['company_name']) < 2) {
            $errors['company_name'] = 'Enter the registered company name.';
        }
        if (!preg_match('/^[A-Za-z0-9\/\-\s]{3,50}$/', $v['brela_reg_no'])) {
            $errors['brela_reg_no'] = 'Enter the BRELA registration number.';
        }
    }
    $password = (string) ($_POST['password'] ?? '');
    if ($err = validate_password($password, (string) ($_POST['password_confirm'] ?? ''))) {
        $errors['password'] = $err;
    }

    if (!$errors) {
        $userId = db_transaction(function () use ($v, $type, $email, $phone, $nida, $tin, $password) {
            db_exec('INSERT INTO users (email, password_hash, role, full_name, phone) VALUES (?, ?, "participant", ?, ?)',
                [$email, password_hash($password, PASSWORD_DEFAULT), $v['full_name'], $phone]);
            $userId = (int) db()->lastInsertId();
            db_exec('INSERT INTO participants (user_id, participant_type, nida_number, tin, company_name, brela_reg_no, address) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$userId, $type, $nida !== '' ? $nida : null, $tin !== '' ? $tin : null,
                 $type === 'company' ? $v['company_name'] : null, $type === 'company' ? strtoupper($v['brela_reg_no']) : null, $v['address']]);
            audit('participant_registered', 'Registered ' . $type . ' participant ' . ($type === 'company' ? $v['company_name'] . ' / ' : '') . $v['full_name'] . " <$email>", 'user', $userId, null, $userId);
            notify_user($userId, 'registration', 'Welcome to the ' . APP_ORG . ' Online Auction',
                'Your participant account has been created. You can now bid on open auctions. '
                . 'Before your first bid on each auction you will be asked to accept its terms and conditions.', '');
            return $userId;
        });
        $newUser = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        $newUser['id'] = $userId;
        complete_login($newUser);
        flash('success', 'Your account is ready. You can now bid on open auctions.');
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        header('Location: ' . ($next && str_starts_with($next, APP_BASE . '/') ? $next : url('')));
        exit;
    }
}

$type = $v['participant_type'] ?? 'individual';
page_header('Register', 'public');
?>
<div class="form-card" style="max-width:720px;margin:12px auto">
  <h1>Register to bid</h1>
  <p class="muted">Individuals and companies can take part. Your details are used to identify you if you win an auction.</p>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <fieldset style="margin:16px 0">
      <legend>I am registering as</legend>
      <div class="radio-row">
        <label class="checkbox"><input type="radio" name="participant_type" value="individual" <?= $type === 'individual' ? 'checked' : '' ?>> An individual</label>
        <label class="checkbox"><input type="radio" name="participant_type" value="company" <?= $type === 'company' ? 'checked' : '' ?>> A company</label>
      </div>
      <?= field_error($errors, 'participant_type') ?>
    </fieldset>

    <div class="form-grid">
      <div class="field full<?= isset($errors['company_name']) ? ' has-error' : '' ?>" data-show-if="participant_type:company">
        <label for="company_name">Company name</label>
        <input id="company_name" name="company_name" value="<?= old($v, 'company_name') ?>" maxlength="200">
        <?= field_error($errors, 'company_name') ?>
      </div>
      <div class="field<?= isset($errors['brela_reg_no']) ? ' has-error' : '' ?>" data-show-if="participant_type:company">
        <label for="brela_reg_no">BRELA registration number</label>
        <input id="brela_reg_no" name="brela_reg_no" value="<?= old($v, 'brela_reg_no') ?>" maxlength="50">
        <?= field_error($errors, 'brela_reg_no') ?>
      </div>
      <div class="field<?= isset($errors['tin']) ? ' has-error' : '' ?>">
        <label for="tin">TIN <span data-show-if="participant_type:individual">(optional)</span></label>
        <input id="tin" name="tin" value="<?= old($v, 'tin') ?>" inputmode="numeric" placeholder="123-456-789" maxlength="11">
        <?= field_error($errors, 'tin') ?>
      </div>
      <div class="field full<?= isset($errors['full_name']) ? ' has-error' : '' ?>">
        <label for="full_name"><span data-show-if="participant_type:company">Contact person — </span>Full name</label>
        <input id="full_name" name="full_name" value="<?= old($v, 'full_name') ?>" maxlength="150" autocomplete="name">
        <?= field_error($errors, 'full_name') ?>
      </div>
      <div class="field<?= isset($errors['nida_number']) ? ' has-error' : '' ?>" data-show-if="participant_type:individual">
        <label for="nida_number">NIDA number</label>
        <input id="nida_number" name="nida_number" value="<?= old($v, 'nida_number') ?>" inputmode="numeric" placeholder="20 digits" maxlength="23">
        <?= field_error($errors, 'nida_number') ?>
      </div>
      <div class="field<?= isset($errors['phone']) ? ' has-error' : '' ?>">
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" value="<?= old($v, 'phone') ?>" type="tel" autocomplete="tel" placeholder="0712 345 678">
        <?= field_error($errors, 'phone') ?>
      </div>
      <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
        <label for="email">Email</label>
        <input id="email" name="email" value="<?= old($v, 'email') ?>" type="email" autocomplete="email">
        <?= field_error($errors, 'email') ?>
      </div>
      <div class="field full<?= isset($errors['address']) ? ' has-error' : '' ?>">
        <label for="address">Address</label>
        <input id="address" name="address" value="<?= old($v, 'address') ?>" maxlength="300" placeholder="Street / P.O. Box, city" autocomplete="street-address">
        <?= field_error($errors, 'address') ?>
      </div>
      <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password">
        <div class="hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</div>
        <?= field_error($errors, 'password') ?>
      </div>
      <div class="field">
        <label for="password_confirm">Confirm password</label>
        <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password">
      </div>
    </div>
    <div class="form-actions">
      <button class="btn-primary" type="submit">Create account</button>
      <span class="muted small">Already registered? <a href="<?= h(url('login')) ?>">Log in</a></span>
    </div>
  </form>
</div>
<?php page_footer();
