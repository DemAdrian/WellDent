<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$id = (int) input('id');
$appt = $id ? q_row('SELECT * FROM appointments WHERE id = ?', [$id]) : null;
if ($id && !$appt) {
    http_response_code(404);
    exit('Appointment not found.');
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
    'patient_id'     => input('patient_id'),
    'date'           => valid_date(input('date')) ? input('date') : date('Y-m-d'),
    'time'           => '',
    'duration_min'   => 60,
    'procedure_name' => input('procedure'),
    'dentist_id'     => $user['role'] === 'dentist' ? $user['id'] : '',
    'chair'          => 1,
    'status'         => 'pending',
    'notes'          => '',
];

$errors = [];
if (is_post()) {
    verify_csrf();
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $start = $values['date'] . ' ' . $values['time'] . ':00';
    $duration = (int) $values['duration_min'];
    $chair = max(1, min((int) config('clinic.chairs'), (int) $values['chair']));

    if (!q_val("SELECT 1 FROM patients WHERE id = ? AND status <> 'archived'", [(int) $values['patient_id']])) $errors[] = 'Choose a patient.';
    if (!valid_date($values['date']) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $values['time'])) $errors[] = 'Enter a valid date and time.';
    if ($duration < 10 || $duration > 480) $errors[] = 'Duration must be between 10 and 480 minutes.';
    if ($values['procedure_name'] === '') $errors[] = 'Enter the procedure.';
    if (!isset(APPOINTMENT_STATUSES[$values['status']])) $errors[] = 'Choose a valid status.';
    $errors = array_merge($errors, length_errors($values, ['procedure_name' => ['Procedure', 120], 'notes' => ['Notes', 2000]]));
    if ($values['dentist_id'] !== '' && !in_array((int) $values['dentist_id'], array_map('intval', array_column(dentist_options(), 'id')), true)) {
        $errors[] = 'Choose a valid dentist.';
    }

    // Only check timing when it changes, so older bookings can still be edited (status, notes).
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
            with_booking_lock(function () use (&$errors, &$id, $appt, $values, $user, $start, $duration, $chair, $blocking) {
                if ($blocking && ($clash = appointment_clash($chair, $start, $duration, $id))) {
                    $errors[] = "Chair $chair is already booked at " . fmt_time($clash['starts_at']) . " ({$clash['full_name']}). Pick another time.";
                    return;
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
                        q('UPDATE waitlist SET resolved_at = NOW() WHERE id = ?', [(int) input('waitlist_id')]);
                    }
                }
            });
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors) {
        // Reschedule reminders only when the message, timing or status could have changed them.
        if (!$appt || $appt['starts_at'] !== $start || $appt['status'] !== $values['status']
            || (int) $appt['patient_id'] !== (int) $values['patient_id'] || $appt['procedure_name'] !== $values['procedure_name']) {
            schedule_appointment_reminders($id);
        }
        flash('success', $appt ? 'Appointment updated.' : 'Appointment booked.');
        redirect(url('appointments/') . '?date=' . $values['date']);
    }
}

// Already-booked times on the chosen day, to help pick a free slot.
$booked = valid_date($values['date']) ? q_all("SELECT a.id, a.starts_at, a.duration_min, a.chair, p.full_name FROM appointments a
    JOIN patients p ON p.id = a.patient_id WHERE DATE(a.starts_at) = ? AND a.status NOT IN ('cancelled','no_show') ORDER BY a.starts_at",
    [$values['date']]) : [];

layout_start($appt ? 'Reschedule appointment' : 'New appointment', 'appointments', [
    'subtitle' => 'Checks the chair for overlaps and schedules reminders automatically',
]);
?>
<div class="layout-side">
  <form class="card" method="post">
    <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?= csrf_field() ?>
    <input type="hidden" name="waitlist_id" value="<?= (int) input('waitlist_id') ?>">
    <div class="form-grid">
      <div class="field span-2"><label for="patient_id">Patient *</label>
        <select id="patient_id" name="patient_id" required>
          <option value="">Choose patient…</option>
          <?php foreach (patient_options() as $p): ?>
            <option value="<?= $p['id'] ?>"<?= selected($p['id'], $values['patient_id']) ?>><?= e($p['full_name']) ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <span class="hint">Not listed? <a href="<?= url('patients/form.php') ?>">Add the patient first</a>.</span>
      </div>
      <div class="field"><label for="status">Status</label>
        <select id="status" name="status"><?php foreach (APPOINTMENT_STATUSES as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, $values['status']) ?>><?= $lbl ?></option><?php endforeach; ?></select>
      </div>
      <div class="field"><label for="date">Date *</label><input type="date" id="date" name="date" value="<?= e($values['date']) ?>" required></div>
      <div class="field"><label for="time">Time *</label><input type="time" id="time" name="time" value="<?= e($values['time']) ?>" min="<?= e(config('clinic.opens')) ?>" max="<?= e(config('clinic.closes')) ?>" step="900" required></div>
      <div class="field"><label for="duration_min">Duration</label>
        <select id="duration_min" name="duration_min">
          <?php foreach ([15, 30, 45, 60, 90, 120, 180] as $m): ?><option value="<?= $m ?>"<?= selected($m, $values['duration_min']) ?>><?= $m ?> min</option><?php endforeach; ?>
        </select>
      </div>
      <div class="field"><label for="procedure_name">Procedure *</label>
        <input type="text" id="procedure_name" name="procedure_name" value="<?= e($values['procedure_name']) ?>" list="procedures" maxlength="120" required>
        <datalist id="procedures"><?php foreach (['Consultation', 'Cleaning', 'Adjustment', 'Filling', 'Extraction', 'Root canal', 'Crown fitting', 'Retainer fitting', 'Bracket repair', 'Whitening', 'X-ray'] as $proc): ?><option value="<?= $proc ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="field"><label for="dentist_id">Dentist</label>
        <select id="dentist_id" name="dentist_id"><option value="">—</option>
          <?php foreach (dentist_options() as $d): ?><option value="<?= $d['id'] ?>"<?= selected($d['id'], $values['dentist_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?>
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

  <aside class="card sticky">
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
<?php layout_end();
