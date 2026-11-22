<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$today = q_all("SELECT a.*, p.full_name FROM appointments a JOIN patients p ON p.id = a.patient_id
    WHERE DATE(a.starts_at) = CURDATE() AND a.status NOT IN ('cancelled','no_show')
    ORDER BY a.starts_at");
$confirmed = count(array_filter($today, fn($a) => $a['status'] === 'confirmed'));
$waiting = count(array_filter($today, fn($a) => $a['status'] === 'arrived'));

$date = new DateTimeImmutable('today');
$periods = [
    'today' => ['Today', $date],
    'week' => ['This week', $date->modify('monday this week')],
    'recent' => ['Last 7 days', $date->modify('-6 days')],
    'month' => ['This month', $date->modify('first day of this month')],
];
$period = input('period', 'recent');
if (!isset($periods[$period])) {
    $period = 'recent';
}
[$periodLabel, $from] = $periods[$period];
$range = [$from->format('Y-m-d'), $date->modify('+1 day')->format('Y-m-d')];
$rangeLabel = $from == $date ? $date->format('M j, Y') : $from->format('M j') . ' – ' . $date->format('M j, Y');
$reportLink = url('reports/') . '?' . http_build_query(['from' => $range[0], 'to' => $date->format('Y-m-d')]);

$income = q_row('SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS payments FROM payments
    WHERE paid_on >= ? AND paid_on < ? AND voided_at IS NULL', $range);
$reminders = q_row("SELECT COUNT(*) AS total, COUNT(CASE WHEN send_at <= NOW() THEN 1 END) AS due
    FROM reminders WHERE status = 'scheduled'");
$lowStock = q_all('SELECT name, quantity, min_quantity, unit FROM inventory_items
    WHERE is_active = 1 AND quantity <= min_quantity ORDER BY quantity / GREATEST(min_quantity, 1)');
$critical = count(array_filter($lowStock, fn($i) => $i['quantity'] <= intdiv((int) $i['min_quantity'], 2)));

$flowRows = q_all("SELECT DATE(starts_at) AS d, COUNT(*) AS n FROM appointments
    WHERE starts_at >= ? AND starts_at < ? AND status NOT IN ('cancelled','no_show') GROUP BY DATE(starts_at)", $range);
$flowMap = array_column($flowRows, 'n', 'd');
$flow = [];
for ($day = $from; $day <= $date; $day = $day->modify('+1 day')) {
    $key = $day->format('Y-m-d');
    $flow[$key] = (int) ($flowMap[$key] ?? 0);
}
$flowMax = max(1, max($flow));

$procedures = q_all('SELECT procedure_name, COUNT(*) AS n FROM treatments
    WHERE performed_on >= ? AND performed_on < ? AND voided_at IS NULL
    GROUP BY procedure_name ORDER BY n DESC, procedure_name', $range);
$procedureTotal = array_sum(array_column($procedures, 'n'));
if (count($procedures) > 6) {
    $procedures = array_slice($procedures, 0, 5);
    $procedures[] = ['procedure_name' => 'Other procedures', 'n' => $procedureTotal - array_sum(array_column($procedures, 'n'))];
}
$pieColors = ['#458e85', '#ad814b', '#729167', '#657fa4', '#ae6a71', '#7c6d90'];
$pieStops = [];
$offset = 0;
foreach ($procedures as $i => $procedure) {
    $end = $offset + 100 * $procedure['n'] / $procedureTotal;
    $pieStops[] = sprintf('%s %.5F%% %.5F%%', $pieColors[$i], $offset, $end);
    $offset = $end;
}

// Attention needed: things someone should act on today.
$attention = [];
foreach (q_all('SELECT message FROM notices WHERE CURDATE() BETWEEN starts_on AND ends_on ORDER BY id DESC') as $n) {
    $attention[] = ['', $n['message'], null];
}
foreach (array_slice($lowStock, 0, 3) as $item) {
    $attention[] = ['red', "{$item['name']} low: {$item['quantity']} {$item['unit']} left (min {$item['min_quantity']})", url('inventory/') . '?filter=low'];
}
$pending = count(array_filter($today, fn($a) => $a['status'] === 'pending'));
if ($pending) {
    $attention[] = ['', "$pending appointment" . ($pending > 1 ? 's' : '') . ' today still unconfirmed', url('appointments/')];
}
$failed = (int) q_val("SELECT COUNT(*) FROM reminders WHERE status = 'failed'");
if ($failed) {
    $attention[] = ['red', "$failed reminder" . ($failed > 1 ? 's' : '') . ' failed to send', url('reminders/') . '?tab=failed'];
}
$incomplete = (int) q_val("SELECT COUNT(DISTINCT p.id) FROM patients p JOIN appointments a ON a.patient_id = p.id
    WHERE p.status IN ('incomplete','draft') AND a.starts_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY AND a.status NOT IN ('cancelled','no_show')");
if ($incomplete) {
    $attention[] = ['', "$incomplete upcoming patient" . ($incomplete > 1 ? 's have' : ' has') . ' an incomplete chart', url('patients/') . '?status=incomplete'];
}

$accounts = q_all('SELECT p.id, p.full_name, b.balance FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id
    WHERE p.status <> 'archived' AND b.balance <> 0 ORDER BY ABS(b.balance) DESC LIMIT 5");

$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$nameParts = preg_split('/\s+/', preg_replace('/^Dr\.?\s+/i', '', $user['name']));
$name = $user['role'] === 'dentist' ? 'Dr. ' . end($nameParts) : $nameParts[0];

layout_start("$greeting, $name", 'dashboard', [
    'subtitle' => $date->format('l, F j, Y'),
]);
?>
<section class="dashboard-overview">
  <div class="dashboard-overview-head">
    <div><h2>Clinic overview</h2><p class="muted"><?= e($rangeLabel) ?></p></div>
    <form method="get" class="dashboard-filter">
      <label for="dashboard-period">Date range</label>
      <div class="row">
        <select id="dashboard-period" name="period">
          <?php foreach ($periods as $key => [$label]): ?>
            <option value="<?= $key ?>"<?= selected($key, $period) ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Apply</button>
      </div>
    </form>
  </div>
  <div class="grid-4 dashboard-stats">
    <a class="card stat" href="<?= url('appointments/') ?>">
      <i class="dot" style="background:var(--teal)"></i>
      <div class="label">Patients today</div>
      <div class="value"><?= count($today) ?></div>
      <div class="sub"><?= $confirmed ?> confirmed · <?= $waiting ?> waiting</div>
    </a>
    <a class="card stat dashboard-income" href="<?= e($reportLink) ?>">
      <i class="dot" style="background:var(--amber-ink)"></i>
      <div class="label">Total income</div>
      <div class="value"><?= money($income['total']) ?></div>
      <div class="sub"><?= (int) $income['payments'] ?> payments · <?= e($periodLabel) ?></div>
    </a>
    <a class="card stat" href="<?= url('reminders/') ?>">
      <i class="dot" style="background:#e0b23c"></i>
      <div class="label">Reminders scheduled</div>
      <div class="value"><?= (int) $reminders['total'] ?></div>
      <div class="sub"><?= (int) $reminders['due'] ?> due now</div>
    </a>
    <a class="card stat" href="<?= url('inventory/') ?>?filter=low">
      <i class="dot" style="background:var(--blue)"></i>
      <div class="label">Low stock</div>
      <div class="value"><?= count($lowStock) ?></div>
      <div class="sub"><?= $critical ?> currently critical</div>
    </a>
  </div>
</section>

<section class="card dashboard-actions">
  <h2>Quick actions</h2>
  <div class="row">
    <a class="btn btn-primary" href="<?= url('appointments/form.php') ?>">+ New appointment</a>
    <a class="btn" href="<?= url('patients/form.php') ?>">Add patient</a>
    <a class="btn" href="<?= url('patients/payment.php') ?>">Log payment</a>
    <a class="btn" href="<?= url('inventory/') ?>?open=stock-dialog">Stock in</a>
  </div>
</section>

<div class="dashboard-columns">
  <div class="stack">
    <section class="card">
      <h2>Today · <?= date('l, F j') ?></h2>
      <?php if (!$today): ?>
        <p class="empty">No appointments today. <a href="<?= url('appointments/form.php') ?>">Book one</a></p>
      <?php else: ?>
        <div class="sched">
          <?php foreach ($today as $a): ?>
            <a class="sched-row<?= in_array($a['status'], ['arrived', 'in_treatment'], true) ? ' is-now' : '' ?>" href="<?= url('patients/view.php') ?>?id=<?= $a['patient_id'] ?>" style="color:inherit;text-decoration:none">
              <span class="time"><?= fmt_time($a['starts_at']) ?></span>
              <span class="who-line"><strong><?= e($a['full_name']) ?></strong><small><?= e($a['procedure_name']) ?></small></span>
              <?= pill($a['status']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head"><h2>Patient flow</h2><span class="muted small"><?= e($periodLabel) ?></span></div>
      <div class="dashboard-flow" aria-label="Appointments per day" role="list">
        <?php foreach ($flow as $day => $count): ?>
          <div class="dashboard-flow-day" role="listitem" aria-label="<?= e(fmt_date($day, 'M j')) ?> · <?= $count ?> appointments">
            <strong><?= $count ?></strong>
            <div class="dashboard-flow-track"><span style="height:<?= round($count / $flowMax * 100) ?>%"></span></div>
            <small><?= e(fmt_date($day, count($flow) > 7 ? 'j' : 'D')) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <div class="stack">
    <section class="card">
      <div class="card-head"><h2>Procedures</h2><span class="muted small"><?= e($periodLabel) ?></span></div>
      <?php if ($procedures): ?>
        <div class="dashboard-procedures">
          <div class="dashboard-pie" aria-hidden="true" style="background:conic-gradient(<?= e(implode(', ', $pieStops)) ?>)"></div>
          <ul class="dashboard-legend">
            <?php foreach ($procedures as $i => $procedure): ?>
              <li><i aria-hidden="true" style="background:<?= $pieColors[$i] ?>"></i><span><?= e($procedure['procedure_name']) ?></span><strong><?= (int) $procedure['n'] ?></strong></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <p class="muted small dashboard-procedure-total"><?= $procedureTotal ?> recorded treatments</p>
      <?php else: ?>
        <p class="empty">No treatments recorded in this date range.</p>
      <?php endif; ?>
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
              <a href="<?= url('patients/view.php') ?>?id=<?= $acc['id'] ?>&amp;tab=billing" class="<?= $acc['balance'] > 0 ? '' : 'amount-credit' ?>" style="<?= $acc['balance'] > 0 ? 'color:inherit' : '' ?>">
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