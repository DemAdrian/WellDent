# WellDent User Guide

Last updated: October 1, 2026

## Overview

WellDent+ is the clinic's staff app for appointments, patient records, billing, inventory, reminders and reports. Patients never log in; everyone who uses it works at the clinic. It runs on one clinic laptop and also opens on staff phones connected to the same Wi-Fi.

What you can do depends on your role. Your role shows under your name in the top-right corner.

| Role | What it can do |
| --- | --- |
| Staff | Patients, appointments, billing and payments, inventory, reminders, reports |
| Dentist | Everything Staff can, plus edit dental charts, archive patients and post clinic notices |
| Administrator | Everything, plus the Settings page: user accounts, phone access address and database backups |

## Getting started

Open the app in a browser, sign in with the username and password your administrator gave you, and you land on the Dashboard.

1. Open the app.
   - On the clinic laptop: http://localhost/GitHub/WellDent/
   - On a phone: connect to the clinic Wi-Fi, then open the address shown in **Settings → Phone access** (for example http://192.168.1.10/GitHub/WellDent/). Ask an administrator for it.
2. Enter your **Username** and **Password**, then tap **Sign in**.
3. Five wrong passwords within 15 minutes lock that account for up to 15 minutes; the message says how long. If you can't wait, ask an administrator to reset your password (Settings → Reset password), which also lifts the lock.
4. To change your password, click your name in the top-right corner and choose **Change password**. New passwords need at least 8 characters.
5. When you finish, click your name and choose **Sign out**, especially on a shared laptop.

**First time the app is used:** if no accounts exist yet, the app opens a setup page instead of sign-in. Enter a full name, username and password to create the first Administrator account. The setup page locks itself after that.

## Finding your way around

Every page has the same frame: a menu on the left, the page title and main button at the top, and your account menu in the top-right corner.

- **Left menu:** Dashboard, Appointments, Patients, Inventory, Reminders, Reports, and Settings (Administrators only). The page you are on is highlighted.
- **Chair status card:** under the menu. It shows whether the chair is available, has a patient waiting or is in treatment, and when the next patient is due.
- **Top-right:** the page's main button (for example **+ New appointment**) and your name. Click your name for **Change password** and **Sign out**.
- **Messages:** after you save something, a green (success) or red (problem) message appears at the top of the page.

| Page | Go here to |
| --- | --- |
| Dashboard | See today at a glance and jump to anything that needs action |
| Appointments | Book, reschedule and update appointments; manage the waitlist |
| Patients | Find, add and edit patient records |
| Inventory | Track supplies, record stock in and out |
| Reminders | Check, send or cancel SMS and email reminders |
| Reports | View and print clinic summaries |
| Settings | Manage users, phone access and backups (Administrators) |

### The Dashboard

The Dashboard is your home page and greets you by name. Every card on it is a shortcut.

- **Four summary cards** across the top: Patients today, Pending balances, Reminders scheduled and Low stock. Click a card to open the matching list.
- **Today:** the day's schedule with each patient's time, procedure and status. Click a row to open that patient.
- **Patient flow:** a bar chart of appointments over the last 7 days.
- **Quick actions:** **Add patient**, **Log payment** and **Stock in**.
- **Attention needed:** clinic notices, low stock, unconfirmed appointments, failed reminders and upcoming patients with incomplete charts. Click an item to fix it.
- **Balances & credits:** the five largest amounts owed or held as credit. Click a name to open that patient's billing.

## Everyday tasks: patients, appointments and billing

A patient must exist before you can book them, so the usual order is: add the patient, book the appointment, update its status on the day, then record treatments and payments.

### Add a new patient

1. Go to **Patients** and click **+ Add patient** (or **Add patient** on the Dashboard).
2. Fill in the five parts of the chart: **Personal details**, **Emergency contact**, **Medical profile**, **Dental profile** and **Consent**. Fields marked \* are required.
3. Phone numbers must be Philippine numbers, for example 0917 123 4567 or (02) 8123 4567.
4. Click save. If details are still missing, click **Save as draft** instead and finish later.

The **Paperless checklist** on the right ticks off Demographics, Medical history, Dental history and Consent as you fill them in. A saved patient is marked **Active** when all four are complete, otherwise **Incomplete**.

### Find a patient

1. Go to **Patients**.
2. Type a name, phone number, email or record ID in the search box.
3. Narrow the list with **Balance due**, or open **Filters** to choose a record status (Active, Incomplete, Draft, Archived) or care type (New patient, Preventive, Orthodontic, Restorative, Recall), then click **Apply**.
4. Click a patient to open their record. **All records** clears the filters.

### Book an appointment

1. Click **+ New appointment** on the Dashboard or the Appointments page.
2. Choose the **Patient**. If they are not listed, click **Add the patient first**.
3. Set the **Date**, **Time**, **Duration** and **Procedure** (type or pick from the list). Optionally choose the **Dentist**, **Chair** and add **Notes**.
4. Check the **Booked on** panel on the right to see times already taken that day.
5. Click **Book appointment**.

The app blocks times in the past, outside clinic hours and overlaps with another booking on the same chair. If it refuses, the red message says why; pick another time. A reminder is scheduled automatically for the day before.

### Work the day's schedule

- **Appointments** opens on today in **Day** view. Switch to **Week**, use **‹** and **›** to move, pick a date, or click **Today** to return.
- On each appointment, use the **Update status** dropdown. It saves as soon as you choose. The statuses are Pending, Confirmed, Arrived, In treatment, Completed, Cancelled and No-show.
- Click **Reschedule** to change the time or details, or **View patient** to open the record.
- **Chair capacity** shows how much of the day is booked and how many hours remain.
- Setting Cancelled or No-show frees the slot and cancels its reminders. Re-opening it brings them back, unless someone else has taken the slot.

### Use the waitlist

1. On **Appointments**, click **Add to waitlist**, choose the patient, enter the procedure and any preferred days, then click **Add**.
2. When a slot opens, click **Fill open slot** next to their name. The booking form opens pre-filled; choose a time and book. They leave the waitlist automatically.
3. Click **✕** to remove someone without booking.

### The patient record

A patient's record has three tabs: **Dental Chart**, **Treatments & Billing** and **Patient Info**. Click **Edit** to change their details.

**Dental Chart:** 32 teeth in Universal Numbering, coloured by condition. Click any tooth to see its history. Dentists and Administrators can also record a new entry: choose the **Condition** (Healthy, Cavity, Filled, Crown, Extracted, Root Canal, Braces, Missing, Prosthetic), the **Procedure** and **Notes**, add an optional **Charge** to bill it, and click **Save tooth entry**. Staff can view the chart but not change it.

**Treatments & Billing:** shows total charged, paid and balance.

1. Click **+ Add treatment**, enter the procedure, date, amount in ₱, optional tooth number and dentist, then click **Save treatment**.
2. Click **+ Log payment** to record money received (next section).
3. Only Administrators can remove a treatment or payment with **✕**.

### Record a payment

1. Click **Log payment** on the Dashboard, or **+ Log payment** on the patient's billing tab.
2. Choose the **Patient**, enter the **Amount (₱)** and **Date** (not in the future), and choose the **Method**: Cash, GCash, Maya, Card, Bank transfer or Other.
3. Add the **Reference / OR no.** and any **Notes**, then click **Record payment**.

A payment larger than the balance leaves a credit, which shows on the Dashboard under **Balances & credits**.

### Archive a patient (Dentists and Administrators)

Click **Archive** on the patient's record and confirm. Their records are kept but hidden from lists, and their upcoming appointments, reminders and waitlist entries are cancelled. To undo, find them with the **Archived** filter and click **Restore**; re-opened appointments come back as Pending for you to confirm.

### Post a clinic notice (Dentists and Administrators)

On **Appointments**, click **Post a notice**, type the message (for example a weather warning or lab delay), set **From** and **Until** dates, then click **Post notice**. It appears as a banner on Appointments and under **Attention needed** on the Dashboard. Click **Dismiss** to remove it early.

## Inventory, reminders, reports and settings

These pages keep supplies stocked, patients reminded and the clinic's numbers in view. Settings is for Administrators only.

### Inventory

- **Add an item:** click **+ Add item**, enter the **Name**, **Category**, **Unit**, **Supplier**, **Opening quantity** and the **Alert at or below** level, then click **Save item**.
- **Record stock in or out:** click **Stock in / out** (or **Stock in** on the Dashboard), choose the **Item**, pick **Stock in (received)** or **Stock out (used)**, enter the **Quantity** and an optional **Reason**, then click **Save**. You cannot take out more than is in stock.
- **See what is running low:** click **Low stock**. Items at or below their alert level are flagged low, and those at half the level or less are critical. **All items** shows everything again.
- **Change an item:** click **Edit** on the item. **Recent movements** lists the latest stock in and out.

### Reminders

The app schedules a reminder for every booked appointment, to go out at 8:00 AM the day before, by SMS and/or email. Reminders are only actually sent once an SMS or email provider is set up; until then they are saved in test mode.

- **Tabs:** Scheduled, Sent, Failed and All. Numbers in brackets show how many are in each.
- **Send due now:** sends every reminder whose time has come. Use it if automatic sending is not set up.
- **Send now / Retry / Cancel:** on each reminder. **Retry** appears on failed ones; check the Failed tab for the reason.
- **Custom reminder:** click **+ Custom reminder**, choose the **Patient**, **Channel** (SMS or Email), **Send at** time and **Message** (up to 480 characters, about 3 SMS), then click **Schedule**. The patient needs a mobile number or email on file.

### Reports

Reports shows clinic numbers for any date range. It opens on this month.

1. Choose **From** and **To** dates and click **Apply**, or click **Today**, **This month** or **This year**.
2. Read the four totals: Appointments, New patients, Charged and Collected.
3. Scroll for Top procedures, Payments by method, Outstanding balances (all time), Inventory usage and the Activity log (who did what, and when).
4. Click **Print** for a paper copy.

### Settings (Administrators)

- **Add a user:** click **+ Add user**, enter **Full name**, **Username**, **Role** and a **Temporary password** (at least 8 characters), then click **Create user**. Give them the password privately and ask them to change it after signing in.
- **Reset a password:** click **Reset** next to the user, enter a **New password** and share it privately.
- **Disable or enable an account:** click **Disable** to stop someone signing in without deleting their history. You cannot disable your own account.
- **Phone access:** shows the address staff open on their phones while on the clinic Wi-Fi.
- **Backup:** click **Download backup (.sql)**. Do this at least weekly and keep a copy off the laptop, on a USB drive or in cloud storage.

## Troubleshooting

Most problems come from the laptop's server being off or the phone being on a different network. Start with the first row.

| Problem | What to do |
| --- | --- |
| The page will not load at all | On the clinic laptop, open the XAMPP Control Panel and start **Apache** and **MySQL**. |
| A phone cannot open the app | Check the phone is on the clinic Wi-Fi, not mobile data. Use the address from **Settings → Phone access**. If it still fails, an Administrator should allow **Apache HTTP Server** for Private networks in Windows Defender Firewall. |
| The phone address stopped working | The laptop's network address changed. Get the new one from **Settings → Phone access**, and ask whoever manages the router to give the laptop a fixed address. |
| "Too many attempts" at sign-in | Wait 60 seconds, then try again. If you forgot your password, ask an Administrator to reset it. |
| "You do not have access to this action" | Your role cannot do this. Ask a Dentist or Administrator. |
| "Chair is already booked" | Another appointment overlaps on that chair. Check the **Booked on** panel and pick a free time. |
| "That time has already passed" or outside clinic hours | Pick a later time within opening hours. |
| Patient not in the booking list | Archived patients are hidden. Add them first, or restore them from **Patients → Filters → Archived**. |
| Reminders show as sent but patients got nothing | SMS or email is not set up yet, so the app is in test mode. Ask an Administrator. |
| Reminders on the Failed tab | Check the patient's mobile number or email, then click **Retry**. |
| Data lost or the laptop failed | An Administrator can restore the latest **Download backup** file in phpMyAdmin. |
