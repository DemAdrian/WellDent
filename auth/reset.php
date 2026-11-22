<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

// The token is in the URL; don't pass it on to any other site.
header('Referrer-Policy: no-referrer');

$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$reset = find_password_reset($token);
$errors = [];

if ($reset && is_post()) {
    verify_csrf();
    $new = (string) ($_POST['new_password'] ?? '');
    if (strlen($new) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($new !== ($_POST['confirm_password'] ?? '')) $errors[] = 'The new passwords do not match.';
    if (!$errors) {
        if (complete_password_reset($token, $new)) {
            end_user_session('Password changed. Sign in with your new password.');
            redirect(url('auth/login.php'));
        }
        $reset = null;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Choose a new password · WellDent+</title>
<?= theme_head() ?>
</head>
<body>
<div class="auth">
  <form class="card stack" method="post" action="<?= url('auth/reset.php') ?>">
    <div class="brand">
      <?= clinic_logo() ?>
      <span><strong><?= e(config('clinic.name')) ?></strong><small>Choose a new password</small></span>
    </div>
    <?php if (!$reset): ?>
      <div class="flash flash-error" role="alert">This reset link has expired or was already used.</div>
      <a class="btn btn-primary" href="<?= url('auth/forgot.php') ?>">Request a new link</a>
      <a class="small" href="<?= url('auth/login.php') ?>">Back to sign in</a>
    <?php else: ?>
      <p class="muted small">New password for <b><?= e($reset['username']) ?></b>. Every device signed in to this account will be signed out.</p>
      <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="text" name="username" value="<?= e($reset['username']) ?>" autocomplete="username" hidden>
      <div class="field"><label for="np">New password</label><input type="password" id="np" name="new_password" minlength="8" required autocomplete="new-password" autofocus></div>
      <div class="field"><label for="cf">Confirm new password</label><input type="password" id="cf" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn btn-primary" type="submit">Save new password</button>
    <?php endif; ?>
  </form>
  <?php clinic_footer(true); ?>
</div>
</body>
</html>
