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
    stock_count     DECIMAL(10,2) NULL,
    low_stock_days  SMALLINT UNSIGNED NOT NULL DEFAULT 7,
    expiry_date     DATE          NULL,          -- use-by date of the current supply, if known
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
    kind           VARCHAR(12)   NOT NULL,              -- side_effect | feeling
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
    level         VARCHAR(8)    NOT NULL DEFAULT 'view',   -- view | manage
    include_mind  TINYINT(1)    NOT NULL DEFAULT 0,
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
