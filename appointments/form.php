<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$id = (int) input('id');
$appt = $id ? q_row('SELECT * FROM appointments WHERE id = ?', [$id]) : null;
if ($id && !$appt) {
    http_response_code(404);
    exit('Appointment not found.');
}

if ($appt) {
    ensure_appointment_progress_table();
}
$dentists = dentist_options();
$dentistIds = array_map('intval', array_column($dentists, 'id'));
$rebook = null;
if (!$id && (int) input('rebook')) {
    $rebook = q_row("SELECT a.* FROM appointments a JOIN patients p ON p.id = a.patient_id
        WHERE a.id = ? AND p.status <> 'archived'", [(int) input('rebook')]);
    if (!$rebook) {
        flash('error', 'This appointment is unavailable for rebooking. Choose a patient from the list.');
        redirect(url('appointments/form.php'));
    }
}

$values = $appt ? [
    'patient_id'     => $appt['patient_id'],
    'date'           => substr($appt['starts_at'], 0, 10),
    'time'           => substr($appt['starts_at'], 11, 5),
    'duration_min'   => $appt['duration_min'],
    'procedure_name' => $appt['procedure_name'],
    'dentist_id'     => $appt['dentist_id'],
    'chair'          => $appt['chair'],
    'status'         => $appt['status'],
    'notes'          => $appt['notes'],
] : [
    'patient_id'     => $rebook['patient_id'] ?? input('patient_id'),
    'date'           => valid_date(input('date')) ? input('date') : date('Y-m-d'),
    'time'           => input('time'),
    'duration_min'   => $rebook['duration_min'] ?? max(10, min(480, (int) input('duration_min', '60'))),
    'procedure_name' => $rebook['procedure_name'] ?? input('procedure'),
    'dentist_id'     => $rebook
        ? (in_array((int) $rebook['dentist_id'], $dentistIds, true) ? $rebook['dentist_id'] : '')
        : ($user['role'] === 'dentist' ? $user['id'] : ''),
    'chair'          => max(1, min((int) config('clinic.chairs'), (int) ($rebook['chair'] ?? input('chair', '1')))),
    'status'         => 'pending',
    'notes'          => '',
];

$errors = [];
$progressError = '';
if ($appt && is_post() && input('action') === 'add_progress') {
    verify_csrf();
    $progressNote = input('progress_note');
    if ($progressNote === '' || mb_strlen($progressNote) > 2000) {
        $progressError = 'Enter a progress note of up to 2,000 characters.';
    } else {
        try {
            $saved = with_booking_lock(function () use ($id, $appt, $progressNote, $user) {
                $patientId = q_val('SELECT patient_id FROM appointments WHERE id = ?', [$id]);
                if ((int) $patientId !== (int) $appt['patient_id'] || (int) input('progress_patient_id') !== (int) $patientId) {
                    return false;
                }
                q('INSERT INTO appointment_progress (appointment_id, note, recorded_by) VALUES (?, ?, ?)',
                    [$id, $progressNote, $user['id']]);
                return true;
            });
            flash($saved ? 'success' : 'error', $saved ? 'Progress note saved.'
                : 'The appointment’s patient changed. Review it before entering a progress note.');
            redirect(url('appointments/form.php') . '?id=' . $id . '#progress');
        } catch (BookingBusyException $ex) {
            $progressError = $ex->getMessage();
        }
    }
} elseif (is_post()) {
    verify_csrf();
    if ($appt && !hash_equals(appointment_version($appt), input('appointment_version'))) {
        flash('error', 'Someone updated this appointment. Review the current details and try again.');
        redirect(url('appointments/form.php') . '?id=' . $id);
    }
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $start = $values['date'] . ' ' . $values['time'] . ':00';
    $duration = (int) $values['duration_min'];
    $chair = max(1, min((int) config('clinic.chairs'), (int) $values['chair']));

    $patientStatus = q_val('SELECT status FROM patients WHERE id = ?', [(int) $values['patient_id']]);
    if (!$patientStatus || ($patientStatus === 'archived' && (
        !$appt || (int) $appt['patient_id'] !== (int) $values['patient_id'] || !in_array($values['status'], NON_BLOCKING_STATUSES, true)
    ))) $errors[] = 'Choose an active patient.';
    if (!valid_date($values['date']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values['time'])) $errors[] = 'Enter a valid date and time.';
    if ($duration < 10 || $duration > 480) $errors[] = 'Duration must be between 10 and 480 minutes.';
    if ($values['procedure_name'] === '') $errors[] = 'Enter the procedure.';
    if (!isset(APPOINTMENT_STATUSES[$values['status']])) $errors[] = 'Choose a valid status.';
    $errors = array_merge($errors, length_errors($values, ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 2000]]));
    if ($values['dentist_id'] !== '' && (!preg_match('/^[0-9]+$/D', $values['dentist_id']) || (int) $values['dentist_id'] < 1
        || (!in_array((int) $values['dentist_id'], $dentistIds, true)
            && (int) ($appt['dentist_id'] ?? 0) !== (int) $values['dentist_id']))) {
        $errors[] = 'Choose a valid dentist.';
    }

    // Check hours on new or rescheduled bookings so older records can still be edited.
    $timingChanged = !$appt || $appt['starts_at'] !== $start || (int) $appt['duration_min'] !== $duration;
    if (!$errors && $timingChanged) {
        $startTs = strtotime($start);
        $endTs = $startTs + $duration * 60;
        $opens = strtotime($values['date'] . ' ' . config('clinic.opens'));
        $closes = strtotime($values['date'] . ' ' . config('clinic.closes'));
        if ($startTs === false || $startTs < time()) {
            $errors[] = 'That time has already passed. Pick a time later than now.';
        } elseif ($startTs < $opens || $endTs > $closes) {
            $errors[] = 'The clinic is open ' . fmt_time(config('clinic.opens')) . '–' . fmt_time(config('clinic.closes'))
                . '. This appointment would run ' . date('g:i A', $startTs) . '–' . date('g:i A', $endTs) . '.';
        }
    }

    $blocking = !in_array($values['status'], NON_BLOCKING_STATUSES, true);
    if (!$errors) {
        try {
            $stale = with_booking_lock(function () use (&$errors, &$id, $appt, $values, $user, $start, $duration, $chair, $blocking) {
                return in_transaction(function () use (&$errors, &$id, $appt, $values, $user, $start, $duration, $chair, $blocking) {
                    if ($appt) {
                        $current = q_row('SELECT * FROM appointments WHERE id = ? FOR UPDATE', [$id]);
                        if (!$current || !hash_equals(appointment_version($appt), appointment_version($current))) {
                            return true;
                        }
                    }
                    $patientStatus = q_val('SELECT status FROM patients WHERE id = ? FOR UPDATE', [(int) $values['patient_id']]);
                    if (!$patientStatus || ($patientStatus === 'archived' && (!$appt || $blocking || (int) $appt['patient_id'] !== (int) $values['patient_id']))) {
                        $errors[] = 'Choose an available patient.';
                        return false;
                    }
                    if ($appt && (int) $appt['patient_id'] !== (int) $values['patient_id']
                        && q_val('SELECT 1 FROM appointment_progress WHERE appointment_id = ? LIMIT 1', [$id])) {
                        $errors[] = 'This appointment has progress notes and must stay with its original patient.';
                        return false;
                    }
                    if ($blocking && ($clash = appointment_clash($chair, $start, $duration, $id))) {
                        $errors[] = "Chair $chair is already booked at " . fmt_time($clash['starts_at']) . " ({$clash['full_name']}). Pick another time.";
                        return false;
                    }
                    $params = [(int) $values['patient_id'], nullable($values['dentist_id']), $chair, $start, $duration,
                        $values['procedure_name'], $values['status'], nullable($values['notes'])];
                    if ($appt) {
                        q('UPDATE appointments SET patient_id = ?, dentist_id = ?, chair = ?, starts_at = ?, duration_min = ?,
                            procedure_name = ?, status = ?, notes = ? WHERE id = ?', [...$params, $id]);
                        log_activity('appointment_updated', 'appointment', $id, $start);
                    } else {
                        q('INSERT INTO appointments (patient_id, dentist_id, chair, starts_at, duration_min, procedure_name, status, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$params, $user['id']]);
                        $id = (int) db()->lastInsertId();
                        log_activity('appointment_created', 'appointment', $id, $start);
                        if ((int) input('waitlist_id')) {
                            q('UPDATE waitlist SET resolved_at = NOW() WHERE id = ? AND patient_id = ? AND resolved_at IS NULL',
                                [(int) input('waitlist_id'), (int) $values['patient_id']]);
                        }
                    }
                    if (!$appt || $appt['starts_at'] !== $start || $appt['status'] !== $values['status']
                        || (int) $appt['patient_id'] !== (int) $values['patient_id'] || $appt['procedure_name'] !== $values['procedure_name']) {
                        schedule_appointment_reminders($id);
                    }
                    return false;
                });
            });
            if ($stale) {
                flash('error', 'Someone updated this appointment. Review the current details and try again.');
                redirect(url('appointments/form.php') . '?id=' . $id);
            }
        } catch (BookingBusyException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        if ($values['status'] === 'no_show') {
            flash('success', 'Marked as no-show. The appointment no longer blocks chair time.');
        } else {
            flash('success', $appt ? 'Appointment updated.' : 'Appointment booked.');
        }
        redirect(url('appointments/') . '?date=' . $values['date']);
    }
}

$booked = valid_date($values['date']) ? q_all("SELECT a.id, a.starts_at, a.duration_min, a.chair, p.full_name FROM appointments a
    JOIN patients p ON p.id = a.patient_id WHERE DATE(a.starts_at) = ? AND a.status NOT IN ('cancelled','no_show') ORDER BY a.starts_at",
    [$values['date']]) : [];

$patients = patient_options();
if ($appt && !in_array((int) $appt['patient_id'], array_map('intval', array_column($patients, 'id')), true)) {
    $patients[] = q_row('SELECT id, full_name, phone, record_no FROM patients WHERE id = ?', [$appt['patient_id']]);
}
if ($appt && $appt['dentist_id'] && !in_array((int) $appt['dentist_id'], $dentistIds, true)) {
    $dentists[] = q_row('SELECT id, name FROM users WHERE id = ?', [$appt['dentist_id']]);
}
$progress = $appt ? q_all('SELECT p.note, p.recorded_at, u.name FROM appointment_progress p
    LEFT JOIN users u ON u.id = p.recorded_by WHERE p.appointment_id = ? ORDER BY p.id DESC', [$id]) : [];

$isArchived = $appt && (q_val('SELECT status FROM patients WHERE id = ?', [$appt['patient_id']]) === 'archived');
$headerActions = $appt && !$isArchived
    ? '<a class="btn" href="' . e(url('appointments/form.php') . '?rebook=' . $id) . '">Rebook</a>'
    : '';
layout_start($appt ? 'Appointment details' : ($rebook ? 'Rebook appointment' : 'New appointment'), 'appointments', [
    'action' => $headerActions,
]);
?>
<div class="layout-side">
  <form class="card" method="post">
    <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="waitlist_id" value="<?= (int) input('waitlist_id') ?>">
    <?php if ($appt): ?><input type="hidden" name="appointment_version" value="<?= appointment_version($appt) ?>"><?php endif; ?>
    <div class="form-grid">
      <div class="field span-2">
        <div class="field" data-select-search hidden>
          <label for="patient-search">Find an existing patient</label>
          <input id="patient-search" type="search" placeholder="Name, phone, or record ID" data-filter-select="patient_id">
        </div>
        <label for="patient_id">Patient *</label>
        <select id="patient_id" name="patient_id" required>
          <option value="">Choose patient…</option>
          <?php foreach ($patients as $p): ?>
            <option value="<?= $p['id'] ?>" data-record="<?= e($p['record_no']) ?>"<?= selected($p['id'], $values['patient_id']) ?>><?= e($p['full_name']) ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Not listed? <a href="<?= url('patients/form.php') ?>">Add the patient first</a>.</span>
      </div>
      <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><?php foreach (APPOINTMENT_STATUSES as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, $values['status']) ?>><?= $lbl ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label for="date">Date *</label><input type="date" id="date" name="date" value="<?= e($values['date']) ?>" required></div>
      <div class="field"><label for="time">Time *</label><input type="time" id="time" name="time" value="<?= e($values['time']) ?>" required></div>
      <div class="field"><label for="duration_min">Duration</label>
        <select id="duration_min" name="duration_min">
          <?php $durations = array_unique([15, 30, 45, 60, 90, 120, 180, (int) $values['duration_min']]); sort($durations); ?>
          <?php foreach ($durations as $m): ?><option value="<?= $m ?>"<?= selected($m, $values['duration_min']) ?>><?= $m ?> min</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label for="procedure_name">Procedure *</label>
        <input type="text" id="procedure_name" name="procedure_name" value="<?= e($values['procedure_name']) ?>" list="procedures" maxlength="120" required>
        <?= procedure_datalist('procedures') ?>
      </div>
      <div class="field"><label for="dentist_id">Dentist</label>
        <select id="dentist_id" name="dentist_id"><option value="">—</option>
          <?php foreach ($dentists as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $values['dentist_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (config('clinic.chairs') > 1): ?>
        <div class="field"><label for="chair">Chair</label>
          <select id="chair" name="chair"><?php for ($c = 1; $c <= config('clinic.chairs'); $c++): ?><option value="<?= $c ?>"<?= selected($c, $values['chair']) ?>>Chair <?= $c ?></option><?php endfor; ?></select>
        </div>
      <?php else: ?><input type="hidden" name="chair" value="1"><?php endif; ?>
      <div class="field span-all"><label for="notes">Notes</label><textarea id="notes" name="notes" maxlength="2000" placeholder="Anything the team should know"><?= e($values['notes']) ?></textarea></div>
    </div>
    <div class="row" style="margin-top:20px">
      <button class="btn btn-primary" type="submit"><?= $appt ? 'Save changes' : 'Book appointment' ?></button>
      <a class="btn" href="<?= url('appointments/') ?>?date=<?= e($values['date']) ?>">Cancel</a>
    </div>
  </form>

  <div class="stack">
    <?php if ($appt): ?>
      <section class="card" id="progress">
        <div class="card-head"><h2>Appointment progress</h2><a href="<?= url('patients/view.php') ?>?id=<?= $appt['patient_id'] ?>&amp;tab=chart">Dental chart</a></div>
        <?php if ($progressError): ?><p class="flash flash-error" role="alert"><?= e($progressError) ?></p><?php endif; ?>
        <form method="post" class="stack">
          <?= csrf_field() ?><input type="hidden" name="action" value="add_progress">
          <input type="hidden" name="progress_patient_id" value="<?= $appt['patient_id'] ?>">
          <div class="field"><label for="progress-note">Progress note</label><textarea id="progress-note" name="progress_note" maxlength="2000" required><?= $progressError ? e(input('progress_note')) : '' ?></textarea></div>
          <div><button class="btn btn-primary" type="submit">Save progress</button></div>
        </form>
        <?php if (!$progress): ?><p class="muted small appointment-progress-empty">No progress notes saved.</p><?php endif; ?>
        <ul class="appointment-progress">
          <?php foreach ($progress as $entry): ?>
            <li><small class="muted"><?= e(fmt_date($entry['recorded_at'], 'M j, Y · g:i A')) ?><?= $entry['name'] ? ' · ' . e($entry['name']) : '' ?></small><p><?= e($entry['note']) ?></p></li>
          <?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>
    <aside class="card">
      <h2>Booked on <?= e(fmt_date($values['date'], 'M j')) ?></h2>
      <p class="muted small" style="margin-bottom:14px">Open <?= e(fmt_time(config('clinic.opens'))) ?>–<?= e(fmt_time(config('clinic.closes'))) ?></p>
      <?php if (!$booked): ?><p class="muted small">The chair is free all day.</p><?php endif; ?>
      <ul class="kv">
        <?php foreach ($booked as $b): ?>
          <li<?= (int) $b['id'] === $id ? ' style="font-weight:600"' : '' ?>>
            <?= fmt_time($b['starts_at']) ?>–<?= date('g:i A', strtotime($b['starts_at']) + $b['duration_min'] * 60) ?> · <?= e($b['full_name']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>
  </div>
</div>
<?php layout_end();