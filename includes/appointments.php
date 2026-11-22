<?php
// Booking rules shared by the appointment form and the schedule page.

class BookingBusyException extends RuntimeException
{
}

/** Statuses that free the chair. */
const NON_BLOCKING_STATUSES = ['cancelled', 'no_show'];

/** Added to the notes of appointments cancelled because their patient was archived. */
const ARCHIVE_NOTE = '[Cancelled: patient archived]';

/**
 * Charting, treatments and billing open once a patient has been booked at least once
 * (any appointment that wasn't cancelled).
 */
function patient_has_appointment(int $patientId): bool
{
    return (bool) q_val("SELECT 1 FROM appointments WHERE patient_id = ? AND status <> 'cancelled' LIMIT 1", [$patientId]);
}

const NO_APPOINTMENT_MESSAGE = 'This patient doesn’t have an appointment yet. Book one before updating the dental chart, treatments or billing.';

/** Detects booking edits made after a staff member opened the form. */
function appointment_version(array $appointment): string
{
    return hash('sha256', serialize($appointment));
}

/** Installs the progress table on existing databases when an appointment is first opened. */
function ensure_appointment_progress_table(): void
{
    try {
        q('SELECT 1 FROM appointment_progress LIMIT 0');
    } catch (PDOException $ex) {
        if ((int) ($ex->errorInfo[1] ?? 0) !== 1146) {
            throw $ex;
        }
        q(<<<'SQL'
            CREATE TABLE IF NOT EXISTS appointment_progress (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                appointment_id INT UNSIGNED NOT NULL,
                note           TEXT NOT NULL,
                recorded_by    INT UNSIGNED NULL,
                recorded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_appt_progress (appointment_id, id),
                FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
                FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB
            SQL);
    }
}

/** The first active appointment on this chair that overlaps the given time, or null. */
function appointment_clash(int $chair, string $start, int $duration, int $exceptId = 0): ?array
{
    return q_row("SELECT a.starts_at, p.full_name FROM appointments a JOIN patients p ON p.id = a.patient_id
        WHERE a.chair = ? AND a.id <> ? AND a.status NOT IN ('cancelled','no_show')
        AND a.starts_at < ? + INTERVAL ? MINUTE AND a.starts_at + INTERVAL a.duration_min MINUTE > ?
        ORDER BY a.starts_at LIMIT 1",
        [$chair, $exceptId, $start, $duration, $start]);
}

/**
 * Runs $fn while holding a MySQL named lock, so two devices can't book the
 * same slot between the overlap check and the save.
 */
function with_booking_lock(callable $fn)
{
    $lock = 'welldent_booking_' . config('db.name');
    if ((int) q_val('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
        throw new BookingBusyException('Someone else is saving to the schedule. Try again in a moment.');
    }
    try {
        return $fn();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}