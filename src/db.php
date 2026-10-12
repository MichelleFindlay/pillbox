<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host'),
            config('db.port'),
            config('db.name')
        );
        $pdo = new PDO($dsn, config('db.user'), config('db.password'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

/** Prepare and execute a statement in one go. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($params));
    return $stmt;
}

/** Run $fn inside a transaction, rolling back on any exception. */
function transaction(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Keep the database up to date, so Pillbox can be installed or upgraded just by
 * uploading the files. Any table in database/schema.sql that doesn't exist yet is
 * created; columns added after a table was first released are added below.
 */
function ensure_schema(): void
{
    $existing = q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    // Drop SQL comments (whole-line and end-of-line) so a ';' inside one can't split a statement.
    $sql = preg_replace('/--[^\n]*/', '', (string) file_get_contents(APP_ROOT . '/database/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+)/i', $statement, $m) && !in_array($m[1], $existing, true)) {
            db()->exec($statement);
        }
    }
    if (!$existing) {
        return; // brand-new install: everything was just created from schema.sql
    }

    $columns = [];
    foreach (q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $c) {
        $columns[$c['TABLE_NAME']][$c['COLUMN_NAME']] = $c['COLUMN_TYPE'];
    }
    $upgrades = [
        ['medications', 'dmd_code', 'ALTER TABLE medications ADD COLUMN dmd_code VARCHAR(20) NULL AFTER name'],
        ['users', 'session_version', 'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER enabled_metrics'],
        ['users', 'theme', "ALTER TABLE users ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'olive' AFTER session_version,
                            ADD COLUMN color_mode VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER theme"],
        ['users', 'calendar_token', 'ALTER TABLE users ADD COLUMN calendar_token CHAR(40) NULL, ADD COLUMN calendar_include_notes TINYINT(1) NOT NULL DEFAULT 0,
                                     ADD UNIQUE KEY uq_users_calendar (calendar_token)'],
        ['appointments', 'outcome_notes', 'ALTER TABLE appointments ADD COLUMN outcome_notes TEXT NULL AFTER notes'],
        ['health_readings', 'external_id', 'ALTER TABLE health_readings ADD COLUMN source VARCHAR(20) NULL AFTER recorded_at,
                                            ADD COLUMN external_id CHAR(40) NULL AFTER source, ADD UNIQUE KEY uq_readings_external (user_id, external_id)'],
        ['users', 'health_sync_token', 'ALTER TABLE users ADD COLUMN health_sync_token CHAR(40) NULL, ADD UNIQUE KEY uq_users_health_sync (health_sync_token)'],
        ['care_tasks', 'remind', 'ALTER TABLE care_tasks ADD COLUMN remind TINYINT(1) NOT NULL DEFAULT 0 AFTER tip'],
        ['medications', 'expiry_date', 'ALTER TABLE medications ADD COLUMN expiry_date DATE NULL AFTER low_stock_days'],
        ['care_tasks', 'amount', 'ALTER TABLE care_tasks ADD COLUMN amount VARCHAR(60) NULL AFTER starts_on'],
        ['users', 'care_areas', 'ALTER TABLE users ADD COLUMN care_areas VARCHAR(255) NULL, ADD COLUMN date_of_birth DATE NULL, ADD COLUMN sex VARCHAR(10) NULL'],
        ['users', 'screening_programmes', 'ALTER TABLE users ADD COLUMN screening_programmes VARCHAR(100) NULL AFTER sex'],
        ['users', 'mind_enabled', "ALTER TABLE users ADD COLUMN mind_enabled TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN mind_pin_hash VARCHAR(255) NULL,
                                   ADD COLUMN mind_lock_plan TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN mind_reminder TIME NULL"],
        ['medications', 'mental_health', 'ALTER TABLE medications ADD COLUMN mental_health TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date'],
        ['users', 'mind_selfcare', 'ALTER TABLE users ADD COLUMN mind_selfcare VARCHAR(500) NULL AFTER mind_reminder'],
        ['mind_contacts', 'helps_with', 'ALTER TABLE mind_contacts ADD COLUMN helps_with VARCHAR(60) NULL AFTER text_body,
                                          ADD COLUMN reach_out_days SMALLINT UNSIGNED NULL AFTER helps_with, ADD COLUMN last_contact DATE NULL AFTER reach_out_days'],
        ['health_checks', 'track', "ALTER TABLE health_checks ADD COLUMN track VARCHAR(8) NOT NULL DEFAULT 'due' AFTER rule,
                                     ADD COLUMN quantity DECIMAL(8,1) NULL AFTER due_date, ADD COLUMN unit VARCHAR(20) NULL AFTER quantity,
                                     ADD COLUMN low_at DECIMAL(8,1) NULL AFTER unit, ADD COLUMN per_week DECIMAL(8,2) NULL AFTER low_at,
                                     ADD COLUMN counted_on DATE NULL AFTER per_week, ADD COLUMN items TEXT NULL AFTER counted_on"],
        ['users', 'meds_show_changes', 'ALTER TABLE users ADD COLUMN meds_show_changes TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'email_digest', 'ALTER TABLE users ADD COLUMN email_digest TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN email_digest_hour TINYINT UNSIGNED NOT NULL DEFAULT 7,
                                   ADD COLUMN email_appointments TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN last_digest_on DATE NULL, ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0'],
        ['appointments', 'reminder_emailed_at', 'ALTER TABLE appointments ADD COLUMN reminder_emailed_at DATETIME NULL AFTER remind_minutes'],
        ['data_shares', 'permissions', 'ALTER TABLE data_shares ADD COLUMN permissions TEXT NULL AFTER include_mind, ADD COLUMN expires_on DATE NULL AFTER permissions'],
        ['health_checks', 'medication_id', 'ALTER TABLE health_checks ADD COLUMN medication_id INT UNSIGNED NULL AFTER items'],
        ['users', 'push_doses', 'ALTER TABLE users ADD COLUMN push_doses TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN push_care TINYINT(1) NOT NULL DEFAULT 1,
                                 ADD COLUMN push_appointments TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN push_private TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'text_size', "ALTER TABLE users ADD COLUMN text_size VARCHAR(4) NULL, ADD COLUMN wide_spacing TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN easy_font TINYINT(1) NOT NULL DEFAULT 0,
                                ADD COLUMN reduce_motion TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN hide_streaks TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN dose_followup SMALLINT UNSIGNED NOT NULL DEFAULT 0"],
        ['medications', 'brand', 'ALTER TABLE medications ADD COLUMN brand VARCHAR(100) NULL AFTER supply_price'],
        ['stock_events', 'brand', 'ALTER TABLE stock_events ADD COLUMN brand VARCHAR(100) NULL AFTER stock_after'],
        ['medications', 'controlled', 'ALTER TABLE medications ADD COLUMN controlled TINYINT(1) NOT NULL DEFAULT 0 AFTER supply_price, ADD COLUMN rx_written DATE NULL AFTER controlled, ADD COLUMN rx_days SMALLINT UNSIGNED NULL AFTER rx_written'],
        ['users', 'pmdd_mode', 'ALTER TABLE users ADD COLUMN pmdd_mode TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN pmdd_headsup TINYINT(1) NOT NULL DEFAULT 0'],
        ['users', 'body_inventory', "ALTER TABLE users ADD COLUMN body_inventory TEXT NULL AFTER screening_programmes, ADD COLUMN pronouns VARCHAR(40) NULL AFTER body_inventory,
                                     ADD COLUMN gender_identity VARCHAR(100) NULL AFTER pronouns, ADD COLUMN privacy_blur TINYINT(1) NOT NULL DEFAULT 0 AFTER gender_identity,
                                     ADD COLUMN disguise VARCHAR(10) NULL AFTER privacy_blur"],
        ['dose_logs', 'site', 'ALTER TABLE dose_logs ADD COLUMN site VARCHAR(10) NULL AFTER amount'],
        ['medication_schedules', 'every_days', 'ALTER TABLE medication_schedules ADD COLUMN every_days SMALLINT UNSIGNED NULL AFTER days_mask, ADD COLUMN anchor_date DATE NULL AFTER every_days'],
        ['medication_schedules', 'cycle_phase', 'ALTER TABLE medication_schedules ADD COLUMN cycle_phase VARCHAR(10) NULL AFTER anchor_date'],
        ['weather_days', 'pollen_grass', 'ALTER TABLE weather_days ADD COLUMN pollen_grass SMALLINT UNSIGNED NULL AFTER rain, ADD COLUMN pollen_tree SMALLINT UNSIGNED NULL AFTER pollen_grass, ADD COLUMN pollen_weed SMALLINT UNSIGNED NULL AFTER pollen_tree'],
        ['medication_schedules', 'cycle_length', 'ALTER TABLE medication_schedules ADD COLUMN cycle_length SMALLINT UNSIGNED NULL AFTER cycle_phase, ADD COLUMN cycle_from SMALLINT UNSIGNED NULL AFTER cycle_length, ADD COLUMN cycle_to SMALLINT UNSIGNED NULL AFTER cycle_from'],
        ['medications', 'min_interval_minutes', 'ALTER TABLE medications ADD COLUMN min_interval_minutes SMALLINT UNSIGNED NULL AFTER prn_max_daily'],
        ['medications', 'is_private', 'ALTER TABLE medications ADD COLUMN is_private TINYINT(1) NOT NULL DEFAULT 0 AFTER supply_price'],
        ['medications', 'is_supplement', 'ALTER TABLE medications ADD COLUMN is_supplement TINYINT(1) NOT NULL DEFAULT 0 AFTER supply_price'],
        ['medications', 'course', "ALTER TABLE medications ADD COLUMN course VARCHAR(8) NOT NULL DEFAULT 'ongoing' AFTER end_date"],
        ['medications', 'ordered_on', 'ALTER TABLE medications ADD COLUMN ordered_on DATE NULL AFTER expiry_date'],
        ['medications', 'supply_type', "ALTER TABLE medications ADD COLUMN supply_type VARCHAR(8) NOT NULL DEFAULT 'nhs' AFTER expiry_date,
                                         ADD COLUMN supply_price DECIMAL(8,2) NULL AFTER supply_type"],
        ['prescription_payments', 'kind', "ALTER TABLE prescription_payments ADD COLUMN kind VARCHAR(8) NOT NULL DEFAULT 'nhs' AFTER note,
                                            ADD COLUMN medication_id INT UNSIGNED NULL AFTER kind"],
        ['users', 'terms_accepted_at', 'ALTER TABLE users ADD COLUMN terms_accepted_at DATETIME NULL AFTER signup_method'],
        ['users', 'pending_approval', "ALTER TABLE users ADD COLUMN pending_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
                                       ADD COLUMN signup_method VARCHAR(60) NULL AFTER pending_approval"],
        ['users', 'care_prompt_hidden', 'ALTER TABLE users ADD COLUMN care_prompt_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER health_sync_token'],
    ];
    foreach ($upgrades as [$table, $column, $alter]) {
        if (isset($columns[$table]) && !isset($columns[$table][$column])) {
            db()->exec($alter);
        }
    }
    // First-login setup arrived in 1.2: people who were already using Pillbox don't need it.
    if (isset($columns['users']) && !isset($columns['users']['onboarded_at'])) {
        db()->exec('ALTER TABLE users ADD COLUMN onboarded_at DATETIME NULL');
        db()->exec('UPDATE users SET onboarded_at = UTC_TIMESTAMP()');
    }
    if (isset($columns['users']['auth_provider']) && !str_contains($columns['users']['auth_provider'], 'oidc')) {
        db()->exec("ALTER TABLE users MODIFY COLUMN auth_provider ENUM('local','entra','oidc') NOT NULL DEFAULT 'local'");
    }
}
