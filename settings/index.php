<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$me = require_login();
// Administrators see everything here; dentists only see and edit the price list.
$admin = can('settings');
if (!$admin) {
    require_perm('fees.manage');
}

if (is_post()) {
    verify_csrf();
    $action = input('action');
    require_perm(str_starts_with($action, 'procedure_') ? 'fees.manage' : 'settings');

    if ($action === 'backup') {
        log_activity('backup_downloaded');
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="welldent-backup-' . date('Y-m-d-His') . '.sql"');
        stream_backup();
        exit;
    }

    if ($action === 'user_add') {
        $username = input('username');
        $password = (string) ($_POST['password'] ?? '');
        $role = input('role');
        if (input('name') === '' || mb_strlen(input('name')) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) || strlen($password) < 8 || !isset(ROLE_LABELS[$role])) {
            flash('error', 'Enter a name, a 3–60 character username, a role, and a password of at least 8 characters.');
        } elseif (q_val('SELECT 1 FROM users WHERE username = ?', [$username])) {
            flash('error', "The username “{$username}” is taken.");
        } else {
            q('INSERT INTO users (name, username, password_hash, role) VALUES (?, ?, ?, ?)', [input('name'), $username, password_hash($password, PASSWORD_DEFAULT), $role]);
            log_activity('user_created', 'user', (int) db()->lastInsertId(), $username);
            flash('success', input('name') . ' can now sign in as ' . $username . '.');
        }
    } elseif ($action === 'user_toggle') {
        $uid = (int) input('id');
        if ($uid === (int) $me['id']) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$uid]);
            log_activity('user_toggled', 'user', $uid);
            flash('success', 'User access updated.');
        }
    } elseif ($action === 'user_reset') {
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) input('id')]);
            if ((int) input('id') === (int) $me['id']) {
                bind_session_to_password((int) $me['id']);
            }
            clear_failed_logins((string) q_val('SELECT username FROM users WHERE id = ?', [(int) input('id')])); // also lifts a sign-in lockout
            log_activity('user_password_reset', 'user', (int) input('id'));
            flash('success', 'Password reset and their other sessions signed out. Share it with the user privately.');
        }
    } elseif ($action === 'procedure_save') {
        procedure_list(); // creates the table on databases set up before the price list existed
        $procId = (int) input('id');
        $name = input('name');
        $fee = input('fee');
        $taken = q_val('SELECT id FROM procedures WHERE name = ? AND id <> ?', [$name, $procId]);
        if ($name === '' || mb_strlen($name) > 120) {
            flash('error', 'Enter a procedure name (up to 120 characters).');
        } elseif (!valid_amount($fee, true)) {
            flash('error', 'Standard fee must be from ₱0 to ' . money(MAX_AMOUNT) . ', with at most 2 decimals.');
        } elseif ($taken) {
            flash('error', "“{$name}” is already on the price list. Edit that one instead.");
        } elseif ($procId) {
            $old = q_row('SELECT name, fee FROM procedures WHERE id = ?', [$procId]);
            q('UPDATE procedures SET name = ?, fee = ? WHERE id = ?', [$name, $fee, $procId]);
            if ($old) {
                log_activity('procedure_updated', 'procedure', $procId, "{$old['name']} " . money($old['fee']) . " → $name " . money($fee));
            }
            flash('success', "$name updated. New charges will use " . money($fee) . '; past charges are unchanged.');
        } else {
            q('INSERT INTO procedures (name, fee) VALUES (?, ?)', [$name, $fee]);
            log_activity('procedure_created', 'procedure', (int) db()->lastInsertId(), "$name " . money($fee));
            flash('success', "$name added at " . money($fee) . '.');
        }
    } elseif ($action === 'procedure_remove') {
        procedure_list();
        $proc = q_row('SELECT * FROM procedures WHERE id = ?', [(int) input('id')]);
        if ($proc) {
            q('DELETE FROM procedures WHERE id = ?', [$proc['id']]);
            log_activity('procedure_removed', 'procedure', (int) $proc['id'], "{$proc['name']} " . money($proc['fee']));
            flash('success', "{$proc['name']} removed from the price list. Past charges are unchanged.");
        }
    }
    redirect(url('settings/'));
}

/** Writes a plain SQL dump of every table to the output. */
function stream_backup(): void
{
    $pdo = db();
    echo "-- WellDent+ backup of `" . config('db.name') . "` taken " . date('Y-m-d H:i:s') . "\n";
    echo "-- Restore: create/select the database in phpMyAdmin, then Import this file.\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\nSET NAMES utf8mb4;\n\n";
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        echo "DROP TABLE IF EXISTS `$table`;\n$create;\n\n";
        $rows = $pdo->query("SELECT * FROM `$table`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
            echo "INSERT INTO `$table` (`" . implode('`, `', array_keys($row)) . '`) VALUES (' . implode(', ', $vals) . ");\n";
        }
        echo "\n";
    }
    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
}

/** IPv4 addresses phones on the same Wi-Fi can use to reach this laptop. */
function lan_addresses(): array
{
    $ips = gethostbynamel(gethostname()) ?: [];
    return array_values(array_filter($ips, fn($ip) => !str_starts_with($ip, '127.')));
}

$users = $admin ? q_all('SELECT * FROM users ORDER BY is_active DESC, name') : [];
$procedures = procedure_list();

layout_start('Settings', 'settings', ['subtitle' => $admin ? 'Clinic users, procedure fees, phone access and backups' : 'Procedures and standard fees']);
?>
<div class="<?= $admin ? 'layout-side' : '' ?>">
  <div class="stack">
  <?php if ($admin): ?>
  <section class="card">
    <div class="card-head"><h2>Clinic users</h2><button class="btn btn-sm btn-primary" data-open="#user-dialog">+ Add user</button></div>
    <div class="table-wrap"><table class="table-cards">
      <thead><tr><th>Name</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $i => $u): ?>
          <tr>
            <td><div class="who"><?= avatar($u['name'], $i) ?><span><strong><?= e($u['name']) ?></strong><small><?= e($u['username']) ?></small></span></div></td>
            <td data-label="Role"><?= e(ROLE_LABELS[$u['role']]) ?></td>
            <td data-label="Last sign-in"><?= $u['last_login_at'] ? e(fmt_date($u['last_login_at'], 'M j, g:i A')) : '<span class="muted">Never</span>' ?></td>
            <td data-label="Status"><?= $u['is_active'] ? pill('active') : pill('cancelled', 'Disabled') ?></td>
            <td class="num" style="white-space:nowrap">
              <button class="link-btn" data-open="#reset-dialog" data-fill='<?= e(json_encode(['id' => $u['id'], 'reset_name' => $u['name']])) ?>'>Reset password</button>
              <?php if ((int) $u['id'] !== (int) $me['id']): ?> ·
                <form method="post" style="display:inline" data-confirm="<?= $u['is_active'] ? 'Disable' : 'Enable' ?> <?= e($u['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="user_toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="link-btn"><?= $u['is_active'] ? 'Disable' : 'Enable' ?></button></form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small" style="margin-top:14px"><b>Admin</b>: everything. <b>Dentist</b>: edits dental charts and procedure fees, archives patients, posts notices. <b>Staff</b>: patients, appointments, billing, inventory and reminders.</p>
  </section>
  <?php endif; ?>

  <section class="card">
    <div class="card-head"><h2>Procedures &amp; fees</h2><button class="btn btn-sm btn-primary" data-open="#procedure-dialog" data-fill='{"id":"","name":"","fee":"","dialog_title":"Add procedure"}'>+ Add procedure</button></div>
    <p class="muted small" style="margin:-6px 0 14px">Picking one of these when adding a treatment or charting a tooth fills in its standard fee. Staff can still change the amount for a discount or special case.</p>
    <div class="table-wrap"><table class="table-cards">
      <thead><tr><th>Procedure</th><th class="num">Standard fee</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($procedures as $p): ?>
          <tr>
            <td><strong><?= e($p['name']) ?></strong></td>
            <td class="num" data-label="Standard fee"><?= money($p['fee']) ?></td>
            <td class="num" style="white-space:nowrap">
              <button class="link-btn" data-open="#procedure-dialog" data-fill='<?= e(json_encode(['id' => $p['id'], 'name' => $p['name'], 'fee' => $p['fee'], 'dialog_title' => 'Edit procedure'])) ?>'>Edit</button> ·
              <form method="post" style="display:inline" data-confirm="Remove <?= e($p['name']) ?> from the price list? Past charges are not affected."><?= csrf_field() ?><input type="hidden" name="action" value="procedure_remove"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="link-btn">Remove</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$procedures): ?><tr><td colspan="3" class="empty">No procedures yet. Add your standard fees so charges fill in automatically.</td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>
  </div>

  <?php if ($admin): ?>
  <div class="stack">
    <section class="card">
      <h2>Phone access</h2>
      <p class="muted small" style="margin-bottom:12px">Phones on the clinic Wi-Fi can open WellDent+ at:</p>
      <?php foreach (lan_addresses() as $ip): ?>
        <p style="margin-bottom:6px"><code>http://<?= e($ip . url()) ?></code></p>
      <?php endforeach; ?>
      <?php if (!lan_addresses()): ?><p class="small">No network address found. Connect the laptop to the clinic Wi-Fi.</p><?php endif; ?>
      <p class="muted small" style="margin-top:12px">If a phone can't connect, allow Apache through Windows Defender Firewall (Private networks).</p>
    </section>

    <section class="card">
      <h2>Backup</h2>
      <p class="muted small" style="margin:10px 0 16px">Download a full copy of the database. Keep it on a USB drive or cloud folder, at least weekly.</p>
      <p class="small" style="margin:-6px 0 16px"><b>The file holds every patient record and the users' password hashes, unencrypted.</b> Keep it in a password-protected or encrypted location, and don't send it by email or chat.</p>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="backup"><button class="btn btn-primary" type="submit">Download backup (.sql)</button></form>
    </section>
  </div>
  <?php endif; ?>
</div>

<?php if ($admin): ?>
<dialog id="user-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Add user</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="user_add">
    <div class="field"><label for="u-name">Full name</label><input type="text" id="u-name" name="name" maxlength="120" required></div>
    <div class="row">
      <div class="field spacer"><label for="u-user">Username</label><input type="text" id="u-user" name="username" required pattern="[A-Za-z0-9._-]{3,60}"></div>
      <div class="field spacer"><label for="u-role">Role</label><select id="u-role" name="role"><?php foreach (ROLE_LABELS as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, 'staff') ?>><?= $lbl ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="u-pass">Temporary password</label><input type="text" id="u-pass" name="password" minlength="8" required autocomplete="off"><span class="hint">They can change it under their name → Change password.</span></div>
    <div><button class="btn btn-primary" type="submit">Create user</button></div>
  </form>
</dialog>
<?php endif; ?>

<dialog id="procedure-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2 data-text="dialog_title">Add procedure</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="procedure_save"><input type="hidden" name="id">
    <div class="field"><label for="p-name">Procedure</label><input type="text" id="p-name" name="name" maxlength="120" required placeholder="e.g. Oral prophylaxis"></div>
    <div class="field"><label for="p-fee">Standard fee (₱)</label><input type="number" id="p-fee" name="fee" min="0" max="<?= MAX_AMOUNT ?>" step="0.01" required><span class="hint">Changing a fee only affects new charges.</span></div>
    <div><button class="btn btn-primary" type="submit">Save procedure</button></div>
  </form>
</dialog>

<?php if ($admin): ?>
<dialog id="reset-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Reset password</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <p>New password for <b data-text="reset_name"></b></p>
    <?= csrf_field() ?><input type="hidden" name="action" value="user_reset"><input type="hidden" name="id">
    <div class="field"><label for="r-pass">New password</label><input type="text" id="r-pass" name="password" minlength="8" required autocomplete="off"></div>
    <div><button class="btn btn-primary" type="submit">Reset</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php layout_end();
