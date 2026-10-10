# Pillbox

<img src="public/assets/logo.svg" alt="Pillbox logo" width="96" align="right">

A medication and health tracker built with PHP and MySQL. It works in mobile and desktop browsers.

- **Medications**: search the NHS medicines dictionary (dm+d) or type them in; add, edit, stop (history is kept) or permanently delete. Each medicine can have several
  dose times with **different amounts**, on **chosen weekdays**, and between set dates (useful for reducing
  doses). "As needed" medicines can have a daily maximum, and any medicine an optional **expiry date**
  (set when you add it or collect a new supply), flagged on Today when it's within 30 days or past.
- **Today view**: tick off doses (Take, Skip or Undo), see missed doses, get low-stock warnings and log
  as-needed doses.
- **Health readings**: blood pressure, heart rate, blood glucose, ketones, weight, temperature, SpO₂,
  breathing rate, peak flow, INR, cholesterol, pain, mood, sleep, fluid intake, steps, and symptoms or side
  effects. Each user picks the ones their doctor wants, and can **add their own** custom measurements.
  Every measurement has trend charts and averages.
- **Apple Health import**: upload the Health app's export, or sync automatically every day from an iPhone
  using Shortcuts or the Health Auto Export app.
- **History and adherence**: each day's doses, plus the percentage of doses taken for each medicine.
- **Dose change history**: changes to strength, amount or timing are recorded automatically, e.g.
  "Increased from 10mg to 20mg a day", with the date and reason. Each medicine has a timeline, and you can
  add earlier changes by hand.
- **Travel mode**: plan trips across time zones and choose to switch to local time, stay on home time, or
  shift gradually by 1–4 hours a day, with a day-by-day preview of dose times.
- **Care** (the **Care** tab): dental care, eye care, sleep, physical activity & rehab, foot health, women's health,
  hearing, skin, screening and vaccinations. Choose which appear under **Account → Health profile**, along with your
  date of birth and sex (for screening). Each category can have:
  - **Routines** ticked off on the Today page: one-tap presets (brushing, screen breaks, physio exercises with reps,
    stretching, foot checks, monthly mole checks, CPAP cleaning...) or your own, at set times on chosen days or every
    few days or weeks, with a streak and a 7-day view for catching up on missed ticks.
  - **Checks and renewals** with a next due date: eye and hearing tests, skin checks, pill reviews, implant and coil
    replacements, flu (every autumn), COVID boosters, tetanus, travel jabs. Anything overdue or due within 30 days is
    listed under **Coming up** on Today.
  - **NHS screening**: cervical, breast, bowel and AAA, on NHS England intervals, worked out from your date of birth
    and when you were last screened.
  - **Cycle tracking** (Women's health): log periods, see your average cycle and when the next is expected.
  - **Sleep log**: bedtime, wake time, time awake and how you slept, saved as a Sleep reading alongside Apple Health
    nights, with the last 14 nights as a chart.
  Tasks marked **Remind me**, and checks on their due dates, are added to your private calendar feed with an alert,
  so your phone's calendar reminds you.
- **Care summary**: a printable page (Care → **Summary**) for any period and one or all categories: how often each
  routine task was done, every check, screening and vaccination with last done and next due, sleep and cycle figures.
- **Mind** (its own tab; switch it off under Account → Health profile):
  - **Check-ins**: mood (1 to 5), energy, motivation, anxiety (0 to 10), words that fit, triggers and a note, with
    30 and 90-day charts and patterns such as "mood is lower after under 6 hours of sleep". No streaks.
  - **Help now** (the lifebuoy in the header, on every page): UK crisis lines (999, NHS 111 option 2, Samaritans,
    Shout), editable for other countries, your own people and professionals, and your **safety plan** in the
    NHS / Stanley-Brown format. A check-in saying you're having thoughts of harming yourself, or any answer above
    "Not at all" to PHQ-9 question 9, goes straight here.
  - **Coping toolkit** of what helps you, shown after a low check-in, plus box breathing and 5-4-3-2-1 grounding timers.
  - **PHQ-9** and **GAD-7** with scores over time; **journal** with prompts, and CBT **thought records**.
  - **Summary**: a printable 4, 8 or 12-week report (notes and journal only if you tick them).
  - **Privacy**: an optional PIN (locks after 10 minutes; crisis contacts are never locked), and a daily check-in
    reminder in the calendar feed worded just "Pillbox check-in".
- **Appointments**: GP, specialist, hospital, dentist, optician, blood tests and more, with a private
  calendar link per person that Apple Calendar, Google Calendar and Outlook can subscribe to.
- **Doctor's report**: a printable summary (or save as PDF), plus CSV export.
- **Accounts**: local username/password and **Microsoft Entra ID single sign-on**. Supports multiple users,
  each with private data, plus an admin page.

## Appointments and the calendar link

The calendar icon in the top bar opens **Appointments**. Add the date, time, length, type (GP, specialist,
hospital, dentist, optician, blood test, scan, physio, mental health, vaccination, ...), who it's with,
where, notes and an optional reminder. The next two weeks also show on the Today page, and the doctor's
report lists appointments.

**Add to your calendar** creates a private calendar link (`calendar.php?token=…`) unique to each person.
Subscribe to it in Apple Calendar, Google Calendar or Outlook, and new or changed appointments appear there
automatically. Calendar apps check every few hours.

- Anyone with the link can see that person's appointments. Notes are left out unless the person switches
  them on. **Make a new link** stops the old one working immediately, and **Turn off link** disables it.
- The link uses your site's address as the browser sees it. If you're behind a proxy or the link shows the
  wrong address, set `'public_url' => 'https://your-site'` in the `app` section of `config/config.php`.

### Preparing for an appointment

Tap an appointment to open its page. It has:

- **Questions to ask**, ticked off on the day, with space for each answer.
- **Suggested topics** from what you've recorded since your last appointment: side effects, missed doses,
  heavy use of as-needed medicines, recent dose changes, prescriptions running low, and blood pressure.
- **After the appointment** notes for what was decided.

**Print / save as PDF** gives a one-page sheet with tick boxes and space to write answers.

## Travel mode

**Meds → ✈ Travel** lets you plan a trip: where you're going (its time zone), when you leave and when you get
back, and how dose times should change:

| Option | What happens (e.g. 08:00 dose, London → Tokyo) |
|---|---|
| Switch to local time | 08:00 Tokyo time. Home time resumes the day after you get back. |
| Stay on home time | 16:00 Tokyo time, the same moment as 08:00 in London. |
| Shift gradually | Moves 1–4 hours a day until it's 08:00 Tokyo time, then moves back the same way after you return. |

While you're away, all times in Pillbox are shown in local time, and each dose also shows its home time.
If any medicines are time-critical (insulin, contraceptive pills, epilepsy, Parkinson's or HIV medicines),
check with a doctor or pharmacist before travelling.

## Apple Health

Apple doesn't let websites read Apple Health directly, so **Readings → Import from Apple Health** offers two ways:

1. **Import the Health export.** In the Health app, tap your picture → **Export All Health Data**, then upload
   `export.zip` (from the phone itself or a computer). Large exports are uploaded in pieces, so your web host's
   upload limit doesn't matter. Re-importing never creates duplicates.
2. **Sync automatically.** Create a private sync link, then use the **Health Auto Export** app (REST API
   automation, JSON) or an Apple **Shortcuts** automation to post new readings to it every day.

What's imported:

- **Each reading:** blood pressure, weight, blood glucose, body temperature, peak flow.
- **One value per day:** resting heart rate, oxygen saturation, breathing rate, steps, and sleep (time asleep).

Units are converted to Pillbox's (kg, mmol/L, °C, %). Steps and sleep recorded by both an iPhone and a Watch
are only counted once. Measurements that receive data are switched on automatically. Imported readings are
labelled "Apple Health", and **Remove all imported readings** takes them out again without touching readings
you typed in yourself.

The server needs PHP's `xmlreader` extension (standard) and, to read `export.zip` directly, `zip`. Without
`zip`, unzip the export and upload `export.xml` instead.

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

## Other single sign-on providers (OpenID Connect)

Besides Microsoft, Pillbox can sign people in with any OpenID Connect provider, such as Google, Okta,
Auth0, Keycloak, Authentik, Authelia or Zitadel. Add as many as you like under `'oidc' => ['providers' => [...]]`
in `config/config.php`; each gets its own button on the login page. The template has a Google example plus
commented examples for Okta, Keycloak and Authentik.

For each provider:

1. Create an OAuth/OIDC **web application** client in the provider's admin console.
2. Set its redirect (callback) URI to `https://your-site/oidc_callback.php` (the same URL for every provider).
3. Copy the client ID and secret into `config/config.php`, set `issuer` to the provider's issuer URL and
   `'enabled' => true`.

| Provider  | `issuer` |
|-----------|----------|
| Google    | `https://accounts.google.com` |
| Okta      | `https://YOUR-ORG.okta.com` (or `https://YOUR-ORG.okta.com/oauth2/default`) |
| Auth0     | `https://YOUR-TENANT.auth0.com/` |
| Keycloak  | `https://HOST/realms/REALM` |
| Authentik | `https://HOST/application/o/SLUG/` |

Options per provider:

- `auto_provision`: create an account on first sign-in. **With Google, always combine this with
  `allowed_email_domains`**, or anyone with a Google account can sign up.
- `allowed_email_domains`: only accept verified email addresses at these domains, e.g. `['example.com']`.
- `admin_claim` / `admin_values`: make people admins from a claim in the ID token, e.g.
  `'groups'` / `['pillbox-admins']`. This only applies to accounts that sign in only with that provider.
- `single_logout`: also sign out of the provider when signing out of Pillbox.

How accounts work:

- **Pre-registering people:** with `auto_provision` off, an admin adds them under **Admin → Add a user**
  and picks the provider. They're matched by their verified email the first time they sign in.
- **Linking:** anyone signed in can link a provider account under **Account → Sign-in options**, and then
  sign in either way. Pillbox never lets you unlink your last way of signing in.

Pillbox uses the authorisation-code flow with PKCE. It checks every ID token's RS256/384/512 signature
against the provider's published keys, plus the issuer, audience, expiry and nonce.

`'disable_local_login_when_sso' => true` (in the `app` section) turns off password sign-in whenever
Microsoft or any OIDC provider is enabled.

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

## Version and updates

The installed version is in the `VERSION` file and is shown in the footer, with a link to the GitHub
repository (`github_repo` in `config/config.php`; set it to `''` to hide both). For admins, Pillbox compares
its `VERSION` with the newest published release on GitHub, at most twice a day, and shows
**Update available** in the footer when that release is newer. Drafts and pre-releases are ignored. The
Admin page has **Check now** and links to the release notes.

- **To release a new version:** raise the number in `VERSION` (e.g. `1.0.0` → `1.1.0`), merge it into `main`,
  then publish a GitHub release tagged with the same number (`v1.1.0` or `1.1.0`).
- **Private repository:** create a fine-grained GitHub token with read-only **Contents** access to the
  repository and put it in `github_token`. Without it, GitHub hides the repository and the check reports
  that it couldn't look.

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
