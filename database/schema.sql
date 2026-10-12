-- Pillbox schema (MySQL 8.0+ / MariaDB 10.5+)
-- All DATETIME columns are stored in UTC; the app converts to each user's timezone.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username         VARCHAR(100)  NULL,
    email            VARCHAR(255)  NULL,
    display_name     VARCHAR(150)  NOT NULL,
    password_hash    VARCHAR(255)  NULL,
    auth_provider    ENUM('local','entra','oidc') NOT NULL DEFAULT 'local',
    entra_oid        CHAR(36)      NULL,
    entra_tid        CHAR(36)      NULL,
    is_admin         TINYINT(1)    NOT NULL DEFAULT 0,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    pending_approval TINYINT(1)    NOT NULL DEFAULT 0,
    terms_accepted_at DATETIME     NULL,     -- agreed to the terms and privacy policy when signing up  -- a sign-up waiting for an admin to approve it
    signup_method    VARCHAR(60)   NULL,     -- how they signed up: password | entra | oidc:<provider>; NULL = added by an admin
    timezone         VARCHAR(64)   NOT NULL DEFAULT 'Europe/London',
    enabled_metrics  VARCHAR(1000) NULL,
    session_version  INT UNSIGNED  NOT NULL DEFAULT 0,  -- bumped to sign out every other device
    theme            VARCHAR(20)   NOT NULL DEFAULT 'olive',
    color_mode       VARCHAR(10)   NOT NULL DEFAULT 'auto',  -- auto | light | dark
    calendar_token   CHAR(40)      NULL,     -- secret for the private calendar feed URL
    calendar_include_notes TINYINT(1) NOT NULL DEFAULT 0,
    health_sync_token CHAR(40)    NULL,     -- secret for the Apple Health sync URL
    care_prompt_hidden TINYINT(1) NOT NULL DEFAULT 0,  -- "Not now" on the Today page's care routines invite
    care_areas       VARCHAR(255)  NULL,     -- Care categories switched on (comma list); NULL = the defaults
    date_of_birth    DATE          NULL,     -- for screening recall dates
    sex              VARCHAR(10)   NULL,     -- female | male | other: which screening programmes apply
    screening_programmes VARCHAR(100) NULL,  -- with sex 'other': the programmes ticked (comma list)
    body_inventory   TEXT          NULL,     -- JSON: organ => {state: yes|removed|no, date}; NULL = not filled in (then sex is used)
    pronouns         VARCHAR(40)   NULL,
    gender_identity  VARCHAR(100)  NULL,
    privacy_blur     TINYINT(1)    NOT NULL DEFAULT 0,  -- hide the screen when switching apps
    text_size        VARCHAR(4)    NULL,     -- accessibility: NULL (normal) | l | xl
    wide_spacing     TINYINT(1)    NOT NULL DEFAULT 0,
    easy_font        TINYINT(1)    NOT NULL DEFAULT 0,  -- an easy-read font (dyslexia-friendly)
    reduce_motion    TINYINT(1)    NOT NULL DEFAULT 0,
    hide_streaks     TINYINT(1)    NOT NULL DEFAULT 0,
    dose_followup    SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- minutes after a dose reminder to ask "did you take it?" (0 = off)
    pmdd_mode        TINYINT(1)    NOT NULL DEFAULT 0,  -- daily premenstrual symptom tracking in Periods and contraception
    pmdd_headsup     TINYINT(1)    NOT NULL DEFAULT 0,  -- notification when the luteal phase is predicted to start
    disguise         VARCHAR(10)   NULL,     -- home screen name and icon: NULL (Pillbox) | planner | notes
    mind_enabled     TINYINT(1)    NOT NULL DEFAULT 1,  -- show the Mind tab
    mind_pin_hash    VARCHAR(255)  NULL,     -- optional PIN for the Mind tab
    mind_lock_plan   TINYINT(1)    NOT NULL DEFAULT 0,  -- also keep the safety plan behind the PIN
    mind_reminder    TIME          NULL,     -- daily check-in reminder in the calendar feed (neutral wording)
    mind_selfcare    VARCHAR(500)  NULL,     -- self-care checklist items (newline list); NULL = the defaults
    meds_show_changes TINYINT(1)   NOT NULL DEFAULT 0,  -- Meds page: show the latest dose change under each medicine
    email_digest     TINYINT(1)    NOT NULL DEFAULT 0,  -- daily summary email
    email_digest_hour TINYINT UNSIGNED NOT NULL DEFAULT 7,  -- local hour to send it
    email_appointments TINYINT(1)  NOT NULL DEFAULT 0,  -- appointment reminder emails
    last_digest_on   DATE          NULL,
    is_demo          TINYINT(1)    NOT NULL DEFAULT 0,  -- a throwaway demo account (deleted on sign-out)
    push_doses       TINYINT(1)    NOT NULL DEFAULT 1,  -- push notifications: dose reminders
    push_care        TINYINT(1)    NOT NULL DEFAULT 1,  -- ... Care reminders and "coming up"
    push_appointments TINYINT(1)   NOT NULL DEFAULT 1,  -- ... appointment reminders
    push_private     TINYINT(1)    NOT NULL DEFAULT 0,  -- ... just "You have a reminder" on the lock screen
    onboarded_at     DATETIME      NULL,     -- finished (or skipped) the first-login setup
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at    DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_entra (entra_tid, entra_oid),
    UNIQUE KEY uq_users_calendar (calendar_token),
    UNIQUE KEY uq_users_health_sync (health_sync_token),
    KEY ix_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-ins through generic OpenID Connect providers (Google, Okta, Keycloak, ...).
CREATE TABLE IF NOT EXISTS user_identities (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    provider      VARCHAR(30)  NOT NULL,          -- key under 'oidc' => 'providers' in config.php
    subject       VARCHAR(255) NULL,              -- the provider's user id, or NULL if pre-registered by email
    email         VARCHAR(255) NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_identity (provider, subject),
    KEY ix_identity_user (user_id),
    KEY ix_identity_email (provider, email),
    CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip            VARCHAR(45)  NOT NULL,
    username      VARCHAR(100) NOT NULL,
    attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_attempts_user (username, attempted_at),
    KEY ix_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS medications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(150)  NOT NULL,
    dmd_code        VARCHAR(20)   NULL,          -- NHS dm+d / SNOMED CT product code, if picked from the dictionary
    strength        VARCHAR(60)   NULL,
    form            VARCHAR(30)   NOT NULL DEFAULT 'tablet',
    dose_unit       VARCHAR(30)   NOT NULL DEFAULT 'tablet',
    colour          VARCHAR(20)   NOT NULL DEFAULT 'blue',
    reason          VARCHAR(150)  NULL,
    instructions    VARCHAR(255)  NULL,
    prescriber      VARCHAR(150)  NULL,
    notes           TEXT          NULL,
    as_needed       TINYINT(1)    NOT NULL DEFAULT 0,
    prn_dose_amount DECIMAL(8,2)  NULL,
    prn_max_daily   DECIMAL(8,2)  NULL,
    min_interval_minutes SMALLINT UNSIGNED NULL,   -- the shortest gap allowed between doses (e.g. 240 for 4 hours)
    stock_count     DECIMAL(10,2) NULL,
    low_stock_days  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    expiry_date     DATE          NULL,          -- use-by date of the current supply, if known
    course          VARCHAR(8)    NOT NULL DEFAULT 'ongoing', -- ongoing | short (a set number of days) | once (a one-off dose); short and once stop by themselves
    ordered_on      DATE          NULL,          -- repeat ordered and waiting to be collected (cleared when collected)
    supply_type     VARCHAR(8)    NOT NULL DEFAULT 'nhs', -- nhs | private (private prescription) | otc (bought without one)
    supply_price    DECIMAL(8,2)  NULL,          -- what a supply costs, for private and bought medicines
    brand           VARCHAR(100)  NULL,          -- brand or manufacturer of the current supply
    controlled      TINYINT(1)    NOT NULL DEFAULT 0, -- a controlled drug (e.g. stimulants): prescriptions expire 28 days after writing
    rx_written      DATE          NULL,          -- the latest prescription's date, until it's collected
    rx_days         SMALLINT UNSIGNED NULL,      -- days of supply per prescription (often 30 for controlled drugs)
    is_private      TINYINT(1)    NOT NULL DEFAULT 0, -- reminders and emails just say "Reminder"; hidden from people it's shared with
    is_supplement   TINYINT(1)    NOT NULL DEFAULT 0, -- a vitamin, mineral or other supplement (listed separately)
    mental_health   TINYINT(1)    NOT NULL DEFAULT 0, -- shown in Mind, with side-effect and "how I feel" notes
    start_date      DATE          NOT NULL,
    end_date        DATE          NULL,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_meds_user (user_id, is_active),
    CONSTRAINT fk_meds_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per dose time. Different amounts at different times, on different
-- weekdays (days_mask: bit0=Mon .. bit6=Sun, 127 = every day) and over different
-- date ranges (tapering courses) are all expressed as separate rows.
CREATE TABLE IF NOT EXISTS medication_schedules (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medication_id  INT UNSIGNED NOT NULL,
    dose_time      TIME          NOT NULL,
    dose_amount    DECIMAL(8,2)  NOT NULL,
    days_mask      TINYINT UNSIGNED NOT NULL DEFAULT 127,
    every_days     SMALLINT UNSIGNED NULL,   -- set: every N days from anchor_date instead of on weekdays (e.g. injections every 3 weeks)
    anchor_date    DATE          NULL,      -- the first dose of an every-N-days schedule
    cycle_phase    VARCHAR(10)   NULL,      -- 'luteal': only in the 2 weeks before a period (e.g. SSRIs for PMDD);
                                            -- 'cycle': days cycle_from to cycle_to of a repeating cycle_length-day cycle, day 1 = anchor_date;
                                            -- 'month': the cycle_from to cycle_to of each calendar month (e.g. sequential HRT)
    cycle_length   SMALLINT UNSIGNED NULL,
    cycle_from     SMALLINT UNSIGNED NULL,
    cycle_to       SMALLINT UNSIGNED NULL,
    valid_from     DATE          NULL,
    valid_to       DATE          NULL,
    PRIMARY KEY (id),
    KEY ix_sched_med (medication_id),
    CONSTRAINT fk_sched_med FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dose_logs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    medication_id  INT UNSIGNED NOT NULL,
    schedule_id    INT UNSIGNED NULL,          -- NULL for as-needed (PRN) doses
    scheduled_for  DATETIME      NULL,          -- UTC time of the scheduled slot
    status         ENUM('taken','skipped') NOT NULL,
    amount         DECIMAL(8,2)  NOT NULL DEFAULT 0,
    site           VARCHAR(10)   NULL,     -- injection site (for rotation)
    taken_at       DATETIME      NOT NULL,      -- UTC time the action was recorded for
    notes          VARCHAR(255)  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_dose_slot (schedule_id, scheduled_for),
    KEY ix_dose_user_time (user_id, taken_at),
    KEY ix_dose_med (medication_id),
    CONSTRAINT fk_dose_user  FOREIGN KEY (user_id)       REFERENCES users (id)                ON DELETE CASCADE,
    CONSTRAINT fk_dose_med   FOREIGN KEY (medication_id) REFERENCES medications (id)          ON DELETE CASCADE,
    CONSTRAINT fk_dose_sched FOREIGN KEY (schedule_id)   REFERENCES medication_schedules (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Medical appointments (GP, specialist, dentist, ...).
CREATE TABLE IF NOT EXISTS appointments (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id           INT UNSIGNED NOT NULL,
    kind              VARCHAR(20)   NOT NULL DEFAULT 'gp',
    title             VARCHAR(150)  NULL,
    starts_at         DATETIME      NOT NULL,      -- UTC
    duration_minutes  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    with_whom         VARCHAR(150)  NULL,
    location          VARCHAR(255)  NULL,
    notes             TEXT          NULL,          -- before: things to bring, fasting, ...
    outcome_notes     TEXT          NULL,          -- after: what was decided, next steps
    remind_minutes    INT UNSIGNED  NULL,          -- reminder before the appointment, for calendar apps
    reminder_emailed_at DATETIME    NULL,
    created_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_appt_user_time (user_id, starts_at),
    CONSTRAINT fk_appt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Questions to ask at an appointment, ticked off (and answered) on the day.
CREATE TABLE IF NOT EXISTS appointment_questions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id  INT UNSIGNED NOT NULL,
    question        VARCHAR(500)  NOT NULL,
    answer          TEXT          NULL,
    done            TINYINT(1)    NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_question_appt (appointment_id),
    CONSTRAINT fk_question_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dose change history: started, stopped, strength / amount / timing changes, and notes.
CREATE TABLE IF NOT EXISTS medication_changes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    medication_id   INT UNSIGNED NOT NULL,
    effective_date  DATE          NOT NULL,
    kind            VARCHAR(20)   NOT NULL,     -- started | increased | decreased | strength | timing | as_needed | stopped | restarted | note
    summary         VARCHAR(500)  NOT NULL,
    reason          VARCHAR(255)  NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_change_med (medication_id, effective_date),
    KEY ix_change_user (user_id, effective_date),
    CONSTRAINT fk_change_user FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
    CONSTRAINT fk_change_med  FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Travel mode: how dose times follow you across time zones between two dates.
CREATE TABLE IF NOT EXISTS travel_plans (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(100)  NULL,
    destination_tz  VARCHAR(64)   NOT NULL,
    start_date      DATE          NOT NULL,     -- first day away (home calendar)
    end_date        DATE          NOT NULL,     -- day you get back; home time resumes the day after
    mode            VARCHAR(10)   NOT NULL DEFAULT 'local',  -- home | local | gradual
    shift_hours     TINYINT UNSIGNED NOT NULL DEFAULT 2,     -- for gradual: hours to move per day
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_travel_user (user_id, start_date),
    CONSTRAINT fk_travel_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prescription collections and stock recounts.
CREATE TABLE IF NOT EXISTS stock_events (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    medication_id  INT UNSIGNED NOT NULL,
    kind           ENUM('collected','recount') NOT NULL,
    quantity       DECIMAL(10,2) NOT NULL,      -- amount collected, or the new counted total
    stock_after    DECIMAL(10,2) NOT NULL,
    brand          VARCHAR(100)  NULL,         -- brand or manufacturer collected (some people notice a difference)
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_stock_med (medication_id, created_at),
    CONSTRAINT fk_stock_user FOREIGN KEY (user_id)       REFERENCES users (id)       ON DELETE CASCADE,
    CONSTRAINT fk_stock_med  FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Measurements a user creates themselves (e.g. something a doctor asked them to track).
CREATE TABLE IF NOT EXISTS custom_metrics (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL,
    label     VARCHAR(80)  NOT NULL,
    unit      VARCHAR(30)  NULL,
    PRIMARY KEY (id),
    KEY ix_custom_user (user_id),
    CONSTRAINT fk_custom_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS health_readings (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    metric       VARCHAR(40)   NOT NULL,        -- built-in key, or 'c<id>' for a custom metric
    value1       DECIMAL(10,2) NULL,
    value2       DECIMAL(10,2) NULL,
    value3       DECIMAL(10,2) NULL,
    context      VARCHAR(60)   NULL,
    notes        VARCHAR(500)  NULL,
    recorded_at  DATETIME      NOT NULL,
    source       VARCHAR(20)   NULL,          -- NULL = entered in Pillbox, 'apple_health' = imported
    external_id  CHAR(40)      NULL,          -- identifies an imported sample, so re-imports don't duplicate it
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_readings_external (user_id, external_id),
    KEY ix_readings_user_metric (user_id, metric, recorded_at),
    CONSTRAINT fk_readings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Care routines (dental care, eye care, ...): tasks ticked off on the Today page.
CREATE TABLE IF NOT EXISTS care_tasks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    area        VARCHAR(20)   NOT NULL,                   -- key of CARE_AREAS: dental | eyes | sleep | activity | ...
    kind        VARCHAR(20)   NOT NULL DEFAULT 'other',   -- preset key within the area, or 'other'
    label       VARCHAR(100)  NOT NULL,
    times       VARCHAR(60)   NOT NULL,                   -- local times, comma separated: '08:00,21:00'
    days_mask   TINYINT UNSIGNED NOT NULL DEFAULT 127,    -- bit0=Mon .. bit6=Sun; used when every_days is NULL
    every_days  SMALLINT UNSIGNED NULL,                   -- or: every N days, counting from starts_on
    starts_on   DATE          NOT NULL,                   -- the user's local date the task starts
    amount      VARCHAR(60)   NULL,                       -- e.g. '3 sets of 10', '10 minutes'
    tip         VARCHAR(255)  NULL,
    remind      TINYINT(1)    NOT NULL DEFAULT 0,         -- include in the calendar feed, with an alarm
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_care_user (user_id, area),
    CONSTRAINT fk_care_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS care_logs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    task_id     INT UNSIGNED NOT NULL,
    log_date    DATE          NOT NULL,                   -- the user's local date
    slot        TINYINT UNSIGNED NOT NULL,                -- which of the task's times (0 = first)
    done_at     DATETIME      NOT NULL,                   -- UTC
    PRIMARY KEY (id),
    UNIQUE KEY uq_care_slot (task_id, log_date, slot),
    KEY ix_care_log_user (user_id, log_date),
    CONSTRAINT fk_care_log_user FOREIGN KEY (user_id) REFERENCES users (id)      ON DELETE CASCADE,
    CONSTRAINT fk_care_log_task FOREIGN KEY (task_id) REFERENCES care_tasks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checks and renewals that come round every few months or years (eye tests, vaccinations,
-- screening, contraception renewals, ...), with the date last done and the next due date.
CREATE TABLE IF NOT EXISTS health_checks (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    area           VARCHAR(20)   NOT NULL,                -- key of CARE_AREAS
    kind           VARCHAR(20)   NOT NULL DEFAULT 'other', -- preset key, or 'other'
    label          VARCHAR(100)  NOT NULL,
    interval_days  SMALLINT UNSIGNED NULL,                -- repeat after this many days; NULL = one-off or a rule
    rule           VARCHAR(10)   NULL,                    -- 'autumn' (due each 1 September) or 'screening'
    track          VARCHAR(8)    NOT NULL DEFAULT 'due',  -- due | expiry | stock | level | kit (see CHECK_TRACKS)
    last_done      DATE          NULL,
    due_date       DATE          NULL,                    -- set by hand: overrides the worked-out date until marked done; for 'expiry', the expiry date
    quantity       DECIMAL(8,1)  NULL,                    -- stock: how many at the last count; level: % full
    unit           VARCHAR(20)   NULL,                    -- stock: 'strips', 'dressings', ...
    low_at         DECIMAL(8,1)  NULL,                    -- stock: restock when down to this many
    per_week       DECIMAL(8,2)  NULL,                    -- stock: roughly how many are used a week
    counted_on     DATE          NULL,                    -- stock / level: when quantity was last set
    items          TEXT          NULL,                    -- kit: contents, one per line, "Name | YYYY-MM-DD" with an optional expiry
    medication_id  INT UNSIGNED  NULL,                    -- e.g. a blood test needed for this medicine
    tip            VARCHAR(255)  NULL,
    notes          VARCHAR(500)  NULL,
    remind         TINYINT(1)    NOT NULL DEFAULT 1,      -- show in the calendar feed on the due date
    hidden         TINYINT(1)    NOT NULL DEFAULT 0,      -- a screening programme marked "doesn't apply to me"
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_checks_user (user_id, area),
    CONSTRAINT fk_checks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Period log for cycle tracking (Women's health).
CREATE TABLE IF NOT EXISTS cycle_periods (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    start_date  DATE          NOT NULL,
    end_date    DATE          NULL,
    flow        VARCHAR(10)   NULL,                      -- light | medium | heavy
    notes       VARCHAR(255)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cycle_start (user_id, start_date),
    CONSTRAINT fk_cycle_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: check-ins (mood, energy, motivation, anxiety), with tags, triggers and a note.
CREATE TABLE IF NOT EXISTS mind_checkins (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    logged_at    DATETIME      NOT NULL,                  -- UTC
    log_date     DATE          NOT NULL,                  -- the user's local date
    mood         TINYINT UNSIGNED NOT NULL,               -- 1 (very low) to 5 (very good)
    energy       TINYINT UNSIGNED NULL,                   -- 1 to 5
    motivation   TINYINT UNSIGNED NULL,                   -- 1 to 5
    anxiety      TINYINT UNSIGNED NULL,                   -- 0 (none) to 10 (worst)
    tags         VARCHAR(255)  NULL,                      -- comma list
    triggers     VARCHAR(500)  NULL,
    note         TEXT          NULL,
    PRIMARY KEY (id),
    KEY ix_checkin_user (user_id, log_date),
    CONSTRAINT fk_checkin_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: the user's own list of things that help.
CREATE TABLE IF NOT EXISTS mind_toolkit (
    id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id  INT UNSIGNED NOT NULL,
    item     VARCHAR(200)  NOT NULL,
    PRIMARY KEY (id),
    KEY ix_toolkit_user (user_id),
    CONSTRAINT fk_toolkit_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: safety plan, one row per section (see SAFETY_PLAN_SECTIONS).
CREATE TABLE IF NOT EXISTS mind_plan (
    user_id     INT UNSIGNED NOT NULL,
    section     VARCHAR(20)   NOT NULL,
    content     TEXT          NOT NULL,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, section),
    CONSTRAINT fk_plan_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: crisis lines and the user's own people and professionals to contact.
CREATE TABLE IF NOT EXISTS mind_contacts (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL,
    kind      VARCHAR(12)   NOT NULL,                    -- crisis | person | professional
    name      VARCHAR(100)  NOT NULL,
    detail    VARCHAR(255)  NULL,                        -- what they're for, when to use it
    phone     VARCHAR(40)   NULL,
    text_to   VARCHAR(40)   NULL,                        -- number to text, e.g. 85258
    text_body VARCHAR(40)   NULL,                        -- what to text, e.g. SHOUT
    helps_with VARCHAR(60)  NULL,                        -- comma list: talk | practical | distraction | company
    reach_out_days SMALLINT UNSIGNED NULL,               -- nudge to get in touch after this many days
    last_contact DATE       NULL,
    sort      SMALLINT      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_contacts_user (user_id, kind),
    CONSTRAINT fk_contacts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: PHQ-9 and GAD-7 questionnaire results.
CREATE TABLE IF NOT EXISTS mind_questionnaires (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL,
    kind      VARCHAR(10)   NOT NULL,                    -- phq9 | gad7
    answers   VARCHAR(40)   NOT NULL,                    -- comma list of 0-3
    score     TINYINT UNSIGNED NOT NULL,
    difficulty TINYINT UNSIGNED NULL,                    -- the optional "how difficult" question, 0-3
    taken_at  DATETIME      NOT NULL,                    -- UTC
    PRIMARY KEY (id),
    KEY ix_questionnaire_user (user_id, kind, taken_at),
    CONSTRAINT fk_questionnaire_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: journal entries and CBT thought records.
CREATE TABLE IF NOT EXISTS mind_journal (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    kind        VARCHAR(10)   NOT NULL,                  -- journal | thought
    prompt      VARCHAR(255)  NULL,
    body        TEXT          NULL,                      -- journal text
    fields      TEXT          NULL,                      -- thought record, as JSON
    created_at  DATETIME      NOT NULL,                  -- UTC
    updated_at  DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY ix_journal_user (user_id, created_at),
    CONSTRAINT fk_journal_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: simple lists. question = to raise at the next appointment; action = agreed in a session
-- (homework); win = gratitude and small wins.
CREATE TABLE IF NOT EXISTS mind_notes (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    kind            VARCHAR(10)   NOT NULL,
    text            VARCHAR(500)  NOT NULL,
    appointment_id  INT UNSIGNED NULL,
    due_date        DATE          NULL,
    done            TINYINT(1)    NOT NULL DEFAULT 0,
    created_at      DATETIME      NOT NULL,             -- UTC
    PRIMARY KEY (id),
    KEY ix_mind_notes_user (user_id, kind, done),
    CONSTRAINT fk_mind_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_mind_notes_appt FOREIGN KEY (appointment_id) REFERENCES appointments (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Side effects, and "how I've felt since starting / changing the dose", for any medicine.
CREATE TABLE IF NOT EXISTS med_notes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    medication_id  INT UNSIGNED NOT NULL,
    kind           VARCHAR(12)   NOT NULL,              -- side_effect | feeling | supply (pharmacy and shortage notes)
    note           VARCHAR(1000) NOT NULL,
    severity       TINYINT UNSIGNED NULL,               -- side effects: 0-10
    created_at     DATETIME      NOT NULL,              -- UTC
    PRIMARY KEY (id),
    KEY ix_med_notes (medication_id, created_at),
    CONSTRAINT fk_med_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_med_notes_med FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: the user's own symptoms, rated in check-ins.
CREATE TABLE IF NOT EXISTS mind_symptoms (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id   INT UNSIGNED NOT NULL,
    name      VARCHAR(80)   NOT NULL,
    active    TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY ix_symptoms_user (user_id),
    CONSTRAINT fk_symptoms_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mind_symptom_logs (
    checkin_id  INT UNSIGNED NOT NULL,
    symptom_id  INT UNSIGNED NOT NULL,
    severity    TINYINT UNSIGNED NOT NULL,              -- 0 (none) to 10 (worst)
    PRIMARY KEY (checkin_id, symptom_id),
    CONSTRAINT fk_symlog_checkin FOREIGN KEY (checkin_id) REFERENCES mind_checkins (id) ON DELETE CASCADE,
    CONSTRAINT fk_symlog_symptom FOREIGN KEY (symptom_id) REFERENCES mind_symptoms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: daily self-care basics ticked off (eaten, water, outside, ...).
CREATE TABLE IF NOT EXISTS mind_selfcare (
    user_id   INT UNSIGNED NOT NULL,
    log_date  DATE          NOT NULL,
    item      VARCHAR(60)   NOT NULL,
    PRIMARY KEY (user_id, log_date, item),
    CONSTRAINT fk_selfcare_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mind: activity scheduling (behavioural activation): plan it, then rate it.
CREATE TABLE IF NOT EXISTS mind_activities (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    planned_for   DATE          NOT NULL,               -- local date
    planned_time  TIME          NULL,
    activity      VARCHAR(200)  NOT NULL,
    status        VARCHAR(8)    NOT NULL DEFAULT 'planned', -- planned | done | skipped
    enjoyment     TINYINT UNSIGNED NULL,                -- 0-10
    achievement   TINYINT UNSIGNED NULL,                -- 0-10
    note          VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    KEY ix_activities_user (user_id, planned_for),
    CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sharing: someone (a carer, partner, ...) who can see, or help manage, another user's Pillbox.
CREATE TABLE IF NOT EXISTS data_shares (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id      INT UNSIGNED NOT NULL,
    grantee_id    INT UNSIGNED NOT NULL,
    level         VARCHAR(8)    NOT NULL DEFAULT 'view',   -- before 1.2: view | manage (now in permissions)
    include_mind  TINYINT(1)    NOT NULL DEFAULT 0,        -- before 1.2 (now in permissions)
    permissions   TEXT          NULL,                      -- JSON: section => none|view|log|edit, care_hidden [areas], journal bool
    expires_on    DATE          NULL,                      -- access ends after this date
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_share (owner_id, grantee_id),
    KEY ix_share_grantee (grantee_id),
    CONSTRAINT fk_share_owner   FOREIGN KEY (owner_id)   REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_share_grantee FOREIGN KEY (grantee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fingerprint / face unlock (WebAuthn platform authenticators) for the Mind PIN.
CREATE TABLE IF NOT EXISTS webauthn_credentials (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    credential_id  VARCHAR(512)  NOT NULL,                 -- base64url
    public_key     TEXT          NOT NULL,                 -- PEM
    sign_count     INT UNSIGNED  NOT NULL DEFAULT 0,
    label          VARCHAR(100)  NOT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   DATETIME      NULL,
    PRIMARY KEY (id),
    KEY ix_webauthn_user (user_id),
    CONSTRAINT fk_webauthn_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What someone with shared access changed, for the owner to see.
CREATE TABLE IF NOT EXISTS share_activity (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    share_id    INT UNSIGNED NOT NULL,
    happened_at DATETIME      NOT NULL,                   -- UTC
    page        VARCHAR(40)   NOT NULL,
    action      VARCHAR(40)   NULL,
    PRIMARY KEY (id),
    KEY ix_activity_share (share_id, happened_at),
    CONSTRAINT fk_activity_share FOREIGN KEY (share_id) REFERENCES data_shares (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Results for checks that have them (blood tests, HbA1c...).
CREATE TABLE IF NOT EXISTS check_results (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    check_id    INT UNSIGNED NOT NULL,
    taken_on    DATE          NOT NULL,
    result      VARCHAR(200)  NOT NULL,                   -- e.g. "0.6 mmol/L" or "All normal"
    notes       VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    KEY ix_results_check (check_id, taken_on),
    CONSTRAINT fk_results_check FOREIGN KEY (check_id) REFERENCES health_checks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was paid for prescriptions, for the cost log and the prepayment certificate calculator.
CREATE TABLE IF NOT EXISTS prescription_payments (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    paid_on    DATE          NOT NULL,
    items      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    amount     DECIMAL(8,2)  NOT NULL,
    note       VARCHAR(200)  NULL,
    kind       VARCHAR(8)    NOT NULL DEFAULT 'nhs', -- nhs (NHS charge) | private | otc
    medication_id INT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY ix_payments_user (user_id, paid_on),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Web Push: each device (browser) that has turned notifications on.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    endpoint      VARCHAR(1000) NOT NULL,
    endpoint_hash CHAR(64)      NOT NULL,
    p256dh        VARCHAR(200)  NOT NULL,
    auth          VARCHAR(100)  NOT NULL,
    label         VARCHAR(100)  NOT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME      NULL,
    fail_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_push_endpoint (endpoint_hash),
    KEY ix_push_user (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications already sent, so each reminder goes out once.
CREATE TABLE IF NOT EXISTS push_sent (
    user_id   INT UNSIGNED NOT NULL,
    ref       VARCHAR(100)  NOT NULL,
    sent_at   DATETIME      NOT NULL,
    PRIMARY KEY (user_id, ref),
    KEY ix_push_sent_at (sent_at),
    CONSTRAINT fk_push_sent_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Connected services (WHOOP, Fitbit, Withings, Oura): OAuth tokens (encrypted) and sync state.
CREATE TABLE IF NOT EXISTS connections (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    provider       VARCHAR(20)   NOT NULL,
    access_token   TEXT          NOT NULL,                 -- encrypted with storage/app.key
    refresh_token  TEXT          NULL,                     -- encrypted
    expires_at     DATETIME      NULL,                     -- UTC
    external_user  VARCHAR(100)  NULL,
    last_sync_at   DATETIME      NULL,
    last_error     VARCHAR(500)  NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_connection (user_id, provider),
    CONSTRAINT fk_connection_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pregnancy (Care): one record per pregnancy; the antenatal timeline follows from its due date.
CREATE TABLE IF NOT EXISTS pregnancies (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    due_date        DATE          NOT NULL,
    due_source      VARCHAR(8)    NOT NULL DEFAULT 'lmp',  -- lmp (worked out from the last period) | scan (from the dating scan or midwife)
    lmp_date        DATE          NULL,
    first_baby      TINYINT(1)    NOT NULL DEFAULT 1,      -- first babies get extra checks at 25, 31 and 40 weeks
    rh_negative     TINYINT(1)    NULL,                    -- NULL = not known yet
    maternity_unit  VARCHAR(150)  NULL,
    maternity_phone VARCHAR(40)   NULL,                    -- maternity triage / assessment line
    midwife         VARCHAR(150)  NULL,
    midwife_phone   VARCHAR(40)   NULL,
    status          VARCHAR(8)    NOT NULL DEFAULT 'active', -- active | born | ended
    ended_on        DATE          NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_pregnancies_user (user_id, status),
    CONSTRAINT fk_pregnancies_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline items (appointments, scans, vaccines, forms) marked done, with an optional result.
CREATE TABLE IF NOT EXISTS pregnancy_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pregnancy_id  INT UNSIGNED NOT NULL,
    item_key      VARCHAR(30)   NOT NULL,
    done_on       DATE          NULL,
    result        VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pregnancy_item (pregnancy_id, item_key),
    CONSTRAINT fk_pregnancy_items FOREIGN KEY (pregnancy_id) REFERENCES pregnancies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was checked at each antenatal appointment.
CREATE TABLE IF NOT EXISTS antenatal_checks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pregnancy_id  INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    checked_on    DATE          NOT NULL,
    bp_sys        SMALLINT UNSIGNED NULL,
    bp_dia        SMALLINT UNSIGNED NULL,
    urine         VARCHAR(20)   NULL,     -- clear | protein | glucose | both | not_done
    fundal_cm     DECIMAL(4,1)  NULL,     -- bump measurement
    heartbeat     TINYINT(1)    NULL,     -- baby's heartbeat heard
    position      VARCHAR(12)   NULL,     -- head_down | breech | transverse | unsure
    notes         VARCHAR(2000) NULL,
    questions     VARCHAR(2000) NULL,     -- to ask next time
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_antenatal_pregnancy (pregnancy_id, checked_on),
    CONSTRAINT fk_antenatal_pregnancy FOREIGN KEY (pregnancy_id) REFERENCES pregnancies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Changes in the baby's movements, and other notes.
CREATE TABLE IF NOT EXISTS pregnancy_log (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    pregnancy_id  INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    logged_at     DATETIME      NOT NULL,
    kind          VARCHAR(12)   NOT NULL,  -- movements | note
    note          VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY ix_pregnancy_log (pregnancy_id, logged_at),
    CONSTRAINT fk_pregnancy_log FOREIGN KEY (pregnancy_id) REFERENCES pregnancies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private records (the pregnancy options and aftercare pathway): everything is in one encrypted blob
-- (key in storage/app.key), never shared, reported, exported or emailed, and deleted in one go.
CREATE TABLE IF NOT EXISTS private_records (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    kind        VARCHAR(20)   NOT NULL,
    data        MEDIUMTEXT    NOT NULL,   -- encrypted JSON
    pin_hash    VARCHAR(255)  NULL,       -- optional PIN for this page
    remind_at   DATETIME      NULL,       -- next reminder (UTC); reminders only ever say "You have a reminder"
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_private_record (user_id, kind),
    KEY ix_private_remind (remind_at),
    CONSTRAINT fk_private_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Menopause (Care): stage and work notes, the daily symptom diary, and Greene Climacteric Scale scores.
-- Periods and bleeds are in cycle_periods (shared with Women's health); HRT is in medications.
CREATE TABLE IF NOT EXISTS menopause_profiles (
    user_id      INT UNSIGNED NOT NULL,
    stage        VARCHAR(10)   NULL,     -- unsure | peri | post
    cause        VARCHAR(10)   NULL,     -- natural | surgery | treatment | unsure
    hrt_started  DATE          NULL,     -- started or last changed HRT (for the 3-month review and bleeding advice)
    work_notes   TEXT          NULL,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_meno_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menopause_logs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    log_date       DATE          NOT NULL,
    flushes        TINYINT UNSIGNED NULL,   -- hot flushes that day
    flush_severity TINYINT UNSIGNED NULL,   -- 0 to 3
    scores         VARCHAR(500)  NULL,      -- JSON: symptom => 0 to 3
    triggers       VARCHAR(100)  NULL,      -- comma list: alcohol, caffeine, spicy, hot_drinks, stress
    notes          VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meno_log (user_id, log_date),
    CONSTRAINT fk_meno_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menopause_scores (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    taken_on   DATE          NOT NULL,
    answers    VARCHAR(60)   NOT NULL,      -- 21 answers, 0 to 3, comma separated
    total      TINYINT UNSIGNED NOT NULL,
    psych      TINYINT UNSIGNED NOT NULL,
    somatic    TINYINT UNSIGNED NOT NULL,
    vasomotor  TINYINT UNSIGNED NOT NULL,
    sexual     TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY ix_meno_scores (user_id, taken_on),
    CONSTRAINT fk_meno_scores_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lab results (Blood tests): numbers in UK units, when the blood was taken relative to the last dose,
-- and the lab's own range from the report. Target ranges are chosen by the person or their clinic.
CREATE TABLE IF NOT EXISTS lab_results (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    test             VARCHAR(30)   NOT NULL,
    value            DECIMAL(12,3) NOT NULL,
    unit             VARCHAR(20)   NOT NULL,
    taken_at         DATETIME      NOT NULL,     -- UTC
    timing           VARCHAR(10)   NOT NULL DEFAULT 'unknown', -- trough | peak | mid | unknown
    medication_id    INT UNSIGNED  NULL,
    hours_since_dose INT UNSIGNED  NULL,
    lab_low          DECIMAL(12,3) NULL,
    lab_high         DECIMAL(12,3) NULL,
    notes            VARCHAR(300)  NULL,
    PRIMARY KEY (id),
    KEY ix_lab_user (user_id, test, taken_at),
    CONSTRAINT fk_lab_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lab_targets (
    user_id  INT UNSIGNED NOT NULL,
    test     VARCHAR(30)   NOT NULL,
    low      DECIMAL(12,3) NULL,
    high     DECIMAL(12,3) NULL,
    note     VARCHAR(150)  NULL,
    PRIMARY KEY (user_id, test),
    CONSTRAINT fk_lab_target_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Premenstrual symptoms (Periods and contraception): daily DRSP ratings, and "day 1" markers for cycles without a bleed.
CREATE TABLE IF NOT EXISTS pmdd_logs (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    log_date   DATE          NOT NULL,
    scores     VARCHAR(400)  NOT NULL,   -- JSON: DRSP item number => 1 to 6
    quick      TINYINT(1)    NOT NULL DEFAULT 0,
    lifestyle  VARCHAR(100)  NULL,       -- comma list: exercise, caffeine, alcohol, short_sleep
    notes      VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmdd_log (user_id, log_date),
    CONSTRAINT fk_pmdd_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pmdd_markers (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    marked_on  DATE          NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pmdd_marker (user_id, marked_on),
    CONSTRAINT fk_pmdd_marker_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How a dose worked: when it was taken, kicked in and wore off, a rating and side effects (titration and timing).
CREATE TABLE IF NOT EXISTS dose_effects (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        INT UNSIGNED NOT NULL,
    medication_id  INT UNSIGNED NOT NULL,
    log_date       DATE          NOT NULL,
    dose           VARCHAR(150)  NULL,     -- the dose at the time (strength and regimen), for the titration table
    taken_at       TIME          NULL,
    onset_at       TIME          NULL,
    wore_off_at    TIME          NULL,
    crash          TINYINT(1)    NOT NULL DEFAULT 0,
    rating         TINYINT UNSIGNED NULL,  -- 1 (not at all) to 5 (very well)
    side_effects   VARCHAR(200)  NULL,     -- comma list: appetite, sleep, mood, heart, tics, headache, other
    notes          VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    KEY ix_effects (medication_id, log_date),
    CONSTRAINT fk_effects_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_effects_med FOREIGN KEY (medication_id) REFERENCES medications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ADHD and autism (Care): identity, communication passport and assessment prep; waiting lists; daily energy and sensory log.
CREATE TABLE IF NOT EXISTS nd_profiles (
    user_id     INT UNSIGNED NOT NULL,
    identity    VARCHAR(400)  NULL,      -- JSON: condition => self | waiting | diagnosed
    passport    TEXT          NULL,      -- JSON: communication passport fields
    raf_asked   DATE          NULL,      -- asked the GP for a Reasonable Adjustment Flag
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_nd_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nd_waitlist (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    assessment   VARCHAR(20)   NOT NULL,  -- adhd | autism | other
    provider     VARCHAR(150)  NULL,
    route        VARCHAR(12)   NULL,      -- nhs | rtc (Right to Choose) | private
    referred_on  DATE          NULL,
    assessed_on  DATE          NULL,
    notes        VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_nd_wait_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nd_prep (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    category    VARCHAR(20)   NOT NULL,   -- childhood | daily | work | relationships | sensory | strengths | other
    note        VARCHAR(2000) NOT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_nd_prep (user_id, category),
    CONSTRAINT fk_nd_prep_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nd_logs (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NOT NULL,
    log_date     DATE          NOT NULL,
    spoons       TINYINT UNSIGNED NULL,   -- energy, 0 to 10
    sensory      TINYINT UNSIGNED NULL,   -- sensory load, 0 to 5
    event        VARCHAR(10)   NULL,      -- meltdown | shutdown
    triggers     VARCHAR(300)  NULL,
    sleep_hours  DECIMAL(3,1)  NULL,
    burnout      VARCHAR(200)  NULL,      -- comma list of warning signs noticed
    notes        VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_nd_log (user_id, log_date),
    CONSTRAINT fk_nd_log_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thyroid (Care): a weekly check of symptoms that can mean under- or overtreatment.
CREATE TABLE IF NOT EXISTS thyroid_weeks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    week_start  DATE          NOT NULL,   -- the Monday
    scores      VARCHAR(500)  NOT NULL,   -- JSON: symptom => 0 to 3
    notes       VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_thyroid_week (user_id, week_start),
    CONSTRAINT fk_thyroid_week_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Diabetes (Care): type and targets, sick-day mode; insulin and carbs; hypos logged with the hypo guide.
CREATE TABLE IF NOT EXISTS diabetes_profiles (
    user_id       INT UNSIGNED NOT NULL,
    type          VARCHAR(12)   NULL,      -- 1 | 2 | lada | mody | gestational | prediabetes | other
    target_low    DECIMAL(3,1)  NOT NULL DEFAULT 3.9,
    target_high   DECIMAL(3,1)  NOT NULL DEFAULT 10.0,
    hba1c_target  TINYINT UNSIGNED NULL,   -- mmol/mol
    drives        TINYINT(1)    NOT NULL DEFAULT 0,
    sick_since    DATETIME      NULL,      -- sick-day mode on (UTC)
    PRIMARY KEY (user_id),
    CONSTRAINT fk_dm_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS insulin_logs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    taken_at      DATETIME      NOT NULL,  -- UTC
    kind          VARCHAR(10)   NOT NULL,  -- basal | bolus | correction
    units         DECIMAL(5,1)  NULL,
    carbs         DECIMAL(5,1)  NULL,      -- grams
    glucose       DECIMAL(4,1)  NULL,      -- mmol/L at the time
    medication_id INT UNSIGNED  NULL,
    notes         VARCHAR(300)  NULL,
    external_id   CHAR(40)      NULL,      -- imported from Nightscout
    PRIMARY KEY (id),
    UNIQUE KEY uq_insulin_external (user_id, external_id),
    KEY ix_insulin_user (user_id, taken_at),
    CONSTRAINT fk_insulin_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hypo_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    at            DATETIME      NOT NULL,  -- UTC
    glucose       DECIMAL(4,1)  NULL,
    treated_with  VARCHAR(150)  NULL,
    notes         VARCHAR(500)  NULL,
    PRIMARY KEY (id),
    KEY ix_hypo_user (user_id, at),
    CONSTRAINT fk_hypo_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migraine and headaches (Care): each headache, and medicines taken for it.
CREATE TABLE IF NOT EXISTS headache_episodes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    started_at  DATETIME      NOT NULL,   -- UTC
    ended_at    DATETIME      NULL,       -- NULL while it's still going
    severity    TINYINT UNSIGNED NULL,    -- 1 mild, 2 moderate, 3 severe
    location    VARCHAR(10)   NULL,
    migraine    TINYINT(1)    NOT NULL DEFAULT 0,
    aura        TINYINT(1)    NOT NULL DEFAULT 0,
    symptoms    VARCHAR(200)  NULL,
    triggers    VARCHAR(200)  NULL,
    notes       VARCHAR(1000) NULL,
    PRIMARY KEY (id),
    KEY ix_headache_user (user_id, started_at),
    CONSTRAINT fk_headache_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS episode_meds (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    episode_id     INT UNSIGNED NOT NULL,
    medication_id  INT UNSIGNED  NULL,
    name           VARCHAR(150)  NOT NULL,
    taken_at       DATETIME      NOT NULL,  -- UTC
    helped         TINYINT UNSIGNED NULL,   -- 0 no to 3 took it away
    PRIMARY KEY (id),
    KEY ix_episode_meds (episode_id),
    CONSTRAINT fk_episode_meds FOREIGN KEY (episode_id) REFERENCES headache_episodes (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Allergies (Care): allergies, intolerances and side effects. drug_class: a medicine group to check
-- Meds against too (see DRUG_ALLERGY_CLASSES). status 'resolved': no longer current (kept as a record).
CREATE TABLE IF NOT EXISTS allergies (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    allergen      VARCHAR(120)  NOT NULL,
    category      VARCHAR(12)   NOT NULL DEFAULT 'other',
    drug_class    VARCHAR(20)   NULL,
    kind          VARCHAR(12)   NOT NULL DEFAULT 'allergy',  -- allergy | intolerance | side_effect
    reactions     VARCHAR(200)  NULL,
    severity      VARCHAR(12)   NULL,                      -- mild | moderate | severe | anaphylaxis
    confirmed     VARCHAR(10)   NULL,
    since         VARCHAR(40)   NULL,
    notes         VARCHAR(1000) NULL,
    status        VARCHAR(10)   NOT NULL DEFAULT 'current',
    resolved_on   DATE          NULL,
    resolved_note VARCHAR(200)  NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_allergies_user (user_id),
    CONSTRAINT fk_allergies_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "No known allergies" (and when it was recorded), and adrenaline auto-injectors.
CREATE TABLE IF NOT EXISTS allergy_profiles (
    user_id       INT UNSIGNED NOT NULL,
    none_known    TINYINT(1)    NOT NULL DEFAULT 0,
    none_known_on DATE          NULL,
    aai           TINYINT(1)    NOT NULL DEFAULT 0,
    aai_brand     VARCHAR(40)   NULL,
    aai_where     VARCHAR(120)  NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_allergy_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weather for the headache diary (Migraine): the town chosen (coordinates rounded to ~10 km), and daily
-- weather there from Open-Meteo: mean sea-level pressure (hPa), how far it fell from the day before's
-- (negative: rose), temperatures (°C), mean humidity (%) and rain (mm). forecast = 1 for days still ahead.
CREATE TABLE IF NOT EXISTS weather_places (
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(120)  NOT NULL,
    latitude    DECIMAL(4,1)  NOT NULL,
    longitude   DECIMAL(5,1)  NOT NULL,
    fetched_at  DATETIME      NULL,
    last_error  VARCHAR(300)  NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_weather_place_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS weather_days (
    user_id        INT UNSIGNED NOT NULL,
    day            DATE          NOT NULL,
    pressure       DECIMAL(5,1)  NOT NULL,
    pressure_fall  DECIMAL(4,1)  NULL,
    temp_max       DECIMAL(4,1)  NULL,
    temp_min       DECIMAL(4,1)  NULL,
    humidity       TINYINT UNSIGNED NULL,
    rain           DECIMAL(5,1)  NULL,
    pollen_grass   SMALLINT UNSIGNED NULL,  -- highest hourly count that day, grains/m³ (Europe only; Allergies)
    pollen_tree    SMALLINT UNSIGNED NULL,  -- alder, birch or olive, whichever is highest
    pollen_weed    SMALLINT UNSIGNED NULL,  -- mugwort or ragweed
    forecast       TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, day),
    CONSTRAINT fk_weather_day_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Blood pressure (Care): monitor, home target set by a clinician, sick-day rules given, and the home monitoring week.
CREATE TABLE IF NOT EXISTS bp_profiles (
    user_id        INT UNSIGNED NOT NULL,
    target_sys     SMALLINT UNSIGNED NULL,  -- NULL: the NICE home target by age
    target_dia     SMALLINT UNSIGNED NULL,
    monitor        VARCHAR(100)  NULL,
    cuff           VARCHAR(10)   NULL,
    validated      TINYINT(1)    NOT NULL DEFAULT 0,
    sick_day       TINYINT(1)    NOT NULL DEFAULT 0,  -- the clinician has given sick-day rules
    session_start  DATE          NULL,               -- the current or last home monitoring week
    PRIMARY KEY (user_id),
    CONSTRAINT fk_bp_profile_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
