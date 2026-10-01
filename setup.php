<?php
// First-run setup: checks the database and creates the first admin account.
// Locks itself once any user exists.
require __DIR__ . '/includes/bootstrap.php';

$problem = '';
try {
    $userCount = (int) q_val('SELECT COUNT(*) FROM users');
} catch (PDOException $ex) {
    $userCount = -1;
    $problem = str_contains($ex->getMessage(), 'Unknown database') || str_contains($ex->getMessage(), "doesn't exist")
        ? 'The database tables were not found. Import database/schema.sql in phpMyAdmin, then reload this page.'
        : 'Could not connect to MySQL. Start MySQL in the XAMPP Control Panel and check config/config.php. (' . $ex->getMessage() . ')';
}
if ($userCount > 0) {
    redirect('login.php');
}

$errors = [];
if ($userCount === 0 && is_post()) {
    verify_csrf();
    $name = input('name');
    $username = input('username');
    $password = (string) ($_POST['password'] ?? '');
    if ($name === '' || mb_strlen($name) > 120) $errors[] = 'Enter your name (up to 120 characters).';
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) $errors[] = 'Username must be 3–60 letters, numbers, dots, dashes or underscores.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if (!$errors) {
        q("INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, 'admin')",
            [$name, $username, password_hash($password, PASSWORD_DEFAULT)]);
        attempt_login($username, $password);
        flash('success', 'Welcome! Your admin account is ready.');
        redirect('index.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup · WellDent+</title>
<?= theme_head() ?>
</head>
<body>
<div class="auth">
  <form class="card stack" method="post">
    <div class="brand">
      <span class="brand-mark">D</span>
      <span><strong>WellDent+ setup</strong><small>Create the first administrator</small></span>
    </div>
    <?php if ($problem): ?>
      <div class="flash flash-error"><?= e($problem) ?></div>
    <?php else: ?>
      <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
      <?= csrf_field() ?>
      <div class="field"><label for="name">Full name</label><input type="text" id="name" name="name" maxlength="120" value="<?= e(input('name')) ?>" placeholder="Dr. Maya Santos" required></div>
      <div class="field"><label for="username">Username</label><input type="text" id="username" name="username" value="<?= e(input('username')) ?>" required></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" minlength="8" required></div>
      <button class="btn btn-primary" type="submit">Create admin account</button>
    <?php endif; ?>
  </form>
</div>
</body>
</html>
