<?php
// Appointment reminders: scheduling, and delivery over SMS / email.

function reminder_message(array $appt): string
{
    return strtr(config('reminders.template'), [
        '{first_name}' => first_name($appt['full_name']),
        '{clinic}'     => config('clinic.name'),
        '{procedure}'  => mb_strtolower($appt['procedure_name']),
        '{date}'       => date('D, M j', strtotime($appt['starts_at'])),
        '{time}'       => date('g:i A', strtotime($appt['starts_at'])),
    ]);
}

/**
 * (Re)schedule reminders for an appointment: one per channel the patient has
 * contact details for, sent at the configured time the day before.
 */
function schedule_appointment_reminders(int $appointmentId): void
{
    q("UPDATE reminders SET status = 'cancelled' WHERE appointment_id = ? AND status = 'scheduled'", [$appointmentId]);

    $appt = q_row('SELECT a.*, p.full_name, p.phone, p.email FROM appointments a
        JOIN patients p ON p.id = a.patient_id WHERE a.id = ?', [$appointmentId]);
    if (!$appt || in_array($appt['status'], ['cancelled', 'no_show', 'completed'], true)) {
        return;
    }

    $start = strtotime($appt['starts_at']);
    if ($start < time() + 2 * 3600) {
        return; // too late for a reminder to be useful
    }
    $sendAt = strtotime(date('Y-m-d', $start - 86400) . ' ' . config('reminders.send_time'));
    $sendAt = max($sendAt, time());

    $message = reminder_message($appt);
    $channels = array_filter([
        'sms'   => $appt['phone'] ?: null,
        'email' => ($appt['email'] && filter_var($appt['email'], FILTER_VALIDATE_EMAIL)) ? $appt['email'] : null,
    ]);
    foreach ($channels as $channel => $recipient) {
        q('INSERT INTO reminders (patient_id, appointment_id, channel, recipient, message, send_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$appt['patient_id'], $appointmentId, $channel, $recipient, $message, date('Y-m-d H:i:s', $sendAt)]);
    }
}

/** Sends every scheduled reminder that is due. Returns [sent, failed]. */
function process_due_reminders(?int $onlyId = null): array
{
    $sql = "SELECT * FROM reminders WHERE status = 'scheduled' AND send_at <= NOW()";
    $params = [];
    if ($onlyId !== null) {
        $sql .= ' AND id = ?';
        $params[] = $onlyId;
    }
    $sent = $failed = 0;
    foreach (q_all($sql . ' ORDER BY send_at LIMIT 100', $params) as $r) {
        [$ok, $error] = $r['channel'] === 'sms'
            ? send_sms($r['recipient'], $r['message'])
            : send_email($r['recipient'], config('clinic.name') . ' appointment reminder', $r['message']);
        if ($ok) {
            q("UPDATE reminders SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?", [$r['id']]);
            $sent++;
        } else {
            q("UPDATE reminders SET status = 'failed', error = ? WHERE id = ?", [mb_substr($error, 0, 255), $r['id']]);
            $failed++;
        }
    }
    return [$sent, $failed];
}

function write_outbox(string $channel, string $to, string $message): array
{
    $line = sprintf("[%s] %s to %s: %s\n", date('Y-m-d H:i:s'), strtoupper($channel), $to, $message);
    $ok = file_put_contents(APP_ROOT . '/storage/outbox.log', $line, FILE_APPEND | LOCK_EX) !== false;
    return [$ok, $ok ? '' : 'Could not write storage/outbox.log'];
}

/** SMS via Semaphore (semaphore.co), a common Philippine SMS gateway. */
function send_sms(string $to, string $message): array
{
    if (config('sms.driver') !== 'semaphore') {
        return write_outbox('sms', $to, $message);
    }
    if (!function_exists('curl_init')) {
        return [false, 'PHP curl extension is not enabled'];
    }
    $fields = ['apikey' => config('sms.api_key'), 'number' => preg_replace('/\D+/', '', $to), 'message' => $message];
    if (config('sms.sender_name')) {
        $fields['sendername'] = config('sms.sender_name');
    }
    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return [false, 'SMS request failed: ' . $error];
    }
    return $status >= 200 && $status < 300 ? [true, ''] : [false, "SMS gateway returned HTTP $status: " . mb_substr((string) $body, 0, 150)];
}

/** Email via PHP mail(); point XAMPP's sendmail at Gmail SMTP (see README). */
function send_email(string $to, string $subject, string $message): array
{
    if (config('email.driver') !== 'mail') {
        return write_outbox('email', $to, $message);
    }
    $from = config('email.from');
    $headers = [
        'From: ' . config('email.from_name') . " <$from>",
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $ok = @mail($to, $subject, $message, implode("\r\n", $headers));
    return [$ok, $ok ? '' : 'mail() failed; check the sendmail configuration'];
}
