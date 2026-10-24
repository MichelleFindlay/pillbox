# Pillbox

<img src="public/assets/logo.svg" alt="Pillbox logo" width="96" align="right">

A medication and health tracker built with PHP and MySQL. It works in mobile and desktop browsers.

**How to use it:** see the [documentation](docs/README.md), also in the app under **Docs** in the footer (and the **?** next to headings).

- **Medications**: search the NHS medicines dictionary (dm+d) or type them in; add, edit, stop (history is kept) or permanently delete. Each medicine can have several
  dose times with **different amounts**, on **chosen weekdays**, and between set dates (useful for reducing
  doses). "As needed" medicines can have a daily maximum, and any medicine an optional **expiry date**
  (set when you add it or collect a new supply), flagged on Today when it's within 30 days or past.
  **Dose changes only apply from now on**: past days keep the dose they had, and if any of today's doses
  are already logged the change starts tomorrow (or on a later "takes effect from" date). The latest
  change under each medicine is hidden unless you tick **Show dose changes**.
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
- **Care** (the **Care** tab): dental care, eye care, sleep, physical activity & rehab, respiratory, diabetes and
  monitoring, foot health, women's health, hearing, skin and wound care, mobility and supports, emergency and first aid,
  sharps and disposal, screening and vaccinations. Choose which appear under **Account → Health profile**, along with your
  date of birth and sex (for screening). Each category can have:
  - **Routines** ticked off on the Today page: one-tap presets (brushing, screen breaks, physio exercises with reps,
    stretching, foot checks, monthly mole checks, CPAP cleaning...) or your own, at set times on chosen days or every
    few days or weeks, with a streak and a 7-day view for catching up on missed ticks.
  - **Blood tests** that medicines need (lithium, methotrexate, INR, thyroid, kidney...), linked to the medicine,
    with a results log; and **Prescriptions and payments**: PPC and HRT PPC (start date and length), MedEx,
    MatEx and HC2 certificates with renewal reminders, a cost log, and a calculator showing whether a PPC would
    save money (prices in `config/config.php`). Medicines can be marked as a private prescription or bought
    without one, with their price: these are logged when a new supply is recorded and kept out of the PPC sums.
  - **Blood pressure**: guided home monitoring the NICE way (2 readings a minute apart with a 1-minute timer, morning
    and evening for up to 7 days, day 1 left out of the average) against the home target (under 135/85, or 145/85 from
    80, or what your clinician sets); pulse and irregular heartbeat flags; a 90-day chart with blood pressure medicine
    changes marked, and morning and evening averages; medicines recognised by type (ACE inhibitors, ARBs, calcium
    channel blockers, diuretics, beta blockers) with one-tap side effects; sick-day rules only if your clinician gave
    them; advice for readings of 180/120 or more and low readings (also in Readings); blood tests after starting an ACE
    inhibitor or ARB; a monitor CSV import (such as Omron); prompts for pregnancy and combined contraception; and a
    clinician summary.
  - **Migraine and headaches**: a diary (start and end or ongoing, severity, where, migraine, aura, symptoms, triggers,
    and medicines taken with how well they helped, optionally logged as a dose); headache, migraine and acute-medicine
    days each month (including as-needed doses logged on Today); medication overuse warnings at the NHS thresholds
    (15+ days of simple painkillers, 10+ of triptans, opioids, combination painkillers or a mix) with an early nudge
    from 8 days; common triggers; menstrual migraine (days -2 to +3); preventive treatment with a before-and-after
    review; the evidence NICE uses for CGRP medicines; warnings for topiramate (pregnancy prevention) and for combined
    hormonal contraception with migraine with aura (also on Meds); red flags; and a clinician summary.
  - **Diabetes** (type 1, type 2 and others): glucose time in range (very low, low, in range, high, very high) with a
    chart, patterns by time of day and before a period; CGM readings, insulin and carbs from **Nightscout**; HbA1c from
    Blood tests; ketone guidance when you log one (under 0.6, 0.6 to 1.4, 1.5 to 2.9, 3 or more); an insulin and
    carbs log with daily totals; pens, glucagon and hypo kits as checks; "can cause hypos" and SGLT2 sick-day notes on
    medicines; a one-tap **hypo guide** with a 15-minute timer (opened automatically when a reading is under 4);
    **sick-day mode** (on Today too); the DVLA **"five to drive"** check; the annual care checks as a done / due list
    with eye screening; pregnancy planning and the gestational diabetes test; a medical ID text; and a clinician
    summary. Nightscout addresses on private networks are blocked unless `'nightscout' => ['allow_private' => true]`.
  - **Thyroid** (underactive thyroid): levothyroxine set-ups (morning on an empty stomach, or bedtime) and liothyronine,
    missed-dose guidance, the brand recorded with each collection (changes go in the medicine's history), and an
    automatic check across all medicines for calcium, iron, antacids or minerals taken within 4 hours (also warned on
    Meds); TSH, free T4, free T3 and antibodies in Blood tests with dose changes marked on the chart; when the next TSH
    test is due (about 3 months after a dose change until settled, then yearly, as NICE advises); a biotin reminder
    before blood tests; a weekly check of under- and overtreatment symptoms charted against dose changes; MedEx
    renewal; prompts when pregnant or starting oestrogen; a medical ID line; and a one-page clinician summary.
  - **Premenstrual symptoms (PMS, PMDD, PME)**, in Periods and contraception for anyone with ovaries: daily ratings with
    the DRSP (24 items, 1 to 6) or a quick check-in, cycle phases from logged bleeds or "day 1" markers when there's no
    bleed, a chart of severity by cycle day with cycles stacked and the luteal phase shaded, the week before each bleed
    compared with days 4 to 10 (telling a premenstrual pattern apart from premenstrual worsening of something ongoing),
    predicted harder days with an optional heads-up, medicines that run **only in the 2 weeks before a period**, one-tap
    treatments (SSRIs, continuous pill, GnRH with add-back HRT) compared before and after starting, lifestyle patterns,
    gentle links to the safety plan and Samaritans on very hard days, and a printable GP summary.
  - **Menopause**: stage (and whether it followed surgery or treatment), months since the last period (from the
    period log), a daily symptom diary (hot flush count, 12 symptoms, triggers, with patterns such as "more flushes on
    caffeine days"), the Greene Climacteric Scale over time, one-tap HRT set-ups in Meds (patch, gel, spray, tablets,
    progestogen, vaginal oestrogen, testosterone), notes on contraception, early or premature menopause and bleeding
    that needs a GP, HRT review, blood pressure, DEXA and Mirena checks, bone-health routines, work notes, and a
    printable one-page GP summary.
  - **Pregnancy** has its own page: the due date (from the last period, then the dating scan) and a weeks + days
    counter; the NHS antenatal timeline (booking, scans, midwife checks, extra checks for first babies, whooping
    cough, RSV and flu vaccines, MatEx, MATB1, maternity leave notice, birth plan, hospital bag) with Book and Done
    buttons and space for screening results; a log of each check-up (blood pressure, urine, bump measurement,
    heartbeat, position, questions for next time); folic acid and vitamin D added to Meds in one tap; and a
    "Something not right?" panel with the maternity unit's number. It never interprets results: a blood pressure of
    140/90 or more, or a change in movements, points to the midwife or maternity unit.
  - **Pregnancy options and aftercare** is a private page for deciding about a pregnancy, abortion care, or aftercare
    after a miscarriage or pregnancy loss. It sets out continuing the pregnancy, adoption and abortion without
    recommending any of them, and what's usually available at each stage. You can track steps (referral, consultation,
    counselling, treatment, follow-up test), medicine times, bleeding and symptoms, with red-flag advice and the
    provider's aftercare number. Everything is in one encrypted record (key in `storage/app.key`) with an optional
    PIN. It's never shared, and never appears on Today, in the calendar, emails, reports, backups or data downloads.
    Reminders only say "You have a reminder", and **Delete everything** removes it completely.
  - **Checks and renewals**, each tracked the way that suits it:
    - *due every so often*: eye and hearing tests, night guard checks, spacer and CPAP part replacements, pill reviews,
      flu (every autumn), tetanus, smoke alarm tests...
    - *expiry dates*: adrenaline auto-injectors, test strips ("Replaced" asks for the new date), or a time after
      opening: eye drops (28 days), sunscreen (12 months) ("Opened a new one");
    - *stock*: test strips, lancets, CGM sensors, dressings, with "restock by" worked out from how many you use a week;
    - *fill level*: the sharps bin, flagged for collection at three-quarters full;
    - *kits*: first aid kit and emergency meds bag, with contents and an expiry date per item.
    Anything overdue, expiring, low or due within 30 days is listed under **Coming up** on Today. Respiratory shows your
    inhalers' doses left and expiry from Meds; Sharps lists stopped or expired medicines to take back to a pharmacy.
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
  - **Therapy and care**: therapy and psychiatrist appointments (suggested topics include your mood since last time,
    PHQ-9/GAD-7 changes, medication notes and your running list of questions), actions agreed, care team contacts.
  - **Mental health medication**: mark medicines as such, and keep side-effect and "how I've felt since the change" notes.
  - **Today** on the Mind page: a self-care checklist (eaten, water, outside...), planned activities, and reminders
    to get in touch with people you've chosen. After a few low days, your own early-warning steps are shown.
  - **Symptoms** of your own, rated in each check-in; **activity scheduling** with enjoyment and achievement ratings;
    **habits and routines** (morning, evening, screen-time limits) on Today; a **wins and gratitude** log shown on hard days.
  - **Staying well plan** (relapse prevention: well, early warning signs, crisis) and a printable **advance statement**.
  - **Summary**: a printable 4, 8 or 12-week report (notes and journal only if you tick them).
  - **Privacy**: an optional PIN (locks after 10 minutes; crisis contacts are never locked), and a daily check-in
    reminder in the calendar feed worded just "Pillbox check-in".
- **Appointments**: GP, specialist, hospital, dentist, optician, blood tests and more, with a private
  calendar link per person that Apple Calendar, Google Calendar and Outlook can subscribe to.
- **Doctor's report**: a printable summary (or save as PDF), plus CSV export.
- **Accounts**: local username/password and **Microsoft Entra ID single sign-on**, with optional self sign-up and admin approval. Supports multiple users,
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

5. **Open the site**. On a new install you'll be asked to create the first administrator account, with a one-time
   **setup code** so nobody else who finds the site first can take it over. The code is in `storage/setup_code.txt`
   on the server, and in PHP's error log (`docker logs`, or your host's error log); or set your own as `'setup_code'`
   under `'app'` in config.php. It's deleted once the administrator exists. Everyone's
   first sign-in then goes through a short setup (about you, what to use, reminders), and administrators get a
   **Site settings** step too: site name and address, default time zone, email, scheduled tasks, demo mode.
   Those are saved in `config/settings.php` (git-ignored), which overrides `config/config.php`, so config.php
   itself, with its comments and database details, is never rewritten; they can be changed later on the Admin
   page. If the `config/` folder isn't writable, Pillbox shows what to paste into config.php instead.

6. **Set up the scheduled tasks** (optional, but needed for emails): run them every 5 to 15 minutes, either
   with cron, `*/10 * * * * php /var/www/pillbox/bin/cron.php`, or, if your host has no cron, set a long
   random `token` under `'cron'` in `config/config.php` and have a web cron service call
   `https://your-site/cron.php?token=...`. The Admin page shows when they last ran and what they did.

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
- On a new install, the administrator can be a Microsoft account: open the site, enter the setup code, then sign in with Microsoft.

**Multi-tenant:** set `tenant_id` to `organizations` and list the permitted tenant GUIDs in `allowed_tenants`. Only list tenants you control or trust. Pre-registered accounts are only linked by email for people from your `tenant_id` or a tenant listed by GUID; never through `'*'` (any tenant), since another tenant could claim someone else's email address.
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

## Inclusive care and privacy

Everything clinical follows a person's body and medicines, never a gender field:

- **Profile**: pronouns (shown on reports) and an optional gender identity in your own words.
- **Body inventory** (Account → Health profile, optional): cervix, uterus, ovaries, breasts or chest tissue, prostate
  and testes, each "have", "removed (surgery)" with a date, or "don't have". When it's filled in, screening follows
  it (cervical: a cervix; breast: breast or chest tissue; AAA: a prostate or testes, now or before surgery; bowel:
  everyone), and Pregnancy and Pregnancy options only appear with a uterus. NHS invitations follow the sex on the GP
  record, so the Screening page points out any that may not arrive automatically, and suggests asking the GP practice.
  "Women's health" is now **Periods and contraception**, and can be switched off.
- **Hormones** are ordinary medicines with one-tap set-ups (Menopause and Gender-affirming care): testosterone gel,
  short- and long-acting injections, oestradiol patches, gel, spray, tablets and injections, progesterone, blockers and
  anti-androgens, with any dose (including micro-dosing). Doses can repeat **every N days or weeks** (e.g. every 3 or
  12 weeks), and injections get a **site rotation**: Take suggests the next site, and the medicine shows when each was
  last used.
- **Blood tests → Results and trends**: results in UK units with trend charts, the lab's range from the report, and a
  target range set by you or your clinic (lab ranges follow the sex on your record, which often doesn't fit on
  hormones). Each result can note its timing (e.g. trough, just before an injection) and works out the time since your
  last dose.
- **Gender-affirming care** (a private page, like Pregnancy options): GIC waiting-list tracker, prescriber, shared care
  agreement with a renewal reminder, fertility preservation, a changes journal with your own fields, and progress
  photos, all encrypted (photos as encrypted files in `storage/private/`), with its own PIN, never shared, and
  deleted in one go.
- **Privacy**: tick **Private** on a medicine so reminders and emails just say "Reminder" and it's hidden from anyone
  you share Pillbox with; **hide the screen** when switching apps; and a **disguised name and icon** ("Planner" or
  "Notes") for the home screen and browser tab.

## ADHD, autism and accessibility

- **ADHD and autism** (Care, no diagnosis needed): self-identified, waiting or diagnosed per condition; waiting lists
  for assessments (NHS, Right to Choose, private) with time waited; assessment prep notes by topic (printable); a
  **communication passport** shown full screen or printed, added to appointment sheets, and as text to copy into an
  ICE / medical ID; a Reasonable Adjustment Flag reminder; optional energy (spoons), sensory load, meltdowns or
  shutdowns, sleep and burnout signs, with a gentle note when a week looks hard and a cycle link; and checks for
  blood pressure and pulse, weight, the yearly medication review and shared care.
- **Controlled drugs** (any medicine): prescriptions are often 30 days and expire 28 days after writing, so Meds shows
  when to ask for the next one and a "collect by" date, with notifications, and a pharmacy and shortage log.
- **How it's working** (any medicine): when each dose was taken, kicked in and wore off (and any crash), a rating and
  side effects; a timeline, each dose compared (titration), and, with cycle tracking, how it works before a period.
- **For everyone** (Account → Display and reminders): larger text, more spacing, an easy-read font, no animations,
  hidden streaks, and an optional "did you take it?" follow-up notification. Doses can be logged with the time they
  were taken ("At…"), Today has a **Now / Next** view with a timeline of the day, there's a visual **timer**, running-low
  notices show the steps (order, collect, log stock), and unlogged doses say "Not logged" rather than "Missed".

## Terms and privacy

**Terms** and **Privacy** pages are linked in every page's footer, and people agree to them when they sign up (the
date is saved with the account). The text is generated from what Pillbox actually does: what it stores, encryption,
sharing, the optional services you've switched on (email, push, connected apps, dm+d, single sign-on), its one
cookie, and people's rights under UK GDPR. Fill in who runs it, a contact and the governing law under **Admin → Site
settings → Terms and privacy** (or `'legal'` in `config/config.php`), or replace either text with your own. It's a
starting point, not legal advice.

## Letting people sign up

By default only admins add people. To let people create their own accounts, turn on **Admin → Site settings →
Sign-ups** (or set it in `config/config.php`):

```php
'signup' => [
    'enabled'          => true,   // "Create an account" on the sign-in page
    'require_approval' => true,   // new accounts wait for an admin to approve them
    'notify_admins'    => true,   // email admins about each new sign-up
],
```

- **How people sign up:** the sign-in page gets a **Create an account** link. The sign-up page has a button for
  Microsoft and each OpenID Connect provider that's switched on ("Sign up with Google", ...), plus a username
  and password form if password sign-in is allowed. Signing in with a provider for the first time while
  sign-ups are open also creates an account.
- **Approval:** with `require_approval`, a new account can't be used until an admin presses **Approve** under
  **Waiting for approval** on the Admin page (admins also see a notice on Today). **Decline** removes the
  account. People who gave an email address are emailed when they're approved.
- **Admin emails:** with `notify_admins` and the `mail` settings filled in, every active admin with an email
  address is told about each sign-up, with a link to the Admin page.
- New accounts go through the first-login setup. Sign-ups from one address are limited to 5 an hour.
- Providers with `'auto_provision' => true` (a trusted Microsoft tenant or company directory) still create
  accounts on first sign-in whatever these settings say, without approval. With open sign-ups and a public
  provider such as Google, anyone with an account there can sign up, so keep `require_approval` on or
  set `allowed_email_domains`.

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

## Email and scheduled tasks

Fill in the `'mail'` settings in `config/config.php`: `'transport' => 'mail'` uses PHP's `mail()` (most shared
hosting), or `'smtp'` with a host, port, `'secure' => 'tls'` (587) or `'ssl'` (465), username and password.
Each person chooses under **Account → Email**:

- **Daily summary** at a chosen hour: today's doses, anything not logged yesterday, medicines running low or
  expiring, Care items coming up, and appointments today and tomorrow. Nothing from Mind is ever included.
- **Appointment reminders**, at each appointment's reminder time (or the day before), with the questions
  you want to ask.

Admins are emailed once when a new version is released. The scheduled tasks also refresh the update check
and tidy up (old sign-in attempts, leftover upload files, stale sessions, finished demo accounts). They
check for updates but never install them. **Send me a test email** on the Account page checks the settings.

## Sharing with a carer or partner

Under **Sharing** (the people icon), you can give someone with an account on the same Pillbox access to parts
of yours. For each part (medicines and doses, readings, appointments, Care, Mind, reports) you choose
**no access**, **see**, or a level of change: for medicines, **log doses** (take, skip, collected
prescriptions) or **change medicines**. You can keep chosen Care categories private (e.g. Women's health),
include or leave out the Mind journal, and set an end date. Quick choices: "See everything except Mind",
"Help with medicines", "Full help".

They open it from their own Sharing page, with a banner showing whose Pillbox it is and **Back to mine**;
only the tabs and parts they've been given appear. Pages are checked centrally, and anything not shared is
refused. They can never see or change your account settings, sign-in details, sync and calendar links,
data export or Mind PIN, can't permanently delete medicines, and can't share your Pillbox further. Every
change they make is listed under their name (**Recent activity**), and you can stop sharing at any time.

## Demo mode

Set `'demo' => ['enabled' => true]` in `config/config.php` to add **Try the demo** to the sign-in page.
Each visitor gets their own copy of a sample account for Nell Whitcombe, a made-up person (the same one as
the ICE demo), with a few weeks of doses, readings, appointments, Care and Mind data. Everything they change
is deleted when they end the demo or sign out, or after `'hours'` if they just leave. Sign-in details,
email, sharing, Apple Health sync, calendar links and passkeys are switched off in the demo, and
demo accounts don't appear in the admin's user list.

## Connecting apps and devices

**Readings → Connect apps and devices** brings readings in from other places, labelled with where they came from
and never duplicated:

- **Apple Health** (export file, or automatic sync from an iPhone app), see above.
- **Android Health Connect** (Samsung Health, Google Fit, Fitbit and most Android apps): Health Connect only
  works on the phone, so an Android app that can send its data to a web address posts it to your private sync
  link, in the same format as the iPhone sync.
- **WHOOP, Fitbit, Withings and Oura**: direct connections. An admin registers a free app with each service
  (WHOOP: developer.whoop.com, Fitbit: dev.fitbit.com, Withings: developer.withings.com, Oura:
  cloud.ouraring.com), using the return address shown on the Admin page (`.../connect.php`), and enters its
  client ID and secret under **Admin → Site settings → Connected services**. Each person then presses
  **Connect**. Readings sync about once an hour via the scheduled tasks; sign-in tokens are stored encrypted
  (key in `storage/app.key`). They bring in resting heart rate, heart rate variability, oxygen level, sleep,
  breathing rate, steps, weight, and (Withings) blood pressure and temperature, depending on the service.
- **MyFitnessPal** has no public API: import its **Export data** file (weight, and calories eaten per day), or
  let it write to Apple Health or Health Connect.
- **Google Fit** is being retired: import **Daily activity metrics.csv** (or the whole zip) from Google Takeout.
- **Garmin** only shares data with approved businesses, so connect it through Apple Health or Health Connect.

## Push notifications

Each person can turn on notifications in each browser or device they use (**Account → Notifications**):
dose reminders at dose time (if not logged), Care reminders and a daily "coming up", appointment reminders,
and the Mind check-in. **Hide details on the lock screen** shows just "Pillbox: you have a reminder". They use
the standard Web Push protocol (VAPID keys are created automatically in `storage/`; messages are encrypted
for the device), so no third-party service or account is needed. They're sent by the scheduled tasks, so run
those every 5 minutes. On iPhone and iPad, notifications work once Pillbox has been added to the Home Screen
(iOS 16.4 or later).

## Updating

Admins can update from the Admin page: **Install version X now** downloads the release from GitHub, checks
it, saves a backup of the current files in `storage/backups/`, then replaces them. `config/config.php` and
`storage/` are never touched, and the database updates itself on the next page load. Any backup can be put
back with **Restore**. It needs PHP's zip and curl extensions and write access to the Pillbox folder; copies
that are git checkouts should use `git pull`. Uploading the files by hand works too.

## Tidy addresses

With Apache's `mod_rewrite`, pages have addresses without ".php" (e.g. `/medications`); `public/.htaccess`
turns this on and tells Pillbox, which then makes its links that way and redirects old ".php" addresses.
Calendar feed and Apple Health sync addresses keep working as they are. Without `mod_rewrite` (or with
`'pretty_urls' => false`), everything uses ".php" as before.

## Moving your data

**Account → Your data → Download all my data** gives one file with everything (readable sections, plus a
complete backup). **Import data** restores that backup into an account, replacing what's there: useful for
moving to another Pillbox. Sign-in details, sharing, passkeys and sync links aren't included.

## Security notes

- Passwords are hashed with PHP's `password_hash`. Local logins are rate-limited per username and per IP.
- All forms have CSRF protection. Sessions use HttpOnly and SameSite cookies, which are Secure over HTTPS.
- A strict Content-Security-Policy is sent: no inline scripts or styles, and no third-party assets.
- Entra ID tokens are obtained with the authorisation-code flow plus PKCE. The ID token's signature,
  audience, issuer, expiry, nonce and tenant are all checked.
- Every query is scoped to the signed-in user, so people can only see their own data, or data that has
  been shared with them (see Sharing above).
- Mind can have its own PIN, plus a passkey (WebAuthn, platform authenticators only: the device asks for
  a fingerprint, face or its own PIN;
  needs HTTPS). The device signs a one-time challenge; the origin, site, user verification and signature
  are all checked, and the PIN still works. Crisis contacts are never locked.
- Health data is sensitive. Use HTTPS, keep the server patched, back up the database, and check what
  your local data-protection rules require.

## Project layout

```
public/      web root: pages, assets (CSS, JS, logo)
src/         application code (auth, Entra OIDC, scheduling, metrics, charts, layout, care, mind, mail, tasks, demo)
bin/         cron.php: the scheduled tasks, for the command line
config/      config.example.php (template) -> copy to config.php (your settings)
database/    schema.sql
storage/     cache (must be writable)
```

## License

Pillbox is free software, released under the [GNU General Public License v3.0](LICENSE). You can use, study,
change and share it. If you share it (changed or not), you must share the source code under the same licence.

Copyright (C) 2026 Michelle Findlay

*Pillbox is a record-keeping aid. It is not medical advice; always follow your prescriber's instructions.*
