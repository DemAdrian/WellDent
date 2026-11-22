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
ensure_dental_chart_schema(); // converts charts made before the paper-form layout
$hasAppointment = patient_has_appointment($id);
$canChart = can('chart.edit') && $hasAppointment;
$canVoid = can('billing.delete') && $hasAppointment;

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if (!$hasAppointment && in_array($action, ['tooth', 'exam', 'treatment', 'void_treatment', 'void_payment'], true)) {
        flash('error', NO_APPOINTMENT_MESSAGE);
        redirect($back(in_array($action, ['tooth', 'exam'], true) ? 'chart' : 'billing'));
    }

    if ($action === 'tooth') {
        require_perm('chart.edit');
        $tooth = (int) input('tooth_no');
        $status = input('status');
        $treatment = input('treatment');
        $surfaces = clean_surfaces($_POST['surfaces'] ?? []);
        $amountRaw = input('amount') === '' ? '0' : input('amount');
        $amount = (float) $amountRaw;
        $tooLong = length_errors(['procedure_name' => input('procedure_name'), 'notes' => input('notes')], ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 255]]);
        if (!valid_tooth($tooth)) {
            flash('error', 'Choose a tooth from the chart.');
        } elseif (($status !== '' && !isset(TOOTH_CONDITIONS[$status])) || ($treatment !== '' && !isset(TOOTH_RESTORATIONS[$treatment]) && !isset(TOOTH_SURGERY[$treatment]))) {
            flash('error', 'Choose a condition and treatment from the legend.');
        } elseif ($status === '' && $treatment === '') {
            flash('error', 'Choose a condition or a restoration/surgery for the tooth.');
        } elseif (!valid_amount($amountRaw, true)) {
            flash('error', 'Charge must be from ₱0 to ' . money(MAX_AMOUNT) . ', with at most 2 decimals.');
        } elseif ($tooLong) {
            flash('error', implode(' ', $tooLong));
        } else {
            q('INSERT INTO tooth_records (patient_id, tooth_no, status, treatment, surfaces, procedure_name, notes, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $tooth, nullable($status), nullable($treatment), $surfaces ? implode(',', $surfaces) : null,
                    nullable(input('procedure_name')), nullable(input('notes')), $user['id']]);
            if ($amount > 0) {
                q('INSERT INTO treatments (patient_id, tooth_no, procedure_name, notes, amount, performed_on, dentist_id) VALUES (?, ?, ?, ?, ?, CURDATE(), ?)',
                    [$id, $tooth, input('procedure_name') ?: tooth_code_label($treatment ?: $status), nullable(input('notes')), $amount, $user['id']]);
            }
            $codes = trim($status . ' ' . $treatment) . ($surfaces ? ' (' . implode('', $surfaces) . ')' : '');
            log_activity('tooth_charted', 'patient', $id, "Tooth $tooth: $codes");
            flash('success', "Tooth $tooth updated" . ($amount > 0 ? ' and ' . money($amount) . ' charged.' : '.'));
        }
        redirect($back('chart'));
    }

    if ($action === 'exam') {
        require_perm('chart.edit');
        $picked = fn(string $name, array $options) => implode(',', array_intersect(array_keys($options), (array) ($_POST[$name] ?? []))) ?: null;
        $text = fn(string $name, int $max) => mb_substr(trim((string) input($name)), 0, $max) ?: null;
        $exam = [
            'periodontal' => $picked('periodontal', PERIODONTAL_OPTIONS),
            'appliances' => $picked('appliances', APPLIANCE_OPTIONS), 'appliance_other' => $text('appliance_other', 120),
            'tmd' => $picked('tmd', TMD_OPTIONS),
            'xrays' => $picked('xrays', XRAY_OPTIONS), 'xray_periapical_teeth' => $text('xray_periapical_teeth', 60),
            'xray_occlusal_arch' => in_array(input('xray_occlusal_arch'), ['Upper', 'Lower', 'Upper and lower'], true) ? input('xray_occlusal_arch') : null,
            'xray_other' => $text('xray_other', 120),
        ];
        foreach (array_keys(OCCLUSION_FIELDS) as $f) {
            $exam[$f] = $text($f, 40);
        }
        $exam['updated_by'] = $user['id'];
        $cols = array_keys($exam);
        q('INSERT INTO dental_exams (patient_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols)), [$id, ...array_values($exam)]);
        log_activity('dental_exam_saved', 'patient', $id);
        flash('success', 'Examination findings saved.');
        redirect($back('chart') . '#exam');
    }

    if ($action === 'treatment') {
        $amount = (float) input('amount');
        $tooth = (int) input('tooth_no');
        $date = input('performed_on');
        $dentistId = input('dentist_id');
        $tooLong = length_errors(['procedure_name' => input('procedure_name'), 'notes' => input('notes')], ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 255]]);
        if (input('procedure_name') === '' || !valid_amount(input('amount'), true) || !valid_date($date) || ($tooth && !valid_tooth($tooth))) {
            flash('error', 'Enter a procedure, a valid date, a tooth from the chart (if any) and an amount from ₱0 to ' . money(MAX_AMOUNT) . '.');
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

    // Billing entries are never deleted. Voiding keeps the row (shown struck through in the ledger),
    // drops it from every total, and logs what it was so the change can be traced later.
    if ($action === 'void_treatment' || $action === 'void_payment') {
        require_perm('billing.delete');
        $isTreatment = $action === 'void_treatment';
        $table = $isTreatment ? 'treatments' : 'payments';
        $reason = trim((string) input('void_reason'));
        $row = q_row("SELECT * FROM $table WHERE id = ? AND patient_id = ? AND voided_at IS NULL", [(int) input('row_id'), $id]);
        if (!$row) {
            flash('error', 'That entry was not found or is already voided.');
        } elseif ($reason === '') {
            flash('error', 'Enter a reason for voiding the entry.');
        } elseif ($tooLong = length_errors(['void_reason' => $reason], ['void_reason' => ['Reason', 255]])) {
            flash('error', implode(' ', $tooLong));
        } else {
            q("UPDATE $table SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?", [$user['id'], $reason, $row['id']]);
            $what = $isTreatment
                ? $row['procedure_name'] . ($row['tooth_no'] ? ' #' . (int) $row['tooth_no'] : '') . ' ' . money($row['amount']) . ' on ' . fmt_date($row['performed_on'])
                : PAYMENT_METHODS[$row['method']] . ' payment ' . money($row['amount']) . ' on ' . fmt_date($row['paid_on']) . ($row['reference'] ? ' ref ' . $row['reference'] : '');
            log_activity($action, 'patient', $id, "$what. Reason: $reason");
            flash('success', ($isTreatment ? 'Charge' : 'Payment') . ' voided. It stays in the ledger but no longer counts toward the balance.');
        }
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
$treatments = q_all('SELECT t.*, u.name AS dentist, v.name AS voided_by_name FROM treatments t LEFT JOIN users u ON u.id = t.dentist_id LEFT JOIN users v ON v.id = t.voided_by
    WHERE t.patient_id = ? ORDER BY t.performed_on DESC, t.id DESC', [$id]);
$payments = q_all('SELECT y.*, u.name AS received, v.name AS voided_by_name FROM payments y LEFT JOIN users u ON u.id = y.received_by LEFT JOIN users v ON v.id = y.voided_by
    WHERE y.patient_id = ? ORDER BY y.paid_on DESC, y.id DESC', [$id]);

// One dated list of charges and payments, oldest first, with the balance after each line.
// Same-day entries keep the order they were entered in. Voided entries stay listed but don't count.
$voidInfo = fn(array $r) => $r['voided_at']
    ? 'Voided ' . fmt_date($r['voided_at']) . ($r['voided_by_name'] ? ' by ' . $r['voided_by_name'] : '') . ': ' . $r['void_reason']
    : null;
$ledger = [];
foreach ($treatments as $t) {
    $ledger[] = ['kind' => 'treatment', 'id' => (int) $t['id'], 'date' => $t['performed_on'], 'at' => $t['created_at'],
        'charge' => (float) $t['amount'], 'paid' => 0.0,
        'what' => $t['procedure_name'] . ($t['tooth_no'] ? ' · Tooth #' . (int) $t['tooth_no'] : ''),
        'detail' => implode(' · ', array_filter([$t['notes'], $t['dentist']])), 'void' => $voidInfo($t)];
}
foreach ($payments as $y) {
    $ledger[] = ['kind' => 'payment', 'id' => (int) $y['id'], 'date' => $y['paid_on'], 'at' => $y['created_at'],
        'charge' => 0.0, 'paid' => (float) $y['amount'],
        'what' => 'Payment · ' . PAYMENT_METHODS[$y['method']] . ($y['reference'] ? ' · Ref ' . $y['reference'] : ''),
        'detail' => implode(' · ', array_filter([$y['notes'], $y['received'] ? 'Received by ' . $y['received'] : null])), 'void' => $voidInfo($y)];
}
usort($ledger, fn($a, $b) => [$a['date'], $a['at'], $a['kind'] === 'payment', $a['id']] <=> [$b['date'], $b['at'], $b['kind'] === 'payment', $b['id']]);
$running = 0.0;
foreach ($ledger as &$entry) {
    if ($entry['void']) {
        $entry['balance'] = null;
        continue;
    }
    $running = round($running + $entry['charge'] - $entry['paid'], 2);
    $entry['balance'] = $running;
}
unset($entry);
$counted = array_values(array_filter($ledger, fn($l) => !$l['void']));
$treatmentCount = count(array_filter($counted, fn($l) => $l['kind'] === 'treatment'));

if (input('format') === 'pdf' && !$hasAppointment) {
    flash('error', NO_APPOINTMENT_MESSAGE);
    redirect($back('billing'));
}
if (input('format') === 'pdf') {
    require dirname(__DIR__) . '/includes/pdf.php';
    $due = '#e0785f';
    $credit = '#1f8a80';
    $bal = (float) $patient['balance'];
    $pdf = new PdfReport('Statement of account', $patient['full_name'] . ($patient['record_no'] ? ' · ' . $patient['record_no'] : '') . ' · As of ' . date('M j, Y'),
        config('clinic.name'), 'Generated ' . date('M j, Y g:i A') . ' by ' . $user['name']);
    $pdf->stats([
        ['Total charged', money($patient['charged']), $treatmentCount . ' treatment(s)'],
        ['Total paid', money($patient['paid']), (count($counted) - $treatmentCount) . ' payment(s)'],
        [$bal < 0 ? 'Credit' : 'Balance due', money($bal), $bal < 0 ? 'Held for future treatment' : ($bal > 0 ? 'Amount still owed' : 'Fully paid')],
    ]);
    $pdf->table('Charges and payments', [['Date', 2, 'L'], ['Description', 6, 'L'], ['Charge', 2, 'R'], ['Payment', 2, 'R'], ['Balance', 2.8, 'R']],
        array_map(fn($l) => [
            fmt_date($l['date']),
            $l['what'] . ($l['detail'] !== '' ? ' · ' . $l['detail'] : ''),
            $l['kind'] === 'treatment' ? money($l['charge']) : '',
            $l['kind'] === 'payment' ? [money($l['paid']), $credit] : '',
            [money($l['balance']) . ($l['balance'] < 0 ? ' CR' : ''), $l['balance'] > 0 ? $due : $credit],
        ], $counted), 'No charges or payments recorded.');
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
        $toothState[$no] = ['status' => $log['status'], 'treatment' => $log['treatment'], 'surfaces' => $log['surfaces']];
        $toothCurrent[$no] = $log;
    }
    $history[$no][] = ['status' => $log['status'], 'treatment' => $log['treatment'], 'surfaces' => $log['surfaces'],
        'procedure' => $log['procedure_name'], 'notes' => $log['notes'],
        'by' => $log['by_name'], 'at' => date('M j, Y', strtotime($log['recorded_at']))];
}
ksort($toothCurrent);
// Everything but a plain "present" tooth is worth listing under the chart.
$conditions = array_filter($toothCurrent, fn($l) => $l['status'] !== '/' || $l['treatment'] || $l['surfaces']);
$exam = q_row('SELECT e.*, u.name AS by_name FROM dental_exams e LEFT JOIN users u ON u.id = e.updated_by WHERE e.patient_id = ?', [$id]) ?: [];

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
<div class="toolbar no-print">
  <a class="back" href="<?= url('patients/') ?>">← Back to Patients</a>
  <form method="get" action="<?= url('patients/') ?>" class="row patient-picker" role="search">
    <input type="search" name="q" aria-label="Find another patient" placeholder="Find another patient…" required>
    <button class="btn" type="submit">Search</button>
  </form>
</div>

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
      <?php if ($patient['status'] !== 'archived'): ?>
      <a class="btn btn-sm" href="<?= url('appointments/form.php') ?>?patient_id=<?= $id ?>">Book appointment</a>
    <?php endif; ?>
    <?php if ($hasAppointment): ?><a class="btn btn-sm" href="<?= url('patients/payment.php') ?>?patient_id=<?= $id ?>">Log payment</a><?php endif; ?>
    <?php if ($patient['status'] === 'archived' && can('patients.archive')): ?>
      <form method="post" data-confirm="Restore this patient?">
        <?= csrf_field() ?><input type="hidden" name="action" value="restore">
        <button class="btn btn-sm" type="submit">Restore</button>
      </form>
    <?php endif; ?>
  </div>
</section>

<div class="mini-stats">
  <div class="card"><strong><?= count($appointments) ?></strong><span>Appointments</span></div>
  <div class="card"><strong><?= $treatmentCount ?></strong><span>Treatments</span></div>
  <div class="card"><strong><?= money($patient['charged']) ?></strong><span>Total charged</span></div>
  <div class="card"><strong><?= money($patient['paid']) ?></strong><span>Total paid</span></div>
</div>

<nav class="tabs no-print">
  <a href="<?= $back('chart') ?>" class="<?= $tab === 'chart' ? 'active' : '' ?>">Dental Chart</a>
  <a href="<?= $back('billing') ?>" class="<?= $tab === 'billing' ? 'active' : '' ?>">Treatments &amp; Billing</a>
  <a href="<?= $back('info') ?>" class="<?= $tab === 'info' ? 'active' : '' ?>">Patient Info</a>
</nav>

<?php if (!$hasAppointment && $tab !== 'info'): ?>
  <div class="flash flash-info no-appointment-notice no-print" role="status">
    <span><b>No appointment yet.</b> The dental chart and billing are view-only until this patient has been booked.</span>
    <?php if ($patient['status'] !== 'archived'): ?><a class="btn btn-sm btn-primary" href="<?= url('appointments/form.php') ?>?patient_id=<?= $id ?>">Book appointment</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($tab === 'chart'): ?>
  <section class="card">
    <div class="card-head"><h2>Dental Record Chart</h2><span class="muted small">FDI numbering</span></div>
    <p class="muted small" style="margin:6px 0 16px"><?= $canChart ? 'Click any tooth to view its history, set its status boxes and mark affected surfaces.'
        : (!$hasAppointment ? 'Click any tooth to view its history. Book an appointment to update the chart.' : 'Click any tooth to view its history. Only dentists can change the chart.') ?></p>
    <?= render_dental_chart($toothState) ?>
    <?= render_chart_legend() ?>
    <h3 style="margin-bottom:12px">Charted teeth</h3>
    <?php if (!$conditions): ?><p class="muted small">No teeth charted yet.</p><?php endif; ?>
    <div class="conditions">
      <?php foreach ($conditions as $no => $c):
          $main = $c['treatment'] ?: $c['status'];
          $color = tooth_code_color($main); ?>
        <div class="condition" style="--tint:color-mix(in srgb, <?= $color ?> 9%, var(--panel));--c:<?= $color ?>;border-color:color-mix(in srgb, <?= $color ?> 25%, transparent)">
          <span class="n" style="background:<?= $color ?>"><?= $no ?></span>
          <b><?php foreach (array_filter([$c['status'], $c['treatment']]) as $code): ?><span class="code-chip" style="--c:<?= tooth_code_color($code) ?>"><?= e($code) ?></span><?php endforeach; ?>
            <?= e(implode(' · ', array_filter([tooth_code_label($c['status'] === '/' && $c['treatment'] ? null : $c['status']), tooth_code_label($c['treatment'])]))) ?>
            <span class="muted small" style="font-weight:400">· <?= e(tooth_name($no)) ?><?= $c['surfaces'] ? ' · surfaces ' . e(str_replace(',', '', $c['surfaces'])) : '' ?></span></b>
          <span><?= e($c['procedure_name'] ?: '—') ?></span>
          <small><?= e($c['notes'] ?: 'Recorded ' . fmt_date($c['recorded_at'])) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php
  $examChecks = function (string $name, array $options) use ($exam, $canChart): string {
      $on = array_filter(explode(',', (string) ($exam[$name] ?? '')));
      $out = '<div class="check-grid exam-checks">';
      foreach ($options as $key => $label) {
          $out .= '<label class="check-chip"><input type="checkbox" name="' . $name . '[]" value="' . $key . '"' . (in_array($key, $on, true) ? ' checked' : '')
              . ($canChart ? '' : ' disabled') . '><span>' . e($label) . '</span></label>';
      }
      return $out . '</div>';
  };
  $examText = fn(string $name, string $label, string $placeholder = '') => '<div class="field"><label for="ex-' . $name . '">' . e($label) . '</label><input type="text" id="ex-' . $name . '" name="' . $name . '" value="' . e($exam[$name] ?? '') . '" placeholder="' . e($placeholder) . '" maxlength="120"' . ($canChart ? '' : ' disabled') . '></div>';
  ?>
  <section class="card" id="exam" style="margin-top:20px">
    <div class="card-head"><h2>Examination</h2>
      <?php if (!empty($exam['updated_at'])): ?><span class="muted small">Updated <?= e(fmt_date($exam['updated_at'], 'M j, Y')) ?><?= $exam['by_name'] ? ' by ' . e($exam['by_name']) : '' ?></span><?php endif; ?></div>
    <form method="post" class="exam-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="exam">
      <fieldset class="exam-box"><legend>Periodontal Screening</legend><?= $examChecks('periodontal', PERIODONTAL_OPTIONS) ?></fieldset>
      <fieldset class="exam-box"><legend>Occlusion</legend>
        <div class="form-grid exam-fields">
          <?php foreach (['molar_class', 'canine_class'] as $f): ?>
            <div class="field"><label for="ex-<?= $f ?>"><?= e(OCCLUSION_FIELDS[$f]) ?></label>
              <select id="ex-<?= $f ?>" name="<?= $f ?>"<?= $canChart ? '' : ' disabled' ?>><option value="">—</option>
                <?php foreach (OCCLUSION_CLASSES as $cls): ?><option<?= selected($cls, $exam[$f] ?? '') ?>><?= $cls ?></option><?php endforeach; ?>
              </select></div>
          <?php endforeach; ?>
          <?= $examText('overjet', 'Overjet', 'e.g. 3 mm') ?>
          <?= $examText('overbite', 'Overbite', 'e.g. 2 mm') ?>
          <?= $examText('midline_deviation', 'Midline Deviation', 'e.g. 1 mm to the left') ?>
          <?= $examText('crossbite', 'Crossbite', 'e.g. Anterior, tooth 12') ?>
        </div>
      </fieldset>
      <fieldset class="exam-box"><legend>Appliances</legend><?= $examChecks('appliances', APPLIANCE_OPTIONS) ?><?= $examText('appliance_other', 'Others') ?></fieldset>
      <fieldset class="exam-box"><legend>TMD</legend><?= $examChecks('tmd', TMD_OPTIONS) ?></fieldset>
      <fieldset class="exam-box exam-wide"><legend>X-ray Taken</legend>
        <?= $examChecks('xrays', XRAY_OPTIONS) ?>
        <div class="form-grid exam-fields">
          <?= $examText('xray_periapical_teeth', 'Periapical: tooth no.', 'e.g. 36, 46') ?>
          <div class="field"><label for="ex-occ">Occlusal</label>
            <select id="ex-occ" name="xray_occlusal_arch"<?= $canChart ? '' : ' disabled' ?>><option value="">—</option>
              <?php foreach (['Upper', 'Lower', 'Upper and lower'] as $arch): ?><option<?= selected($arch, $exam['xray_occlusal_arch'] ?? '') ?>><?= $arch ?></option><?php endforeach; ?>
            </select></div>
          <?= $examText('xray_other', 'Others') ?>
        </div>
      </fieldset>
      <?php if ($canChart): ?><div class="exam-wide"><button class="btn btn-primary" type="submit">Save examination</button></div><?php endif; ?>
    </form>
  </section>

  <script type="application/json" id="tooth-history"><?= json_encode($history, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <dialog id="tooth-dialog">
    <form class="dialog-body" method="post">
      <div class="dialog-head"><h2 data-text="tooth_title">Tooth</h2><button type="button" class="close" aria-label="Close">×</button></div>
      <div><span class="label">History</span><ul class="history" style="margin-top:8px"></ul></div>
      <?php if ($canChart): ?>
        <?= csrf_field() ?><input type="hidden" name="action" value="tooth"><input type="hidden" name="tooth_no">
        <div class="row">
          <div class="field spacer"><label for="t-status">Condition</label>
            <select id="t-status" name="status"><option value="">— None —</option><?php foreach (TOOTH_CONDITIONS as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e("$k  ·  $label") ?></option><?php endforeach; ?></select></div>
          <div class="field spacer"><label for="t-treatment">Restoration / surgery</label>
            <select id="t-treatment" name="treatment"><option value="">— None —</option><?php foreach (TOOTH_RESTORATIONS + TOOTH_SURGERY as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e("$k  ·  $label") ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><span class="label">Surfaces</span>
          <div class="surface-picker">
            <?php foreach (TOOTH_SURFACES as $k => $label): ?><label class="check-chip"><input type="checkbox" name="surfaces[]" value="<?= $k ?>"><span><b><?= $k ?></b> <?= e($label) ?></span></label><?php endforeach; ?>
          </div>
          <span class="hint">Marked surfaces are coloured red for decay and blue for restorations.</span></div>
        <div class="field"><label for="t-proc">Procedure</label><input type="text" id="t-proc" name="procedure_name" maxlength="120" placeholder="e.g. Composite filling" list="procedure-list" data-fee-field="amount" autocomplete="off"><?= procedure_datalist() ?></div>
        <div class="field"><label for="t-notes">Notes</label><input type="text" id="t-notes" name="notes" maxlength="255" placeholder="e.g. Composite resin, A2 shade"></div>
        <div class="field"><label for="t-amt">Charge (optional)</label><input type="number" id="t-amt" name="amount" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" placeholder="0.00"><span class="hint">Adds a treatment to this patient's bill.</span><span class="hint" data-fee-hint hidden></span></div>
        <div><button class="btn btn-primary" type="submit">Save tooth entry</button></div>
      <?php else: ?>
        <input type="hidden" name="tooth_no"><input type="hidden" name="status"><input type="hidden" name="treatment"><input type="hidden" name="procedure_name"><input type="hidden" name="notes">
      <?php endif; ?>
    </form>
  </dialog>

<?php elseif ($tab === 'billing'): ?>
  <section class="card">
    <div class="card-head"><h2>Account ledger</h2>
      <div class="row no-print">
        <?php if ($hasAppointment): ?>
          <a class="btn btn-sm" href="<?= $back('billing') ?>&amp;format=pdf">Statement PDF</a>
          <a class="btn btn-sm" href="<?= url('patients/payment.php') ?>?patient_id=<?= $id ?>">+ Log payment</a>
          <button class="btn btn-sm btn-primary" data-open="#treatment-dialog">+ Add treatment</button>
        <?php endif; ?>
      </div>
    </div>
    <p class="muted small" style="margin:-8px 0 16px">Oldest first. The balance column shows what the patient owed after each entry. Voided entries are struck through and not counted.</p>
    <div class="table-wrap"><table class="table-cards">
      <thead><tr><th>Date</th><th>Description</th><th class="num">Charge</th><th class="num">Payment</th><th class="num">Balance</th><?php if ($canVoid): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($ledger as $l): ?>
          <?php $strike = fn(string $html) => $l['void'] ? "<s>$html</s>" : $html; ?>
          <tr<?= $l['void'] ? ' class="voided"' : '' ?>>
            <td><?= e(fmt_date($l['date'])) ?></td>
            <td data-label="Description"><?= $strike(e($l['what'])) ?><?= $l['detail'] !== '' ? '<br><small class="muted">' . e($l['detail']) . '</small>' : '' ?><?= $l['void'] ? '<br><small class="void-note">' . e($l['void']) . '</small>' : '' ?></td>
            <td class="num" data-label="Charge"><?= $l['kind'] === 'treatment' ? $strike(money($l['charge'])) : '' ?></td>
            <td class="num<?= $l['void'] ? '' : ' amount-paid' ?>" data-label="Payment"><?= $l['kind'] === 'payment' ? $strike(money($l['paid'])) : '' ?></td>
            <td class="num" data-label="Balance"><?php if ($l['void']): ?><span class="muted small">Not counted</span><?php else: ?><b class="<?= $l['balance'] > 0 ? 'amount-due' : 'amount-credit' ?>"><?= money($l['balance']) ?><?= $l['balance'] < 0 ? ' credit' : '' ?></b><?php endif; ?></td>
            <?php if ($canVoid): ?><td class="num no-print"><?php if (!$l['void']): ?><button class="link-btn" data-open="#void-dialog" data-fill="<?= e(json_encode([
                'action' => 'void_' . $l['kind'], 'row_id' => $l['id'], 'void_reason' => '',
                'void_title' => $l['what'] . ' · ' . money($l['kind'] === 'treatment' ? $l['charge'] : $l['paid']) . ' · ' . fmt_date($l['date']),
            ])) ?>">Void</button><?php endif; ?></td><?php endif; ?>
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
        <div class="field spacer"><label for="tr-tooth">Tooth (optional)</label>
          <select id="tr-tooth" name="tooth_no"><option value="">—</option><?php foreach (tooth_options() as $no => $label): ?><option value="<?= $no ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
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

  <?php if ($canVoid): ?>
    <dialog id="void-dialog">
      <form class="dialog-body" method="post">
        <div class="dialog-head"><h2>Void entry</h2><button type="button" class="close" aria-label="Close">×</button></div>
        <?= csrf_field() ?><input type="hidden" name="action"><input type="hidden" name="row_id">
        <p><b data-text="void_title"></b></p>
        <p class="muted small">The entry stays in the ledger, struck through, and stops counting toward the balance. This can't be undone; if it was voided by mistake, enter it again.</p>
        <div class="field"><label for="void-reason">Reason</label><input type="text" id="void-reason" name="void_reason" maxlength="255" required placeholder="e.g. Wrong amount, re-entered as ₱1,500"></div>
        <div><button class="btn btn-danger" type="submit">Void entry</button></div>
      </form>
    </dialog>
  <?php endif; ?>

<?php else: ?>
  <div class="stack">
    <?php
    $p = $patient;
    $allergyAll = array_merge(option_labels($p['allergy_list'], ALLERGY_OPTIONS), $p['allergies'] ? [$p['allergies']] : []);
    $conditionAll = array_merge(option_labels($p['medical_conditions'], MEDICAL_CONDITIONS), $p['conditions'] ? [$p['conditions']] : []);
    $infoSections = [
        'Patient information' => [
            'Record ID' => $p['record_no'], 'Last name' => $p['last_name'], 'First name' => $p['first_name'], 'Middle name' => $p['middle_name'],
            'Birthdate' => $p['birth_date'] ? fmt_date($p['birth_date']) . ' (' . age($p['birth_date']) . ' years old)' : null,
            'Sex' => ucfirst((string) $p['sex']), 'Nickname' => $p['nickname'], 'Religion' => $p['religion'], 'Nationality' => $p['nationality'],
            'Mobile no.' => $p['phone'], 'Email address' => $p['email'], 'Occupation' => $p['occupation'], 'Home address' => $p['address'],
            'Parent/guardian' => trim($p['guardian_name'] . ($p['guardian_occupation'] ? ' (' . $p['guardian_occupation'] . ')' : '')),
            'Referred by' => $p['referred_by'], 'Reason for consultation' => $p['consultation_reason'],
        ] + ($p['emergency_name'] || $p['emergency_phone'] ? [
            'Emergency contact' => trim($p['emergency_name'] . ($p['emergency_relationship'] ? ' (' . $p['emergency_relationship'] . ')' : '') . ($p['emergency_phone'] ? ' · ' . $p['emergency_phone'] : '')),
        ] : []),
        'Dental history' => [
            'Previous dentist' => $p['previous_dentist'], 'Last dental visit' => $p['last_dental_visit'], 'Other dental history' => $p['dental_history'],
        ],
        'Medical history' => [
            'Physician' => trim($p['physician_name'] . ($p['physician_specialty'] ? ' · ' . $p['physician_specialty'] : '')),
            'Physician’s office' => trim($p['physician_address'] . ($p['physician_phone'] ? ' · ' . $p['physician_phone'] : ''), ' ·'),
            'Blood type' => $p['blood_type'], 'Blood pressure' => $p['blood_pressure'],
            'Allergies' => implode(', ', $allergyAll), 'Conditions' => implode(', ', $conditionAll),
        ],
    ];
    $answers = [];
    foreach (MEDICAL_YES_NO as $name => [$no, $question, $follow]) {
        $answers[] = ["$no. $question", yes_no_label($p[$name]), $follow && (int) $p[$name] === 1 ? $p[$follow[0]] : null];
    }
    if ($p['sex'] === 'female') {
        foreach (WOMEN_YES_NO as $name => $question) $answers[] = ["9. $question", yes_no_label($p[$name]), null];
    }
    ?>
    <section class="card">
      <div class="card-head"><h2>Patient Information Record</h2><a class="btn btn-sm no-print" href="<?= url('patients/form.php') ?>?id=<?= $id ?>">Edit</a></div>
      <?php if ($allergyAll || $conditionAll): ?>
        <div class="flash flash-error medical-alert" role="note"><b>Medical alert:</b>
          <?= e(implode(' · ', array_filter([$allergyAll ? 'Allergic to ' . implode(', ', $allergyAll) : '', $conditionAll ? implode(', ', $conditionAll) : '']))) ?></div>
      <?php endif; ?>
      <?php foreach ($infoSections as $heading => $items): ?>
        <h3 class="info-heading"><?= e($heading) ?></h3>
        <dl class="info-grid">
          <?php foreach ($items as $label => $value): ?>
            <div><dt><?= e($label) ?></dt><dd><?= e($value !== null && $value !== '' ? $value : '—') ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <?php if ($heading === 'Medical history'): ?>
          <ul class="answer-list">
            <?php foreach ($answers as [$q, $a, $detail]): ?>
              <li><span><?= e($q) ?><?php if ($detail): ?><small class="muted"><?= e($detail) ?></small><?php endif; ?></span>
                <b class="answer answer-<?= strtolower($a === '—' ? 'none' : $a) ?>"><?= e($a) ?></b></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endforeach; ?>
      <h3 class="info-heading">Clinic use</h3>
      <dl class="info-grid">
        <div><dt>Signature &amp; date</dt><dd><?= e($p['consent_signed'] ? 'Signed ' . fmt_date($p['consent_date']) : 'Not signed') ?></dd></div>
        <div><dt>Care type</dt><dd><?= e($p['care_type']) ?></dd></div>
        <div><dt>Notes</dt><dd><?= e($p['notes'] ?: '—') ?></dd></div>
      </dl>
    </section>

    <section class="card">
      <div class="card-head"><h2>Appointment history</h2><?php if ($patient['status'] !== 'archived'): ?><a class="btn btn-sm btn-primary no-print" href="<?= url('appointments/form.php') ?>?patient_id=<?= $id ?>">+ Book</a><?php endif; ?></div>
      <div class="table-wrap"><table class="table-cards">
        <thead><tr><th>Date</th><th>Procedure</th><th>Dentist</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($appointments as $a): ?>
            <tr>
              <td><?= e(fmt_date($a['starts_at'])) ?> · <?= fmt_time($a['starts_at']) ?></td>
              <td data-label="Procedure"><?= e($a['procedure_name']) ?></td>
              <td data-label="Dentist"><?= e($a['dentist'] ?? '—') ?></td>
              <td data-label="Status"><?= pill($a['status']) ?></td>
              <td class="num"><a href="<?= url('appointments/form.php') ?>?id=<?= $a['id'] ?>">Open</a>
                <?php if ($patient['status'] !== 'archived'): ?> · <a href="<?= url('appointments/form.php') ?>?rebook=<?= $a['id'] ?>">Rebook</a><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$appointments): ?><tr><td colspan="5" class="empty">No appointments yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
  </div>
<?php endif; ?>
<?php layout_end();
