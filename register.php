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
        header('Location: ' . ($next && str_starts_with($next, APP_BASE . '/') ? $next : return_url()));
        exit;
    }
    // Back to the registration pop-up with the entries and errors.
    stash_form('register', $v, $errors);
    back_to_modal('mRegister');
}

// Registration is a pop-up on every public page.
redirect('?modal=mRegister');
