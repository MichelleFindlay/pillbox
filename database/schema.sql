-- Pillbox schema (MySQL 8.0+ / MariaDB 10.5+)
-- All DATETIME columns are stored in UTC; the app converts to each user's timezone.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username         VARCHAR(100)  NULL,
    email            VARCHAR(255)  NULL,
    display_name     VARCHAR(150)  NOT NULL,
    password_hash    VARCHAR(255)  NULL,
    auth_provider    ENUM('local','entra') NOT NULL DEFAULT 'local',
    entra_oid        CHAR(36)      NULL,
    entra_tid        CHAR(36)      NULL,
    is_admin         TINYINT(1)    NOT NULL DEFAULT 0,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    timezone         VARCHAR(64)   NOT NULL DEFAULT 'Europe/London',
    enabled_metrics  VARCHAR(1000) NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at    DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_entra (entra_tid, entra_oid),
    KEY ix_users_email (email)
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
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_readings_user_metric (user_id, metric, recorded_at),
    CONSTRAINT fk_readings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
