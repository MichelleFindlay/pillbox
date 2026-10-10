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
    ];
    foreach ($upgrades as [$table, $column, $alter]) {
        if (isset($columns[$table]) && !isset($columns[$table][$column])) {
            db()->exec($alter);
        }
    }
    if (isset($columns['users']['auth_provider']) && !str_contains($columns['users']['auth_provider'], 'oidc')) {
        db()->exec("ALTER TABLE users MODIFY COLUMN auth_provider ENUM('local','entra','oidc') NOT NULL DEFAULT 'local'");
    }
}
