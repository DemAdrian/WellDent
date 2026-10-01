<?php

function icon(string $name): string
{
    $paths = [
        'dashboard'    => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'appointments' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'patients'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
        'inventory'    => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'reminders'    => '<circle cx="12" cy="13" r="7"/><path d="M12 10v3l2 2M5 4 3 6M19 4l2 2"/>',
        'reports'      => '<path d="M6 3h9l4 4v14H6z"/><path d="M9 12h7M9 16h7M9 8h3"/>',
        'settings'     => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'logout'       => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'theme'        => '<path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z"/>',
        'key'          => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
        'menu'         => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron'      => '<path d="M9 6l6 6-6 6"/>',
    ];
    return '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Applies the saved theme (or the OS preference) and the collapsed sidebar before the
 * stylesheet paints, so neither flashes on load. Both are per device, kept in localStorage.
 */
function theme_head(): string
{
    return "<script>(function(){var p;try{p=localStorage.getItem('welldent-theme')}catch(e){}"
        . "var d=p==='dark'||(p!=='light'&&window.matchMedia('(prefers-color-scheme: dark)').matches);"
        . "document.documentElement.setAttribute('data-theme',d?'dark':'light');"
        . "try{if(localStorage.getItem('welldent-sidebar')==='collapsed')document.documentElement.setAttribute('data-sidebar','collapsed')}catch(e){}})()</script>\n"
        . '<link rel="stylesheet" href="assets/css/app.css?v=3">';
}

/** Current chair state for the sidebar card. */
function chair_status(): array
{
    $chairs = (int) config('clinic.chairs');
    if ($chairs > 1) {
        $busy = (int) q_val("SELECT COUNT(DISTINCT chair) FROM appointments WHERE DATE(starts_at) = CURDATE() AND status = 'in_treatment'");
        $next = q_val("SELECT MIN(starts_at) FROM appointments WHERE starts_at > NOW() AND DATE(starts_at) = CURDATE() AND status IN ('pending','confirmed')");
        return ["Chairs · $busy of $chairs in use", $next ? 'Next patient ' . fmt_time($next) : 'No more patients today'];
    }

    $current = q_row("SELECT a.starts_at, a.duration_min, a.status FROM appointments a
        WHERE DATE(a.starts_at) = CURDATE() AND a.status IN ('in_treatment','arrived')
        ORDER BY FIELD(a.status, 'in_treatment', 'arrived'), a.starts_at LIMIT 1");
    $next = q_row("SELECT a.starts_at FROM appointments a
        WHERE a.starts_at > NOW() AND DATE(a.starts_at) = CURDATE() AND a.status IN ('pending','confirmed')
        ORDER BY a.starts_at LIMIT 1");

    if ($current && $current['status'] === 'in_treatment') {
        $end = strtotime($current['starts_at']) + $current['duration_min'] * 60;
        return ['Chair 1 · In treatment', 'Next turnover ' . date('g:i A', $end)];
    }
    if ($current) {
        return ['Chair 1 · Patient waiting', 'Scheduled ' . fmt_time($current['starts_at'])];
    }
    return ['Chair 1 · Available', $next ? 'Next patient ' . fmt_time($next['starts_at']) : 'No more patients today'];
}

/**
 * Opens the page shell. $opts: subtitle, action (html for the header button).
 */
function layout_start(string $title, string $active, array $opts = []): void
{
    $user = current_user();
    $nav = [
        'dashboard'    => ['index.php', 'Dashboard'],
        'appointments' => ['appointments.php', 'Appointments'],
        'patients'     => ['patients.php', 'Patients'],
        'inventory'    => ['inventory.php', 'Inventory'],
        'reminders'    => ['reminders.php', 'Reminders'],
        'reports'      => ['reports.php', 'Reports'],
    ];
    if (can('settings')) {
        $nav['settings'] = ['settings.php', 'Settings'];
    }
    [$chairState, $chairNote] = chair_status();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · WellDent+</title>
<?= theme_head() ?>
</head>
<body>
<div class="shell">
  <aside class="sidebar">
    <div class="side-top">
      <a class="brand" href="index.php" title="<?= e(config('clinic.name')) ?>">
        <span class="brand-mark">D</span>
        <span class="lbl"><strong><?= e(config('clinic.name')) ?></strong><small><?= e(config('clinic.tagline')) ?></small></span>
      </a>
      <button type="button" class="side-toggle" data-sidebar-toggle aria-label="Collapse sidebar" title="Collapse sidebar"><?= icon('menu') ?></button>
    </div>
    <nav class="nav">
      <?php foreach ($nav as $key => [$href, $label]): ?>
        <a href="<?= $href ?>" class="<?= $key === $active ? 'active' : '' ?>" title="<?= e($label) ?>"><?= icon($key) ?><span class="lbl"><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <details class="side-account">
      <summary title="<?= e($user['name']) ?>">
        <span class="avatar avatar-0"><?= e(initials($user['name'])) ?></span>
        <span class="lbl"><strong><?= e($user['name']) ?></strong><small><?= e(ROLE_LABELS[$user['role']]) ?> · <?= e(config('clinic.city')) ?></small></span>
        <span class="chev"><?= icon('chevron') ?></span>
      </summary>
      <div class="side-account-menu">
        <button type="button" data-theme-toggle title="Theme"><?= icon('theme') ?><span class="lbl">Theme: <span data-theme-label>System</span></span></button>
        <a href="account.php" title="Change password"><?= icon('key') ?><span class="lbl">Change password</span></a>
        <a href="logout.php" title="Sign out"><?= icon('logout') ?><span class="lbl">Sign out</span></a>
      </div>
    </details>
    <div class="chair-card">
      <small>Chair status</small>
      <strong><?= e($chairState) ?></strong>
      <span><?= e($chairNote) ?></span>
    </div>
  </aside>
  <main class="main">
    <header class="page-head">
      <div>
        <h1><?= e($title) ?></h1>
        <?php if (!empty($opts['subtitle'])): ?><p class="muted"><?= e($opts['subtitle']) ?></p><?php endif; ?>
      </div>
      <div class="head-actions">
        <?= $opts['action'] ?? '' ?>
      </div>
    </header>
    <?php foreach (take_flashes() as $f): ?>
      <div class="flash flash-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
<?php
}

function layout_end(): void
{
    ?>
  </main>
</div>
<script src="assets/js/app.js?v=3"></script>
</body>
</html>
<?php
}
