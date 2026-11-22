-- Optional demo data matching the design mockups. Dates are relative to today.
-- Import AFTER schema.sql. Demo logins (password for both: welldent123):
--   drsantos  (dentist)    admin  (administrator)
-- Change these passwords, or delete the demo accounts, before real use.
USE welldent;

INSERT INTO users (id, name, username, password_hash, role) VALUES
 (1, 'Clinic Admin', 'admin', '$2y$10$YeL2PNWI1j/RQ1h9E.bOw.uDOuBgZB7n6mkQpaJu9pZ.VZyBw0IL2', 'admin'),
 (2, 'Dr. Maya Santos', 'drsantos', '$2y$10$YeL2PNWI1j/RQ1h9E.bOw.uDOuBgZB7n6mkQpaJu9pZ.VZyBw0IL2', 'dentist');

INSERT INTO patients (id, record_no, full_name, birth_date, sex, phone, email, care_type, emergency_name, emergency_relationship, emergency_phone,
    allergies, conditions, medications, dental_history, consent_signed, consent_date, status, created_by) VALUES
 (1, 'WD-00001', 'Lia Mendoza',  '1998-04-12', 'female', '+63 917 555 0120', 'lia.mendoza@example.com',  'Orthodontic', 'Rosa Mendoza', 'Mother', '+63 917 555 0220', 'None', 'None', 'None', 'Braces since 2025', 1, CURDATE() - INTERVAL 200 DAY, 'active', 2),
 (2, 'WD-00002', 'Paolo Reyes',  '1990-09-03', 'male',   '+63 917 555 0121', 'paolo.reyes@example.com',  'Preventive',  'Ana Reyes', 'Spouse', '+63 917 555 0221', 'Penicillin', 'None', 'None', 'Regular cleanings', 1, CURDATE() - INTERVAL 400 DAY, 'active', 2),
 (3, 'WD-00003', 'Ana Cruz',     '2001-01-22', 'female', '+63 917 555 0122', NULL, 'New patient', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, 'incomplete', 2),
 (4, 'WD-00004', 'Miguel Lim',   '2008-06-30', 'male',   '+63 917 555 0123', 'miguel.lim@example.com',   'Orthodontic', 'Grace Lim', 'Mother', '+63 917 555 0223', 'None', 'Asthma', 'Salbutamol inhaler as needed', 'Braces removed; retainer phase', 1, CURDATE() - INTERVAL 500 DAY, 'active', 2),
 (5, 'WD-00005', 'Jose Garcia',  '1975-11-15', 'male',   '+63 917 555 0124', 'jose.garcia@example.com',  'Recall',      'Maria Garcia', 'Spouse', '+63 917 555 0224', 'None', 'Hypertension', 'Amlodipine 5mg', 'Crown on #3 (2024)', 1, CURDATE() - INTERVAL 700 DAY, 'active', 2),
 (6, 'WD-00006', 'Maria Santos', '1990-03-08', 'female', '0917-123-4567', 'maria.santos@example.com',   'Restorative', 'Carlo Santos', 'Brother', '0917-765-4321', 'None', 'None', 'None', 'Crown #3, filling #6, root canal #14, extraction #19', 1, CURDATE() - INTERVAL 250 DAY, 'active', 2),
 (7, 'WD-00007', 'Bea Tan',      '1995-07-19', 'female', '+63 917 555 0126', NULL, 'Preventive', NULL, NULL, NULL, 'None', 'None', 'None', 'Cleaning every 6 months', 1, CURDATE() - INTERVAL 100 DAY, 'active', 2),
 (8, 'WD-00008', 'Marco Uy',     '2005-02-11', 'male',   '+63 917 555 0127', NULL, 'Orthodontic', NULL, NULL, NULL, 'None', 'None', 'None', 'Braces, loose bracket', 1, CURDATE() - INTERVAL 90 DAY, 'active', 2);

INSERT INTO appointments (patient_id, dentist_id, starts_at, duration_min, procedure_name, status, created_by) VALUES
 (1, 2, CONCAT(CURDATE(), ' 08:30:00'), 60, 'Adjustment', 'confirmed', 2),
 (2, 2, CONCAT(CURDATE(), ' 10:00:00'), 60, 'Cleaning', 'arrived', 2),
 (3, 2, CONCAT(CURDATE(), ' 13:30:00'), 60, 'Consultation', 'pending', 2),
 (4, 2, CONCAT(CURDATE(), ' 15:00:00'), 60, 'Retainer fitting', 'confirmed', 2),
 (5, 2, CONCAT(CURDATE() + INTERVAL 14 DAY, ' 09:00:00'), 60, 'Recall check-up', 'confirmed', 2),
 (6, 2, CONCAT(CURDATE() + INTERVAL 3 DAY, ' 11:00:00'), 90, 'Crown check', 'confirmed', 2),
 (6, 2, CONCAT(CURDATE() - INTERVAL 30 DAY, ' 10:00:00'), 90, 'Root canal', 'completed', 2),
 (7, 2, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 09:00:00'), 60, 'Cleaning', 'completed', 2),
 (8, 2, CONCAT(CURDATE() - INTERVAL 1 DAY, ' 10:30:00'), 30, 'Bracket repair', 'completed', 2),
 (1, 2, CONCAT(CURDATE() - INTERVAL 2 DAY, ' 14:00:00'), 60, 'Adjustment', 'completed', 2),
 (2, 2, CONCAT(CURDATE() - INTERVAL 3 DAY, ' 09:00:00'), 60, 'Cleaning', 'completed', 2),
 (4, 2, CONCAT(CURDATE() - INTERVAL 3 DAY, ' 11:00:00'), 60, 'Consultation', 'completed', 2),
 (5, 2, CONCAT(CURDATE() - INTERVAL 3 DAY, ' 13:00:00'), 60, 'X-ray', 'completed', 2),
 (7, 2, CONCAT(CURDATE() - INTERVAL 4 DAY, ' 15:00:00'), 60, 'Whitening', 'completed', 2),
 (3, 2, CONCAT(CURDATE() - INTERVAL 5 DAY, ' 10:00:00'), 30, 'Consultation', 'no_show', 2),
 (8, 2, CONCAT(CURDATE() - INTERVAL 5 DAY, ' 11:00:00'), 60, 'Adjustment', 'completed', 2);

INSERT INTO waitlist (patient_id, procedure_name) VALUES (7, 'Cleaning'), (8, 'Bracket repair');

-- Teeth use FDI numbering (11–48 permanent, 51–85 temporary) and the paper chart's legend codes.
INSERT INTO tooth_records (patient_id, tooth_no, status, treatment, surfaces, procedure_name, notes, recorded_by, recorded_at) VALUES
 (6, 16, '/',  'JC', NULL,  'Dental Crown',       'Full ceramic crown placed', 2, NOW() - INTERVAL 240 DAY),
 (6, 13, '/',  'Co', 'M,O', 'Composite Filling',  'Composite resin, A2 shade', 2, NOW() - INTERVAL 120 DAY),
 (6, 26, '/',  NULL, NULL,  'Root Canal Therapy', 'Root canal · Two canals treated', 2, NOW() - INTERVAL 30 DAY),
 (6, 36, 'M',  'X',  NULL,  'Simple Extraction',  'Non-restorable decay', 2, NOW() - INTERVAL 200 DAY),
 (6, 46, '/',  'Am', 'O',   'Amalgam Filling',    'Older restoration, monitor', 2, NOW() - INTERVAL 600 DAY),
 (6, 47, 'D',  NULL, 'D,O', NULL,                 'Caries indicated for filling', 2, NOW() - INTERVAL 30 DAY),
 (5, 16, '/',  'JC', NULL,  'Dental Crown',       'PFM crown', 2, NOW() - INTERVAL 650 DAY),
 (1, 11, '/',  NULL, NULL,  'Bracket bonded',     'Braces', 2, NOW() - INTERVAL 200 DAY),
 (1, 21, '/',  NULL, NULL,  'Bracket bonded',     'Braces', 2, NOW() - INTERVAL 200 DAY);

INSERT INTO dental_exams (patient_id, periodontal, molar_class, canine_class, overjet, overbite, appliances, xrays, xray_periapical_teeth, updated_by) VALUES
 (1, 'gingivitis', 'Class II', 'Class II', '5 mm', '4 mm', 'orthodontic', 'panoramic,cephalometric', NULL, 2),
 (6, 'early', 'Class I', 'Class I', '2 mm', '2 mm', NULL, 'periapical', '26, 36', 2);

INSERT INTO treatments (patient_id, tooth_no, procedure_name, amount, performed_on, dentist_id) VALUES
 (6, 16, 'Dental Crown', 8000, CURDATE() - INTERVAL 240 DAY, 2),
 (6, 26, 'Root Canal Therapy', 6500, CURDATE() - INTERVAL 30 DAY, 2),
 (1, NULL, 'Orthodontic braces package (balance)', 12500, CURDATE() - INTERVAL 200 DAY, 2),
 (2, NULL, 'Oral prophylaxis', 1200, CURDATE() - INTERVAL 3 DAY, 2),
 (4, NULL, 'Retainer', 4200, CURDATE() - INTERVAL 3 DAY, 2),
 (7, NULL, 'Oral prophylaxis', 1200, CURDATE() - INTERVAL 1 DAY, 2),
 (8, NULL, 'Bracket repair', 800, CURDATE() - INTERVAL 1 DAY, 2);

INSERT INTO procedures (name, fee) VALUES
 ('Consultation', 500), ('Oral prophylaxis', 1200), ('Tooth extraction', 1500), ('Composite filling', 1500),
 ('Root Canal Therapy', 6500), ('Dental Crown', 8000), ('Retainer', 4200), ('Bracket repair', 800),
 ('Braces adjustment', 1000), ('Periapical X-ray', 400);

INSERT INTO payments (patient_id, amount, method, reference, paid_on, received_by) VALUES
 (6, 8000, 'cash', NULL, CURDATE() - INTERVAL 240 DAY, 2),
 (6, 3500, 'gcash', 'GC-88231', CURDATE() - INTERVAL 30 DAY, 2),
 (2, 1200, 'cash', NULL, CURDATE() - INTERVAL 3 DAY, 2),
 (5, 2000, 'bank', 'Advance payment', CURDATE() - INTERVAL 20 DAY, 2),
 (7, 1200, 'gcash', 'GC-90111', CURDATE() - INTERVAL 1 DAY, 2),
 (8, 800, 'cash', NULL, CURDATE() - INTERVAL 1 DAY, 2);

INSERT INTO inventory_items (name, category, unit, quantity, min_quantity, supplier) VALUES
 ('Nitrile gloves (M)', 'Consumables', 'box', 2, 6, 'MedSupply PH'),
 ('Face masks', 'Consumables', 'box', 3, 5, 'MedSupply PH'),
 ('Composite resin A2', 'Restorative', 'syringe', 1, 4, 'DentaTrade'),
 ('Lidocaine 2%', 'Anesthetics', 'cartridge', 40, 30, 'DentaTrade'),
 ('Orthodontic brackets', 'Orthodontic', 'set', 5, 4, 'OrthoLine'),
 ('Sterilization pouches', 'Sterilization', 'pack', 3, 4, 'MedSupply PH'),
 ('Saliva ejectors', 'Consumables', 'pack', 12, 5, 'MedSupply PH');

INSERT INTO notices (message, starts_on, ends_on, created_by) VALUES
 ('Heavy rain expected after 3 PM. Rivera aligner delivery may be delayed; review affected appointments.', CURDATE(), CURDATE(), 2),
 ('Compressor maintenance scheduled in 5 days.', CURDATE(), CURDATE() + INTERVAL 5 DAY, 2);

INSERT INTO reminders (patient_id, appointment_id, channel, recipient, message, send_at) VALUES
 (6, 6, 'sms', '0917-123-4567', 'Hi Maria, this is Dental Wellness reminding you of your crown check appointment. Please call us if you need to reschedule.', CONCAT(CURDATE() + INTERVAL 2 DAY, ' 08:00:00')),
 (6, 6, 'email', 'maria.santos@example.com', 'Hi Maria, this is Dental Wellness reminding you of your crown check appointment. Please call us if you need to reschedule.', CONCAT(CURDATE() + INTERVAL 2 DAY, ' 08:00:00'));
