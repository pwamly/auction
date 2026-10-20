<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/reports.php';

$user = require_staff('reports.view');

$type = get_str('type', 20);
if (!isset(REPORTS[$type])) {
    $type = 'summary';
}
[$title, $permission, $description] = REPORTS[$type];
if (!can($permission)) {
    render_error_page('Not allowed', 'Your role does not have access to the ' . $title . ' report.');
}

$from = get_str('from', 10);
$to = get_str('to', 10);
$q = get_str('q');
$extra = get_str('extra', 40);
$report = build_report($type, report_range($from, $to), $q, $extra);

if (get_str('format') === 'csv') {
    audit('report_exported', "Exported $title report" . ($from || $to ? " ($from – $to)" : ''), 'report', null);
    send_csv(
        'auction-' . $type . '-' . date('Y-m-d') . '.csv',
        array_map(static fn($c) => $c[1] . (($c[2] ?? '') === 'money' ? ' (' . CURRENCY . ')' : ''), $report['columns']),
        (static function () use ($report) {
            foreach ($report['rows'] as $row) {
                yield array_map(static function ($c) use ($row) {
                    $v = $row[$c[0]] ?? null;
                    $t = $c[2] ?? 'text';
                    if ($v === null) {
                        return '';
                    }
                    return $t === 'money' ? cents_to_decimal(decimal_to_cents($v)) : ($t === 'datetime' ? fmt_dt($v, 'Y-m-d H:i:s') : $v);
                }, $report['columns']);
            }
        })()
    );
}

$extraOptions = [
    'summary'      => ['Status', ['' => 'All'] + array_diff_key(AUCTION_STATUS_LABELS, array_flip(PRIVATE_STATUSES))],
    'participants' => ['Type', ['' => 'All', 'individual' => 'Individuals', 'company' => 'Companies']],
    'payments'     => ['Status', ['' => 'All', 'Paid' => 'Paid', 'Pending' => 'Pending', 'Overdue' => 'Overdue', 'Defaulted' => 'Defaulted']],
    'audit'        => ['Action', ['' => 'All'] + AUDIT_ACTIONS],
][$type] ?? null;

$shown = array_slice($report['rows'], 0, 500);
page_header($title . ' report', 'admin', 'reports');
?>
<div class="page-head"><div><h1>Reports</h1><p>All amounts in <?= h(CURRENCY) ?>; times in East Africa Time.</p></div></div>

<div class="tabs">
  <?php foreach (REPORTS as $key => [$label, $perm]): if (!can($perm)) { continue; } ?>
    <a href="<?= h(url('admin/reports?type=' . $key)) ?>" class="<?= $key === $type ? 'active' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
</div>

<p class="muted"><?= h($description) ?></p>
<form class="filters" method="get">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <div class="field"><label for="from">From</label><input id="from" type="date" name="from" value="<?= h($from) ?>"></div>
  <div class="field"><label for="to">To</label><input id="to" type="date" name="to" value="<?= h($to) ?>"></div>
  <?php if ($extraOptions): ?>
    <div class="field"><label for="extra"><?= h($extraOptions[0]) ?></label><select id="extra" name="extra">
      <?php foreach ($extraOptions[1] as $val => $label): ?><option value="<?= h($val) ?>" <?= (string) $val === $extra ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
    </select></div>
  <?php endif; ?>
  <?php if ($type !== 'revenue'): ?><div class="field grow"><label for="q">Search</label><input id="q" type="search" name="q" value="<?= h($q) ?>"></div><?php endif; ?>
  <button class="btn-secondary">Apply</button>
  <a class="btn-secondary" href="<?= h(url('admin/reports?type=' . $type)) ?>">Reset</a>
  <a class="btn-primary" href="?<?= h(http_build_query(array_merge($_GET, ['type' => $type, 'format' => 'csv']))) ?>">Export CSV</a>
</form>

<div class="stat-row">
  <?php foreach ($report['summary'] as [$label, $value]): ?>
    <div class="stat"><div class="stat-label"><?= h($label) ?></div><div class="stat-value tabular"><?= h($value) ?></div></div>
  <?php endforeach; ?>
</div>

<?php if (!$report['rows']): ?>
  <div class="empty-state">Nothing in this report for the selected filters.</div>
<?php else: ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><?php foreach ($report['columns'] as $c): ?><th class="<?= in_array($c[2] ?? '', ['money', 'int'], true) ? 'num' : '' ?>"><?= h($c[1]) ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($shown as $row): ?>
      <tr><?php foreach ($report['columns'] as $c): $t = $c[2] ?? 'text'; ?>
        <td class="<?= in_array($t, ['money', 'int'], true) ? 'num' : ($t === 'datetime' ? 'nowrap' : '') ?>"><?= h(format_report_cell($row[$c[0]] ?? null, $t)) ?></td>
      <?php endforeach; ?></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if (count($report['rows']) > count($shown)): ?>
    <p class="small muted">Showing the first <?= count($shown) ?> of <?= count($report['rows']) ?> rows. Export the CSV for all of them.</p>
  <?php endif; ?>
<?php endif; ?>
<?php page_footer();
