<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$values = [
    'patient_id' => input('patient_id'),
    'amount'     => '',
    'method'     => 'cash',
    'reference'  => '',
    'paid_on'    => date('Y-m-d'),
    'notes'      => '',
];

$errors = [];
if (is_post()) {
    verify_csrf();
    foreach (array_keys($values) as $key) {
        $values[$key] = input($key);
    }
    $patientId = (int) $values['patient_id'];
    if (!q_val('SELECT 1 FROM patients WHERE id = ?', [$patientId])) $errors[] = 'Choose a patient.';
    if (!valid_amount($values['amount'])) $errors[] = 'Enter an amount from ₱0.01 to ' . money(MAX_AMOUNT) . ', with at most 2 decimals.';
    $errors = array_merge($errors, length_errors($values, ['reference' => ['Reference', 80], 'notes' => ['Notes', 255]]));
    if (!isset(PAYMENT_METHODS[$values['method']])) $errors[] = 'Choose a payment method.';
    if (!valid_date($values['paid_on']) || $values['paid_on'] > date('Y-m-d')) $errors[] = 'Enter a valid payment date (not in the future).';

    if (!$errors) {
        q('INSERT INTO payments (patient_id, amount, method, reference, notes, paid_on, received_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$patientId, round((float) $values['amount'], 2), $values['method'], nullable($values['reference']), nullable($values['notes']), $values['paid_on'], $user['id']]);
        log_activity('payment_logged', 'patient', $patientId, money($values['amount']) . ' ' . $values['method']);
        flash('success', money($values['amount']) . ' payment recorded.');
        redirect("patient.php?id=$patientId&tab=billing");
    }
}

// Show balances beside names so staff can see who owes what.
$patients = q_all('SELECT p.id, p.full_name, b.balance FROM patients p JOIN ' . BALANCES . " b ON b.patient_id = p.id
    WHERE p.status <> 'archived' ORDER BY b.balance > 0 DESC, p.full_name");

layout_start('Log payment', 'patients', ['subtitle' => 'Record a payment against a patient account']);
?>
<form class="card stack" method="post" style="max-width:640px">
  <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?= csrf_field() ?>
  <div class="field"><label for="patient_id">Patient</label>
    <select id="patient_id" name="patient_id" required><option value="">Choose patient…</option>
      <?php foreach ($patients as $p): ?>
        <option value="<?= $p['id'] ?>"<?= selected($p['id'], $values['patient_id']) ?>><?= e($p['full_name']) ?><?= $p['balance'] > 0 ? ' · ' . money($p['balance']) . ' due' : ($p['balance'] < 0 ? ' · ' . money($p['balance']) . ' credit' : '') ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="form-grid">
    <div class="field"><label for="amount">Amount (₱)</label><input type="number" id="amount" name="amount" min="0.01" max="<?= MAX_AMOUNT ?>" step="0.01" value="<?= e($values['amount']) ?>" required></div>
    <div class="field"><label for="method">Method</label>
      <select id="method" name="method"><?php foreach (PAYMENT_METHODS as $k => $lbl): ?><option value="<?= $k ?>"<?= selected($k, $values['method']) ?>><?= $lbl ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="paid_on">Date</label><input type="date" id="paid_on" name="paid_on" max="<?= date('Y-m-d') ?>" value="<?= e($values['paid_on']) ?>" required></div>
    <div class="field"><label for="reference">Reference / OR no.</label><input type="text" id="reference" name="reference" maxlength="80" value="<?= e($values['reference']) ?>" placeholder="GCash ref, OR number"></div>
    <div class="field span-2"><label for="notes">Notes</label><input type="text" id="notes" name="notes" maxlength="255" value="<?= e($values['notes']) ?>"></div>
  </div>
  <div class="row"><button class="btn btn-primary" type="submit">Record payment</button><a class="btn" href="<?= $values['patient_id'] ? 'patient.php?id=' . (int) $values['patient_id'] . '&amp;tab=billing' : 'index.php' ?>">Cancel</a></div>
</form>
<?php layout_end();
