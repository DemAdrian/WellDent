-- WellDent+ database schema (MySQL 5.7+ / MariaDB 10.3+)
-- Import this file in phpMyAdmin, or: mysql -u root < database/schema.sql

CREATE DATABASE IF NOT EXISTS welldent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE welldent;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','dentist','staff') NOT NULL DEFAULT 'staff',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patients (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_no              VARCHAR(20) NULL UNIQUE,
    full_name              VARCHAR(150) NOT NULL,
    birth_date             DATE NULL,
    sex                    ENUM('female','male') NULL,
    phone                  VARCHAR(30) NULL,
    email                  VARCHAR(150) NULL,
    address                VARCHAR(255) NULL,
    care_type              ENUM('New patient','Preventive','Orthodontic','Restorative','Recall') NOT NULL DEFAULT 'New patient',
    emergency_name         VARCHAR(150) NULL,
    emergency_relationship VARCHAR(60) NULL,
    emergency_phone        VARCHAR(30) NULL,
    allergies              TEXT NULL,
    conditions             TEXT NULL,
    medications            TEXT NULL,
    dental_history         TEXT NULL,
    notes                  TEXT NULL,
    consent_signed         TINYINT(1) NOT NULL DEFAULT 0,
    consent_date           DATE NULL,
    status                 ENUM('draft','incomplete','active','archived') NOT NULL DEFAULT 'incomplete',
    created_by             INT UNSIGNED NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_patients_name (full_name),
    INDEX idx_patients_phone (phone),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NOT NULL,
    dentist_id     INT UNSIGNED NULL,
    chair          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    starts_at      DATETIME NOT NULL,
    duration_min   SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    procedure_name VARCHAR(120) NOT NULL,
    status         ENUM('pending','confirmed','arrived','in_treatment','completed','cancelled','no_show') NOT NULL DEFAULT 'pending',
    notes          TEXT NULL,
    created_by     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appt_start (starts_at),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (dentist_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS waitlist (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NOT NULL,
    procedure_name VARCHAR(120) NOT NULL,
    notes          VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at    DATETIME NULL,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- One row per charting event; the latest row per tooth is its current state.
CREATE TABLE IF NOT EXISTS tooth_records (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NOT NULL,
    tooth_no       TINYINT UNSIGNED NOT NULL,
    status         ENUM('healthy','cavity','filled','crown','extracted','root_canal','braces','missing','prosthetic') NOT NULL,
    procedure_name VARCHAR(120) NULL,
    notes          VARCHAR(255) NULL,
    recorded_by    INT UNSIGNED NULL,
    recorded_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tooth (patient_id, tooth_no),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS treatments (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    tooth_no       TINYINT UNSIGNED NULL,
    procedure_name VARCHAR(120) NOT NULL,
    notes          VARCHAR(255) NULL,
    amount         DECIMAL(10,2) NOT NULL DEFAULT 0,
    performed_on   DATE NOT NULL,
    dentist_id     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_treat_patient (patient_id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
    FOREIGN KEY (dentist_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id  INT UNSIGNED NOT NULL,
    amount      DECIMAL(10,2) NOT NULL,
    method      ENUM('cash','gcash','maya','card','bank','other') NOT NULL DEFAULT 'cash',
    reference   VARCHAR(80) NULL,
    notes       VARCHAR(255) NULL,
    paid_on     DATE NOT NULL,
    received_by INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_patient (patient_id),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_items (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    category     VARCHAR(60) NULL,
    unit         VARCHAR(30) NOT NULL DEFAULT 'pcs',
    quantity     INT NOT NULL DEFAULT 0,
    min_quantity INT NOT NULL DEFAULT 0,
    supplier     VARCHAR(120) NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id    INT UNSIGNED NOT NULL,
    type       ENUM('in','out') NOT NULL,
    quantity   INT UNSIGNED NOT NULL,
    reason     VARCHAR(160) NULL,
    user_id    INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reminders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_id     INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NULL,
    channel        ENUM('sms','email') NOT NULL,
    recipient      VARCHAR(150) NOT NULL,
    message        TEXT NOT NULL,
    send_at        DATETIME NOT NULL,
    status         ENUM('scheduled','sent','failed','cancelled') NOT NULL DEFAULT 'scheduled',
    sent_at        DATETIME NULL,
    error          VARCHAR(255) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rem_due (status, send_at),
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Clinic-wide notices (weather, lab delays, maintenance) shown on the dashboard and schedule.
CREATE TABLE IF NOT EXISTS notices (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message    VARCHAR(255) NOT NULL,
    starts_on  DATE NOT NULL,
    ends_on    DATE NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    action     VARCHAR(60) NOT NULL,
    entity     VARCHAR(40) NULL,
    entity_id  INT UNSIGNED NULL,
    details    VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_time (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Failed sign-ins, for lockouts that survive a new session (see includes/auth.php).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(60) NOT NULL,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_user (username, attempted_at),
    INDEX idx_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB;
