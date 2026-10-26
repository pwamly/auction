<?php
declare(strict_types=1);

/**
 * Pop-up forms used across pages. Each posts to its own handler script, which redirects
 * back here and reopens the pop-up (?modal=id) with the values and errors if something is wrong.
 */

function login_modal(): string
{
    [$v, $errors] = take_form('login');
    ob_start(); ?>
<?= modal_start('mLogin', 'Log in') ?>
  <form method="post" action="<?= h(url('login')) ?>" novalidate>
    <?= csrf_field() ?><?= return_field() ?>
    <div class="field"><label for="loginEmail">Email</label><input id="loginEmail" name="email" type="email" value="<?= old($v, 'email') ?>" autocomplete="username"></div>
    <div class="field"><label for="loginPassword">Password</label><input id="loginPassword" name="password" type="password" autocomplete="current-password"></div>
    <?= field_error($errors, '_form') ?>
    <p class="small muted">Staff accounts continue with a two-factor code. New participant? <a href="#" data-open="mRegister">Register to bid</a>.</p>
    <?= modal_actions('Log in') ?>
  </form>
<?= modal_end() ?>
<?php return ob_get_clean();
}

function register_modal(): string
{
    [$v, $errors] = take_form('register');
    $type = $v['participant_type'] ?? 'individual';
    $f = static function (string $key, string $label, string $attrs = '', string $hint = '', string $class = '', string $showIf = '') use ($v, $errors): string {
        return '<div class="field ' . $class . (isset($errors[$key]) ? ' has-error' : '') . '"' . ($showIf ? ' data-show-if="participant_type:' . $showIf . '"' : '') . '>'
            . '<label for="reg_' . $key . '">' . $label . '</label>'
            . '<input id="reg_' . $key . '" name="' . $key . '" value="' . old($v, $key) . '" ' . $attrs . '>'
            . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . field_error($errors, $key) . '</div>';
    };
    ob_start(); ?>
<?= modal_start('mRegister', 'Register to bid', 'Individuals and companies can take part. Registration is free.', 'wide') ?>
  <form method="post" action="<?= h(url('register')) ?>" novalidate>
    <?= csrf_field() ?><?= return_field() ?>
    <div class="choice-row" style="margin-bottom:16px">
      <label class="choice"><input type="radio" name="participant_type" value="individual" <?= $type === 'individual' ? 'checked' : '' ?>><span><b>An individual</b><span>Identified by NIDA number</span></span></label>
      <label class="choice"><input type="radio" name="participant_type" value="company" <?= $type === 'company' ? 'checked' : '' ?>><span><b>A company</b><span>Identified by TIN and BRELA</span></span></label>
    </div>
    <div class="form-grid">
      <?= $f('company_name', 'Company name', 'maxlength="200"', '', 'full', 'company') ?>
      <?= $f('brela_reg_no', 'BRELA registration number', 'maxlength="50"', '', '', 'company') ?>
      <?= $f('full_name', 'Full name <span class="muted" data-show-if="participant_type:company">(contact person)</span>', 'maxlength="150" autocomplete="name"') ?>
      <?= $f('nida_number', 'NIDA number', 'inputmode="numeric" maxlength="23" placeholder="20 digits"', '', '', 'individual') ?>
      <?= $f('tin', 'TIN <span class="muted" data-show-if="participant_type:individual">(optional)</span>', 'inputmode="numeric" maxlength="11" placeholder="123-456-789"') ?>
      <?= $f('phone', 'Phone number', 'type="tel" autocomplete="tel" placeholder="0712 345 678"') ?>
      <?= $f('email', 'Email', 'type="email" autocomplete="email"') ?>
      <?= $f('address', 'Address', 'maxlength="300" placeholder="Street / P.O. Box, city"', '', 'full') ?>
      <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>"><label for="reg_password">Password</label><input id="reg_password" name="password" type="password" autocomplete="new-password"><div class="hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</div><?= field_error($errors, 'password') ?></div>
      <div class="field"><label for="reg_password_confirm">Confirm password</label><input id="reg_password_confirm" name="password_confirm" type="password" autocomplete="new-password"></div>
    </div>
    <?= modal_actions('Create account') ?>
  </form>
<?= modal_end() ?>
<?php return ob_get_clean();
}

function password_modal(): string
{
    [, $errors] = take_form('password');
    ob_start(); ?>
<?= modal_start('mPassword', 'Change password') ?>
  <form method="post" action="<?= h(url('change_password')) ?>" novalidate>
    <?= csrf_field() ?><?= return_field() ?>
    <div class="field"><label for="pwCurrent">Current password</label><input id="pwCurrent" name="current_password" type="password" autocomplete="current-password"></div>
    <div class="field"><label for="pwNew">New password</label><input id="pwNew" name="new_password" type="password" autocomplete="new-password"><div class="hint">At least <?= PASSWORD_MIN_LENGTH ?> characters.</div></div>
    <div class="field"><label for="pwConfirm">Confirm new password</label><input id="pwConfirm" name="new_password_confirm" type="password" autocomplete="new-password"></div>
    <?= field_error($errors, '_form') ?>
    <?= modal_actions('Change password') ?>
  </form>
<?= modal_end() ?>
<?php return ob_get_clean();
}

/** Create (no $a) or edit (existing auction) form. Posts to admin/auction_edit. */
function auction_form_modal(?array $a = null): string
{
    $id = $a ? 'mAuctionEdit' : 'mAuctionNew';
    [$stashed, $errors] = take_form($a ? 'auction_' . $a['id'] : 'auction_new');
    $plain = static fn($d) => $d === null ? '' : rtrim(rtrim((string) $d, '0'), '.');
    $v = $stashed ?: ($a ? [
        'title' => $a['title'], 'description' => $a['description'], 'quantity' => $a['quantity'], 'location' => $a['location'],
        'starting_price' => $plain($a['starting_price']), 'reserve_price' => $plain($a['reserve_price']), 'min_increment' => $plain($a['min_increment']),
        'opens_at' => to_local_input($a['opens_at']), 'closes_at' => to_local_input($a['closes_at']), 'terms' => $a['terms'],
    ] : ['min_increment' => (string) DEFAULT_MIN_INCREMENT]);
    $photos = $a ? auction_files($a['id'], 'photo') : [];
    $documents = $a ? auction_files($a['id'], 'document') : [];
    $f = static function (string $key, string $label, string $input, string $hint = '', string $class = '') use ($errors): string {
        return '<div class="field ' . $class . (isset($errors[$key]) ? ' has-error' : '') . '"><label for="af_' . $key . '">' . h($label) . '</label>'
            . $input . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . field_error($errors, $key) . '</div>';
    };
    $in = static fn(string $key, string $attrs = '') => '<input id="af_' . $key . '" name="' . $key . '" value="' . old($v, $key) . '" ' . $attrs . '>';
    ob_start(); ?>
<?= modal_start($id, $a ? 'Edit ' . $a['reference'] : 'New auction', $a ? '' : 'Saved as a draft until you submit it for approval by an administrator.', 'wide') ?>
  <?php if ($a && $a['status'] === 'rejected'): ?><div class="flash flash-error"><div><b>Returned for changes:</b> <?= h($a['status_reason']) ?></div></div><?php endif; ?>
  <?php if ($errors): ?><div class="flash flash-error"><div>Please correct the highlighted fields.</div></div><?php endif; ?>
  <form method="post" action="<?= h(url('admin/auction_edit' . ($a ? '?id=' . $a['id'] : ''))) ?>" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?><?= return_field() ?>
    <div class="form-grid">
      <div class="form-section-title">Item</div>
      <?= $f('title', 'Item / asset name', $in('title', 'maxlength="200"'), '', 'full') ?>
      <?= $f('description', 'Description', '<textarea id="af_description" name="description" rows="3">' . old($v, 'description') . '</textarea>', '', 'full') ?>
      <?= $f('quantity', 'Quantity', $in('quantity', 'maxlength="100" placeholder="e.g. 20 tonnes, 1 vehicle"')) ?>
      <?= $f('location', 'Location', $in('location', 'maxlength="200" placeholder="Where it can be inspected / collected"')) ?>
      <div class="form-section-title">Pricing (<?= h(CURRENCY) ?>)</div>
      <?= $f('starting_price', 'Starting price', $in('starting_price', 'inputmode="decimal" data-money'), 'Set by ' . h(APP_ORG) . '.') ?>
      <?= $f('reserve_price', 'Reserve price (optional)', $in('reserve_price', 'inputmode="decimal" data-money'), 'Never shown to bidders. Below it, the item is not sold.') ?>
      <?= $f('min_increment', 'Minimum bid increment', $in('min_increment', 'inputmode="decimal" data-money')) ?>
      <div></div>
      <div class="form-section-title">Schedule (East Africa Time)</div>
      <?= $f('opens_at', 'Opens', $in('opens_at', 'type="datetime-local"')) ?>
      <?= $f('closes_at', 'Closes', $in('closes_at', 'type="datetime-local"'), 'A bid in the last ' . ANTI_SNIPING_WINDOW_MIN . ' min extends it by ' . ANTI_SNIPING_EXTENSION_MIN . ' min.') ?>
      <div class="form-section-title">Terms</div>
      <?= $f('terms', 'Terms & conditions', '<textarea id="af_terms" name="terms" rows="4">' . old($v, 'terms') . '</textarea>', 'Bidders accept these before their first bid.', 'full') ?>
      <div class="form-section-title">Photos &amp; documents</div>
      <div class="field full">
        <label>Photos <span class="muted">(JPG, PNG, WebP · up to <?= MAX_PHOTO_BYTES / 1048576 ?> MB each)</span></label>
        <?php if ($photos): ?><div class="gallery-thumbs" style="margin:0 0 8px">
          <?php foreach ($photos as $p): ?><div style="text-align:center"><img src="<?= h(file_url($p)) ?>" alt="" style="width:84px;height:62px;object-fit:cover;border-radius:6px;display:block">
            <button class="btn-ghost btn-sm" name="delete_file" value="<?= (int) $p['id'] ?>" formnovalidate>Remove</button></div><?php endforeach; ?>
        </div><?php endif; ?>
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
      </div>
      <div class="field full">
        <label>Supporting documents <span class="muted">(PDF, Word, Excel, images · up to <?= MAX_DOCUMENT_BYTES / 1048576 ?> MB each)</span></label>
        <?php if ($documents): ?><ul class="doc-list" style="margin-bottom:8px">
          <?php foreach ($documents as $d): ?><li><a href="<?= h(file_url($d)) ?>"><?= icon('file') ?><?= h($d['original_name']) ?></a>
            <button class="btn-ghost btn-sm" name="delete_file" value="<?= (int) $d['id'] ?>" formnovalidate>Remove</button></li><?php endforeach; ?>
        </ul><?php endif; ?>
        <input type="file" name="documents[]" accept=".pdf,.doc,.docx,.xls,.xlsx,image/jpeg,image/png" multiple>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-ghost" type="button" data-close>Cancel</button>
      <button class="btn-secondary" type="submit" name="save" value="1">Save draft</button>
      <?php if (!$a || in_array($a['status'], ['draft', 'rejected'], true)): ?>
        <button class="btn-primary" type="submit" name="submit_for_approval" value="1">Save &amp; submit for approval</button>
      <?php endif; ?>
    </div>
  </form>
<?= modal_end() ?>
<?php return ob_get_clean();
}
