# WellDent+

Dental Wellness Management System: a clinic web app that runs on one laptop (XAMPP) and is used from that laptop and from staff phones on the same Wi-Fi.

Plain PHP 8.1+ and MySQL/MariaDB. No framework and no Composer. It works offline, and only the optional SMS gateway needs internet.

## Modules

| Module | What it does |
| --- | --- |
| **Dashboard** | Today's patients, pending balances, reminders due, low stock, today's schedule, attention list, balances & credits, 7-day patient flow |
| **Appointments** | Day/week view per chair, overlap checks, status updates, waitlist with "fill open slot", clinic notices (weather, lab delays), chair capacity |
| **Patients** | Searchable records, balance-due and status filters, full chart form (personal, emergency, medical, dental, consent) with draft saving and a paperless checklist |
| **Patient record** | Interactive 32-tooth dental chart (Universal Numbering) with per-tooth history, treatments & billing, payments, appointment history |
| **Inventory** | Items with minimum levels, stock in/out log, low/critical alerts |
| **Reminders** | Scheduled automatically at 8:00 AM the day before each appointment by SMS and/or email, plus custom reminders, retries and cancellation |
| **Reports** | Appointments, charges, collections, top procedures, payment methods, outstanding balances, inventory usage, activity log (printable) |
| **Settings** (admin) | Clinic users and roles, phone-access address, one-click database backup |

Roles: **Admin** can do everything. **Dentist** can also edit dental charts, archive patients and post notices. **Staff** handle patients, appointments, billing, inventory and reminders.

## Setup

1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open phpMyAdmin (http://localhost/phpmyadmin), go to **Import**, and import `database/schema.sql`. This creates the `welldent` database.
   - Optional: also import `database/demo_data.sql` for sample patients. The demo logins are `admin` / `welldent123` and `drsantos` / `welldent123`. Change or remove these before real use.
3. Open http://localhost/GitHub/WellDent/. If there are no users yet, it opens **setup** so you can create the first admin account.

If your MySQL root user has a password, create `config/config.local.php`:

```php
<?php
return [
    'db' => ['pass' => 'your-password'],
    'clinic' => ['name' => 'Dental Wellness', 'city' => 'Manila', 'opens' => '09:00', 'closes' => '17:00'],
];
```

Anything in `config/config.local.php` overrides `config/config.php`. The local file is git-ignored.

## Phone access (local IP)

1. Connect the laptop and the phones to the same Wi-Fi.
2. **Settings → Phone access** shows the address to open on phones, for example `http://192.168.1.10/GitHub/WellDent/`.
3. If phones can't connect, open Windows Defender Firewall, choose **Allow an app**, and tick **Apache HTTP Server** for Private networks.
4. Give the laptop a fixed IP (a DHCP reservation in the router) so the address doesn't change.

## SMS and email reminders

Until you configure a provider, reminders are written to `storage/outbox.log` (test mode) and nothing is actually sent.

**SMS through [Semaphore](https://semaphore.co)**, a Philippine gateway. Add this to `config/config.local.php`:

```php
'sms' => ['driver' => 'semaphore', 'api_key' => 'YOUR_KEY', 'sender_name' => 'DENTWELL'],
```

**Email through Gmail**:
1. Create a Gmail App Password (Google Account → Security → 2-Step Verification → App passwords).
2. In `C:\xampp\sendmail\sendmail.ini`, set:
   - `smtp_server=smtp.gmail.com`
   - `smtp_port=587`
   - `auth_username=you@gmail.com`
   - `auth_password=<app password>`
3. In `C:\xampp\php\php.ini`, set `sendmail_path = "\"C:\xampp\sendmail\sendmail.exe\" -t"`.
4. Restart Apache.
5. Add this to `config/config.local.php`:

   ```php
   'email' => ['driver' => 'mail', 'from' => 'you@gmail.com', 'from_name' => 'Dental Wellness'],
   ```

**Sending on a schedule:** use the **Send due now** button, or set up Windows Task Scheduler to run this every 15 minutes:

```
C:\xampp\php\php.exe C:\xampp\htdocs\GitHub\WellDent\cron\send_reminders.php
```

## Backup and restore

- **Backup:** Settings → **Download backup (.sql)**. Keep a copy off the laptop (USB drive or cloud) at least weekly.
- **Restore:** in phpMyAdmin, select (or create) the `welldent` database, then **Import** the backup file.

## Project layout

Each page handles its own form POSTs. Link between pages with `url('patients/view.php')`, which works from any folder.

```
index.php        dashboard
appointments/    index.php (day/week schedule, waitlist, notices), form.php (book / reschedule)
patients/        index.php (list), view.php (record, chart, billing), form.php (add / edit), payment.php
inventory/       index.php
reminders/       index.php
reports/         index.php (screen, print and PDF download)
settings/        index.php (admin)
auth/            login.php, logout.php, account.php (change password), setup.php (first run)
includes/        bootstrap, db helpers, auth/roles, layout, reminders, dental chart, PDF writer; not web-accessible
assets/          css/app.css, js/app.js
config/          config.php (+ your config.local.php)
database/        schema.sql, demo_data.sql
cron/            send_reminders.php
docs/            USER_GUIDE.md (for clinic staff)
storage/         outbox.log (test-mode messages), php-errors.log; not web-accessible
```

The old single-folder addresses (`patients.php`, `patient.php?id=…`, `login.php`, …) redirect to the new ones, so existing bookmarks keep working.

## Security notes

- Passwords are hashed with `password_hash`. Sign-in locks for up to 15 minutes after 5 wrong passwords for one username, or 10 failed attempts from one device, within 15 minutes. Failures are counted in the database (`login_attempts`), so a new session doesn't reset them. An admin password reset lifts the lock, and each lockout appears in the activity log.
- Every form carries a CSRF token, and all queries use prepared statements.
- Apache (`.htaccess`) blocks web access to `config/`, `includes/`, `database/`, `cron/` and `storage/`, hidden files such as `.git`, Markdown/SQL/log files, and folder listings. These rules need `AllowOverride All` for the WellDent folder (XAMPP's default for `htdocs`).
- PHP errors are logged to `storage/php-errors.log` and never shown on screen. Set `'security' => ['debug' => true]` in `config/config.local.php` while developing to see them.
- Sessions sign out after 30 minutes without activity (`security.idle_minutes`). Changing or resetting a password signs out that user's other sessions.
- Archiving a patient hides the record and keeps it, since medical records should be retained. Only admins can delete billing entries.
- Over plain `http://`, anyone on the same Wi-Fi can read what's sent. Set up HTTPS (below) before real patient data goes on the network.

## HTTPS on the clinic machine

1. Install [mkcert](https://github.com/FiloSottile/mkcert) (`winget install FiloSottile.mkcert`), then in a terminal run `mkcert -install`.
2. Create a certificate for the addresses staff will use (the machine's fixed LAN IP and name), for example:
   `mkcert -cert-file C:\xampp\apache\conf\ssl.crt\welldent.crt -key-file C:\xampp\apache\conf\ssl.key\welldent.key 192.168.1.10 clinic-pc localhost`
3. In `C:\xampp\apache\conf\extra\httpd-ssl.conf`, point `SSLCertificateFile` and `SSLCertificateKeyFile` at those two files. Restart Apache and open `https://192.168.1.10/GitHub/WellDent/`.
4. Make every other device trust the certificate: run `mkcert -CAROOT` to find `rootCA.pem`, copy it to each PC (install into **Trusted Root Certification Authorities**) and phone (Android: Settings → Security → Encryption & credentials → Install a certificate → CA certificate; iPhone: install the profile, then Settings → General → About → Certificate Trust Settings).
5. Allow **Apache HTTP Server** through Windows Defender Firewall on Private networks (port 443).
6. Add `'security' => ['force_https' => true]` to `config/config.local.php` so plain `http://` links redirect to HTTPS. The session cookie is marked Secure automatically on HTTPS.

Keep `rootCA-key.pem` (in the `mkcert -CAROOT` folder) private and off other devices: anyone with it can make certificates your devices will trust.

## Hardening the clinic machine

These are server settings, outside the app:

- `C:\xampp\php\php.ini`: `expose_php = Off`.
- `C:\xampp\apache\conf\httpd.conf`: add `ServerTokens Prod` and `ServerSignature Off` so pages don't advertise the Apache/PHP versions.
- `C:\xampp\mysql\bin\my.ini`: under `[mysqld]`, set `bind-address = 127.0.0.1` so MySQL isn't reachable from the network.
- Give MySQL `root` a password and create a separate user for WellDent with rights on the `welldent` database only; put it in `config/config.local.php`.
- Copy only the `WellDent` folder to the clinic machine, not the `.git` folder or other projects.

Restart Apache and MySQL after changing these.
