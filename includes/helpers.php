<?php

function config(string $key, $default = null)
{
    $value = $GLOBALS['config'];
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₱' . number_format(abs((float) $amount), ((float) $amount == floor((float) $amount)) ? 0 : 2);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/** Call at the top of every POST handler. */
function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function input(string $key, $default = ''): string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($value) ? trim($value) : (string) $default;
}

function nullable(string $value): ?string
{
    return $value === '' ? null : $value;
}

function valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    return $d !== false && $d->format('Y-m-d') === $value;
}

/** Largest amount a DECIMAL(10,2) column holds. */
const MAX_AMOUNT = 99999999.99;

/** Largest stock count we accept in one entry. */
const MAX_QUANTITY = 1000000;

/** True for a number from 0 (or above 0 when $allowZero is false) up to MAX_AMOUNT, with at most 2 decimals. */
function valid_amount(string $value, bool $allowZero = false): bool
{
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
        return false;
    }
    $n = (float) $value;
    return ($allowZero ? $n >= 0 : $n > 0) && $n <= MAX_AMOUNT;
}

/**
 * Errors for values longer than their database column allows.
 * $limits: field => [label, max characters]
 */
function length_errors(array $values, array $limits): array
{
    $errors = [];
    foreach ($limits as $field => [$label, $max]) {
        if (mb_strlen((string) ($values[$field] ?? '')) > $max) {
            $errors[] = "$label must be at most $max characters.";
        }
    }
    return $errors;
}

/**
 * Normalizes a Philippine phone number, or returns null when it isn't one.
 *   Mobile:   0917 123 4567, +63 917 123 4567, 639171234567 → 09171234567 (11 digits)
 *   Landline: (02) 8123 4567, (032) 123 4567, +63 2 8123 4567 → 0281234567 (10 digits)
 */
function normalize_ph_phone(string $value): ?string
{
    $digits = preg_replace('/[\s().-]+/', '', $value);
    if (!preg_match('/^(?:\+?63|0)(\d{9,10})$/', $digits, $m)) {
        return null;
    }
    $national = $m[1];
    if (strlen($national) === 10 && $national[0] === '9') {
        return '0' . $national; // mobile
    }
    if (strlen($national) === 9 && $national[0] !== '9') {
        return '0' . $national; // landline: area code + local number
    }
    return null;
}

/** True when the number can receive SMS (a PH mobile number). */
function is_ph_mobile(?string $value): bool
{
    $n = normalize_ph_phone((string) $value);
    return $n !== null && strlen($n) === 11;
}

function fmt_date(?string $value, string $format = 'M j, Y'): string
{
    return $value ? date($format, strtotime($value)) : '—';
}

function fmt_time(?string $value): string
{
    return $value ? date('g:i A', strtotime($value)) : '';
}

function age(?string $birthDate): ?int
{
    return $birthDate ? (new DateTime($birthDate))->diff(new DateTime('today'))->y : null;
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last);
}

function first_name(string $name): string
{
    return explode(' ', trim($name))[0];
}

const APPOINTMENT_STATUSES = [
    'pending'      => 'Pending',
    'confirmed'    => 'Confirmed',
    'arrived'      => 'Arrived',
    'in_treatment' => 'In treatment',
    'completed'    => 'Completed',
    'cancelled'    => 'Cancelled',
    'no_show'      => 'No-show',
];

const PATIENT_STATUSES = [
    'active'     => 'Active',
    'incomplete' => 'Incomplete',
    'draft'      => 'Draft',
    'archived'   => 'Archived',
];

const CARE_TYPES = ['New patient', 'Preventive', 'Orthodontic', 'Restorative', 'Recall'];

const PAYMENT_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'bank' => 'Bank transfer', 'other' => 'Other'];

function pill(string $status, ?string $label = null): string
{
    $label ??= APPOINTMENT_STATUSES[$status] ?? PATIENT_STATUSES[$status] ?? ucfirst(str_replace('_', ' ', $status));
    return '<span class="pill pill-' . e($status) . '">' . e($label) . '</span>';
}

function selected($a, $b): string
{
    return (string) $a === (string) $b ? ' selected' : '';
}

function avatar(string $name, int $seed = 0): string
{
    return '<span class="avatar avatar-' . ($seed % 3) . '">' . e(initials($name)) . '</span>';
}

/** Patients for <select> pickers. */
function patient_options(): array
{
    return q_all("SELECT id, full_name, phone FROM patients WHERE status <> 'archived' ORDER BY full_name");
}

function dentist_options(): array
{
    return q_all("SELECT id, name FROM users WHERE is_active = 1 AND role IN ('dentist','admin') ORDER BY name");
}
