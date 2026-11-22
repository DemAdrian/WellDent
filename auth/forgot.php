<?php
require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    redirect(url());
}

$sent = false;
if (is_post()) {
    verify_csrf();
    request_password_reset(mb_substr(input('identifier'), 0, 190), client_ip());
    $sent = true;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Forgot password · WellDent+</title>
<?= theme_head() ?>
</head>
<body>
<div class="auth">
  <form class="card stack" method="post">
    <div class="brand">
      <?= clinic_logo() ?>
      <span><strong><?= e(config('clinic.name')) ?></strong><small>Reset your password</small></span>
    </div>
    <?php if ($sent): ?>
      <div class="flash flash-success" role="status">If that account has a recovery email, a reset link is on its way. It works once, for <?= RESET_LINK_MINUTES ?> minutes.</div>
      <p class="muted small">No email after a few minutes? Check the spam folder, or ask an administrator to reset your password under Settings → Clinic users.</p>
      <a class="btn" href="<?= url('auth/login.php') ?>">Back to sign in</a>
    <?php else: ?>
      <p class="muted small">Enter your username or the email saved on your account. We'll email you a link to choose a new password.</p>
      <?= csrf_field() ?>
      <div class="field"><label for="identifier">Username or email</label><input type="text" id="identifier" name="identifier" maxlength="190" autocomplete="username" required autofocus></div>
      <button class="btn btn-primary" type="submit">Email me a reset link</button>
      <a class="small" href="<?= url('auth/login.php') ?>">Back to sign in</a>
    <?php endif; ?>
  </form>
</div>
<?php clinic_footer(); ?>
</body>
</html>
