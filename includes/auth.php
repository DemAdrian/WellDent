<?php

const ROLE_PERMISSIONS = [
    'admin'   => ['*'],
    'dentist' => ['chart.edit', 'patients.archive', 'notices.manage', 'fees.manage'],
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
        redirect(url('auth/login.php'));
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

/*
 * Forgot password. A user with a recovery email gets a single-use link valid for
 * RESET_LINK_MINUTES. Only the SHA-256 of the token is stored, so a database backup can't be
 * used to reset anyone's password.
 */
const RESET_LINK_MINUTES = 30;
const RESET_MAX_PER_USER = 3;   // per hour
const RESET_MAX_PER_DEVICE = 10; // per hour

function valid_email(string $email): bool
{
    return $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function ensure_password_resets_table(): void
{
    static $done = false;
    if (!$done) {
        q('CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            token_hash CHAR(64) NULL UNIQUE,
            ip VARCHAR(45) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            used_at DATETIME NULL,
            INDEX idx_resets_user (user_id, created_at),
            INDEX idx_resets_ip (ip, created_at),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB');
        $done = true;
    }
}

/**
 * The address emailed links should point to. Uses config('app_url') when set; otherwise the
 * address this page was opened with, but only if it is this computer's own name or IP, so a
 * forged Host header can't send someone a link to another site.
 */
function app_base_url(): ?string
{
    $configured = rtrim((string) config('app_url', ''), '/');
    if ($configured !== '') {
        return $configured;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/^([a-z0-9.-]+)(:\d{1,5})?$/D', $host, $m)) {
        return null;
    }
    $self = strtolower((string) gethostname());
    $trusted = array_merge(['localhost', '127.0.0.1', $self], gethostbynamel($self) ?: []);
    if (!in_array($m[1], $trusted, true)) {
        return null;
    }
    return (is_https() ? 'https://' : 'http://') . $host . rtrim(url(), '/');
}

/**
 * Emails a reset link when the username or email matches an active account with a recovery
 * email. Says nothing either way, so the form can't be used to discover usernames.
 */
function request_password_reset(string $identifier, string $ip): void
{
    ensure_password_resets_table();
    q('DELETE FROM password_resets WHERE created_at < NOW() - INTERVAL 1 DAY');
    $deviceCount = (int) q_val('SELECT COUNT(*) FROM password_resets WHERE ip = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$ip]);
    if ($deviceCount >= RESET_MAX_PER_DEVICE) {
        return;
    }
    $identifier = trim($identifier);
    $user = $identifier === '' ? null : q_row('SELECT id, name, username, email FROM users
        WHERE is_active = 1 AND email IS NOT NULL AND (username = ? OR email = ?) LIMIT 1', [$identifier, $identifier]);
    if (!$user) {
        q('INSERT INTO password_resets (ip) VALUES (?)', [$ip]);
        return;
    }
    $userCount = (int) q_val('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$user['id']]);
    if ($userCount >= RESET_MAX_PER_USER) {
        q('INSERT INTO password_resets (ip) VALUES (?)', [$ip]);
        return;
    }
    $base = app_base_url();
    if ($base === null) {
        error_log('Password reset not sent: open WellDent+ by this computer\'s address or set app_url in config.local.php.');
        log_activity('password_reset_failed', 'user', (int) $user['id'], 'Unrecognised site address', (int) $user['id']);
        return;
    }

    $token = bin2hex(random_bytes(32));
    // A new link replaces any earlier one that hasn't been used.
    q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL AND token_hash IS NOT NULL', [$user['id']]);
    q('INSERT INTO password_resets (user_id, token_hash, ip, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . RESET_LINK_MINUTES . ' MINUTE)',
        [$user['id'], hash('sha256', $token), $ip]);

    $link = $base . '/auth/reset.php?token=' . $token;
    $message = "Hi " . first_name($user['name']) . ",\n\n"
        . "Someone asked to reset the WellDent+ password for the username \"{$user['username']}\" at " . config('clinic.name') . ".\n\n"
        . "To choose a new password, open this link within " . RESET_LINK_MINUTES . " minutes:\n$link\n\n"
        . "If you didn't ask for this, ignore this email; your password stays the same.\n";
    [$ok, $error] = send_email($user['email'], config('clinic.name') . ' password reset', $message);
    log_activity($ok ? 'password_reset_requested' : 'password_reset_failed', 'user', (int) $user['id'], $ok ? null : $error, (int) $user['id']);
}

/** The pending reset for this token, or null when it is unknown, used, or expired. */
function find_password_reset(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
        return null;
    }
    ensure_password_resets_table();
    return q_row('SELECT r.id, r.user_id, u.username FROM password_resets r JOIN users u ON u.id = r.user_id AND u.is_active = 1
        WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW()', [hash('sha256', $token)]);
}

/** Sets the new password and uses up the link. False when the link stopped being valid meanwhile. */
function complete_password_reset(string $token, string $password): bool
{
    // Before the transaction: CREATE TABLE IF NOT EXISTS would commit it early.
    ensure_password_resets_table();
    ensure_login_attempts_table();
    $username = in_transaction(function () use ($token, $password) {
        $reset = find_password_reset($token);
        if (!$reset || !q_row('SELECT id FROM password_resets WHERE id = ? AND used_at IS NULL FOR UPDATE', [$reset['id']])) {
            return null;
        }
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $reset['user_id']]);
        q('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$reset['user_id']]);
        log_activity('password_reset_by_email', 'user', (int) $reset['user_id'], null, (int) $reset['user_id']);
        return $reset['username'];
    });
    if ($username === null) {
        return false;
    }
    clear_failed_logins($username); // also lifts a sign-in lockout
    return true;
}
