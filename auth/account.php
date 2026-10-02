<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$errors = [];
if (is_post()) {
    verify_csrf();
    $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $hash)) $errors[] = 'Your current password is incorrect.';
    if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($new !== ($_POST['confirm_password'] ?? '')) $errors[] = 'The new passwords do not match.';
    if (!$errors) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        bind_session_to_password((int) $user['id']); // keep this session; every other one is signed out
        log_activity('password_changed', 'user', (int) $user['id']);
        flash('success', 'Password updated. Any other device signed in as you has been signed out.');
        redirect(url('auth/account.php'));
    }
}

layout_start('My account', '', ['subtitle' => 'Signed in as ' . $user['username']]);
?>
<form class="card stack" method="post" style="max-width:480px">
  <h2>Change password</h2>
  <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?= csrf_field() ?>
  <div class="field"><label for="cp">Current password</label><input type="password" id="cp" name="current_password" required autocomplete="current-password"></div>
  <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" minlength="8" required autocomplete="new-password"></div>
  <div class="field"><label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
  <div><button class="btn btn-primary" type="submit">Update password</button></div>
</form>
<?php layout_end();
