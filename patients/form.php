<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$id = (int) input('id');
$patient = $id ? q_row('SELECT * FROM patients WHERE id = ?', [$id]) : null;
if ($id && !$patient) {
    http_response_code(404);
    exit('Patient not found.');
}

// Text fields, in the order of the clinic's paper Patient Information Record, with their limits.
const PATIENT_TEXT_FIELDS = [
    'last_name' => ['Last name', 80], 'first_name' => ['First name', 80], 'middle_name' => ['Middle name', 80],
    'birth_date' => ['Birthdate', 10], 'sex' => ['Sex', 6], 'nickname' => ['Nickname', 60],
    'religion' => ['Religion', 60], 'nationality' => ['Nationality', 60], 'phone' => ['Mobile no.', 30],
    'address' => ['Home address', 255], 'occupation' => ['Occupation', 100], 'email' => ['Email address', 150],
    'guardian_name' => ['Parent/guardian’s name', 150], 'guardian_occupation' => ['Parent/guardian’s occupation', 100],
    'referred_by' => ['Referred by', 150], 'consultation_reason' => ['Reason for dental consultation', 255],
    'previous_dentist' => ['Previous dentist', 150], 'last_dental_visit' => ['Last dental visit', 60],
    'dental_history' => ['Other dental history', 5000],
    'physician_name' => ['Name of physician', 150], 'physician_specialty' => ['Specialty', 100],
    'physician_address' => ['Office address', 255], 'physician_phone' => ['Office no.', 30],
    'treatment_condition' => ['Condition being treated', 255], 'illness_details' => ['Illness or surgical operation', 255],
    'hospitalization_details' => ['When and why hospitalized', 255], 'medications' => ['Medication', 5000],
    'allergies' => ['Other allergies', 5000], 'blood_type' => ['Blood type', 10], 'blood_pressure' => ['Blood pressure', 20],
    'conditions' => ['Other conditions', 5000],
    'care_type' => ['Care type', 20], 'notes' => ['Notes', 5000], 'consent_date' => ['Consent date', 10],
];
$yesNoFields = array_merge(array_keys(MEDICAL_YES_NO), array_keys(WOMEN_YES_NO));

$values = [];
foreach (array_keys(PATIENT_TEXT_FIELDS) as $f) {
    $values[$f] = (string) ($patient[$f] ?? '');
}
foreach ($yesNoFields as $f) {
    $values[$f] = isset($patient[$f]) ? (string) (int) $patient[$f] : '';
}
$values['allergy_list'] = array_values(array_filter(explode(',', (string) ($patient['allergy_list'] ?? ''))));
$values['medical_conditions'] = array_values(array_filter(explode(',', (string) ($patient['medical_conditions'] ?? ''))));
$values['care_type'] = $values['care_type'] ?: 'New patient';
$values['consent_signed'] = (int) ($patient['consent_signed'] ?? 0);
// Records made before names were split: start from a best guess the staff can correct.
if ($patient && $values['first_name'] === '' && $values['last_name'] === '') {
    $values = array_merge($values, split_full_name($patient['full_name']));
}

/** A record is complete once the patient details, medical history answers and consent are in. */
function chart_complete(array $v): bool
{
    foreach (['first_name', 'last_name', 'birth_date', 'sex', 'phone', 'address'] as $f) {
        if ($v[$f] === '') return false;
    }
    $questions = array_keys(MEDICAL_YES_NO);
    if ($v['sex'] === 'female') $questions = array_merge($questions, array_keys(WOMEN_YES_NO));
    foreach ($questions as $f) {
        if ($v[$f] === '') return false;
    }
    return (bool) $v['consent_signed'];
}

$errors = [];
if (is_post()) {
    verify_csrf();
    foreach (array_keys(PATIENT_TEXT_FIELDS) as $f) {
        $values[$f] = input($f);
    }
    foreach ($yesNoFields as $f) {
        $values[$f] = in_array(input($f), ['0', '1'], true) ? input($f) : '';
    }
    $picked = fn(string $name, array $options) => array_values(array_intersect(array_keys($options), (array) ($_POST[$name] ?? [])));
    $values['allergy_list'] = $picked('allergy_list', ALLERGY_OPTIONS);
    $values['medical_conditions'] = $picked('medical_conditions', MEDICAL_CONDITIONS);
    $values['care_type'] = $values['care_type'] ?: 'New patient';
    $values['consent_signed'] = isset($_POST['consent_signed']) ? 1 : 0;
    if ($values['consent_signed'] && $values['consent_date'] === '') {
        $values['consent_date'] = date('Y-m-d');
    }
    // Question 9 is for women only; a follow-up answer only stands while its question is "Yes".
    if ($values['sex'] !== 'female') {
        foreach (array_keys(WOMEN_YES_NO) as $f) $values[$f] = '';
    }
    foreach (MEDICAL_YES_NO as $f => [, , $follow]) {
        if ($follow && $values[$f] === '0') $values[$follow[0]] = '';
    }
    $draft = input('submit') === 'draft';

    if ($values['last_name'] === '' || $values['first_name'] === '') $errors[] = 'Last name and first name are required.';
    if (!$draft) {
        if ($values['birth_date'] === '') $errors[] = 'Birthdate is required.';
        if ($values['sex'] === '') $errors[] = 'Sex is required.';
    }
    if ($values['birth_date'] !== '' && (!valid_date($values['birth_date']) || $values['birth_date'] > date('Y-m-d'))) $errors[] = 'Birthdate is not valid.';
    if ($values['sex'] !== '' && !in_array($values['sex'], ['female', 'male'], true)) $errors[] = 'Choose a valid sex.';
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if ($values['blood_type'] !== '' && !in_array($values['blood_type'], BLOOD_TYPES, true)) $errors[] = 'Choose a valid blood type.';
    if (!in_array($values['care_type'], CARE_TYPES, true)) $errors[] = 'Choose a care type.';
    if ($values['consent_date'] !== '' && !valid_date($values['consent_date'])) $errors[] = 'Consent date is not valid.';
    if ($values['phone'] !== '') {
        $normalized = normalize_ph_phone($values['phone']);
        if ($normalized === null) {
            $errors[] = 'Mobile no. must be a Philippine number, e.g. 0917 123 4567 or (02) 8123 4567.';
        } else {
            $values['phone'] = $normalized;
        }
    }
    $errors = array_merge($errors, length_errors($values, PATIENT_TEXT_FIELDS));
    $fullName = compose_full_name($values['first_name'], $values['middle_name'], $values['last_name']);
    if (mb_strlen($fullName) > 150) $errors[] = 'The full name can be at most 150 characters.';

    if (!$errors) {
        $status = $draft ? 'draft' : (chart_complete($values) ? 'active' : 'incomplete');
        if ($patient && $patient['status'] === 'archived') {
            $status = 'archived';
        }
        $data = array_map('nullable', array_intersect_key($values, PATIENT_TEXT_FIELDS));
        foreach ($yesNoFields as $f) {
            $data[$f] = $values[$f] === '' ? null : (int) $values[$f];
        }
        $data['allergy_list'] = $values['allergy_list'] ? implode(',', $values['allergy_list']) : null;
        $data['medical_conditions'] = $values['medical_conditions'] ? implode(',', $values['medical_conditions']) : null;
        $data['full_name'] = $fullName;
        $data['consent_signed'] = $values['consent_signed'];
        $data['status'] = $status;

        if ($patient) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($data)));
            q("UPDATE patients SET $sets WHERE id = ?", [...array_values($data), $id]);
            // Reminders carry the name, number and address they were scheduled with.
            if ($patient['full_name'] !== $fullName || (string) $patient['phone'] !== $values['phone'] || (string) $patient['email'] !== $values['email']) {
                reschedule_patient_reminders($id);
            }
            log_activity('patient_updated', 'patient', $id, $fullName);
            flash('success', 'Patient record saved.');
        } else {
            $data['created_by'] = $user['id'];
            $cols = implode(', ', array_keys($data));
            $marks = implode(', ', array_fill(0, count($data), '?'));
            q("INSERT INTO patients ($cols) VALUES ($marks)", array_values($data));
            $id = (int) db()->lastInsertId();
            q('UPDATE patients SET record_no = ? WHERE id = ?', ['WD-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT), $id]);
            log_activity('patient_created', 'patient', $id, $fullName);
            flash('success', $draft ? 'Draft saved. Finish the record when you have the details.' : 'Patient created.');
        }
        redirect(url('patients/view.php') . '?id=' . $id . '&tab=' . ($patient ? 'info' : 'chart'));
    }
}

$field = function (string $name, string $label, string $type = 'text', string $placeholder = '', bool $required = false, string $class = '') use ($values): string {
    return '<div class="field' . ($class ? " $class" : '') . '"><label for="' . $name . '">' . e($label) . ($required ? ' *' : '') . '</label>'
        . '<input type="' . $type . '" id="' . $name . '" name="' . $name . '" value="' . e($values[$name]) . '" placeholder="' . e($placeholder) . '"'
        . ($type === 'date' ? ' max="' . date('Y-m-d') . '"' : '')
        . ($type === 'tel' ? ' inputmode="tel"' : '')
        . ' maxlength="' . PATIENT_TEXT_FIELDS[$name][1] . '"></div>';
};
$area = fn(string $name, string $label, string $placeholder, string $class = '') => '<div class="field' . ($class ? " $class" : '') . '"><label for="' . $name . '">' . e($label) . '</label>'
    . '<textarea id="' . $name . '" name="' . $name . '" maxlength="' . PATIENT_TEXT_FIELDS[$name][1] . '" placeholder="' . e($placeholder) . '">' . e($values[$name]) . '</textarea></div>';
$yesNo = function (string $name, string $label) use ($values): string {
    $out = '<div class="yn" role="radiogroup" aria-label="' . e($label) . '">';
    foreach (['1' => 'Yes', '0' => 'No'] as $v => $text) {
        $out .= '<label class="yn-opt"><input type="radio" name="' . $name . '" value="' . $v . '"' . ($values[$name] === (string) $v ? ' checked' : '') . '><span>' . $text . '</span></label>';
    }
    return $out . '</div>';
};
$checks = function (string $name, array $options, string $class) use ($values): string {
    $out = '<div class="' . $class . '">';
    foreach ($options as $key => $label) {
        $out .= '<label class="check-chip"><input type="checkbox" name="' . $name . '[]" value="' . $key . '"' . (in_array($key, $values[$name], true) ? ' checked' : '') . '><span>' . e($label) . '</span></label>';
    }
    return $out . '</div>';
};
$age = age($values['birth_date'] ?: null);

layout_start($patient ? 'Edit patient' : 'Add patient', 'patients', [
    'subtitle' => $patient ? $patient['full_name'] . ' · ' . $patient['record_no'] : 'Patient Information Record',
]);
?>
<form method="post" data-patient-form novalidate>
  <?= csrf_field() ?>
  <div class="layout-side">
    <div>
      <?php if ($errors): ?><ul class="errors"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul><?php endif; ?>

      <section class="card form-card">
        <h2 class="form-card-title">Patient information</h2>
        <div class="form-grid">
          <?= $field('last_name', 'Last name', 'text', 'Dela Cruz', true) ?>
          <?= $field('first_name', 'First name', 'text', 'Juan', true) ?>
          <?= $field('middle_name', 'Middle name', 'text', 'Santos') ?>
          <?= $field('birth_date', 'Birthdate', 'date', '', true) ?>
          <div class="field"><label for="age-display">Age</label><input type="text" id="age-display" value="<?= $age !== null ? $age : '' ?>" placeholder="From birthdate" readonly tabindex="-1" data-age-display></div>
          <div class="field"><label for="sex">Sex *</label>
            <select id="sex" name="sex"><option value="">Select sex</option><option value="male"<?= selected('male', $values['sex']) ?>>Male</option><option value="female"<?= selected('female', $values['sex']) ?>>Female</option></select>
          </div>
          <?= $field('nickname', 'Nickname') ?>
          <?= $field('religion', 'Religion') ?>
          <?= $field('nationality', 'Nationality', 'text', 'Filipino') ?>
          <?= $field('phone', 'Mobile no.', 'tel', '09XX XXX XXXX') ?>
          <?= $field('email', 'Email address', 'email', 'name@email.com') ?>
          <?= $field('occupation', 'Occupation') ?>
          <?= $field('address', 'Home address', 'text', 'House no., street, barangay, city', false, 'span-all') ?>
        </div>

        <fieldset class="form-sub" data-minor-section>
          <legend>For minors <span class="muted small" data-minor-note></span></legend>
          <div class="form-grid">
            <?= $field('guardian_name', 'Parent/guardian’s name', 'text', '', false, 'span-2') ?>
            <?= $field('guardian_occupation', 'Occupation') ?>
          </div>
        </fieldset>

        <div class="form-grid" style="margin-top:16px">
          <?= $field('referred_by', 'Whom may we thank for referring you?', 'text', '', false, 'span-all') ?>
          <?= $area('consultation_reason', 'What is your reason for dental consultation?', 'e.g. Toothache, cleaning, braces consultation', 'span-all') ?>
        </div>
      </section>

      <section class="card form-card">
        <h2 class="form-card-title">Dental history</h2>
        <div class="form-grid">
          <?= $field('previous_dentist', 'Previous dentist', 'text', '', false, 'span-2') ?>
          <?= $field('last_dental_visit', 'Last dental visit', 'text', 'e.g. March 2025') ?>
          <?= $area('dental_history', 'Other dental history (optional)', 'Previous treatments, orthodontics, concerns', 'span-all') ?>
        </div>
      </section>

      <section class="card form-card">
        <h2 class="form-card-title">Medical history</h2>
        <div class="form-grid">
          <?= $field('physician_name', 'Name of physician', 'text', '', false, 'span-2') ?>
          <?= $field('physician_specialty', 'Specialty (if applicable)') ?>
          <?= $field('physician_address', 'Office address', 'text', '', false, 'span-2') ?>
          <?= $field('physician_phone', 'Office no.', 'tel') ?>
        </div>

        <ol class="med-questions">
          <?php foreach (MEDICAL_YES_NO as $name => [$no, $question, $follow]): ?>
            <li class="med-q" data-yes-no="<?= $name ?>">
              <div class="med-q-row"><span class="med-no"><?= $no ?>.</span><span class="med-text"><?= e($question) ?></span><?= $yesNo($name, $question) ?></div>
              <?php if ($follow): ?>
                <div class="med-follow" data-follow-for="<?= $name ?>"><?= $name === 'takes_medication'
                    ? $area($follow[0], $follow[1], 'Name and dose')
                    : $field($follow[0], $follow[1]) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>

          <li class="med-q">
            <div class="med-q-row"><span class="med-no">8.</span><span class="med-text">Are you allergic to any of the following?</span></div>
            <?= $checks('allergy_list', ALLERGY_OPTIONS, 'check-grid check-grid-3') ?>
            <?= $field('allergies', 'Others') ?>
          </li>

          <li class="med-q" data-women-only>
            <div class="med-q-row"><span class="med-no">9.</span><span class="med-text">For women only:</span></div>
            <?php foreach (WOMEN_YES_NO as $name => $question): ?>
              <div class="med-q-row med-q-sub" data-yes-no="<?= $name ?>"><span class="med-text"><?= e($question) ?></span><?= $yesNo($name, $question) ?></div>
            <?php endforeach; ?>
          </li>

          <li class="med-q">
            <div class="form-grid">
              <div class="field"><label for="blood_type"><span class="med-no">10.</span> Blood type</label>
                <select id="blood_type" name="blood_type"><option value="">Select blood type</option>
                  <?php foreach (BLOOD_TYPES as $bt): ?><option<?= selected($bt, $values['blood_type']) ?>><?= $bt ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="field"><label for="blood_pressure"><span class="med-no">11.</span> Blood pressure</label>
                <input type="text" id="blood_pressure" name="blood_pressure" value="<?= e($values['blood_pressure']) ?>" placeholder="e.g. 120/80" maxlength="20"></div>
            </div>
          </li>

          <li class="med-q">
            <div class="med-q-row"><span class="med-no">12.</span><span class="med-text">Do you have or have you had any of the following? Check which apply.</span></div>
            <?= $checks('medical_conditions', MEDICAL_CONDITIONS, 'check-grid check-grid-3') ?>
            <?= $field('conditions', 'Other/s') ?>
          </li>
        </ol>
      </section>

      <section class="card form-card">
        <h2 class="form-card-title">Signature &amp; date</h2>
        <label class="check"><input type="checkbox" name="consent_signed" value="1"<?= $values['consent_signed'] ? ' checked' : '' ?>>
          <span>The patient (or parent/guardian) has signed the Patient Information Record, consenting to treatment and to the clinic storing their personal and health information, as required by the Data Privacy Act of 2012.</span></label>
        <div class="form-grid" style="margin-top:14px"><?= $field('consent_date', 'Date signed', 'date') ?></div>
      </section>
    </div>

    <aside class="stack sticky">
      <section class="card">
        <h2><?= $patient ? 'Edit record' : 'New record' ?></h2>
        <p class="muted small" style="margin:10px 0 18px">Required fields are marked *. The dental record chart, treatments and payments are added after saving.</p>
        <div class="stack" style="gap:12px;align-items:flex-start">
          <button class="btn btn-primary" type="submit" name="submit" value="save"><?= $patient ? 'Save patient' : 'Create patient' ?></button>
          <?php if (!$patient || $patient['status'] === 'draft'): ?>
            <button class="btn" type="submit" name="submit" value="draft">Save as draft</button>
          <?php endif; ?>
          <?php if ($patient): ?><a href="<?= url('patients/view.php') ?>?id=<?= $id ?>">Cancel</a><?php endif; ?>
        </div>
      </section>
      <section class="card">
        <h2>Record checklist</h2>
        <ul class="checklist" style="margin-top:14px">
          <li data-check="demographics">Patient information</li>
          <li data-check="dental">Dental history <span class="muted small">(optional)</span></li>
          <li data-check="medical">Medical history questions</li>
          <li data-check="consent">Signature &amp; date</li>
        </ul>
      </section>
      <section class="card">
        <h2>Clinic use</h2>
        <div class="stack" style="gap:14px;margin-top:14px">
          <div class="field"><label for="care_type">Care type</label>
            <select id="care_type" name="care_type"><?php foreach (CARE_TYPES as $c): ?><option<?= selected($c, $values['care_type']) ?>><?= $c ?></option><?php endforeach; ?></select>
          </div>
          <?= $area('notes', 'Notes', 'Patient preferences and additional notes') ?>
        </div>
      </section>
    </aside>
  </div>
</form>
<?php layout_end();
