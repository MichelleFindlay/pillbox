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
 * Create the tables on first run if they don't exist yet, so the app can be
 * installed just by uploading it and visiting the site. Also applies small
 * upgrades to databases created by earlier versions.
 */
function ensure_schema(): void
{
    if (q("SHOW TABLES LIKE 'users'")->fetch()) {
        if (!q("SHOW COLUMNS FROM medications LIKE 'dmd_code'")->fetch()) {
            db()->exec('ALTER TABLE medications ADD COLUMN dmd_code VARCHAR(20) NULL AFTER name');
        }
        if (!q("SHOW COLUMNS FROM users LIKE 'session_version'")->fetch()) {
            db()->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER enabled_metrics');
        }
        if (!q("SHOW TABLES LIKE 'stock_events'")->fetch()) {
            db()->exec("CREATE TABLE IF NOT EXISTS stock_events (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
        if (!q("SHOW COLUMNS FROM users LIKE 'theme'")->fetch()) {
            db()->exec("ALTER TABLE users ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'olive' AFTER session_version,
                        ADD COLUMN color_mode VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER theme");
        }
        return;
    }
    $sql = (string) file_get_contents(APP_ROOT . '/database/schema.sql');
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        db()->exec($statement);
    }
}
