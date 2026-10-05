<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/dental_chart.php';
$user = require_login();

$id = (int) input('id');
$patient = q_row('SELECT p.*, b.charged, b.paid, b.balance FROM patients p JOIN ' . BALANCES . ' b ON b.patient_id = p.id WHERE p.id = ?', [$id]);
if (!$patient) {
    http_response_code(404);
    exit('Patient not found.');
}
$tab = in_array(input('tab'), ['chart', 'billing', 'info'], true) ? input('tab') : 'chart';
$back = fn(string $tab) => url('patients/view.php') . "?id=$id&tab=$tab";

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'tooth') {
        require_perm('chart.edit');
        $tooth = (int) input('tooth_no');
        $status = input('status');
        $amountRaw = input('amount') === '' ? '0' : input('amount');
        $amount = (float) $amountRaw;
        $tooLong = length_errors(['procedure_name' => input('procedure_name'), 'notes' => input('notes')], ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 255]]);
        if ($tooth < 1 || $tooth > 32 || !isset(TOOTH_STATUSES[$status])) {
            flash('error', 'Choose a tooth and a valid condition.');
        } elseif (!valid_amount($amountRaw, true)) {
            flash('error', 'Charge must be from ₱0 to ' . money(MAX_AMOUNT) . ', with at most 2 decimals.');
        } elseif ($tooLong) {
            flash('error', implode(' ', $tooLong));
        } else {
            q('INSERT INTO tooth_records (patient_id, tooth_no, status, procedure_name, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $tooth, $status, nullable(input('procedure_name')), nullable(input('notes')), $user['id']]);
            if ($amount > 0) {
                q('INSERT INTO treatments (patient_id, tooth_no, procedure_name, notes, amount, performed_on, dentist_id) VALUES (?, ?, ?, ?, ?, CURDATE(), ?)',
                    [$id, $tooth, input('procedure_name') ?: TOOTH_STATUSES[$status][0], nullable(input('notes')), $amount, $user['id']]);
            }
            log_activity('tooth_charted', 'patient', $id, "Tooth $tooth: $status");
            flash('success', "Tooth $tooth updated" . ($amount > 0 ? ' and ' . money($amount) . ' charged.' : '.'));
        }
        redirect($back('chart'));
    }

    if ($action === 'treatment') {
        $amount = (float) input('amount');
        $tooth = (int) input('tooth_no');
        $date = input('performed_on');
        $dentistId = input('dentist_id');
        $tooLong = length_errors(['procedure_name' => input('procedure_name'), 'notes' => input('notes')], ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 255]]);
        if (input('procedure_name') === '' || !valid_amount(input('amount'), true) || !valid_date($date) || ($tooth && ($tooth < 1 || $tooth > 32))) {
            flash('error', 'Enter a procedure, a valid date and an amount from ₱0 to ' . money(MAX_AMOUNT) . '.');
        } elseif ($tooLong) {
            flash('error', implode(' ', $tooLong));
        } elseif ($dentistId !== '' && !in_array((int) $dentistId, array_map('intval', array_column(dentist_options(), 'id')), true)) {
            flash('error', 'Choose a valid dentist.');
        } else {
            q('INSERT INTO treatments (patient_id, tooth_no, procedure_name, notes, amount, performed_on, dentist_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $tooth ?: null, input('procedure_name'), nullable(input('notes')), $amount, $date, nullable(input('dentist_id'))]);
            log_activity('treatment_added', 'patient', $id, input('procedure_name') . ' ' . money($amount));
            flash('success', 'Treatment recorded.');
        }
        redirect($back('billing'));
    }

    if ($action === 'delete_treatment' || $action === 'delete_payment') {
        require_perm('billing.delete');
        $table = $action === 'delete_treatment' ? 'treatments' : 'payments';
        q("DELETE FROM $table WHERE id = ? AND patient_id = ?", [(int) input('row_id'), $id]);
        log_activity($action, 'patient', $id, 'row ' . input('row_id'));
        flash('success', 'Entry removed.');
        redirect($back('billing'));
    }

    if ($action === 'archive' || $action === 'restore') {
        require_perm('patients.archive');
        $new = $action === 'archive' ? 'archived' : 'incomplete';
        q('UPDATE patients SET status = ? WHERE id = ?', [$new, $id]);
        $cancelled = $restored = $skipped = 0;
        if ($action === 'archive') {
            // Free their upcoming chair time; past appointments stay as history. The note marks
            // which ones archiving cancelled, so a restore can bring them back.
            $cancelled = q("UPDATE appointments SET status = 'cancelled', notes = TRIM(CONCAT(COALESCE(notes, ''), ' ', ?))
                WHERE patient_id = ? AND starts_at > NOW() AND status IN ('pending','confirmed')", [ARCHIVE_NOTE, $id])->rowCount();
            q("UPDATE reminders SET status = 'cancelled' WHERE patient_id = ? AND status IN ('scheduled','failed')", [$id]);
            q('UPDATE waitlist SET resolved_at = NOW() WHERE patient_id = ? AND resolved_at IS NULL', [$id]);
        } else {
            // Re-open still-upcoming appointments that archiving cancelled, unless the slot was taken since.
            $toRestore = q_all("SELECT * FROM appointments WHERE patient_id = ? AND status = 'cancelled' AND starts_at > NOW() AND notes LIKE ?",
                [$id, '%' . ARCHIVE_NOTE . '%']);
            foreach ($toRestore as $a) {
                try {
                    $ok = with_booking_lock(function () use ($a) {
                        if (appointment_clash((int) $a['chair'], $a['starts_at'], (int) $a['duration_min'], (int) $a['id'])) {
                            return false;
                        }
                        q("UPDATE appointments SET status = 'pending', notes = ? WHERE id = ?",
                            [nullable(trim(str_replace(ARCHIVE_NOTE, '', (string) $a['notes']))), $a['id']]);
                        return true;
                    });
                } catch (RuntimeException $ex) {
                    $ok = false;
                }
                if ($ok) {
                    schedule_appointment_reminders((int) $a['id']);
                    $restored++;
                } else {
                    $skipped++;
                }
            }
        }
        $summary = $action === 'archive'
            ? ($cancelled ? " ($cancelled appointments cancelled)" : '')
            : ($restored || $skipped ? " ($restored appointments restored, $skipped not)" : '');
        log_activity("patient_$action", 'patient', $id, $patient['full_name'] . $summary);
        flash('success', $action === 'archive'
            ? 'Patient archived. Their records are kept but hidden from lists.' . ($cancelled ? " $cancelled upcoming appointment(s) were cancelled." : '')
            : 'Patient restored. Review the chart to mark it complete.'
                . ($restored ? " $restored upcoming appointment(s) were re-opened as Pending; confirm them with the patient." : '')
                . ($skipped ? " $skipped could not be re-opened because the slot is now taken; book those again." : ''));
        redirect($action === 'archive' ? url('patients/') : url('patients/form.php') . '?id=' . $id);
    }
}

$appointments = q_all('SELECT a.*, u.name AS dentist FROM appointments a LEFT JOIN users u ON u.id = a.dentist_id
    WHERE a.patient_id = ? ORDER BY a.starts_at DESC', [$id]);
$treatments = q_all('SELECT t.*, u.name AS dentist FROM treatments t LEFT JOIN users u ON u.id = t.dentist_id
    WHERE t.patient_id = ? ORDER BY t.performed_on DESC, t.id DESC', [$id]);
$payments = q_all('SELECT y.*, u.name AS received FROM payments y LEFT JOIN users u ON u.id = y.received_by
    WHERE y.patient_id = ? ORDER BY y.paid_on DESC, y.id DESC', [$id]);

// One dated list of charges and payments, oldest first, with the balance after each line.
// Same-day entries keep the order they were entered in.
$ledger = [];
foreach ($treatments as $t) {
    $ledger[] = ['kind' => 'treatment', 'id' => (int) $t['id'], 'date' => $t['performed_on'], 'at' => $t['created_at'],
        'charge' => (float) $t['amount'], 'paid' => 0.0,
        'what' => $t['procedure_name'] . ($t['tooth_no'] ? ' · Tooth #' . (int) $t['tooth_no'] : ''),
        'detail' => implode(' · ', array_filter([$t['notes'], $t['dentist']]))];
}
foreach ($payments as $y) {
    $ledger[] = ['kind' => 'payment', 'id' => (int) $y['id'], 'date' => $y['paid_on'], 'at' => $y['created_at'],
        'charge' => 0.0, 'paid' => (float) $y['amount'],
        'what' => 'Payment · ' . PAYMENT_METHODS[$y['method']] . ($y['reference'] ? ' · Ref ' . $y['reference'] : ''),
        'detail' => implode(' · ', array_filter([$y['notes'], $y['received'] ? 'Received by ' . $y['received'] : null]))];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['at'], $a['kind'] === 'payment', $a['id']] <=> [$b['date'], $b['at'], $b['kind'] === 'payment', $b['id']]);
$running = 0.0;
foreach ($ledger as &$entry) {
    $running = round($running + $entry['charge'] - $entry['paid'], 2);
    $entry['balance'] = $running;
}
unset($entry);

if (input('format') === 'pdf') {
    require dirname(__DIR__) . '/includes/pdf.php';
    $due = '#e0785f';
    $credit = '#1f8a80';
    $bal = (float) $patient['balance'];
    $pdf = new PdfReport('Statement of account', $patient['full_name'] . ($patient['record_no'] ? ' · ' . $patient['record_no'] : '') . ' · As of ' . date('M j, Y'),
        config('clinic.name'), 'Generated ' . date('M j, Y g:i A') . ' by ' . $user['name']);
    $pdf->stats([
        ['Total charged', money($patient['charged']), count($treatments) . ' treatment(s)'],
        ['Total paid', money($patient['paid']), count($payments) . ' payment(s)'],
        [$bal < 0 ? 'Credit' : 'Balance due', money($bal), $bal < 0 ? 'Held for future treatment' : ($bal > 0 ? 'Amount still owed' : 'Fully paid')],
    ]);
    $pdf->table('Charges and payments', [['Date', 2, 'L'], ['Description', 6, 'L'], ['Charge', 2, 'R'], ['Payment', 2, 'R'], ['Balance', 2.8, 'R']],
        array_map(fn($l) => [
            fmt_date($l['date']),
            $l['what'] . ($l['detail'] !== '' ? ' · ' . $l['detail'] : ''),
            $l['kind'] === 'treatment' ? money($l['charge']) : '',
            $l['kind'] === 'payment' ? [money($l['paid']), $credit] : '',
            [money($l['balance']) . ($l['balance'] < 0 ? ' CR' : ''), $l['balance'] > 0 ? $due : $credit],
        ], $ledger), 'No charges or payments recorded.');
    $pdf->download('statement-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($patient['full_name'])), '-') . '-' . date('Y-m-d') . '.pdf');
}

// Tooth history, newest first; the first entry per tooth is its current state.
$toothLogs = q_all('SELECT r.*, u.name AS by_name FROM tooth_records r LEFT JOIN users u ON u.id = r.recorded_by
    WHERE r.patient_id = ? ORDER BY r.id DESC', [$id]);
$toothState = [];
$toothCurrent = [];
$history = [];
foreach ($toothLogs as $log) {
    $no = (int) $log['tooth_no'];
    if (!isset($toothState[$no])) {
        $toothState[$no] = $log['status'];
        $toothCurrent[$no] = $log;
    }
    $history[$no][] = ['status' => $log['status'], 'procedure' => $log['procedure_name'], 'notes' => $log['notes'],
        'by' => $log['by_name'], 'at' => date('M j, Y', strtotime($log['recorded_at']))];
}
ksort($toothCurrent);
$conditions = array_filter($toothCurrent, fn($l) => $l['status'] !== 'healthy');

$facts = array_filter([
    age($patient['birth_date']) !== null ? age($patient['birth_date']) . ' years old' : null,
    $patient['sex'] ? ucfirst($patient['sex']) : null,
    $patient['phone'],
    $patient['email'],
    $patient['record_no'],
]);
$balance = (float) $patient['balance'];

layout_start($patient['full_name'], 'patients', ['subtitle' => $patient['care_type'] . ' · ' . PATIENT_STATUSES[$patient['status']] . ' record']);
?>
<a class="back no-print" href="<?= url('patients/') ?>">← Back to Patients</a>

<section class="card profile">
  <span class="avatar avatar-2 avatar-lg"><?= e(initials($patient['full_name'])) ?></span>
  <div>
    <h2><?= e($patient['full_name']) ?> <?= $patient['status'] !== 'active' ? pill($patient['status']) : '' ?></h2>
    <div class="facts"><?php foreach ($facts as $f): ?><span><?= e($f) ?></span><?php endforeach; ?></div>
  </div>
  <div class="bal">
    <small><?= $balance < 0 ? 'Credit' : 'Balance' ?></small>
    <strong class="<?= $balance > 0 ? 'amount-due' : 'amount-credit' ?>"><?= money($balance) ?></strong>
  </div>
  <div class="row no-print">
    <a class="btn btn-sm" href="<?= url('appointments/form.php') ?>?patient_id=<?= $id ?>">Book</a>
    <a class="btn btn-sm" href="<?= url('patients/payment.php') ?>?patient_id=<?= $id ?>">Log payment</a>
    <?php if (can('patients.archive')): ?>
      <form method="post" data-confirm="<?= $patient['status'] === 'archived' ? 'Restore this patient?' : 'Archive this patient? Their records are kept but hidden from lists. Upcoming appointments, reminders and waitlist entries are cancelled.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="<?= $patient['status'] === 'archived' ? 'restore' : 'archive' ?>">
        <button class="btn btn-sm <?= $patient['status'] === 'archived' ? '' : 'btn-danger' ?>" type="submit"><?= $patient['status'] === 'archived' ? 'Restore' : 'Archive' ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>

<div class="mini-stats">
  <div class="card"><strong><?= count($appointments) ?></strong><span>Appointments</span></div>
  <div class="card"><strong><?= count($treatments) ?></strong><span>Treatments</span></div>
  <div class="card"><strong><?= money($patient['charged']) ?></strong><span>Total charged</span></div>
  <div class="card"><strong><?= money($patient['paid']) ?></strong><span>Total paid</span></div>
</div>

<nav class="tabs no-print">
  <a href="<?= $back('chart') ?>" class="<?= $tab === 'chart' ? 'active' : '' ?>">Dental Chart</a>
  <a href="<?= $back('billing') ?>" class="<?= $tab === 'billing' ? 'active' : '' ?>">Treatments &amp; Billing</a>
  <a href="<?= $back('info') ?>" class="<?= $tab === 'info' ? 'active' : '' ?>">Patient Info</a>
</nav>

<?php if ($tab === 'chart'): ?>
  <section class="card">
    <h2>Interactive Dental Chart</h2>
    <p class="muted small" style="margin:6px 0 16px"><?= can('chart.edit') ? 'Click any tooth to view its history or update its condition and procedure.' : 'Click any tooth to view its history. Only dentists can change the chart.' ?></p>
    <?= render_dental_chart($toothState) ?>
    <?= render_chart_legend() ?>
    <h3 style="margin-bottom:12px">Documented dental conditions</h3>
    <?php if (!$conditions): ?><p class="muted small">No conditions documented. All teeth are charted as healthy.</p><?php endif; ?>
    <div class="conditions">
      <?php foreach ($conditions as $no => $c): [$label, $color, $tint] = TOOTH_STATUSES[$c['status']]; ?>
        <div class="condition" style="--tint:<?= $tint ?>;--c:<?= $color ?>;border-color:<?= $color ?>33">
          <span class="n" style="background:<?= $color ?>"><?= $no ?></span>
          <b style="color:<?= $color ?>"><?= e($label) ?> <span class="muted small" style="font-weight:400">· <?= e(tooth_name($no)) ?></span></b>
          <span><?= e($c['procedure_name'] ?: '—') ?></span>
          <small><?= e($c['notes'] ?: 'Recorded ' . fmt_date($c['recorded_at'])) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <script type="application/json" id="tooth-history"><?= json_encode($history, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <dialog id="tooth-dialog">
    <form class="dialog-body" method="post">
      <div class="dialog-head"><h2 data-text="tooth_title">Tooth</h2><button type="button" class="close" aria-label="Close">×</button></div>
      <div><span class="label">History</span><ul class="history" style="margin-top:8px"></ul></div>
      <?php if (can('chart.edit')): ?>
        <?= csrf_field() ?><input type="hidden" name="action" value="tooth"><input type="hidden" name="tooth_no">
        <div class="field"><label for="t-status">Condition</label>
          <select id="t-status" name="status"><?php foreach (TOOTH_STATUSES as $k => [$label]): ?><option value="<?= $k ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="t-proc">Procedure</label><input type="text" id="t-proc" name="procedure_name" maxlength="120" placeholder="e.g. Composite filling" list="procedure-list" data-fee-field="amount" autocomplete="off"><?= procedure_datalist() ?></div>
        <div class="field"><label for="t-notes">Notes</label><input type="text" id="t-notes" name="notes" maxlength="255" placeholder="e.g. Composite resin, A2 shade"></div>
        <div class="field"><label for="t-amt">Charge (optional)</label><input type="number" id="t-amt" name="amount" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" placeholder="0.00"><span class="hint">Adds a treatment to this patient's bill.</span><span class="hint" data-fee-hint hidden></span></div>
        <div><button class="btn btn-primary" type="submit">Save tooth entry</button></div>
      <?php else: ?>
        <input type="hidden" name="tooth_no"><input type="hidden" name="status"><input type="hidden" name="procedure_name"><input type="hidden" name="notes">
      <?php endif; ?>
    </form>
  </dialog>

<?php elseif ($tab === 'billing'): ?>
  <section class="card">
    <div class="card-head"><h2>Account ledger</h2>
      <div class="row no-print">
        <a class="btn btn-sm" href="<?= $back('billing') ?>&amp;format=pdf">Statement PDF</a>
        <a class="btn btn-sm" href="<?= url('patients/payment.php') ?>?patient_id=<?= $id ?>">+ Log payment</a>
        <button class="btn btn-sm btn-primary" data-open="#treatment-dialog">+ Add treatment</button>
      </div>
    </div>
    <p class="muted small" style="margin:-8px 0 16px">Oldest first. The balance column shows what the patient owed after each entry.</p>
    <div class="table-wrap"><table class="table-cards">
      <thead><tr><th>Date</th><th>Description</th><th class="num">Charge</th><th class="num">Payment</th><th class="num">Balance</th><?php if (can('billing.delete')): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($ledger as $l): ?>
          <tr>
            <td><?= e(fmt_date($l['date'])) ?></td>
            <td data-label="Description"><?= e($l['what']) ?><?= $l['detail'] !== '' ? '<br><small class="muted">' . e($l['detail']) . '</small>' : '' ?></td>
            <td class="num" data-label="Charge"><?= $l['kind'] === 'treatment' ? money($l['charge']) : '' ?></td>
            <td class="num amount-paid" data-label="Payment"><?= $l['kind'] === 'payment' ? money($l['paid']) : '' ?></td>
            <td class="num" data-label="Balance"><b class="<?= $l['balance'] > 0 ? 'amount-due' : 'amount-credit' ?>"><?= money($l['balance']) ?><?= $l['balance'] < 0 ? ' credit' : '' ?></b></td>
            <?php if (can('billing.delete')): ?><td class="num"><form method="post" data-confirm="<?= $l['kind'] === 'treatment' ? 'Remove this treatment charge?' : 'Remove this payment?' ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete_<?= $l['kind'] ?>"><input type="hidden" name="row_id" value="<?= $l['id'] ?>"><button class="link-btn" aria-label="Remove">✕</button></form></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$ledger): ?><tr><td colspan="6" class="empty">No charges or payments recorded yet.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <p class="row" style="justify-content:flex-end;margin-top:16px;gap:24px">
      <span>Charged <b><?= money($patient['charged']) ?></b></span>
      <span>Paid <b><?= money($patient['paid']) ?></b></span>
      <span><?= $balance < 0 ? 'Credit' : 'Balance' ?> <b class="<?= $balance > 0 ? 'amount-due' : 'amount-credit' ?>"><?= money($balance) ?></b></span>
    </p>
  </section>

  <dialog id="treatment-dialog">
    <form class="dialog-body" method="post">
      <div class="dialog-head"><h2>Add treatment</h2><button type="button" class="close" aria-label="Close">×</button></div>
      <?= csrf_field() ?><input type="hidden" name="action" value="treatment">
      <div class="field"><label for="tr-proc">Procedure</label><input type="text" id="tr-proc" name="procedure_name" maxlength="120" required placeholder="e.g. Oral prophylaxis" list="procedure-list" data-fee-field="amount" autocomplete="off"><?= procedure_datalist() ?></div>
      <div class="row">
        <div class="field spacer"><label for="tr-tooth">Tooth # (optional)</label><input type="number" id="tr-tooth" name="tooth_no" min="1" max="32"></div>
        <div class="field spacer"><label for="tr-amt">Amount (₱)</label><input type="number" id="tr-amt" name="amount" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" required></div>
      </div>
      <p class="hint" data-fee-hint hidden></p>
      <div class="row">
        <div class="field spacer"><label for="tr-date">Date</label><input type="date" id="tr-date" name="performed_on" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field spacer"><label for="tr-dent">Dentist</label>
          <select id="tr-dent" name="dentist_id"><option value="">—</option><?php foreach (dentist_options() as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $user['id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label for="tr-notes">Notes</label><input type="text" id="tr-notes" name="notes" maxlength="255"></div>
      <div><button class="btn btn-primary" type="submit">Save treatment</button></div>
    </form>
  </dialog>

<?php else: ?>
  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Patient information</h2><a class="btn btn-sm no-print" href="<?= url('patients/form.php') ?>?id=<?= $id ?>">Edit</a></div>
      <dl class="info-grid">
        <?php foreach ([
            'Record ID' => $patient['record_no'], 'Birth date' => fmt_date($patient['birth_date']), 'Sex' => ucfirst((string) $patient['sex']),
            'Phone' => $patient['phone'], 'Email' => $patient['email'], 'Address' => $patient['address'],
            'Emergency contact' => trim(($patient['emergency_name'] ?? '') . ($patient['emergency_relationship'] ? ' (' . $patient['emergency_relationship'] . ')' : '')),
            'Emergency phone' => $patient['emergency_phone'],
            'Consent' => $patient['consent_signed'] ? 'Signed ' . fmt_date($patient['consent_date']) : 'Not signed',
            'Allergies' => $patient['allergies'], 'Conditions' => $patient['conditions'], 'Medications' => $patient['medications'],
            'Dental history' => $patient['dental_history'], 'Notes' => $patient['notes'],
        ] as $label => $value): ?>
          <div><dt><?= e($label) ?></dt><dd><?= e($value !== null && $value !== '' ? $value : '—') ?></dd></div>
        <?php endforeach; ?>
      </dl>
    </section>

    <section class="card">
      <div class="card-head"><h2>Appointment history</h2><a class="btn btn-sm btn-primary no-print" href="<?= url('appointments/form.php') ?>?patient_id=<?= $id ?>">+ Book</a></div>
      <div class="table-wrap"><table class="table-cards">
        <thead><tr><th>Date</th><th>Procedure</th><th>Dentist</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td><?= e(fmt_date($a['starts_at'])) ?> · <?= fmt_time($a['starts_at']) ?></td>
              <td data-label="Procedure"><?= e($a['procedure_name']) ?></td>
              <td data-label="Dentist"><?= e($a['dentist'] ?? '—') ?></td>
              <td data-label="Status"><?= pill($a['status']) ?></td>
              <td class="num"><a href="<?= url('appointments/form.php') ?>?id=<?= $a['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$appointments): ?><tr><td colspan="5" class="empty">No appointments yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
  </div>
<?php endif; ?>
<?php layout_end();
