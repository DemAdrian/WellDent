<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$view = input('view', 'day') === 'week' ? 'week' : 'day';
$date = valid_date(input('date')) ? input('date') : date('Y-m-d');
$self = fn(array $extra = []) => 'appointments.php?' . http_build_query(array_merge(['view' => $view, 'date' => $date], $extra));

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'status') {
        $id = (int) input('id');
        $status = input('status');
        $appt = q_row('SELECT * FROM appointments WHERE id = ?', [$id]);
        if ($appt && isset(APPOINTMENT_STATUSES[$status])) {
            try {
                // Re-opening a cancelled or no-show slot must not double-book the chair.
                $clash = with_booking_lock(function () use ($appt, $status) {
                    $reopening = in_array($appt['status'], NON_BLOCKING_STATUSES, true) && !in_array($status, NON_BLOCKING_STATUSES, true);
                    $clash = $reopening ? appointment_clash((int) $appt['chair'], $appt['starts_at'], (int) $appt['duration_min'], (int) $appt['id']) : null;
                    if (!$clash) {
                        q('UPDATE appointments SET status = ? WHERE id = ?', [$status, $appt['id']]);
                    }
                    return $clash;
                });
                if ($clash) {
                    flash('error', "Chair {$appt['chair']} is already booked at " . fmt_time($clash['starts_at']) . " ({$clash['full_name']}). Reschedule this appointment instead.");
                } else {
                    schedule_appointment_reminders($id); // cancels them for closed statuses, restores them when re-opened
                    log_activity('appointment_status', 'appointment', $id, $status);
                    flash('success', 'Status updated to ' . APPOINTMENT_STATUSES[$status] . '.');
                }
            } catch (RuntimeException $ex) {
                flash('error', $ex->getMessage());
            }
        }
    } elseif ($action === 'waitlist_add') {
        $patientId = (int) input('patient_id');
        $validPatient = q_val("SELECT 1 FROM patients WHERE id = ? AND status <> 'archived'", [$patientId]);
        if (!$validPatient || input('procedure_name') === '') {
            flash('error', 'Choose a patient and enter the procedure.');
        } elseif (mb_strlen(input('procedure_name')) > 120 || mb_strlen(input('notes')) > 255) {
            flash('error', 'Keep the procedure to 120 characters and notes to 255.');
        } else {
            q('INSERT INTO waitlist (patient_id, procedure_name, notes) VALUES (?, ?, ?)',
                [$patientId, input('procedure_name'), nullable(input('notes'))]);
            flash('success', 'Added to the waitlist.');
        }
    } elseif ($action === 'waitlist_remove') {
        q('UPDATE waitlist SET resolved_at = NOW() WHERE id = ?', [(int) input('id')]);
        flash('success', 'Removed from the waitlist.');
    } elseif ($action === 'notice_add' && can('notices.manage')) {
        $from = input('starts_on');
        $to = input('ends_on') ?: $from;
        if (input('message') !== '' && mb_strlen(input('message')) <= 255 && valid_date($from) && valid_date($to) && $to >= $from) {
            q('INSERT INTO notices (message, starts_on, ends_on, created_by) VALUES (?, ?, ?, ?)',
                [input('message'), $from, $to, current_user()['id']]);
            flash('success', 'Notice posted.');
        } else {
            flash('error', 'Enter a message and a valid date range.');
        }
    } elseif ($action === 'notice_remove' && can('notices.manage')) {
        q('DELETE FROM notices WHERE id = ?', [(int) input('id')]);
        flash('success', 'Notice removed.');
    }
    redirect($self());
}

if ($view === 'week') {
    $rangeStart = date('Y-m-d', strtotime('monday this week', strtotime($date)));
    $rangeEnd = date('Y-m-d', strtotime($rangeStart . ' +6 day'));
    $prev = date('Y-m-d', strtotime($date . ' -7 day'));
    $next = date('Y-m-d', strtotime($date . ' +7 day'));
    $label = fmt_date($rangeStart, 'M j') . ' – ' . fmt_date($rangeEnd, 'M j, Y');
} else {
    $rangeStart = $rangeEnd = $date;
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $label = fmt_date($date, 'M j, Y');
}

$appointments = q_all('SELECT a.*, p.full_name, p.phone FROM appointments a JOIN patients p ON p.id = a.patient_id
    WHERE a.starts_at >= ? AND a.starts_at < ? + INTERVAL 1 DAY ORDER BY a.starts_at, a.chair',
    [$rangeStart, $rangeEnd]);

$notices = q_all('SELECT * FROM notices WHERE starts_on <= ? AND ends_on >= ? ORDER BY starts_on', [$rangeEnd, $rangeStart]);
$waitlist = q_all('SELECT w.*, p.full_name FROM waitlist w JOIN patients p ON p.id = w.patient_id
    WHERE w.resolved_at IS NULL ORDER BY w.created_at');

// Capacity for the selected day: booked minutes vs. open chair hours.
$active = array_filter($appointments, fn($a) => !in_array($a['status'], ['cancelled', 'no_show'], true));
$openMinutes = (strtotime(config('clinic.closes')) - strtotime(config('clinic.opens'))) / 60 * config('clinic.chairs');
$bookedMinutes = array_sum(array_column($view === 'day' ? $active : [], 'duration_min'));
$capacity = $openMinutes > 0 ? min(100, (int) round($bookedMinutes / $openMinutes * 100)) : 0;
$counts = array_count_values(array_column($appointments, 'status'));

layout_start('Appointments', 'appointments', [
    'subtitle' => 'Chair-aware scheduling with live confirmations and delays',
    'action'   => '<a class="btn btn-primary" href="appointment_form.php?date=' . e($date) . '">+ New appointment</a>',
]);
?>
<div class="toolbar">
  <div class="row">
    <a class="btn<?= $view === 'day' ? ' is-on' : '' ?>" href="appointments.php?view=day&amp;date=<?= e($date) ?>">Day</a>
    <a class="btn<?= $view === 'week' ? ' is-on' : '' ?>" href="appointments.php?view=week&amp;date=<?= e($date) ?>">Week</a>
    <a class="btn" href="appointments.php?view=<?= $view ?>">Today</a>
  </div>
  <div class="row">
    <a class="btn" href="<?= e($self(['date' => $prev])) ?>" aria-label="Previous">‹</a>
    <form method="get" class="row">
      <input type="hidden" name="view" value="<?= $view ?>">
      <input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()" aria-label="Jump to date" style="width:auto">
    </form>
    <a class="btn" href="<?= e($self(['date' => $next])) ?>" aria-label="Next">›</a>
  </div>
</div>

<?php foreach ($notices as $n): ?>
  <div class="banner">
    <p><?= e($n['message']) ?> <span class="small">(<?= e(fmt_date($n['starts_on'], 'M j')) ?><?= $n['ends_on'] !== $n['starts_on'] ? ' – ' . e(fmt_date($n['ends_on'], 'M j')) : '' ?>)</span></p>
    <?php if (can('notices.manage')): ?>
      <form method="post" data-confirm="Remove this notice?">
        <?= csrf_field() ?><input type="hidden" name="action" value="notice_remove"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button class="btn btn-sm" type="submit">Dismiss</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<div class="layout-side">
  <div>
    <?php if ($view === 'day'): ?>
      <div class="day-table">
        <div class="day-head"><span>TIME</span><span>CHAIR<?= config('clinic.chairs') > 1 ? 'S' : ' 1' ?> · <?= round($bookedMinutes / 60, 1) ?> of <?= round($openMinutes / 60, 1) ?> hours booked</span></div>
        <?php if (!$appointments): ?>
          <div class="slot"><span class="t"></span><p class="open-slot">Nothing booked on this day. <a href="appointment_form.php?date=<?= e($date) ?>">Book an appointment</a></p></div>
        <?php endif; ?>
        <?php foreach ($appointments as $a): ?>
          <div class="slot">
            <span class="t"><?= fmt_time($a['starts_at']) ?></span>
            <div class="appt <?= e($a['status']) ?>">
              <div class="appt-top"><strong><?= e($a['full_name']) ?></strong><?= pill($a['status']) ?></div>
              <div class="meta"><?= e($a['procedure_name']) ?> · <?= (int) $a['duration_min'] ?> min<?= config('clinic.chairs') > 1 ? ' · Chair ' . (int) $a['chair'] : '' ?><?= $a['notes'] ? ' · ' . e($a['notes']) : '' ?></div>
              <div class="actions">
                <a href="appointment_form.php?id=<?= $a['id'] ?>">Reschedule</a> ·
                <form method="post" class="row" style="display:inline-flex">
                  <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $a['id'] ?>">
                  <label for="st<?= $a['id'] ?>" style="font-weight:600;color:var(--teal-d)">Update status</label>
                  <select id="st<?= $a['id'] ?>" name="status" data-autosubmit>
                    <?php foreach (APPOINTMENT_STATUSES as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, $a['status']) ?>><?= $lbl ?></option><?php endforeach; ?>
                  </select>
                  <noscript><button class="btn btn-sm">Save</button></noscript>
                </form> ·
                <a href="patient.php?id=<?= $a['patient_id'] ?>">View patient</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="week">
        <?php for ($i = 0; $i < 7; $i++):
            $d = date('Y-m-d', strtotime("$rangeStart +$i day"));
            $dayAppts = array_filter($appointments, fn($a) => substr($a['starts_at'], 0, 10) === $d); ?>
          <div class="week-day<?= $d === date('Y-m-d') ? ' today' : '' ?>">
            <h3><a href="appointments.php?view=day&amp;date=<?= $d ?>" style="color:inherit"><?= date('D j', strtotime($d)) ?></a></h3>
            <?php foreach ($dayAppts as $a): ?>
              <a class="week-item <?= e($a['status']) ?>" href="appointment_form.php?id=<?= $a['id'] ?>">
                <b><?= fmt_time($a['starts_at']) ?></b><?= e($a['full_name']) ?><br><span class="muted"><?= e($a['procedure_name']) ?></span>
              </a>
            <?php endforeach; ?>
            <?php if (!$dayAppts): ?><p class="muted small">Open</p><?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="stack">
    <?php if ($view === 'day'): ?>
      <section class="card">
        <h2>Chair capacity</h2>
        <div class="stat"><div class="value" style="margin:0"><?= $capacity ?>%</div></div>
        <div class="meter"><span style="width:<?= $capacity ?>%"></span></div>
        <p class="muted small"><?= max(0, round(($openMinutes - $bookedMinutes) / 60, 1)) ?> hours remain · open <?= e(fmt_time(config('clinic.opens'))) ?>–<?= e(fmt_time(config('clinic.closes'))) ?></p>
      </section>
    <?php endif; ?>

    <section class="card">
      <h2>Waitlist</h2>
      <?php if (!$waitlist): ?><p class="muted small">No one is waiting for a slot.</p><?php endif; ?>
      <ul class="kv" style="margin-bottom:16px">
        <?php foreach ($waitlist as $w): ?>
          <li class="row">
            <span class="spacer"><?= e($w['full_name']) ?> · <?= e($w['procedure_name']) ?></span>
            <a class="btn btn-sm" href="appointment_form.php?<?= e(http_build_query(['patient_id' => $w['patient_id'], 'procedure' => $w['procedure_name'], 'waitlist_id' => $w['id'], 'date' => $date])) ?>">Fill open slot</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="waitlist_remove"><input type="hidden" name="id" value="<?= $w['id'] ?>"><button class="link-btn" title="Remove" aria-label="Remove <?= e($w['full_name']) ?> from waitlist">✕</button></form>
          </li>
        <?php endforeach; ?>
      </ul>
      <button class="btn btn-sm" data-open="#waitlist-dialog">Add to waitlist</button>
    </section>

    <section class="card">
      <h2>Status summary</h2>
      <ul class="kv">
        <li><?= (int) ($counts['confirmed'] ?? 0) ?> Confirmed · <?= (int) ($counts['pending'] ?? 0) ?> Pending</li>
        <li><?= (int) ($counts['arrived'] ?? 0) + (int) ($counts['in_treatment'] ?? 0) ?> Arrived / in treatment · <?= (int) ($counts['completed'] ?? 0) ?> Completed</li>
        <li><?= (int) ($counts['cancelled'] ?? 0) ?> Cancelled · <?= (int) ($counts['no_show'] ?? 0) ?> No-show</li>
        <li class="muted small">Reminders go out at <?= e(fmt_time(config('reminders.send_time'))) ?>, one day before.</li>
      </ul>
      <?php if (can('notices.manage')): ?>
        <p style="margin-top:16px"><button class="btn btn-sm" data-open="#notice-dialog">Post a notice</button></p>
      <?php endif; ?>
    </section>
  </div>
</div>

<dialog id="waitlist-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Add to waitlist</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="waitlist_add">
    <div class="field"><label for="wl-p">Patient</label>
      <select id="wl-p" name="patient_id" required><option value="">Choose patient…</option>
        <?php foreach (patient_options() as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="wl-proc">Procedure</label><input type="text" id="wl-proc" name="procedure_name" maxlength="120" required placeholder="e.g. Cleaning"></div>
    <div class="field"><label for="wl-n">Notes</label><input type="text" id="wl-n" name="notes" maxlength="255" placeholder="Preferred days or times"></div>
    <div><button class="btn btn-primary" type="submit">Add</button></div>
  </form>
</dialog>

<?php if (can('notices.manage')): ?>
<dialog id="notice-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Post a clinic notice</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="notice_add">
    <div class="field"><label for="n-m">Message</label><input type="text" id="n-m" name="message" maxlength="255" required placeholder="Heavy rain expected after 3 PM; lab delivery may be delayed."></div>
    <div class="row">
      <div class="field spacer"><label for="n-s">From</label><input type="date" id="n-s" name="starts_on" value="<?= e($date) ?>" required></div>
      <div class="field spacer"><label for="n-e">Until</label><input type="date" id="n-e" name="ends_on" value="<?= e($date) ?>"></div>
    </div>
    <div><button class="btn btn-primary" type="submit">Post notice</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php layout_end();
