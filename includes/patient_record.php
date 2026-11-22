<?php
// The clinic's paper "Patient Information Record" (Dental Wellness – Naic Family Dental Clinic),
// as data: shared by the add/edit patient form and the patient's Info tab.

/** Medical history questions answered Yes / No, numbered as on the paper form. */
const MEDICAL_YES_NO = [
    'good_health'     => ['1', 'Are you in good health?', null],
    'under_treatment' => ['2', 'Are you under medical treatment now?', ['treatment_condition', 'If so, what is the condition being treated?']],
    'serious_illness' => ['3', 'Have you ever had a serious illness or surgical operation?', ['illness_details', 'If so, what illness or surgical operation?']],
    'hospitalized'    => ['4', 'Have you ever been hospitalized?', ['hospitalization_details', 'If so, when and why?']],
    'takes_medication'=> ['5', 'Are you taking any prescription/non-prescription medication?', ['medications', 'If so, please specify']],
    'uses_tobacco'    => ['6', 'Do you use tobacco products?', null],
    'uses_drugs'      => ['7', 'Do you use alcohol, cocaine, or other dangerous drugs?', null],
];

/** Question 9, for women only. */
const WOMEN_YES_NO = [
    'is_pregnant'   => 'Are you pregnant?',
    'is_nursing'    => 'Are you nursing?',
    'birth_control' => 'Are you taking birth control pills?',
];

/** Question 8. "Others" is written in the allergies field. */
const ALLERGY_OPTIONS = [
    'local_anesthetic' => 'Local Anesthetic',
    'penicillin'       => 'Penicillin, Antibiotics',
    'latex'            => 'Latex',
    'sulfa'            => 'Sulfa drugs',
    'aspirin'          => 'Aspirin',
];

/** Question 12. "Other/s" is written in the conditions field. */
const MEDICAL_CONDITIONS = [
    'high_bp' => 'High Blood Pressure', 'low_bp' => 'Low Blood Pressure', 'epilepsy' => 'Epilepsy/ Convulsions',
    'hiv' => 'AIDS or HIV Infection', 'std' => 'Sexually Transmitted Disease', 'stomach' => 'Stomach troubles/ Ulcers',
    'fainting' => 'Fainting Seizure', 'weight_loss' => 'Rapid Weight Loss', 'radiation' => 'Radiation Therapy',
    'joint' => 'Joint Replacement/ Implant', 'heart_surgery' => 'Heart Surgery', 'heart_attack' => 'Heart Attack',
    'thyroid' => 'Thyroid Problem', 'heart_disease' => 'Heart Disease', 'heart_murmur' => 'Heart Murmur',
    'rheumatic' => 'Rheumatic Fever', 'hay_fever' => 'Hay Fever/ Allergies', 'kidney' => 'Kidney Disease',
    'diabetes' => 'Diabetes', 'chest_pain' => 'Chest pain', 'stroke' => 'Stroke', 'cancer' => 'Cancer/ Tumors',
    'anemia' => 'Anemia', 'angina' => 'Angina', 'asthma' => 'Asthma', 'emphysema' => 'Emphysema',
    'bleeding' => 'Bleeding Problems', 'blood_disease' => 'Blood Diseases', 'arthritis' => 'Arthritis',
    'head_injury' => 'Head injuries', 'respiratory' => 'Respiratory Problems', 'hepatitis' => 'Hepatitis/ Jaundice',
    'tuberculosis' => 'Tuberculosis', 'swollen_ankles' => 'Swollen Ankles',
];

const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];

/** Columns added for the paper form, with their definitions (also in database/schema.sql). */
const PATIENT_RECORD_COLUMNS = [
    'last_name' => 'VARCHAR(80) NULL', 'first_name' => 'VARCHAR(80) NULL', 'middle_name' => 'VARCHAR(80) NULL',
    'nickname' => 'VARCHAR(60) NULL', 'religion' => 'VARCHAR(60) NULL', 'nationality' => 'VARCHAR(60) NULL',
    'occupation' => 'VARCHAR(100) NULL', 'guardian_name' => 'VARCHAR(150) NULL', 'guardian_occupation' => 'VARCHAR(100) NULL',
    'referred_by' => 'VARCHAR(150) NULL', 'consultation_reason' => 'VARCHAR(255) NULL',
    'previous_dentist' => 'VARCHAR(150) NULL', 'last_dental_visit' => 'VARCHAR(60) NULL',
    'physician_name' => 'VARCHAR(150) NULL', 'physician_specialty' => 'VARCHAR(100) NULL',
    'physician_address' => 'VARCHAR(255) NULL', 'physician_phone' => 'VARCHAR(30) NULL',
    'good_health' => 'TINYINT(1) NULL', 'under_treatment' => 'TINYINT(1) NULL', 'treatment_condition' => 'VARCHAR(255) NULL',
    'serious_illness' => 'TINYINT(1) NULL', 'illness_details' => 'VARCHAR(255) NULL',
    'hospitalized' => 'TINYINT(1) NULL', 'hospitalization_details' => 'VARCHAR(255) NULL',
    'takes_medication' => 'TINYINT(1) NULL', 'uses_tobacco' => 'TINYINT(1) NULL', 'uses_drugs' => 'TINYINT(1) NULL',
    'allergy_list' => 'VARCHAR(255) NULL',
    'is_pregnant' => 'TINYINT(1) NULL', 'is_nursing' => 'TINYINT(1) NULL', 'birth_control' => 'TINYINT(1) NULL',
    'blood_type' => 'VARCHAR(10) NULL', 'blood_pressure' => 'VARCHAR(20) NULL', 'medical_conditions' => 'TEXT NULL',
];

/** Adds the paper-form columns to databases created before them. */
function ensure_patient_record_columns(PDO $pdo): void
{
    try {
        $have = array_column($pdo->query('SHOW COLUMNS FROM patients')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (PDOException $ex) {
        return; // tables not installed yet
    }
    $missing = array_diff_key(PATIENT_RECORD_COLUMNS, array_flip($have));
    if ($missing) {
        $pdo->exec('ALTER TABLE patients ' . implode(', ', array_map(fn($c, $def) => "ADD $c $def", array_keys($missing), $missing)));
    }
}

/** "Juan Santos Dela Cruz": first, middle and last name as shown across the app. */
function compose_full_name(string $first, string $middle, string $last): string
{
    return trim(preg_replace('/\s+/', ' ', "$first $middle $last"));
}

/** Best guess for records made before names were split: the last word is the surname. */
function split_full_name(string $full): array
{
    $parts = preg_split('/\s+/', trim($full)) ?: [];
    $last = count($parts) > 1 ? array_pop($parts) : '';
    return ['first_name' => implode(' ', $parts), 'middle_name' => '', 'last_name' => $last];
}

/** Labels for a comma-separated list of option keys. */
function option_labels(?string $keys, array $options): array
{
    return array_values(array_filter(array_map(fn($k) => $options[$k] ?? null, explode(',', (string) $keys))));
}

function yes_no_label($value): string
{
    return $value === null || $value === '' ? '—' : ((int) $value ? 'Yes' : 'No');
}
