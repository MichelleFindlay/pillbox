<?php
declare(strict_types=1);

/*
 * Granular sharing permissions. Each share gives a level per section (none, view, and
 * for some sections log or edit), Care categories to keep private, whether the Mind
 * journal is included, and an optional end date. Pages are checked centrally in
 * require_login() against SHARE_PAGES; anything not listed there is refused.
 */

// section => [label, help, levels allowed (besides none)]
const SHARE_SECTIONS = [
    'doses'        => ['Medicines and doses', 'Medicines, today\'s doses, history and travel', ['view' => 'See', 'log' => 'Log doses', 'edit' => 'Change medicines']],
    'readings'     => ['Readings', 'Blood pressure, glucose, weight and the rest', ['view' => 'See', 'edit' => 'Add and change']],
    'appointments' => ['Appointments', 'Including questions to ask and what was decided', ['view' => 'See', 'edit' => 'Add and change']],
    'care'         => ['Care', 'Routines, checks, screening and vaccinations', ['view' => 'See', 'edit' => 'Tick off and change']],
    'mind'         => ['Mind', 'Check-ins, safety plan, therapy and the rest of Mind', ['view' => 'See', 'edit' => 'Check in and change']],
    'reports'      => ['Reports and exports', 'Printable reports and CSV downloads', ['view' => 'See and print']],
];
const SHARE_LEVEL_RANK = ['none' => 0, 'view' => 1, 'log' => 2, 'edit' => 3];
const SHARE_PRESETS = [
    'view_all'  => ['See everything except Mind', ['doses' => 'view', 'readings' => 'view', 'appointments' => 'view', 'care' => 'view', 'mind' => 'none', 'reports' => 'view']],
    'medicines' => ['Help with medicines', ['doses' => 'log', 'readings' => 'view', 'appointments' => 'view', 'care' => 'none', 'mind' => 'none', 'reports' => 'none']],
    'full'      => ['Full help (not Mind)', ['doses' => 'edit', 'readings' => 'edit', 'appointments' => 'edit', 'care' => 'edit', 'mind' => 'none', 'reports' => 'view']],
];

/*
 * Pages someone with shared access may open: script => [section, level to view, level to
 * change (or [action => level, '*' => level])]. null = always (their own or harmless pages).
 */
const SHARE_PAGES = [
    'index.php' => null, 'timer.php' => null, 'terms.php' => null, 'privacy.php' => null, 'sharing.php' => null, 'logout.php' => null, 'help.php' => null, 'breathe.php' => null,
    'medications.php'        => ['doses', 'view', ['restock' => 'log', 'ordered' => 'log', 'unorder' => 'log', 'rx_written' => 'log', 'supply_note' => 'log', '*' => 'edit']],
    'medication_edit.php'    => ['doses', 'edit', 'edit'],
    'medication_history.php' => ['doses', 'view', 'edit'],
    'med_effects.php'        => ['doses', 'view', 'log'],
    'dmd_search.php'         => ['doses', 'edit', 'edit'],
    'dose_action.php'        => ['doses', 'log', 'log'],
    'history.php'            => ['doses', 'view', 'edit'],
    'travel.php'             => ['doses', 'view', 'edit'],
    'readings.php'           => ['readings', 'view', 'edit'],
    'appointments.php'       => ['appointments', 'view', 'edit'],
    'appointment.php'        => ['appointments', 'view', 'edit'],
    'appointment_edit.php'   => ['appointments', 'edit', 'edit'],
    'care.php'               => ['care', 'view', 'edit'],
    'care_summary.php'       => ['care', 'view', 'view'],
    'pregnancy.php'          => ['care', 'view', 'edit'],
    'menopause_summary.php'  => ['care', 'view', 'view'],
    'pmdd_summary.php'       => ['care', 'view', 'view'],
    'passport.php'           => ['care', 'view', 'view'],
    'thyroid_summary.php'    => ['care', 'view', 'view'],
    'diabetes_summary.php'   => ['care', 'view', 'view'],
    'migraine_summary.php'   => ['care', 'view', 'view'],
    'bp_summary.php'         => ['care', 'view', 'view'],
    'hypo.php'               => ['care', 'view', 'edit'],
    'report.php'             => ['reports', 'view', 'view'],
    'export.php'             => ['reports', 'view', 'view'],
    'mind.php' => ['mind', 'view', 'edit'], 'mind_care.php' => ['mind', 'view', 'edit'], 'mind_meds.php' => ['mind', 'view', 'edit'],
    'mind_activities.php' => ['mind', 'view', 'edit'], 'mind_symptoms.php' => ['mind', 'view', 'edit'], 'mind_wins.php' => ['mind', 'view', 'edit'],
    'mind_relapse.php' => ['mind', 'view', 'edit'], 'mind_advance.php' => ['mind', 'view', 'edit'], 'mind_plan.php' => ['mind', 'view', 'edit'],
    'mind_toolkit.php' => ['mind', 'view', 'edit'], 'mind_check.php' => ['mind', 'view', 'edit'], 'mind_summary.php' => ['mind', 'view', 'view'],
    'mind_journal.php' => ['mind', 'view', 'edit'],
];

// For the activity log: "page:action" (or "page") => what it means.
const SHARE_ACTIVITY_LABELS = [
    'dose_action.php:take' => 'Logged a dose as taken', 'dose_action.php:take_block' => 'Logged several doses as taken', 'dose_action.php:skip' => 'Logged a dose as skipped', 'dose_action.php:undo' => 'Undid a dose',
    'dose_action.php:prn' => 'Logged an as-needed dose', 'medications.php:restock' => 'Updated medicine stock', 'medications.php:ordered' => 'Marked a repeat as ordered', 'medications.php:unorder' => 'Undid a repeat order', 'medications.php:archive' => 'Stopped a medicine',
    'medications.php:restore' => 'Restarted a medicine', 'medication_edit.php' => 'Added or changed a medicine', 'readings.php' => 'Added or changed a reading',
    'appointment_edit.php' => 'Added or changed an appointment', 'appointment.php' => 'Updated an appointment', 'appointments.php' => 'Changed an appointment',
    'care.php:toggle' => 'Ticked off a Care task', 'care.php:check_done' => 'Marked a Care check as done', 'care.php' => 'Changed Care',
    'travel.php' => 'Changed travel plans', 'mind.php:checkin' => 'Added a Mind check-in', 'mind_journal.php' => 'Wrote in the journal',
];

/** A share's permissions, filled in (and converted from the old view / manage + Mind setting). */
function share_permissions(array $share): array
{
    $stored = json_decode((string) ($share['permissions'] ?? ''), true);
    if (!is_array($stored)) {
        $level = ($share['level'] ?? 'view') === 'manage' ? 'edit' : 'view';
        $stored = array_fill_keys(array_keys(SHARE_SECTIONS), $level);
        $stored['mind'] = !empty($share['include_mind']) ? $level : 'none';
        $stored['reports'] = 'view';
        $stored['journal'] = !empty($share['include_mind']);
    }
    $perms = [];
    foreach (SHARE_SECTIONS as $key => [, , $levels]) {
        $perms[$key] = isset($levels[$stored[$key] ?? '']) ? $stored[$key] : 'none';
    }
    $perms['care_hidden'] = array_values(array_intersect((array) ($stored['care_hidden'] ?? []), array_keys(care_categories())));
    $perms['journal'] = $perms['mind'] !== 'none' && !empty($stored['journal']);
    return $perms;
}

/** Permissions from the sharing form. */
function share_permissions_from_post(): array
{
    $perms = [];
    foreach (SHARE_SECTIONS as $key => [, , $levels]) {
        $v = (string) ($_POST['perm'][$key] ?? 'none');
        $perms[$key] = isset($levels[$v]) ? $v : 'none';
    }
    $perms['care_hidden'] = array_values(array_intersect((array) ($_POST['care_hidden'] ?? []), array_keys(care_categories())));
    $perms['journal'] = $perms['mind'] !== 'none' && post('journal') === '1';
    return $perms;
}

/** Can the person looking at this Pillbox do this? Always true for the owner. */
function share_can(string $section, string $need = 'view', ?array $user = null): bool
{
    $user ??= current_user();
    if (!isset($user['_acting'])) {
        return true;
    }
    $have = $user['_acting']['perms'][$section] ?? 'none';
    return SHARE_LEVEL_RANK[$have] >= SHARE_LEVEL_RANK[$need];
}

/** May this Care area (or Mind's habits) be seen? */
function share_area_allowed(string $area, ?array $user = null): bool
{
    $user ??= current_user();
    if (!isset($user['_acting'])) {
        return true;
    }
    if (!empty(CARE_AREAS[$area]['private'])) {
        return false; // the private pathway is never shared
    }
    return $area === 'mind' ? share_can('mind', 'view', $user)
        : share_can('care', 'view', $user) && !in_array($area, $user['_acting']['perms']['care_hidden'], true);
}

/** The level needed for this request on a shared page, or false if the page isn't shared at all. */
function share_page_need(string $script, bool $isPost, string $action): array|false|null
{
    if (!array_key_exists($script, SHARE_PAGES)) {
        return false;
    }
    $rule = SHARE_PAGES[$script];
    if ($rule === null) {
        return null;
    }
    [$section, $view, $change] = $rule;
    $need = $isPost ? (is_array($change) ? ($change[$action] ?? $change['*']) : $change) : $view;
    return [$section, $need];
}

/** One-line summary of a share's permissions. */
function share_summary(array $perms): string
{
    $parts = [];
    foreach (SHARE_SECTIONS as $key => [$label, , $levels]) {
        if ($perms[$key] !== 'none') {
            $parts[] = $label . ': ' . mb_strtolower($levels[$perms[$key]]);
        }
    }
    return $parts ? implode(' · ', $parts) : 'Nothing yet';
}

function share_log_activity(int $shareId, string $page, string $action): void
{
    q('INSERT INTO share_activity (share_id, happened_at, page, action) VALUES (?, UTC_TIMESTAMP(), ?, ?)', [$shareId, $page, $action !== '' ? mb_substr($action, 0, 40) : null]);
}

function share_activity_label(array $row): string
{
    return SHARE_ACTIVITY_LABELS[$row['page'] . ':' . $row['action']] ?? SHARE_ACTIVITY_LABELS[$row['page']]
        ?? 'Made a change (' . str_replace(['.php', '_'], ['', ' '], $row['page']) . ')';
}
