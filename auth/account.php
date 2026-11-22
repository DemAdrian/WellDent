<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$errors = [];
$emailErrors = [];
$email = (string) q_val('SELECT email FROM users WHERE id = ?', [$user['id']]);
if (is_post()) {
    verify_csrf();
    $hash = q_val('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (input('action') === 'email') {
        $email = input('email');
        if ($email !== '' && !valid_email($email)) $emailErrors[] = 'Enter a valid email address.';
        if (!password_verify((string) ($_POST['email_password'] ?? ''), $hash)) $emailErrors[] = 'Your current password is incorrect.';
        if (!$emailErrors && $email !== '' && q_val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $user['id']])) {
            $emailErrors[] = 'Another account already uses that email.';
        }
        if (!$emailErrors) {
            q('UPDATE users SET email = ? WHERE id = ?', [nullable($email), $user['id']]);
            log_activity('user_email_changed', 'user', (int) $user['id']);
            flash('success', $email === '' ? 'Recovery email removed.' : 'Recovery email saved. Use it with “Forgot your password?” on the sign-in page.');
            redirect(url('auth/account.php'));
        }
    } else {
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
}

layout_start('My account', '', ['subtitle' => 'Signed in as ' . $user['username']]);
?>
<div class="stack" style="max-width:480px">
<form class="card stack" method="post">
  <h2>Change password</h2>
  <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?= csrf_field() ?>
  <div class="field"><label for="cp">Current password</label><input type="password" id="cp" name="current_password" required autocomplete="current-password"></div>
  <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" minlength="8" required autocomplete="new-password"></div>
  <div class="field"><label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
  <div><button class="btn btn-primary" type="submit">Update password</button></div>
</form>

<form class="card stack" method="post">
  <h2>Recovery email</h2>
  <p class="muted small">If you forget your password, a reset link is sent here. Leave it blank to remove it.</p>
  <?php if ($emailErrors): ?><ul class="errors"><?php foreach ($emailErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?= csrf_field() ?><input type="hidden" name="action" value="email">
  <div class="field"><label for="em">Email</label><input type="email" id="em" name="email" value="<?= e($email) ?>" maxlength="190" autocomplete="email"></div>
  <div class="field"><label for="ep">Current password</label><input type="password" id="ep" name="email_password" required autocomplete="current-password"></div>
  <div><button class="btn btn-primary" type="submit">Save email</button></div>
</form>
</div>
<?php layout_end();
