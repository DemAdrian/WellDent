<?php
require __DIR__ . '/includes/bootstrap.php';
require_login();

$tab = in_array(input('tab'), ['scheduled', 'sent', 'failed', 'all'], true) ? input('tab') : 'scheduled';

if (is_post()) {
    verify_csrf();
    $action = input('action');
    if ($action === 'run') {
        [$sent, $failed] = process_due_reminders();
        log_activity('reminders_run', null, null, "$sent sent, $failed failed");
        flash($failed ? 'error' : 'success', "$sent reminder(s) sent" . ($failed ? ", $failed failed. See the Failed tab." : '.'));
    } elseif ($action === 'send_now' || $action === 'retry') {
        $rid = (int) input('id');
        q("UPDATE reminders SET status = 'scheduled', send_at = NOW() WHERE id = ? AND status IN ('scheduled','failed')", [$rid]);
        [$sent] = process_due_reminders($rid);
        flash($sent ? 'success' : 'error', $sent ? 'Reminder sent.' : 'Sending failed. See the error on the Failed tab.');
    } elseif ($action === 'cancel') {
        q("UPDATE reminders SET status = 'cancelled' WHERE id = ? AND status IN ('scheduled','failed')", [(int) input('id')]);
        flash('success', 'Reminder cancelled.');
    } elseif ($action === 'create') {
        $patient = q_row('SELECT * FROM patients WHERE id = ?', [(int) input('patient_id')]);
        $channel = input('channel') === 'email' ? 'email' : 'sms';
        $recipient = !$patient ? null : ($channel === 'sms'
            ? (is_ph_mobile($patient['phone']) ? normalize_ph_phone($patient['phone']) : null)
            : $patient['email']);
        $sendAt = str_replace('T', ' ', input('send_at'));
        if (!$patient || !$recipient) {
            flash('error', 'That patient has no ' . ($channel === 'sms' ? 'mobile number' : 'email address') . ' on file.');
        } elseif (input('message') === '' || strtotime($sendAt) === false) {
            flash('error', 'Enter a message and a send time.');
        } elseif (mb_strlen(input('message')) > 480) {
            flash('error', 'Keep the message to 480 characters (3 SMS) or less.');
        } else {
            q('INSERT INTO reminders (patient_id, channel, recipient, message, send_at) VALUES (?, ?, ?, ?, ?)',
                [$patient['id'], $channel, $recipient, input('message'), date('Y-m-d H:i:s', strtotime($sendAt))]);
            flash('success', 'Reminder scheduled.');
        }
    }
    redirect('reminders.php?tab=' . $tab);
}

$where = $tab === 'all' ? '' : 'WHERE r.status = ?';
$reminders = q_all("SELECT r.*, p.full_name, a.starts_at FROM reminders r JOIN patients p ON p.id = r.patient_id
    LEFT JOIN appointments a ON a.id = r.appointment_id $where
    ORDER BY " . ($tab === 'scheduled' ? 'r.send_at ASC' : 'r.send_at DESC') . ' LIMIT 200', $tab === 'all' ? [] : [$tab]);
$counts = array_column(q_all('SELECT status, COUNT(*) AS n FROM reminders GROUP BY status'), 'n', 'status');
$due = (int) q_val("SELECT COUNT(*) FROM reminders WHERE status = 'scheduled' AND send_at <= NOW()");

layout_start('Reminders', 'reminders', [
    'subtitle' => 'Automatic SMS and email reminders, sent ' . fmt_time(config('reminders.send_time')) . ' the day before each appointment',
    'action'   => '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="run"><button class="btn btn-primary" type="submit">Send due now' . ($due ? " ($due)" : '') . '</button></form>',
]);
?>
<?php if (config('sms.driver') === 'log' || config('email.driver') === 'log'): ?>
  <div class="banner"><p><b>Test mode:</b> <?= config('sms.driver') === 'log' ? 'SMS' : '' ?><?= config('sms.driver') === 'log' && config('email.driver') === 'log' ? ' and ' : '' ?><?= config('email.driver') === 'log' ? 'email' : '' ?> reminders are written to <code>storage/outbox.log</code> instead of being sent. Configure a provider in <code>config/config.local.php</code> to send for real.</p></div>
<?php endif; ?>

<div class="toolbar">
  <nav class="tabs" style="margin:0">
    <?php foreach (['scheduled' => 'Scheduled', 'sent' => 'Sent', 'failed' => 'Failed', 'all' => 'All'] as $k => $lbl): ?>
      <a href="reminders.php?tab=<?= $k ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= $lbl ?><?= $k !== 'all' && !empty($counts[$k]) ? ' (' . (int) $counts[$k] . ')' : '' ?></a>
    <?php endforeach; ?>
  </nav>
  <button class="btn" data-open="#reminder-dialog">+ Custom reminder</button>
</div>

<section class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Send at</th><th>Patient</th><th>Channel</th><th>Message</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($reminders as $r): ?>
        <tr>
          <td style="white-space:nowrap"><?= e(fmt_date($r['send_at'], 'M j, g:i A')) ?></td>
          <td><a href="patient.php?id=<?= $r['patient_id'] ?>"><?= e($r['full_name']) ?></a><br><small class="muted"><?= e($r['recipient']) ?></small></td>
          <td><?= $r['channel'] === 'sms' ? 'SMS' : 'Email' ?></td>
          <td class="small" style="max-width:420px"><?= e($r['message']) ?><?= $r['error'] ? '<br><span class="amount-due">' . e($r['error']) . '</span>' : '' ?></td>
          <td><?= pill($r['status']) ?></td>
          <td class="num" style="white-space:nowrap">
            <?php if (in_array($r['status'], ['scheduled', 'failed'], true)): ?>
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="link-btn" name="action" value="<?= $r['status'] === 'failed' ? 'retry' : 'send_now' ?>"><?= $r['status'] === 'failed' ? 'Retry' : 'Send now' ?></button></form> ·
              <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="link-btn" name="action" value="cancel">Cancel</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$reminders): ?><tr><td colspan="6" class="empty">No <?= $tab === 'all' ? '' : $tab ?> reminders.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<dialog id="reminder-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Custom reminder</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="field"><label for="r-p">Patient</label>
      <select id="r-p" name="patient_id" required><option value="">Choose patient…</option>
        <?php foreach (patient_options() as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="row">
      <div class="field spacer"><label for="r-c">Channel</label><select id="r-c" name="channel"><option value="sms">SMS</option><option value="email">Email</option></select></div>
      <div class="field spacer"><label for="r-t">Send at</label><input type="datetime-local" id="r-t" name="send_at" value="<?= date('Y-m-d\TH:i') ?>" required></div>
    </div>
    <div class="field"><label for="r-m">Message</label><textarea id="r-m" name="message" maxlength="480" required placeholder="Hi! Your recall cleaning is due. Call us to book."></textarea></div>
    <div><button class="btn btn-primary" type="submit">Schedule</button></div>
  </form>
</dialog>
<?php layout_end();
