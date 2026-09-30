<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$today = q_all("SELECT a.*, p.full_name FROM appointments a JOIN patients p ON p.id = a.patient_id
    WHERE DATE(a.starts_at) = CURDATE() AND a.status NOT IN ('cancelled','no_show')
    ORDER BY a.starts_at");
$confirmed = count(array_filter($today, fn($a) => $a['status'] === 'confirmed'));
$waiting = count(array_filter($today, fn($a) => $a['status'] === 'arrived'));

$balances = q_row('SELECT COALESCE(SUM(CASE WHEN b.balance > 0 THEN b.balance END), 0) AS total,
    COUNT(CASE WHEN b.balance > 0 THEN 1 END) AS accounts
    FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id WHERE p.status <> 'archived'");

$reminders = q_row("SELECT COUNT(*) AS total, COUNT(CASE WHEN channel = 'sms' THEN 1 END) AS sms
    FROM reminders WHERE status = 'scheduled'");

$lowStock = q_all('SELECT name, quantity, min_quantity, unit FROM inventory_items
    WHERE is_active = 1 AND quantity <= min_quantity ORDER BY quantity / GREATEST(min_quantity, 1)');
$critical = count(array_filter($lowStock, fn($i) => $i['quantity'] <= intdiv((int) $i['min_quantity'], 2)));

// Patient flow: appointments attended or booked per day for the last 7 days.
$flowRows = q_all("SELECT DATE(starts_at) AS d, COUNT(*) AS n FROM appointments
    WHERE starts_at >= CURDATE() - INTERVAL 6 DAY AND starts_at < CURDATE() + INTERVAL 1 DAY
    AND status NOT IN ('cancelled','no_show') GROUP BY DATE(starts_at)");
$flowMap = array_column($flowRows, 'n', 'd');
$flow = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $flow[$d] = (int) ($flowMap[$d] ?? 0);
}
$flowMax = max(1, max($flow));

// Attention needed: things someone should act on today.
$attention = [];
foreach (q_all('SELECT message FROM notices WHERE CURDATE() BETWEEN starts_on AND ends_on ORDER BY id DESC') as $n) {
    $attention[] = ['', $n['message'], null];
}
foreach (array_slice($lowStock, 0, 3) as $item) {
    $attention[] = ['red', "{$item['name']} low: {$item['quantity']} {$item['unit']} left (min {$item['min_quantity']})", 'inventory.php?filter=low'];
}
$pending = count(array_filter($today, fn($a) => $a['status'] === 'pending'));
if ($pending) {
    $attention[] = ['', "$pending appointment" . ($pending > 1 ? 's' : '') . ' today still unconfirmed', 'appointments.php'];
}
$failed = (int) q_val("SELECT COUNT(*) FROM reminders WHERE status = 'failed'");
if ($failed) {
    $attention[] = ['red', "$failed reminder" . ($failed > 1 ? 's' : '') . ' failed to send', 'reminders.php?tab=failed'];
}
$incomplete = (int) q_val("SELECT COUNT(DISTINCT p.id) FROM patients p JOIN appointments a ON a.patient_id = p.id
    WHERE p.status IN ('incomplete','draft') AND a.starts_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY AND a.status NOT IN ('cancelled','no_show')");
if ($incomplete) {
    $attention[] = ['', "$incomplete upcoming patient" . ($incomplete > 1 ? 's have' : ' has') . ' an incomplete chart', 'patients.php?status=incomplete'];
}

$accounts = q_all('SELECT p.id, p.full_name, b.balance FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id
    WHERE p.status <> 'archived' AND b.balance <> 0 ORDER BY ABS(b.balance) DESC LIMIT 5");

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$nameParts = preg_split('/\s+/', preg_replace('/^Dr\.?\s+/i', '', $user['name']));
$name = $user['role'] === 'dentist' ? 'Dr. ' . end($nameParts) : $nameParts[0];

layout_start("$greeting, $name", 'dashboard', [
    'subtitle' => 'A calm view of your clinic today',
    'action'   => '<a class="btn btn-primary" href="appointment_form.php">+ New appointment</a>',
]);
?>
<section class="grid-4">
  <a class="card stat" href="appointments.php">
    <i class="dot" style="background:var(--teal)"></i>
    <div class="label">Patients today</div>
    <div class="value"><?= count($today) ?></div>
    <div class="sub"><?= $confirmed ?> confirmed · <?= $waiting ?> waiting</div>
  </a>
  <a class="card stat" href="patients.php?filter=balance">
    <i class="dot" style="background:var(--coral)"></i>
    <div class="label">Pending balances</div>
    <div class="value"><?= money($balances['total']) ?></div>
    <div class="sub"><?= (int) $balances['accounts'] ?> open accounts</div>
  </a>
  <a class="card stat" href="reminders.php">
    <i class="dot" style="background:#e0b23c"></i>
    <div class="label">Reminders due</div>
    <div class="value"><?= (int) $reminders['total'] ?></div>
    <div class="sub"><?= (int) $reminders['sms'] ?> SMS scheduled</div>
  </a>
  <a class="card stat" href="inventory.php?filter=low">
    <i class="dot" style="background:var(--blue)"></i>
    <div class="label">Low stock</div>
    <div class="value"><?= count($lowStock) ?></div>
    <div class="sub"><?= $critical ?> item<?= $critical === 1 ? '' : 's' ?> critical</div>
  </a>
</section>

<div class="layout-side">
  <div class="stack">
    <section class="card">
      <h2>Today · <?= date('l, F j') ?></h2>
      <?php if (!$today): ?>
        <p class="empty">No appointments today. <a href="appointment_form.php">Book one</a></p>
      <?php else: ?>
        <div class="sched">
          <?php foreach ($today as $a): ?>
            <a class="sched-row<?= in_array($a['status'], ['arrived', 'in_treatment'], true) ? ' is-now' : '' ?>" href="patient.php?id=<?= $a['patient_id'] ?>" style="color:inherit;text-decoration:none">
              <span class="time"><?= fmt_time($a['starts_at']) ?></span>
              <span class="who-line"><strong><?= e($a['full_name']) ?></strong><small><?= e($a['procedure_name']) ?></small></span>
              <?= pill($a['status']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Patient flow · last 7 days</h2>
      <div class="bars" role="img" aria-label="Appointments per day for the last 7 days">
        <?php foreach ($flow as $d => $n): ?>
          <div class="bar<?= $d === date('Y-m-d') ? ' today' : '' ?>" title="<?= e(fmt_date($d, 'D, M j')) ?>: <?= $n ?> patients">
            <b><?= $n ?></b>
            <span class="fill" style="height:<?= round($n / $flowMax * 85) ?>%"></span>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="bar-labels">
        <?php foreach (array_keys($flow) as $d): ?><span><?= $d === date('Y-m-d') ? 'Today' : date('D', strtotime($d)) ?></span><?php endforeach; ?>
      </div>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <h2>Quick actions</h2>
      <div class="row">
        <a class="btn btn-primary" href="patient_form.php">Add patient</a>
        <a class="btn" href="payment.php">Log payment</a>
        <a class="btn" href="inventory.php?open=stock-dialog">Stock in</a>
      </div>
    </section>

    <section class="card">
      <h2>Attention needed</h2>
      <?php if (!$attention): ?>
        <p class="muted small">All clear. Nothing needs attention right now.</p>
      <?php else: ?>
        <ul class="attention">
          <?php foreach ($attention as [$tone, $text, $href]): ?>
            <li><i class="tag <?= $tone ?>"></i><span><?= $href ? '<a href="' . e($href) . '" style="color:inherit">' . e($text) . '</a>' : e($text) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Balances &amp; credits</h2>
      <?php if (!$accounts): ?>
        <p class="muted small">No open balances or credits.</p>
      <?php else: ?>
        <ul class="kv">
          <?php foreach ($accounts as $acc): ?>
            <li>
              <a href="patient.php?id=<?= $acc['id'] ?>&amp;tab=billing" class="<?= $acc['balance'] > 0 ? '' : 'amount-credit' ?>" style="<?= $acc['balance'] > 0 ? 'color:inherit' : '' ?>">
                <?= e($acc['full_name']) ?> · <?= money($acc['balance']) ?> <?= $acc['balance'] > 0 ? 'due' : 'credit' ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php layout_end();
