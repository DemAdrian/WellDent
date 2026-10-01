<?php
// Booking rules shared by the appointment form and the schedule page.

/** Statuses that free the chair. */
const NON_BLOCKING_STATUSES = ['cancelled', 'no_show'];

/** Added to the notes of appointments cancelled because their patient was archived. */
const ARCHIVE_NOTE = '[Cancelled: patient archived]';

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
        throw new RuntimeException('Someone else is saving to the schedule. Try again in a moment.');
    }
    try {
        return $fn();
    } finally {
        q('SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
