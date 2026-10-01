<?php

const ROLE_PERMISSIONS = [
    'admin'   => ['*'],
    'dentist' => ['chart.edit', 'patients.archive', 'notices.manage'],
    'staff'   => [],
];

const ROLE_LABELS = ['admin' => 'Administrator', 'dentist' => 'Dentist', 'staff' => 'Staff'];

function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $row = q_row('SELECT id, name, username, role, password_hash FROM users WHERE id = ? AND is_active = 1', [$_SESSION['user_id']]);
            $idle = (int) config('security.idle_minutes', 30) * 60;
            if (!$row) {
                end_user_session();
            } elseif ($idle > 0 && time() - (int) ($_SESSION['last_seen'] ?? 0) > $idle) {
                end_user_session('You were signed out after ' . ($idle / 60) . ' minutes without activity.');
            } elseif (!hash_equals((string) ($_SESSION['pw_fp'] ?? ''), password_fingerprint($row['password_hash']))) {
                // The password changed since this session signed in (or it was reset by an admin).
                end_user_session('Your password was changed. Sign in again with the new one.');
            } else {
                $_SESSION['last_seen'] = time();
                unset($row['password_hash']);
                $user = $row;
            }
        }
    }
    return $user;
}

/** Short, non-reversible marker of the password a session signed in with. */
function password_fingerprint(string $hash): string
{
    return hash('sha256', $hash);
}

/** Remembers which password this session belongs to, so changing it signs out every other session. */
function bind_session_to_password(int $userId): void
{
    $_SESSION['pw_fp'] = password_fingerprint((string) q_val('SELECT password_hash FROM users WHERE id = ?', [$userId]));
}

/** Signs the browser out but keeps a fresh session for the sign-in page message. */
function end_user_session(?string $message = null): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    if ($message !== null) {
        flash('info', $message);
    }
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    return $user;
}

/** Every signed-in user can use the core modules; these gate the sensitive extras. */
function can(string $permission): bool
{
    $user = current_user();
    if (!$user) {
        return false;
    }
    $perms = ROLE_PERMISSIONS[$user['role']] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function require_perm(string $permission): void
{
    if (!can($permission)) {
        http_response_code(403);
        exit('You do not have access to this action.');
    }
}

/*
 * Sign-in throttling. Failures are counted in the database, per username (from any device) and
 * per device (any username), so clearing cookies or opening a new session doesn't reset them.
 */
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_PER_USERNAME = 5;
const LOGIN_MAX_PER_DEVICE = 10;

/** Creates the attempts table on installs that predate it. */
function ensure_login_attempts_table(): void
{
    static $done = false;
    if (!$done) {
        q('CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(60) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_attempts_user (username, attempted_at),
            INDEX idx_attempts_ip (ip, attempted_at)
        ) ENGINE=InnoDB');
        $done = true;
    }
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'); // not X-Forwarded-For: the browser can fake that
}

function login_key(string $username): string
{
    return mb_substr(mb_strtolower(trim($username)), 0, 60);
}

/** Seconds until this username and device may try again; 0 when sign-in is allowed. */
function login_lockout_seconds(string $username, string $ip): int
{
    ensure_login_attempts_table();
    $wait = 0;
    // Locked while the window holds the maximum number of failures; it opens once the oldest of them ages out.
    foreach ([['username', login_key($username), LOGIN_MAX_PER_USERNAME], ['ip', $ip, LOGIN_MAX_PER_DEVICE]] as [$col, $value, $max]) {
        $seconds = q_val("SELECT TIMESTAMPDIFF(SECOND, NOW(), attempted_at + INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE)
            FROM login_attempts WHERE $col = ? AND attempted_at > NOW() - INTERVAL " . LOGIN_WINDOW_MINUTES . " MINUTE
            ORDER BY attempted_at DESC, id DESC LIMIT 1 OFFSET " . ($max - 1), [$value]);
        $wait = max($wait, (int) $seconds);
    }
    return $wait;
}

function record_failed_login(string $username, string $ip): void
{
    ensure_login_attempts_table();
    q('INSERT INTO login_attempts (username, ip) VALUES (?, ?)', [login_key($username), $ip]);
    q('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    if (login_lockout_seconds($username, $ip) > 0) {
        log_activity('login_locked', null, null, 'Sign-in locked for "' . login_key($username) . '" from ' . $ip);
    }
}

/** Forgets failures for a username: all of them, or only those from one device. */
function clear_failed_logins(string $username, ?string $ip = null): void
{
    ensure_login_attempts_table();
    if ($ip === null) {
        q('DELETE FROM login_attempts WHERE username = ?', [login_key($username)]);
    } else {
        q('DELETE FROM login_attempts WHERE username = ? AND ip = ?', [login_key($username), $ip]);
    }
}

function attempt_login(string $username, string $password): bool
{
    $user = q_row('SELECT id, password_hash FROM users WHERE username = ? AND is_active = 1', [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['last_seen'] = time();
    bind_session_to_password((int) $user['id']);
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    return true;
}
