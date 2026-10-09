# Pillbox

<img src="public/assets/logo.svg" alt="Pillbox logo" width="96" align="right">

A medication and health tracker built with PHP and MySQL. It works in mobile and desktop browsers.

- **Medications**: search the NHS medicines dictionary (dm+d) or type them in; add, edit, stop (history is kept) or permanently delete. Each medicine can have several
  dose times with **different amounts**, on **chosen weekdays**, and between set dates (useful for reducing
  doses). "As needed" medicines can have a daily maximum.
- **Today view**: tick off doses (Take, Skip or Undo), see missed doses, get low-stock warnings and log
  as-needed doses.
- **Health readings**: blood pressure, heart rate, blood glucose, ketones, weight, temperature, SpO₂,
  breathing rate, peak flow, INR, cholesterol, pain, mood, sleep, fluid intake, steps, and symptoms or side
  effects. Each user picks the ones their doctor wants, and can **add their own** custom measurements.
  Every measurement has trend charts and averages.
- **History and adherence**: each day's doses, plus the percentage of doses taken for each medicine.
- **Doctor's report**: a printable summary (or save as PDF), plus CSV export.
- **Accounts**: local username/password and **Microsoft Entra ID single sign-on**. Supports multiple users,
  each with private data, plus an admin page.

## Requirements

- Apache 2.4 with `mod_rewrite` (normally enabled) and ideally `mod_headers`
- PHP 8.1+ with `pdo_mysql`, `curl`, `openssl`, `mbstring`
- MySQL 8.0+ or MariaDB 10.5+
- HTTPS (required for Entra ID sign-in and strongly recommended anyway)

## Installing on an Apache server

1. **Upload** the whole folder, for example to `/var/www/pillbox` or into your web space.

2. **Create a database and user**, either in phpMyAdmin or with:
   ```sql
   CREATE DATABASE pillbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'pillbox'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT ALL PRIVILEGES ON pillbox.* TO 'pillbox'@'localhost';
   ```
   You don't need to import any tables, because Pillbox creates them the first time it is opened.
   You can import `database/schema.sql` yourself if you prefer.

3. **Configure**: copy `config/config.example.php` to `config/config.php` and fill in the
   database details. `config/config.php` is the only settings file; it is git-ignored, so your passwords stay off GitHub. If the site is in a sub-folder (e.g. `https://example.com/pillbox/`), set
   `'base_path' => '/pillbox'`.

4. **Make `storage/` writable** by the web server (it caches Microsoft's signing keys):
   `chown www-data storage` (or set it to 775 in your file manager).

5. **Open the site**. On a new install you'll be asked to create the first administrator account.

### Where the web root should point

- **Best:** point the virtual host's `DocumentRoot` at the `public/` folder:
  ```apache
  <VirtualHost *:443>
      ServerName pillbox.example.com
      DocumentRoot /var/www/pillbox/public
      <Directory /var/www/pillbox/public>
          AllowOverride All
          Require all granted
      </Directory>
      # ... SSL settings ...
  </VirtualHost>
  ```
- **Shared hosting / can't change it:** upload the whole folder into the web root (or a sub-folder).
  The top-level `.htaccess` sends requests into `public/` and blocks `src/`, `config/`, `database/` and
  `storage/`. Each of those folders also has its own deny-all `.htaccess` as a fallback. This needs
  `AllowOverride All` (or at least `FileInfo` and `AuthConfig`).

  **Check it:** browsing to `https://your-site/config/config.php` should return *403 Forbidden*.

## Installing as an app on iPhone and Android

Pillbox is a Progressive Web App (PWA). Once it's on your HTTPS site, people can add it to their home
screen. It then opens full screen with its own icon, like a normal app; no App Store or Google Play
listing is needed. **Account → Install Pillbox on your phone** explains how:

- **iPhone / iPad:** open the site in Safari → Share → **Add to Home Screen**.
- **Android:** open the site in Chrome → ⋮ menu → **Install app** (or tap **Install the app** on the Account page).

Notes:

- HTTPS is required; browsers won't install the app over plain HTTP.
- The app needs a connection. Health data is never stored on the phone, only on your server; if the
  phone is offline it shows a "You're offline" page.
- Sign-ins last for `session_idle_minutes` (8 hours by default) since last use, even if the app is
  closed. Raise it in `config/config.php` if people find they're signed out too often, e.g. `10080`
  for a week.
- Sessions are stored in `storage/sessions`, so `storage/` must be writable.

## Microsoft Entra ID single sign-on

1. In the Entra admin centre go to **App registrations → New registration**.
   - Supported account types: *This organisational directory only* (single tenant) is usual.
   - Redirect URI: platform **Web**, `https://your-site/auth_callback.php` (or `.../pillbox/auth_callback.php`).
2. Under **Certificates & secrets**, create a client secret and copy its *value*.
3. Under **Token configuration**, optionally add the `email` claim.
4. *(Optional, for admins)* Under **App roles**, create a role with value `Pillbox.Admin`. Then in
   **Enterprise applications → Pillbox → Users and groups**, assign it to people who should manage users.
   Admin rights are then synced from Entra at each sign-in. Set `'admin_role' => ''` to manage admins
   only inside Pillbox.
5. *(Optional)* To limit who can sign in, set **Assignment required = Yes** on the enterprise
   application and assign users or groups.
6. In `config/config.php`, set the `entra` section:
   ```php
   'entra' => [
       'enabled'       => true,
       'tenant_id'     => '<Directory (tenant) ID>',
       'client_id'     => '<Application (client) ID>',
       'client_secret' => '<secret value>',
       'redirect_uri'  => 'https://your-site/auth_callback.php',
   ],
   ```

How accounts are created:

- With `auto_provision` on (the default), anyone from your tenant gets a Pillbox account on first sign-in.
- With it off, an admin adds them first under **Admin → Add a user → Microsoft**, using their sign-in email.
- Accounts are matched by Entra object ID and tenant ID, so a name or email change won't create a second account.
- If no users exist yet, the first person to sign in with Microsoft becomes the administrator.

**Multi-tenant:** set `tenant_id` to `organizations` and list the permitted tenant GUIDs in `allowed_tenants`.
Sign-ins from any other tenant are rejected.

You can run local and Microsoft accounts side by side. To allow Microsoft accounts only, set
`'allow_local_login' => false`.

## NHS medicines dictionary (dm+d) lookup

When adding a medication, you can search the NHS Dictionary of Medicines and Devices (dm+d) and pick the
exact product, e.g. "Metformin 500mg modified-release tablets". Pillbox fills in the name, strength, form
and dose unit, and stores the product's dm+d code, which is shown on the doctor's report. Typing details in by
hand still works.

The search uses the NHS England Terminology Server. dm+d is licensed content, so you need a free
system-to-system account:

1. Go to [NHS England Terminology Server: how to access](https://digital.nhs.uk/services/terminology-server/how-to-access-the-terminology-server)
   and request a **system-to-system account**. You'll be given a client ID and client secret.
2. Add them to `config/config.php`:
   ```php
   'nhs_terminology' => [
       'enabled'       => true,
       'client_id'     => '<client id>',
       'client_secret' => '<client secret>',
   ],
   ```

Only the search words are sent to the NHS server, never anything about the patient.

### Why there's no "import my medications from the NHS"

As of October 2026, NHS England doesn't let new apps read a patient's medication record:

- **GP Connect (Patient Facing) Prescriptions**, the API behind the NHS App's medicines list, states that
  NHS England is *"not onboarding any additional consumer suppliers"*.
- **IM1 pairing** with GP clinical systems (EMIS, TPP) is open only to suppliers, after an application and
  clinical-safety and information-governance assurance.

If that changes, the stored dm+d codes are the same codes GP records use, so imported medicines could be
matched to ones already in Pillbox.

## Security notes

- Passwords are hashed with PHP's `password_hash`. Local logins are rate-limited per username and per IP.
- All forms have CSRF protection. Sessions use HttpOnly and SameSite cookies, which are Secure over HTTPS.
- A strict Content-Security-Policy is sent: no inline scripts or styles, and no third-party assets.
- Entra ID tokens are obtained with the authorisation-code flow plus PKCE. The ID token's signature,
  audience, issuer, expiry, nonce and tenant are all checked.
- Every query is scoped to the signed-in user, so people can only see their own data.
- Health data is sensitive. Use HTTPS, keep the server patched, back up the database, and check what
  your local data-protection rules require.

## Project layout

```
public/      web root: pages, assets (CSS, JS, logo)
src/         application code (auth, Entra OIDC, scheduling, metrics, charts, layout)
config/      config.example.php (template) -> copy to config.php (your settings)
database/    schema.sql
storage/     cache (must be writable)
```

*Pillbox is a record-keeping aid. It is not medical advice; always follow your prescriber's instructions.*
