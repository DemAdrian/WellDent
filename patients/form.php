<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$id = (int) input('id');
$patient = $id ? q_row('SELECT * FROM patients WHERE id = ?', [$id]) : null;
if ($id && !$patient) {
    http_response_code(404);
    exit('Patient not found.');
}

$fields = ['full_name', 'birth_date', 'sex', 'phone', 'email', 'address', 'care_type',
    'emergency_name', 'emergency_relationship', 'emergency_phone',
    'allergies', 'conditions', 'medications', 'dental_history', 'notes', 'consent_date'];
const PATIENT_FIELD_LIMITS = [
    'full_name' => ['Full name', 150], 'email' => ['Email', 150], 'address' => ['Address', 255],
    'emergency_name' => ['Emergency contact name', 150], 'emergency_relationship' => ['Relationship', 60],
    'allergies' => ['Allergies', 5000], 'conditions' => ['Conditions', 5000], 'medications' => ['Medications', 5000],
    'dental_history' => ['Dental history', 5000], 'notes' => ['Notes', 5000],
];

$values = [];
foreach ($fields as $f) {
    $values[$f] = $patient[$f] ?? '';
}
$values['care_type'] = $values['care_type'] ?: 'New patient';
$values['consent_signed'] = (int) ($patient['consent_signed'] ?? 0);

/** A chart is complete once demographics, medical, dental history and consent are all in. */
function chart_complete(array $v): bool
{
    return $v['full_name'] !== '' && $v['birth_date'] !== '' && $v['sex'] !== '' && $v['phone'] !== ''
        && ($v['allergies'] !== '' || $v['conditions'] !== '' || $v['medications'] !== '')
        && $v['dental_history'] !== '' && $v['consent_signed'];
}

$errors = [];
if (is_post()) {
    verify_csrf();
    foreach ($fields as $f) {
        $values[$f] = input($f);
    }
    $values['care_type'] = $values['care_type'] ?: 'New patient';
    $values['consent_signed'] = isset($_POST['consent_signed']) ? 1 : 0;
    if ($values['consent_signed'] && $values['consent_date'] === '') {
        $values['consent_date'] = date('Y-m-d');
    }
    $draft = input('submit') === 'draft';

    if ($values['full_name'] === '') $errors[] = 'Full name is required.';
    if (!$draft) {
        if ($values['birth_date'] === '') $errors[] = 'Birth date is required.';
        if ($values['sex'] === '') $errors[] = 'Sex is required.';
    }
    if ($values['birth_date'] !== '' && (!valid_date($values['birth_date']) || $values['birth_date'] > date('Y-m-d'))) $errors[] = 'Birth date is not valid.';
    if ($values['sex'] !== '' && !in_array($values['sex'], ['female', 'male'], true)) $errors[] = 'Choose a valid sex.';
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if (!in_array($values['care_type'], CARE_TYPES, true)) $errors[] = 'Choose a care type.';
    if ($values['consent_date'] !== '' && !valid_date($values['consent_date'])) $errors[] = 'Consent date is not valid.';
    foreach (['phone' => 'Phone', 'emergency_phone' => 'Emergency contact phone'] as $f => $label) {
        if ($values[$f] !== '') {
            $normalized = normalize_ph_phone($values[$f]);
            if ($normalized === null) {
                $errors[] = "$label must be a Philippine number, e.g. 0917 123 4567 or (02) 8123 4567.";
            } else {
                $values[$f] = $normalized;
            }
        }
    }
    $errors = array_merge($errors, length_errors($values, PATIENT_FIELD_LIMITS));

    if (!$errors) {
        $status = $draft ? 'draft' : (chart_complete($values) ? 'active' : 'incomplete');
        if ($patient && $patient['status'] === 'archived') {
            $status = 'archived';
        }
        $data = array_map('nullable', array_intersect_key($values, array_flip($fields)));
        $data['consent_signed'] = $values['consent_signed'];
        $data['status'] = $status;

        if ($patient) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            q("UPDATE patients SET $sets WHERE id = ?", [...array_values($data), $id]);
            // Reminders carry the name, number and address they were scheduled with.
            foreach (['full_name', 'phone', 'email'] as $f) {
                if ((string) $patient[$f] !== $values[$f]) {
                    reschedule_patient_reminders($id);
                    break;
                }
            }
            log_activity('patient_updated', 'patient', $id, $values['full_name']);
            flash('success', 'Patient record saved.');
        } else {
            $data['created_by'] = $user['id'];
            $cols = implode(', ', array_keys($data));
            $marks = implode(', ', array_fill(0, count($data), '?'));
            q("INSERT INTO patients ($cols) VALUES ($marks)", array_values($data));
            $id = (int) db()->lastInsertId();
            q('UPDATE patients SET record_no = ? WHERE id = ?', ['WD-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            log_activity('patient_created', 'patient', $id, $values['full_name']);
            flash('success', $draft ? 'Draft saved. Finish the chart when you have the details.' : 'Patient created.');
        }
        redirect(url('patients/view.php') . '?id=' . $id . '&tab=info');
    }
}

$field = function (string $name, string $label, string $type = 'text', string $placeholder = '', bool $required = false) use ($values): string {
    return '<div class="field"><label for="' . $name . '">' . e($label) . ($required ? ' *' : '') . '</label>'
        . '<input type="' . $type . '" id="' . $name . '" name="' . $name . '" value="' . e($values[$name]) . '" placeholder="' . e($placeholder) . '"'
        . ($type === 'date' ? ' max="' . date('Y-m-d') . '"' : '')
        . ($type === 'tel' ? ' maxlength="17" inputmode="tel"' : '')
        . (isset(PATIENT_FIELD_LIMITS[$name]) ? ' maxlength="' . PATIENT_FIELD_LIMITS[$name][1] . '"' : '') . '></div>';
};
$area = fn(string $name, string $label, string $placeholder) => '<div class="field"><label for="' . $name . '">' . e($label) . '</label>'
    . '<textarea id="' . $name . '" name="' . $name . '" maxlength="' . PATIENT_FIELD_LIMITS[$name][1] . '" placeholder="' . e($placeholder) . '">' . e($values[$name]) . '</textarea></div>';

layout_start($patient ? 'Edit patient' : 'Add patient', 'patients', [
    'subtitle' => $patient ? $patient['full_name'] . ' · ' . $patient['record_no'] : 'Create a complete chart for a new patient',
]);
?>
<form method="post" data-patient-form novalidate>
  <?= csrf_field() ?>
  <div class="layout-side">
    <div>
      <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>

      <section class="card form-card">
        <h2 style="margin-bottom:18px">Personal details</h2>
        <div class="form-grid">
          <?= $field('full_name', 'Full name', 'text', 'Enter full legal name', true) ?>
          <?= $field('birth_date', 'Birth date', 'date', '', true) ?>
          <div class="field"><label for="sex">Sex *</label>
            <select id="sex" name="sex"><option value="">Select sex</option><option value="female"<?= selected('female', $values['sex']) ?>>Female</option><option value="male"<?= selected('male', $values['sex']) ?>>Male</option></select>
          </div>
          <?= $field('phone', 'Phone', 'tel', '09XX XXX XXXX') ?>
          <?= $field('email', 'Email', 'email', 'name@email.com') ?>
          <div class="field"><label for="care_type">Care type</label>
            <select id="care_type" name="care_type"><?php foreach (CARE_TYPES as $c): ?><option<?= selected($c, $values['care_type']) ?>><?= $c ?></option><?php endforeach; ?></select>
          </div>
          <div class="span-all"><?= $field('address', 'Address', 'text', 'Street, barangay, city') ?></div>
        </div>
      </section>

      <section class="card form-card">
        <h2 style="margin-bottom:18px">Emergency contact</h2>
        <div class="form-grid">
          <?= $field('emergency_name', 'Contact name', 'text', 'Full name') ?>
          <?= $field('emergency_relationship', 'Relationship', 'text', 'e.g. Parent, spouse') ?>
          <?= $field('emergency_phone', 'Phone', 'tel', '09XX XXX XXXX') ?>
        </div>
      </section>

      <section class="card form-card">
        <h2 style="margin-bottom:18px">Medical profile</h2>
        <div class="form-grid">
          <?= $area('allergies', 'Allergies', 'List allergies or “None”') ?>
          <?= $area('conditions', 'Conditions', 'Diagnosed conditions') ?>
          <?= $area('medications', 'Medications', 'Current medication and dose') ?>
        </div>
      </section>

      <section class="card form-card">
        <h2 style="margin-bottom:18px">Dental profile</h2>
        <div class="form-grid">
          <div class="span-2"><?= $area('dental_history', 'Dental history', 'Previous treatments, orthodontics, concerns') ?></div>
          <?= $area('notes', 'Notes', 'Patient preferences and additional notes') ?>
        </div>
      </section>

      <section class="card form-card">
        <h2 style="margin-bottom:18px">Consent</h2>
        <label class="check"><input type="checkbox" name="consent_signed" value="1"<?= $values['consent_signed'] ? ' checked' : '' ?>>
          <span>The patient (or guardian) has signed consent for treatment and for the clinic to store their personal and health information, as required by the Data Privacy Act of 2012.</span></label>
        <div class="form-grid" style="margin-top:14px"><?= $field('consent_date', 'Consent date', 'date') ?></div>
      </section>
    </div>

    <aside class="stack sticky">
      <section class="card">
        <h2><?= $patient ? 'Edit chart' : 'New chart' ?></h2>
        <p class="muted small" style="margin:10px 0 18px">Required fields are marked *. You can add dental chart entries, treatments and payments after saving.</p>
        <div class="stack" style="gap:12px;align-items:flex-start">
          <button class="btn btn-primary" type="submit" name="submit" value="save"><?= $patient ? 'Save patient' : 'Create patient' ?></button>
          <?php if (!$patient || $patient['status'] === 'draft'): ?>
            <button class="btn" type="submit" name="submit" value="draft">Save as draft</button>
          <?php endif; ?>
          <?php if ($patient): ?><a href="<?= url('patients/view.php') ?>?id=<?= $id ?>">Cancel</a><?php endif; ?>
        </div>
      </section>
      <section class="card">
        <h2>Paperless checklist</h2>
        <ul class="checklist" style="margin-top:14px">
          <li data-check="demographics">Demographics</li>
          <li data-check="medical">Medical history</li>
          <li data-check="dental">Dental history</li>
          <li data-check="consent">Consent</li>
        </ul>
      </section>
    </aside>
  </div>
</form>
<?php layout_end();
