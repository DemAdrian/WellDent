<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$search = input('q');
$filter = input('filter');       // '' | 'balance'
$status = input('status');       // PATIENT_STATUSES key or ''
$care = input('care');
$page = max(1, (int) input('page', '1'));
$perPage = 25;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(p.full_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ? OR p.record_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if (isset(PATIENT_STATUSES[$status])) {
    $where[] = 'p.status = ?';
    $params[] = $status;
} else {
    $where[] = "p.status <> 'archived'";
}
if (in_array($care, CARE_TYPES, true)) {
    $where[] = 'p.care_type = ?';
    $params[] = $care;
}
if ($filter === 'balance') {
    $where[] = 'b.balance > 0';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = (int) q_val('SELECT COUNT(*) FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id $whereSql", $params);
$patients = q_all('SELECT p.*, b.balance, b.charged,
        (SELECT MIN(a.starts_at) FROM appointments a WHERE a.patient_id = p.id AND a.starts_at >= CURDATE()
            AND a.status NOT IN (\'cancelled\',\'no_show\',\'completed\')) AS next_appt
    FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id $whereSql
    ORDER BY p.full_name LIMIT $perPage OFFSET " . (($page - 1) * $perPage), $params);
$pages = max(1, (int) ceil($total / $perPage));

$link = fn(array $changes) => 'patients.php?' . http_build_query(array_filter(array_merge(
    ['q' => $search, 'filter' => $filter, 'status' => $status, 'care' => $care], $changes), fn($v) => $v !== '' && $v !== null));

layout_start('Patients', 'patients', [
    'subtitle' => 'Searchable records for every patient',
    'action'   => '<a class="btn btn-primary" href="patient_form.php">+ Add patient</a>',
]);
?>
<div class="toolbar">
  <form method="get" class="spacer" role="search">
    <?php foreach (['filter' => $filter, 'status' => $status, 'care' => $care] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($v) ?>"><?php endif; endforeach; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, phone, email, or record ID" aria-label="Search patients" style="background:#fff;padding:16px 18px">
  </form>
  <div class="row">
    <a class="btn<?= $filter === '' && $status === '' && $care === '' ? ' is-on' : '' ?>" href="patients.php<?= $search !== '' ? '?q=' . e(urlencode($search)) : '' ?>">All records</a>
    <a class="btn<?= $filter === 'balance' ? ' is-on' : '' ?>" href="<?= e($link(['filter' => $filter === 'balance' ? '' : 'balance', 'page' => ''])) ?>">Balance due</a>
    <details class="user-menu">
      <summary class="btn<?= $status !== '' || $care !== '' ? ' is-on' : '' ?>">Filters</summary>
      <form method="get" class="menu stack" style="padding:16px;min-width:240px">
        <input type="hidden" name="q" value="<?= e($search) ?>"><input type="hidden" name="filter" value="<?= e($filter) ?>">
        <div class="field"><label for="f-status">Record status</label>
          <select id="f-status" name="status"><option value="">Any (not archived)</option>
            <?php foreach (PATIENT_STATUSES as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, $status) ?>><?= $lbl ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label for="f-care">Care type</label>
          <select id="f-care" name="care"><option value="">Any</option>
            <?php foreach (CARE_TYPES as $c): ?><option<?= selected($c, $care) ?>><?= $c ?></option><?php endforeach; ?>
          </select></div>
        <button class="btn btn-primary btn-sm" type="submit">Apply</button>
      </form>
    </details>
  </div>
</div>

<section class="card">
  <div class="table-wrap">
    <table>
      <thead><tr><th>Patient</th><th>Care type</th><th>Next appointment</th><th>Balance</th><th>Record status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($patients as $i => $p): ?>
          <tr>
            <td><div class="who"><?= avatar($p['full_name'], $i) ?><span><strong><?= e($p['full_name']) ?></strong><small><?= e($p['phone'] ?: $p['record_no']) ?></small></span></div></td>
            <td><?= e($p['care_type']) ?></td>
            <td><?= $p['next_appt'] ? e(fmt_date($p['next_appt'], 'M j')) . ' · ' . fmt_time($p['next_appt']) : '<span class="muted">—</span>' ?></td>
            <td>
              <?php if ($p['balance'] > 0): ?><span class="amount-due"><?= money($p['balance']) ?></span>
              <?php elseif ($p['balance'] < 0): ?><span class="amount-credit"><?= money($p['balance']) ?> credit</span>
              <?php elseif ($p['charged'] > 0): ?><span class="amount-paid">Paid</span>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td><?= pill($p['status']) ?></td>
            <td class="num"><a href="patient.php?id=<?= $p['id'] ?>" style="font-weight:600">Open →</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$patients): ?>
          <tr><td colspan="6" class="empty"><?= $search !== '' ? 'No patients match “' . e($search) . '”.' : 'No patients yet.' ?> <a href="patient_form.php">Add a patient</a></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="row" style="justify-content:space-between;margin-top:16px">
      <span class="muted small"><?= $total ?> patients · page <?= $page ?> of <?= $pages ?></span>
      <span class="row">
        <?php if ($page > 1): ?><a class="btn btn-sm" href="<?= e($link(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-sm" href="<?= e($link(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</section>
<?php layout_end();
