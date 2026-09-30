<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$from = valid_date(input('from')) ? input('from') : date('Y-m-01');
$to = valid_date(input('to')) ? input('to') : date('Y-m-d');
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$range = [$from, $to];

$apptStatus = array_column(q_all('SELECT status, COUNT(*) AS n FROM appointments WHERE DATE(starts_at) BETWEEN ? AND ? GROUP BY status', $range), 'n', 'status');
$charged = (float) q_val('SELECT COALESCE(SUM(amount), 0) FROM treatments WHERE performed_on BETWEEN ? AND ?', $range);
$collected = (float) q_val('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE paid_on BETWEEN ? AND ?', $range);
$newPatients = (int) q_val('SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN ? AND ?', $range);
$outstanding = q_all('SELECT p.id, p.full_name, p.phone, b.charged, b.paid, b.balance FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id
    WHERE b.balance > 0 AND p.status <> 'archived' ORDER BY b.balance DESC");
$byMethod = q_all('SELECT method, COUNT(*) AS n, SUM(amount) AS total FROM payments WHERE paid_on BETWEEN ? AND ? GROUP BY method ORDER BY total DESC', $range);
$procedures = q_all('SELECT procedure_name, COUNT(*) AS n, SUM(amount) AS total FROM treatments WHERE performed_on BETWEEN ? AND ?
    GROUP BY procedure_name ORDER BY n DESC LIMIT 10', $range);
$stock = q_all("SELECT i.name, i.unit, i.quantity, i.min_quantity,
        COALESCE(SUM(CASE WHEN m.type = 'in' THEN m.quantity END), 0) AS qty_in,
        COALESCE(SUM(CASE WHEN m.type = 'out' THEN m.quantity END), 0) AS qty_out
    FROM inventory_items i LEFT JOIN stock_movements m ON m.item_id = i.id AND DATE(m.created_at) BETWEEN ? AND ?
    WHERE i.is_active = 1 GROUP BY i.id ORDER BY qty_out DESC, i.name", $range);
$activity = q_all('SELECT l.*, u.name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id
    WHERE DATE(l.created_at) BETWEEN ? AND ? ORDER BY l.id DESC LIMIT 60', $range);
$totalAppts = array_sum($apptStatus);

layout_start('Reports', 'reports', [
    'subtitle' => 'Records and activity for ' . fmt_date($from) . ' – ' . fmt_date($to),
    'action'   => '<button class="btn" onclick="window.print()">Print</button>',
]);
?>
<form method="get" class="toolbar no-print">
  <div class="row">
    <div class="field"><label for="from">From</label><input type="date" id="from" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to">To</label><input type="date" id="to" name="to" value="<?= e($to) ?>"></div>
    <button class="btn btn-primary" type="submit" style="align-self:flex-end">Apply</button>
  </div>
  <div class="row">
    <a class="btn btn-sm" href="reports.php?from=<?= date('Y-m-d') ?>&amp;to=<?= date('Y-m-d') ?>">Today</a>
    <a class="btn btn-sm" href="reports.php?from=<?= date('Y-m-01') ?>&amp;to=<?= date('Y-m-d') ?>">This month</a>
    <a class="btn btn-sm" href="reports.php?from=<?= date('Y-01-01') ?>&amp;to=<?= date('Y-m-d') ?>">This year</a>
  </div>
</form>

<section class="grid-4">
  <div class="card stat"><div class="label">Appointments</div><div class="value"><?= $totalAppts ?></div><div class="sub"><?= (int) ($apptStatus['completed'] ?? 0) ?> completed · <?= (int) ($apptStatus['no_show'] ?? 0) ?> no-show</div></div>
  <div class="card stat"><div class="label">New patients</div><div class="value"><?= $newPatients ?></div><div class="sub">Charts created in range</div></div>
  <div class="card stat"><div class="label">Charged</div><div class="value"><?= money($charged) ?></div><div class="sub">Treatments billed</div></div>
  <div class="card stat"><div class="label">Collected</div><div class="value"><?= money($collected) ?></div><div class="sub"><?= $charged > 0 ? round($collected / $charged * 100) . '% of charges' : 'Payments received' ?></div></div>
</section>

<div class="layout-half" style="margin-bottom:24px">
  <section class="card">
    <h2>Top procedures</h2>
    <table><thead><tr><th>Procedure</th><th class="num">Count</th><th class="num">Billed</th></tr></thead><tbody>
      <?php foreach ($procedures as $p): ?><tr><td><?= e($p['procedure_name']) ?></td><td class="num"><?= (int) $p['n'] ?></td><td class="num"><?= money($p['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$procedures): ?><tr><td colspan="3" class="empty">No treatments in this range.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
  <section class="card">
    <h2>Payments by method</h2>
    <table><thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Total</th></tr></thead><tbody>
      <?php foreach ($byMethod as $m): ?><tr><td><?= e(PAYMENT_METHODS[$m['method']]) ?></td><td class="num"><?= (int) $m['n'] ?></td><td class="num"><?= money($m['total']) ?></td></tr><?php endforeach; ?>
      <?php if (!$byMethod): ?><tr><td colspan="3" class="empty">No payments in this range.</td></tr><?php endif; ?>
    </tbody></table>
  </section>
</div>

<div class="stack">
  <section class="card">
    <h2>Outstanding balances (all time)</h2>
    <div class="table-wrap"><table><thead><tr><th>Patient</th><th>Phone</th><th class="num">Charged</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($outstanding as $o): ?>
        <tr><td><a href="patient.php?id=<?= $o['id'] ?>&amp;tab=billing"><?= e($o['full_name']) ?></a></td><td><?= e($o['phone'] ?: '—') ?></td>
          <td class="num"><?= money($o['charged']) ?></td><td class="num"><?= money($o['paid']) ?></td><td class="num amount-due"><?= money($o['balance']) ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$outstanding): ?><tr><td colspan="5" class="empty">No outstanding balances.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="card">
    <h2>Inventory usage</h2>
    <div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Received</th><th class="num">Used</th><th class="num">On hand</th><th class="num">Minimum</th></tr></thead><tbody>
      <?php foreach ($stock as $s): ?>
        <tr><td><?= e($s['name']) ?></td><td class="num"><?= (int) $s['qty_in'] ?></td><td class="num"><?= (int) $s['qty_out'] ?></td>
          <td class="num<?= $s['quantity'] <= $s['min_quantity'] ? ' amount-due' : '' ?>"><?= (int) $s['quantity'] ?> <?= e($s['unit']) ?></td><td class="num"><?= (int) $s['min_quantity'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$stock): ?><tr><td colspan="5" class="empty">No inventory items.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="card">
    <h2>Activity log</h2>
    <div class="table-wrap"><table><thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th></tr></thead><tbody>
      <?php foreach ($activity as $a): ?>
        <tr><td style="white-space:nowrap"><?= e(fmt_date($a['created_at'], 'M j, g:i A')) ?></td><td><?= e($a['name'] ?? '—') ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', $a['action']))) ?></td>
          <td><?= $a['entity'] === 'patient' && $a['entity_id'] ? '<a href="patient.php?id=' . (int) $a['entity_id'] . '">' . e($a['details'] ?: 'Patient') . '</a>' : e($a['details'] ?? '') ?></td></tr>
      <?php endforeach; ?>
      <?php if (!$activity): ?><tr><td colspan="4" class="empty">No activity in this range.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>
<?php layout_end();
