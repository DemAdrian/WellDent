<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'item_save') {
        $itemId = (int) input('id');
        $name = input('name');
        $min = max(0, (int) input('min_quantity'));
        $tooLong = length_errors(['name' => $name, 'category' => input('category'), 'unit' => input('unit'), 'supplier' => input('supplier')],
            ['name' => ['Name', 120], 'category' => ['Category', 60], 'unit' => ['Unit', 30], 'supplier' => ['Supplier', 120]]);
        if ($name === '') {
            flash('error', 'Item name is required.');
        } elseif ($tooLong) {
            flash('error', implode(' ', $tooLong));
        } elseif ($min > MAX_QUANTITY || (int) input('quantity') > MAX_QUANTITY) {
            flash('error', 'Quantities must be ' . number_format(MAX_QUANTITY) . ' or less.');
        } elseif ($itemId) {
            q('UPDATE inventory_items SET name = ?, category = ?, unit = ?, min_quantity = ?, supplier = ? WHERE id = ?',
                [$name, nullable(input('category')), input('unit') ?: 'pcs', $min, nullable(input('supplier')), $itemId]);
            log_activity('item_updated', 'inventory', $itemId, $name);
            flash('success', "$name updated.");
        } else {
            $qty = max(0, (int) input('quantity'));
            q('INSERT INTO inventory_items (name, category, unit, quantity, min_quantity, supplier) VALUES (?, ?, ?, ?, ?, ?)',
                [$name, nullable(input('category')), input('unit') ?: 'pcs', $qty, $min, nullable(input('supplier'))]);
            $itemId = (int) db()->lastInsertId();
            if ($qty > 0) {
                q("INSERT INTO stock_movements (item_id, type, quantity, reason, user_id) VALUES (?, 'in', ?, 'Opening stock', ?)", [$itemId, $qty, $user['id']]);
            }
            log_activity('item_created', 'inventory', $itemId, $name);
            flash('success', "$name added.");
        }
    } elseif ($action === 'move') {
        $itemId = (int) input('item_id');
        $type = input('type') === 'out' ? 'out' : 'in';
        $qty = (int) input('quantity');
        $item = q_row('SELECT * FROM inventory_items WHERE id = ?', [$itemId]);
        if (!$item || $qty <= 0 || $qty > MAX_QUANTITY) {
            flash('error', 'Choose an item and a quantity from 1 to ' . number_format(MAX_QUANTITY) . '.');
        } elseif (mb_strlen(input('reason')) > 160) {
            flash('error', 'Reason must be at most 160 characters.');
        } elseif ($type === 'out' && $qty > $item['quantity']) {
            flash('error', "Only {$item['quantity']} {$item['unit']} of {$item['name']} in stock.");
        } else {
            db()->beginTransaction();
            // The quantity guard stops two devices from using the same last units at once.
            $changed = $type === 'in'
                ? q('UPDATE inventory_items SET quantity = quantity + ? WHERE id = ?', [$qty, $itemId])->rowCount()
                : q('UPDATE inventory_items SET quantity = quantity - ? WHERE id = ? AND quantity >= ?', [$qty, $itemId, $qty])->rowCount();
            if ($changed === 0) {
                db()->rollBack();
                flash('error', "Not enough {$item['name']} in stock. Someone may have just used some; check the list and try again.");
            } else {
                q('INSERT INTO stock_movements (item_id, type, quantity, reason, user_id) VALUES (?, ?, ?, ?, ?)',
                    [$itemId, $type, $qty, nullable(input('reason')), $user['id']]);
                db()->commit();
                log_activity('stock_' . $type, 'inventory', $itemId, "$qty {$item['unit']} {$item['name']}");
                flash('success', ($type === 'in' ? 'Added ' : 'Used ') . "$qty {$item['unit']} of {$item['name']}.");
            }
        }
    } elseif ($action === 'item_remove') {
        q('UPDATE inventory_items SET is_active = 0 WHERE id = ?', [(int) input('id')]);
        flash('success', 'Item removed from the list.');
    }
    redirect('inventory.php' . (input('filter') === 'low' ? '?filter=low' : ''));
}

$filter = input('filter');
$search = input('q');
$sql = 'SELECT * FROM inventory_items WHERE is_active = 1';
$params = [];
if ($filter === 'low') {
    $sql .= ' AND quantity <= min_quantity';
}
if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR category LIKE ? OR supplier LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%");
}
$items = q_all($sql . ' ORDER BY category, name', $params);
$allItems = q_all('SELECT id, name, unit, quantity FROM inventory_items WHERE is_active = 1 ORDER BY name');
$moves = q_all('SELECT m.*, i.name, i.unit, u.name AS user_name FROM stock_movements m
    JOIN inventory_items i ON i.id = m.item_id LEFT JOIN users u ON u.id = m.user_id ORDER BY m.id DESC LIMIT 15');

function stock_level(array $item): string
{
    if ($item['quantity'] <= intdiv((int) $item['min_quantity'], 2)) return 'critical';
    if ($item['quantity'] <= $item['min_quantity']) return 'low';
    return 'ok';
}

layout_start('Inventory', 'inventory', [
    'subtitle' => 'Supplies on hand, with alerts before anything runs out',
    'action'   => '<button class="btn btn-primary" data-open="#stock-dialog">Stock in / out</button>',
]);
?>
<div class="toolbar">
  <form method="get" class="spacer" role="search">
    <?php if ($filter): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search item, category or supplier" aria-label="Search inventory" style="background:#fff">
  </form>
  <div class="row">
    <a class="btn<?= $filter !== 'low' ? ' is-on' : '' ?>" href="inventory.php">All items</a>
    <a class="btn<?= $filter === 'low' ? ' is-on' : '' ?>" href="inventory.php?filter=low">Low stock</a>
    <button class="btn" data-open="#item-dialog" data-fill='{"id":"","name":"","category":"","unit":"pcs","quantity":"0","min_quantity":"0","supplier":"","dialog_title":"Add item"}'>+ Add item</button>
  </div>
</div>

<div class="layout-side">
  <section class="card">
    <div class="table-wrap"><table>
      <thead><tr><th>Item</th><th>Category</th><th class="num">On hand</th><th class="num">Minimum</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): $level = stock_level($it); ?>
          <tr>
            <td><strong><?= e($it['name']) ?></strong><?= $it['supplier'] ? '<br><small class="muted">' . e($it['supplier']) . '</small>' : '' ?></td>
            <td><?= e($it['category'] ?: '—') ?></td>
            <td class="num"><?= (int) $it['quantity'] ?> <?= e($it['unit']) ?></td>
            <td class="num"><?= (int) $it['min_quantity'] ?></td>
            <td><?= pill($level, ['ok' => 'In stock', 'low' => 'Low', 'critical' => 'Critical'][$level]) ?></td>
            <td class="num" style="white-space:nowrap">
              <button class="link-btn" data-open="#stock-dialog" data-fill='<?= e(json_encode(['item_id' => $it['id'], 'type' => 'in'])) ?>'>Stock</button> ·
              <button class="link-btn" data-open="#item-dialog" data-fill='<?= e(json_encode(array_intersect_key($it, array_flip(['id', 'name', 'category', 'unit', 'quantity', 'min_quantity', 'supplier'])) + ['dialog_title' => 'Edit item'])) ?>'>Edit</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="6" class="empty"><?= $filter === 'low' ? 'Nothing is running low.' : 'No items yet. Add your supplies to start tracking stock.' ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
  </section>

  <section class="card">
    <h2>Recent movements</h2>
    <?php if (!$moves): ?><p class="muted small">No stock movements yet.</p><?php endif; ?>
    <ul class="kv">
      <?php foreach ($moves as $m): ?>
        <li>
          <b class="<?= $m['type'] === 'in' ? 'amount-paid' : 'amount-due' ?>"><?= $m['type'] === 'in' ? '+' : '−' ?><?= (int) $m['quantity'] ?></b>
          <?= e($m['unit']) ?> <?= e($m['name']) ?><?= $m['reason'] ? ' · ' . e($m['reason']) : '' ?>
          <br><small class="muted"><?= e(fmt_date($m['created_at'], 'M j, g:i A')) ?> · <?= e($m['user_name'] ?? '—') ?></small>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
</div>

<dialog id="stock-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2>Stock in / out</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="filter" value="<?= e($filter) ?>">
    <div class="field"><label for="s-item">Item</label>
      <select id="s-item" name="item_id" required><option value="">Choose item…</option>
        <?php foreach ($allItems as $it): ?><option value="<?= $it['id'] ?>"><?= e($it['name']) ?> (<?= (int) $it['quantity'] ?> <?= e($it['unit']) ?>)</option><?php endforeach; ?>
      </select></div>
    <div class="row">
      <div class="field spacer"><label for="s-type">Movement</label><select id="s-type" name="type"><option value="in">Stock in (received)</option><option value="out">Stock out (used)</option></select></div>
      <div class="field spacer"><label for="s-qty">Quantity</label><input type="number" id="s-qty" name="quantity" min="1" max="<?= MAX_QUANTITY ?>" required></div>
    </div>
    <div class="field"><label for="s-reason">Reason</label><input type="text" id="s-reason" name="reason" maxlength="160" placeholder="Supplier delivery, used in procedure…"></div>
    <div><button class="btn btn-primary" type="submit">Save</button></div>
  </form>
</dialog>

<dialog id="item-dialog">
  <form class="dialog-body" method="post">
    <div class="dialog-head"><h2 data-text="dialog_title">Add item</h2><button type="button" class="close" aria-label="Close">×</button></div>
    <?= csrf_field() ?><input type="hidden" name="action" value="item_save"><input type="hidden" name="id">
    <div class="field"><label for="i-name">Name</label><input type="text" id="i-name" name="name" maxlength="120" required placeholder="Nitrile gloves (M)"></div>
    <div class="row">
      <div class="field spacer"><label for="i-cat">Category</label><input type="text" id="i-cat" name="category" maxlength="60" list="cats" placeholder="Consumables"></div>
      <div class="field spacer"><label for="i-unit">Unit</label><input type="text" id="i-unit" name="unit" maxlength="30" placeholder="box, pcs, ml"></div>
    </div>
    <datalist id="cats"><option value="Consumables"><option value="Restorative"><option value="Orthodontic"><option value="Anesthetics"><option value="Sterilization"><option value="Equipment"></datalist>
    <div class="row">
      <div class="field spacer"><label for="i-qty">Opening quantity</label><input type="number" id="i-qty" name="quantity" min="0" max="<?= MAX_QUANTITY ?>"><span class="hint">Change stock later with Stock in / out.</span></div>
      <div class="field spacer"><label for="i-min">Alert at or below</label><input type="number" id="i-min" name="min_quantity" min="0" max="<?= MAX_QUANTITY ?>"></div>
    </div>
    <div class="field"><label for="i-sup">Supplier</label><input type="text" id="i-sup" name="supplier" maxlength="120"></div>
    <div><button class="btn btn-primary" type="submit">Save item</button></div>
  </form>
</dialog>
<?php layout_end();
