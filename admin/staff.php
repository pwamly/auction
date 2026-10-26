<?php
declare(strict_types=1);

/** Staff accounts and access privileges. Administrator only (staff.manage is never assignable). */
require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_staff('staff.manage');

function temporary_password(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 12; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

$assignable = STAFF_ROLES;
$v = [];
$errors = [];

if (is_post()) {
    verify_csrf();
    $action = post_str('action', 20);
    if ($action === 'permissions') {
        // Access privileges for Manager and Auction Officer, from the checkbox grid.
        $posted = $_POST['perm'] ?? [];
        $before = role_permissions();
        $changes = [];
        db_transaction(function () use ($posted, $before, &$changes) {
            foreach (CONFIGURABLE_ROLES as $role) {
                $want = array_values(array_intersect(array_keys(PERMISSION_LABELS), array_keys((array) ($posted[$role] ?? []))));
                $added = array_diff($want, $before[$role]);
                $removed = array_diff($before[$role], $want);
                db_exec('DELETE FROM role_permissions WHERE role = ?', [$role]);
                foreach ($want as $perm) {
                    db_exec('INSERT INTO role_permissions (role, permission) VALUES (?, ?)', [$role, $perm]);
                }
                foreach ($added as $perm) {
                    $changes[] = ROLE_LABELS[$role] . ' + ' . PERMISSION_LABELS[$perm];
                }
                foreach ($removed as $perm) {
                    $changes[] = ROLE_LABELS[$role] . ' − ' . PERMISSION_LABELS[$perm];
                }
            }
        });
        if ($changes) {
            audit('permissions_changed', 'Access privileges changed: ' . implode('; ', $changes), 'role', null);
            flash('success', "Access privileges updated:\n" . implode("\n", $changes));
        } else {
            flash('info', 'No changes to access privileges.');
        }
        redirect('admin/staff');
    } elseif ($action === 'create') {
        $v = ['full_name' => post_str('full_name', 150), 'email' => strtolower(post_str('email', 190)), 'phone' => post_str('phone', 20), 'role' => post_str('role', 20)];
        if (mb_strlen($v['full_name']) < 3) {
            $errors['full_name'] = 'Enter the full name.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email.';
        } elseif (db_value('SELECT 1 FROM users WHERE email = ?', [$v['email']])) {
            $errors['email'] = 'An account with this email already exists.';
        }
        if (!in_array($v['role'], $assignable, true)) {
            $errors['role'] = 'Choose a role.';
        }
        if (!$errors) {
            $temp = temporary_password();
            db_exec('INSERT INTO users (email, password_hash, role, full_name, phone, must_change_password) VALUES (?, ?, ?, ?, ?, 1)',
                [$v['email'], password_hash($temp, PASSWORD_DEFAULT), $v['role'], $v['full_name'], $v['phone']]);
            $newId = (int) db()->lastInsertId();
            audit('staff_created', "Created {$v['role']} account for {$v['full_name']} <{$v['email']}>", 'user', $newId);
            flash('success', "Account created for {$v['full_name']}.\nTemporary password: $temp\n\nGive it to them securely. They must change it and set up two-factor authentication at first login. This password is not shown again.");
            redirect('admin/staff');
        }
    } else {
        $target = db_one('SELECT * FROM users WHERE id = ? AND role <> "participant"', [post_int('user_id') ?? 0]);
        $reason = post_str('reason', 500);
        try {
            if (!$target) {
                throw new AuctionError('Staff account not found.');
            }
            if ((int) $target['id'] === $user['id'] && $action !== 'reset_2fa') {
                throw new AuctionError('You cannot change your own account here.');
            }
            $newRole = $action === 'role' ? post_str('role', 20) : null;
            if (mb_strlen($reason) < 5) {
                throw new AuctionError('A reason is required (at least 5 characters).');
            }
            $label = "{$target['full_name']} <{$target['email']}>";
            switch ($action) {
                case 'role':
                    if (!in_array($newRole, STAFF_ROLES, true)) {
                        throw new AuctionError('Unknown role.');
                    }
                    db_exec('UPDATE users SET role = ? WHERE id = ?', [$newRole, $target['id']]);
                    audit('staff_updated', "Changed role of $label from {$target['role']} to $newRole", 'user', (int) $target['id'], $reason);
                    flash('success', 'Role changed.');
                    break;
                case 'block':
                case 'unblock':
                    db_exec('UPDATE users SET status = ?, status_reason = ? WHERE id = ?', [$action === 'block' ? 'blocked' : 'active', $reason, $target['id']]);
                    audit('staff_updated', ($action === 'block' ? 'Blocked ' : 'Unblocked ') . $label, 'user', (int) $target['id'], $reason);
                    flash('success', $action === 'block' ? 'Account blocked.' : 'Account unblocked.');
                    break;
                case 'reset_2fa':
                    db_exec('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL WHERE id = ?', [$target['id']]);
                    audit('two_factor_reset', "Reset two-factor authentication for $label", 'user', (int) $target['id'], $reason);
                    flash('success', 'Two-factor reset. They will set it up again at their next login.');
                    break;
                case 'reset_password':
                    $temp = temporary_password();
                    db_exec('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash($temp, PASSWORD_DEFAULT), $target['id']]);
                    audit('staff_updated', "Reset password for $label", 'user', (int) $target['id'], $reason);
                    flash('success', "New temporary password for {$target['full_name']}: $temp\nThis is not shown again.");
                    break;
                default:
                    throw new AuctionError('Unknown action.');
            }
        } catch (AuctionError $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin/staff');
    }
}

$staff = db_all('SELECT * FROM users WHERE role <> "participant" ORDER BY status, role, full_name');

page_header('Staff accounts', 'admin', 'staff');
?>
<div class="page-head">
  <div><h1>Staff accounts</h1><p>Staff sign in with two-factor authentication. New accounts get a temporary password that must be changed at first login.</p></div>
  <div class="head-actions">
    <button class="btn-secondary" type="button" data-open="mRoles"><?= icon('shield') ?>Access privileges</button>
    <button class="btn-primary" type="button" data-open="mAddStaff"><?= icon('plus') ?>Add staff</button>
  </div>
</div>

<div class="table-wrap"><table class="data">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>2FA</th><th>Last login</th><th></th></tr></thead>
  <tbody><?php foreach ($staff as $s): $self = (int) $s['id'] === $user['id']; ?>
    <tr>
      <td class="mid"><?= h($s['full_name']) ?><?= $self ? ' <span class="muted">(you)</span>' : '' ?></td>
      <td><?= h($s['email']) ?></td>
      <td><?= h(ROLE_LABELS[$s['role']]) ?></td>
      <td><span class="status-pill <?= $s['status'] === 'active' ? 'status-live' : 'status-alert' ?>"><?= h(ucfirst($s['status'])) ?></span><?= $s['must_change_password'] ? '<br><span class="small muted">temporary password</span>' : '' ?></td>
      <td><?= $s['totp_enabled_at'] ? 'On' : '<span class="muted">Not set up</span>' ?></td>
      <td class="nowrap"><?= h(fmt_dt($s['last_login_at'])) ?></td>
      <td class="num"><button class="btn-secondary btn-sm" type="button" data-open="mStaff<?= (int) $s['id'] ?>">Manage</button></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>

<?php foreach ($staff as $s): $self = (int) $s['id'] === $user['id']; ?>
  <?= modal_start('mStaff' . (int) $s['id'], 'Manage ' . $s['full_name'], h($s['email']) . ' · ' . h(ROLE_LABELS[$s['role']])) ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $s['id'] ?>">
      <div class="field"><label>Action</label>
        <select name="action">
          <?php if (!$self): ?>
          <option value="role">Change role</option>
          <option value="<?= $s['status'] === 'active' ? 'block' : 'unblock' ?>"><?= $s['status'] === 'active' ? 'Block account' : 'Unblock account' ?></option>
          <option value="reset_password">Reset password (new temporary password)</option>
          <?php endif; ?>
          <option value="reset_2fa">Reset two-factor (lost phone)</option>
        </select></div>
      <?php if (!$self): ?>
      <div class="field"><label>New role <span class="muted">(for “Change role”)</span></label>
        <select name="role"><?php foreach ($assignable as $r): ?><option value="<?= h($r) ?>" <?= $r === $s['role'] ? 'selected' : '' ?>><?= h(ROLE_LABELS[$r]) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <?= reason_field() ?>
      <?= modal_actions('Apply') ?>
    </form>
  <?= modal_end() ?>
<?php endforeach; ?>

<dialog class="modal" id="mAddStaff" aria-labelledby="mAddStaff-title"<?= $errors ? ' data-autoopen' : '' ?>>
  <div class="modal-head"><h2 id="mAddStaff-title">Add staff account</h2><button class="icon-btn" type="button" data-close aria-label="Close"><?= icon('x') ?></button></div>
  <div class="modal-body">
    <p class="modal-intro">A temporary password is shown once after saving. Give it to the person securely.</p>
    <form method="post" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="field<?= isset($errors['full_name']) ? ' has-error' : '' ?>"><label for="full_name">Full name</label><input id="full_name" name="full_name" value="<?= old($v, 'full_name') ?>"><?= field_error($errors, 'full_name') ?></div>
      <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>"><label for="email">Email</label><input id="email" name="email" type="email" value="<?= old($v, 'email') ?>"><?= field_error($errors, 'email') ?></div>
      <div class="field"><label for="phone">Phone</label><input id="phone" name="phone" value="<?= old($v, 'phone') ?>"></div>
      <div class="field<?= isset($errors['role']) ? ' has-error' : '' ?>"><label for="role">Role / unit</label>
        <select id="role" name="role"><?php foreach ($assignable as $r): ?><option value="<?= h($r) ?>" <?= ($v['role'] ?? 'auction_officer') === $r ? 'selected' : '' ?>><?= h(ROLE_LABELS[$r]) ?></option><?php endforeach; ?></select><?= field_error($errors, 'role') ?></div>
      <?= modal_actions('Create account') ?>
    </form>
  </div>
</dialog>

<?php $granted = role_permissions(); ?>
<?= modal_start('mRoles', 'Access privileges', 'Choose what Managers and Auction Officers may do. Administrators always have every privilege, and only administrators manage staff accounts. Changes apply immediately and are recorded in the history.', 'wide') ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="permissions">
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Privilege</th><th class="num">Administrator</th><?php foreach (CONFIGURABLE_ROLES as $r): ?><th class="num"><?= h(ROLE_LABELS[$r]) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach (PERMISSION_LABELS as $perm => $label): ?>
        <tr><td><?= h($label) ?></td><td class="num muted">✓</td>
          <?php foreach (CONFIGURABLE_ROLES as $r): ?>
            <td class="num"><input type="checkbox" class="perm-box" name="perm[<?= h($r) ?>][<?= h($perm) ?>]" value="1" aria-label="<?= h(ROLE_LABELS[$r] . ': ' . $label) ?>" <?= in_array($perm, $granted[$r], true) ? 'checked' : '' ?>></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
        <tr><td>Manage staff accounts &amp; access privileges</td><td class="num muted">✓</td><?php foreach (CONFIGURABLE_ROLES as $r): ?><td class="num small muted">Admin only</td><?php endforeach; ?></tr>
      </tbody>
    </table></div>
    <?= modal_actions('Save privileges') ?>
  </form>
<?= modal_end() ?>
<?php page_footer();
