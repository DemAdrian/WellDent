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

function lockout_message(int $seconds): string
{
    $minutes = (int) ceil($seconds / 60);
    return 'Too many failed sign-in attempts. Try again in ' . ($minutes <= 1 ? 'a minute' : "$minutes minutes")
        . ', or ask an administrator to reset your password.';
}

if (is_post()) {
    verify_csrf();
    $username = input('username');
    $ip = client_ip();
    // A locked username or device isn't even checked, so guessing gets nowhere while it lasts.
    $wait = login_lockout_seconds($username, $ip);
    if ($wait > 0) {
        $error = lockout_message($wait);
    } elseif (attempt_login($username, (string) ($_POST['password'] ?? ''))) {
        clear_failed_logins($username, $ip);
        log_activity('login', null, null, null, (int) $_SESSION['user_id']);
        redirect('index.php');
    } else {
        record_failed_login($username, $ip);
        $wait = login_lockout_seconds($username, $ip);
        $error = $wait > 0 ? lockout_message($wait) : 'Incorrect username or password.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · WellDent+</title>
<?= theme_head() ?>
</head>
<body>
<div class="auth">
  <form class="card stack" method="post">
    <div class="brand">
      <span class="brand-mark">D</span>
      <span><strong><?= e(config('clinic.name')) ?></strong><small>WellDent+ clinic system</small></span>
    </div>
    <?php foreach (take_flashes() as $f): ?><div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div><?php endforeach; ?>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" value="<?= e(input('username')) ?>" autocomplete="username" required autofocus></div>
    <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>
</body>
</html>
