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
            $user = q_row('SELECT id, name, username, role FROM users WHERE id = ? AND is_active = 1', [$_SESSION['user_id']]);
        }
    }
    return $user;
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
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    return true;
}
