<?php
declare(strict_types=1);

/*
 * Full backup and restore of one user's data (the "backup" part of "Download all my
 * data", and "Import data" on the Account page). Every table that belongs to a user,
 * every row, with ids so links between them survive. Restoring replaces the user's
 * data and gives everything new ids. Sign-in details, sharing, passkeys and sync /
 * calendar tokens are never exported or imported.
 */

const BACKUP_FORMAT = 1;

// Settings (columns of users) that are backed up and restored.
const BACKUP_SETTINGS = ['timezone', 'enabled_metrics', 'theme', 'color_mode', 'care_areas', 'date_of_birth', 'sex', 'screening_programmes',
    'mind_enabled', 'mind_lock_plan', 'mind_reminder', 'mind_selfcare', 'meds_show_changes', 'pmdd_mode', 'pmdd_headsup', 'text_size', 'wide_spacing', 'easy_font', 'reduce_motion', 'hide_streaks', 'dose_followup', 'pronouns', 'gender_identity', 'body_inventory', 'privacy_blur', 'disguise', 'email_digest', 'email_digest_hour', 'email_appointments'];

/*
 * Tables in restore order (parents before children). 'owner': how rows belong to the user
 * (a user_id column, or [parent column, parent table]); 'links': columns pointing at other
 * backed-up tables; 'keyed': false for tables without an id column.
 */
const BACKUP_TABLES = [
    'medications'           => ['owner' => 'user_id', 'links' => []],
    'medication_schedules'  => ['owner' => ['medication_id', 'medications'], 'links' => ['medication_id' => 'medications']],
    'custom_metrics'        => ['owner' => 'user_id', 'links' => []],
    'health_plans'          => ['owner' => 'user_id', 'links' => []],
    'appointments'          => ['owner' => 'user_id', 'links' => ['plan_id' => 'health_plans']],
    'health_claims'         => ['owner' => 'user_id', 'links' => ['plan_id' => 'health_plans', 'appointment_id' => 'appointments']],
    'appointment_questions' => ['owner' => ['appointment_id', 'appointments'], 'links' => ['appointment_id' => 'appointments']],
    'dose_logs'             => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications', 'schedule_id' => 'medication_schedules']],
    'medication_changes'    => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'stock_events'          => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'med_notes'             => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'health_readings'       => ['owner' => 'user_id', 'links' => []],
    'travel_plans'          => ['owner' => 'user_id', 'links' => []],
    'care_tasks'            => ['owner' => 'user_id', 'links' => []],
    'care_logs'             => ['owner' => 'user_id', 'links' => ['task_id' => 'care_tasks']],
    'health_checks'         => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'check_results'         => ['owner' => ['check_id', 'health_checks'], 'links' => ['check_id' => 'health_checks']],
    'cycle_periods'         => ['owner' => 'user_id', 'links' => []],
    'pregnancies'           => ['owner' => 'user_id', 'links' => []],
    'menopause_profiles'    => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'menopause_logs'        => ['owner' => 'user_id', 'links' => []],
    'menopause_scores'      => ['owner' => 'user_id', 'links' => []],
    'dose_effects'          => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'nd_profiles'           => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'nd_waitlist'           => ['owner' => 'user_id', 'links' => []],
    'nd_prep'               => ['owner' => 'user_id', 'links' => []],
    'nd_logs'               => ['owner' => 'user_id', 'links' => []],
    'thyroid_weeks'         => ['owner' => 'user_id', 'links' => []],
    'diabetes_profiles'     => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'insulin_logs'          => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'hypo_events'           => ['owner' => 'user_id', 'links' => []],
    'headache_episodes'     => ['owner' => 'user_id', 'links' => []],
    'weather_places'        => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'allergies'             => ['owner' => 'user_id', 'links' => []],
    'allergy_profiles'      => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'bp_profiles'           => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'episode_meds'          => ['owner' => ['episode_id', 'headache_episodes'], 'links' => ['episode_id' => 'headache_episodes', 'medication_id' => 'medications']],
    'pmdd_logs'             => ['owner' => 'user_id', 'links' => []],
    'pmdd_markers'          => ['owner' => 'user_id', 'links' => []],
    'lab_results'           => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'lab_targets'           => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'pregnancy_items'       => ['owner' => ['pregnancy_id', 'pregnancies'], 'links' => ['pregnancy_id' => 'pregnancies']],
    'antenatal_checks'      => ['owner' => 'user_id', 'links' => ['pregnancy_id' => 'pregnancies']],
    'pregnancy_log'         => ['owner' => 'user_id', 'links' => ['pregnancy_id' => 'pregnancies']],
    'prescription_payments' => ['owner' => 'user_id', 'links' => ['medication_id' => 'medications']],
    'mind_checkins'         => ['owner' => 'user_id', 'links' => []],
    'mind_symptoms'         => ['owner' => 'user_id', 'links' => []],
    'mind_symptom_logs'     => ['owner' => ['checkin_id', 'mind_checkins'], 'links' => ['checkin_id' => 'mind_checkins', 'symptom_id' => 'mind_symptoms'], 'keyed' => false],
    'mind_toolkit'          => ['owner' => 'user_id', 'links' => []],
    'mind_plan'             => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'mind_contacts'         => ['owner' => 'user_id', 'links' => []],
    'mind_questionnaires'   => ['owner' => 'user_id', 'links' => []],
    'mind_journal'          => ['owner' => 'user_id', 'links' => []],
    'mind_notes'            => ['owner' => 'user_id', 'links' => ['appointment_id' => 'appointments']],
    'mind_selfcare'         => ['owner' => 'user_id', 'links' => [], 'keyed' => false],
    'mind_activities'       => ['owner' => 'user_id', 'links' => []],
];

/** The backup: settings plus every row of every table, without user ids. */
function export_backup(array $user): array
{
    $uid = (int) $user['id'];
    $tables = [];
    foreach (BACKUP_TABLES as $table => $spec) {
        $rows = is_array($spec['owner'])
            ? q("SELECT c.* FROM $table c JOIN {$spec['owner'][1]} p ON p.id = c.{$spec['owner'][0]} WHERE p.user_id = ?", [$uid])->fetchAll()
            : q("SELECT * FROM $table WHERE user_id = ?", [$uid])->fetchAll();
        $tables[$table] = array_map(function ($r) {
            unset($r['user_id']);
            return $r;
        }, $rows);
    }
    return [
        'format'   => BACKUP_FORMAT,
        'version'  => pillbox_version(),
        'settings' => ['care_areas' => $user['care_areas'] === null ? null // without the private pathway (it's never in backups)
            : implode(',', array_filter(explode(',', $user['care_areas']), fn ($k) => empty(CARE_AREAS[$k]['private'])))] + array_intersect_key($user, array_flip(BACKUP_SETTINGS)),
        'tables'   => $tables,
    ];
}

/** Is this decoded JSON a Pillbox backup we can read? Returns the backup part, or an error. */
function backup_from_file(mixed $json): array|string
{
    $backup = is_array($json) ? ($json['backup'] ?? $json) : null;
    if (!is_array($backup) || !isset($backup['format'], $backup['tables']) || !is_array($backup['tables'])) {
        return "That isn't a Pillbox backup. Use the file from \"Download all my data\" (from version 1.2 or later).";
    }
    if ((int) $backup['format'] > BACKUP_FORMAT) {
        return 'That backup is from a newer version of Pillbox. Update this Pillbox first.';
    }
    return $backup;
}

/**
 * Replace the user's data with a backup. Returns counts of rows restored per table.
 * Columns the backup has but this database doesn't (and the other way round) are skipped.
 */
function import_backup(array $user, array $backup): array
{
    $uid = (int) $user['id'];
    $columns = [];
    foreach (q('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()') as $c) {
        $columns[$c['TABLE_NAME']][] = $c['COLUMN_NAME'];
    }
    return transaction(function () use ($uid, $backup, $columns) {
        // Clear the current data (children go with their parents).
        foreach (array_reverse(BACKUP_TABLES, true) as $table => $spec) {
            if (!is_array($spec['owner'])) {
                q("DELETE FROM $table WHERE user_id = ?", [$uid]);
            }
        }
        // Settings.
        $settings = array_intersect_key((array) ($backup['settings'] ?? []), array_flip(BACKUP_SETTINGS));
        $settings = array_intersect_key($settings, array_flip($columns['users']));
        if ($settings) {
            q('UPDATE users SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($settings))) . ' WHERE id = ?', [...array_values($settings), $uid]);
        }
        // Rows, with new ids.
        $ids = [];
        $counts = [];
        foreach (BACKUP_TABLES as $table => $spec) {
            $counts[$table] = 0;
            foreach ((array) ($backup['tables'][$table] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $oldId = $row['id'] ?? null;
                unset($row['id'], $row['user_id']);
                foreach ($spec['links'] as $col => $parent) {
                    if (isset($row[$col])) {
                        $row[$col] = $ids[$parent][$row[$col]] ?? null;
                    }
                }
                // A child row whose parent didn't come across is dropped.
                if (is_array($spec['owner']) && empty($row[$spec['owner'][0]])) {
                    continue;
                }
                if ($table === 'health_readings' && preg_match('/^c(\d+)$/', (string) ($row['metric'] ?? ''), $m)) {
                    if (!isset($ids['custom_metrics'][$m[1]])) {
                        continue;
                    }
                    $row['metric'] = 'c' . $ids['custom_metrics'][$m[1]];
                }
                if (!is_array($spec['owner'])) {
                    $row['user_id'] = $uid;
                }
                $row = array_intersect_key($row, array_flip($columns[$table] ?? []));
                if (!$row) {
                    continue;
                }
                q("INSERT INTO $table (" . implode(', ', array_keys($row)) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')', array_values($row));
                if (($spec['keyed'] ?? true) && $oldId !== null) {
                    $ids[$table][$oldId] = (int) db()->lastInsertId();
                }
                $counts[$table]++;
            }
        }
        return $counts;
    });
}
