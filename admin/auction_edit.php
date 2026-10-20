<?php
declare(strict_types=1);

/** Create or edit an auction (Q1 fields). Editable while draft, returned, or awaiting approval. */
require_once dirname(__DIR__) . '/bootstrap.php';

$user = require_staff('auction.create');
$id = get_int('id');
$a = $id ? load_auction($id) : null;
if ($id && !$a) {
    render_error_page('Not found', 'Auction not found.', 404);
}
if ($a && !in_array($a['status'], EDITABLE_STATUSES, true)) {
    flash('error', 'This auction is ' . AUCTION_STATUS_LABELS[$a['status']] . ' and can no longer be edited.');
    redirect('admin/auction_view?id=' . $a['id']);
}

$errors = [];
$v = $a ? [
    'title' => $a['title'], 'description' => $a['description'], 'quantity' => $a['quantity'], 'location' => $a['location'],
    'starting_price' => rtrim(rtrim($a['starting_price'], '0'), '.'), 'reserve_price' => $a['reserve_price'] !== null ? rtrim(rtrim($a['reserve_price'], '0'), '.') : '',
    'min_increment' => rtrim(rtrim($a['min_increment'], '0'), '.'), 'opens_at' => to_local_input($a['opens_at']),
    'closes_at' => to_local_input($a['closes_at']), 'terms' => $a['terms'],
] : ['min_increment' => (string) DEFAULT_MIN_INCREMENT];

if (is_post()) {
    verify_csrf();
    if (isset($_POST['delete_file']) && $a) {
        $name = delete_auction_file((int) $_POST['delete_file'], $a['id']);
        if ($name) {
            audit('auction_updated', "Removed file $name from " . auction_label($a), 'auction', $a['id']);
            flash('success', "Removed $name.");
        }
        redirect('admin/auction_edit?id=' . $a['id']);
    }

    // A form posted with files larger than post_max_size arrives empty.
    if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'The upload was too large. Upload fewer or smaller files at a time.');
        redirect('admin/auction_edit' . ($a ? '?id=' . $a['id'] : ''));
    }

    foreach (['title', 'description', 'quantity', 'location', 'starting_price', 'reserve_price', 'min_increment', 'opens_at', 'closes_at', 'terms'] as $key) {
        $v[$key] = post_str($key, 20000);
    }
    [$fields, $errors] = validate_auction_input($v);
    if (!$errors) {
        try {
            if ($a) {
                update_auction($a['id'], $fields, $user);
                $auctionId = $a['id'];
            } else {
                $auctionId = create_auction($fields, $user);
            }
            $fileErrors = array_merge(
                save_uploads($auctionId, 'photo', uploaded_files('photos'), $user['id']),
                save_uploads($auctionId, 'document', uploaded_files('documents'), $user['id'])
            );
            $added = count(uploaded_files('photos')) + count(uploaded_files('documents')) - count($fileErrors);
            if ($added > 0) {
                $saved = load_auction($auctionId);
                audit('auction_updated', "Added $added file(s) to " . auction_label($saved), 'auction', $auctionId);
            }
            if ($fileErrors) {
                flash('error', "Some files were not saved:\n" . implode("\n", $fileErrors));
            }
            if (isset($_POST['submit_for_approval'])) {
                submit_auction($auctionId, $user);
                flash('success', 'Saved and submitted for approval.');
                redirect('admin/auction_view?id=' . $auctionId);
            }
            flash('success', $a ? 'Changes saved.' : 'Draft auction created.');
            redirect('admin/auction_edit?id=' . $auctionId);
        } catch (AuctionError $e) {
            flash('error', $e->getMessage());
            redirect('admin/auction_edit?id=' . ($auctionId ?? $a['id'] ?? ''));
        }
    }
}

$photos = $a ? auction_files($a['id'], 'photo') : [];
$documents = $a ? auction_files($a['id'], 'document') : [];
$field = static function (string $key, string $label, string $input, string $hint = '', string $class = '') use ($errors): string {
    return '<div class="field ' . $class . (isset($errors[$key]) ? ' has-error' : '') . '"><label for="' . $key . '">' . h($label) . '</label>'
        . $input . ($hint ? '<div class="hint">' . $hint . '</div>' : '') . field_error($errors, $key) . '</div>';
};

page_header($a ? 'Edit ' . $a['reference'] : 'New auction', 'admin', 'auctions');
?>
<div class="page-head">
  <div>
    <h1><?= $a ? 'Edit ' . h($a['reference']) : 'New auction' ?></h1>
    <p><?= $a ? h(AUCTION_STATUS_LABELS[$a['status']]) : 'Saved as a draft until you submit it for approval by an administrator.' ?></p>
  </div>
  <?php if ($a): ?><a class="btn-secondary btn-sm" href="<?= h(url('admin/auction_view?id=' . $a['id'])) ?>">Back to auction</a><?php endif; ?>
</div>

<?php if ($a && $a['status'] === 'rejected'): ?>
  <div class="flash flash-error"><b>Returned for changes:</b> <?= h($a['status_reason']) ?></div>
<?php endif; ?>
<?php if ($a && $a['repeat_of']): ?>
  <div class="flash flash-info">This is a repeat of an unsold auction. Check the dates, prices and terms before submitting.</div>
<?php endif; ?>
<?php if ($errors): ?><div class="flash flash-error">Please correct the highlighted fields.</div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-card" novalidate>
  <?= csrf_field() ?>
  <div class="form-grid">
    <?= $field('title', 'Item / asset name', '<input id="title" name="title" maxlength="200" value="' . old($v, 'title') . '">', '', 'full') ?>
    <?= $field('description', 'Description', '<textarea id="description" name="description">' . old($v, 'description') . '</textarea>', '', 'full') ?>
    <?= $field('quantity', 'Quantity', '<input id="quantity" name="quantity" maxlength="100" value="' . old($v, 'quantity') . '" placeholder="e.g. 20 tonnes, 1 vehicle">') ?>
    <?= $field('location', 'Location', '<input id="location" name="location" maxlength="200" value="' . old($v, 'location') . '" placeholder="Where the item can be inspected / collected">') ?>
    <?= $field('starting_price', 'Starting price (' . CURRENCY . ')', '<input id="starting_price" name="starting_price" inputmode="decimal" value="' . old($v, 'starting_price') . '">', 'Set by ' . h(APP_ORG) . '. The first bid must be at least this.') ?>
    <?= $field('reserve_price', 'Minimum / reserve price (' . CURRENCY . ')', '<input id="reserve_price" name="reserve_price" inputmode="decimal" value="' . old($v, 'reserve_price') . '">', 'Optional and never shown to bidders. If the highest bid is below it, the item is not sold and the auction is repeated.') ?>
    <?= $field('min_increment', 'Minimum bid increment (' . CURRENCY . ')', '<input id="min_increment" name="min_increment" inputmode="decimal" value="' . old($v, 'min_increment') . '">', 'Each new bid must beat the current one by at least this much.') ?>
    <div></div>
    <?= $field('opens_at', 'Opening date & time', '<input id="opens_at" name="opens_at" type="datetime-local" value="' . old($v, 'opens_at') . '">', 'East Africa Time.') ?>
    <?= $field('closes_at', 'Closing date & time', '<input id="closes_at" name="closes_at" type="datetime-local" value="' . old($v, 'closes_at') . '">', 'Closes automatically. A bid in the last ' . ANTI_SNIPING_WINDOW_MIN . ' min extends it by ' . ANTI_SNIPING_EXTENSION_MIN . ' min.') ?>
    <?= $field('terms', 'Terms & conditions', '<textarea id="terms" name="terms" style="min-height:160px">' . old($v, 'terms') . '</textarea>', 'Bidders must accept these before their first bid.', 'full') ?>

    <div class="field full">
      <span class="field-label">Photos (JPG, PNG or WebP, up to <?= MAX_PHOTO_BYTES / 1048576 ?> MB each, max <?= MAX_PHOTOS ?>)</span>
      <?php if ($photos): ?>
        <div class="gallery-thumbs" style="margin-bottom:8px">
          <?php foreach ($photos as $p): ?>
            <div style="text-align:center">
              <img src="<?= h(file_url($p)) ?>" alt="<?= h($p['original_name']) ?>" style="width:96px;height:72px;object-fit:cover;border-radius:4px;display:block">
              <button class="btn-danger btn-sm" style="margin-top:4px;padding:2px 8px" name="delete_file" value="<?= (int) $p['id'] ?>" formnovalidate>Remove</button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
    </div>
    <div class="field full">
      <span class="field-label">Supporting documents (PDF, Word, Excel, images, up to <?= MAX_DOCUMENT_BYTES / 1048576 ?> MB each)</span>
      <?php if ($documents): ?>
        <ul class="doc-list" style="margin-bottom:8px">
          <?php foreach ($documents as $d): ?>
            <li><a href="<?= h(file_url($d)) ?>"><?= h($d['original_name']) ?></a>
              <button class="btn-danger btn-sm" style="padding:2px 8px" name="delete_file" value="<?= (int) $d['id'] ?>" formnovalidate>Remove</button></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <input type="file" name="documents[]" accept=".pdf,.doc,.docx,.xls,.xlsx,image/jpeg,image/png" multiple>
    </div>
  </div>
  <div class="form-actions">
    <button class="btn-secondary" type="submit" name="save">Save draft</button>
    <?php if (!$a || in_array($a['status'], ['draft', 'rejected'], true)): ?>
      <button class="btn-primary" type="submit" name="submit_for_approval" value="1">Save and submit for approval</button>
    <?php endif; ?>
  </div>
</form>
<?php page_footer();
