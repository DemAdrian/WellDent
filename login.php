<?php
require __DIR__ . '/includes/bootstrap.php';

try {
    if ((int) q_val('SELECT COUNT(*) FROM users') === 0) {
        redirect('setup.php');
    }
} catch (PDOException $ex) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('index.php');
}

$error = '';
$lockedUntil = $_SESSION['login_locked_until'] ?? 0;

if (is_post()) {
    verify_csrf();
    if ($lockedUntil > time()) {
        $error = 'Too many attempts. Try again in ' . ($lockedUntil - time()) . ' seconds.';
    } elseif (attempt_login(input('username'), (string) ($_POST['password'] ?? ''))) {
        unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
        log_activity('login');
        redirect('index.php');
    } else {
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_locked_until'] = time() + 60;
            $_SESSION['login_attempts'] = 0;
        }
        $error = 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · WellDent+</title>
<link rel="stylesheet" href="assets/css/app.css?v=1">
</head>
<body>
<div class="auth">
  <form class="card stack" method="post">
    <div class="brand">
      <span class="brand-mark">D</span>
      <span><strong><?= e(config('clinic.name')) ?></strong><small>WellDent+ clinic system</small></span>
    </div>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" value="<?= e(input('username')) ?>" autocomplete="username" required autofocus></div>
    <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
