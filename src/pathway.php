<?php
declare(strict_types=1);

/*
 * Pregnancy options and aftercare: a private pathway (pathway.php) for deciding what to do about
 * a pregnancy, having an abortion, or after a miscarriage or other pregnancy loss.
 * Privacy: everything is one encrypted record (key in storage/app.key) with an optional PIN of its
 * own. It's never shared, never on Today, in the calendar, emails, reports or data downloads, and
 * reminders only ever say "You have a reminder". "Delete everything" removes it completely.
 * The information is non-directive, following the NHS: it doesn't recommend any option.
 */

const PATHWAY_KIND = 'pathway';
const PATHWAY_UNLOCK_MINUTES = 15;

const PATHWAY_PURPOSES = [
    'options' => 'Thinking about my options, or having an abortion',
    'loss'    => 'After a miscarriage or pregnancy loss',
];

// Steps to keep track of, by purpose. 'how' offers a choice; 'test' is the follow-up pregnancy test.
const PATHWAY_STEPS = [
    'options' => [
        'referral'      => ['label' => 'Referral', 'how' => ['self' => 'Contacted a provider myself', 'gp' => 'Through my GP', 'clinic' => 'Through a sexual health clinic'],
            'tip' => 'Contacting a provider yourself is confidential: they usually ask whether you want your GP told. A GP referral means it is part of your GP record.'],
        'consultation'  => ['label' => 'Consultation', 'how' => ['phone' => 'By phone or video', 'person' => 'In person'],
            'tip' => 'To talk through your options and medical history. Sometimes there is a scan to check how many weeks pregnant you are.'],
        'counselling'   => ['label' => 'Counselling (optional)',
            'tip' => 'Free with abortion providers, for anyone who wants to talk things through, before or after.'],
        'treatment'     => ['label' => 'Treatment', 'how' => ['medical_home' => 'Medicines at home', 'medical_clinic' => 'Medicines at a clinic or hospital', 'surgical' => 'A procedure (surgical)'],
            'tip' => 'Follow the instructions your provider gives you. Use Medicines below for the times.'],
        'aftercare'     => ['label' => 'Aftercare',
            'tip' => "Bleeding and cramps can last up to 2 weeks, sometimes longer. Keep your provider's 24-hour aftercare number handy."],
        'test'          => ['label' => 'Follow-up pregnancy test', 'test' => true,
            'tip' => 'Usually 3 weeks after a medical abortion (or as your provider says). Once it is negative, periods usually return within 4 to 6 weeks.'],
        'contraception' => ['label' => 'Contraception discussed',
            'tip' => 'You can get pregnant again soon afterwards, before your next period. Your provider, GP or a sexual health clinic can help.'],
    ],
    'loss' => [
        'epu'        => ['label' => 'Early pregnancy unit, GP or midwife',
            'tip' => 'They can confirm what is happening and talk through what happens next.'],
        'scan'       => ['label' => 'Scan'],
        'management' => ['label' => 'Treatment, if any', 'how' => ['natural' => 'Waiting for it to happen naturally', 'medical' => 'Medicine', 'surgical' => 'A procedure (surgical)'],
            'tip' => 'Follow the instructions you were given. Use Medicines below for the times.'],
        'test'       => ['label' => 'Follow-up pregnancy test', 'test' => true,
            'tip' => 'Usually about 3 weeks later, or as you were told. Periods usually return within 4 to 6 weeks.'],
        'followup'   => ['label' => 'Follow-up appointment'],
    ],
];

const PATHWAY_MEDICINES = [
    'first'  => 'First medicine (mifepristone)',
    'second' => 'Second medicine (misoprostol)',
    'pain'   => 'Pain relief',
    'anti'   => 'Antibiotics',
    'other'  => 'Other',
];

const PATHWAY_BLEEDING = ['none' => 'None', 'spotting' => 'Spotting', 'light' => 'Light', 'period' => 'Like a period', 'heavy' => 'Heavier than a period',
    'very_heavy' => 'Soaking 2 or more pads an hour'];

// Call the provider's 24-hour aftercare line, or 999 in an emergency, if any of these happen.
const PATHWAY_RED_FLAGS = [
    'Heavy bleeding: soaking 2 or more pads an hour for 2 hours in a row',
    'A high temperature, or feeling hot and shivery or generally unwell, especially after the first day',
    "Severe pain that isn't eased by painkillers",
    'Discharge that smells unpleasant',
    'No bleeding within 24 hours of the second medicine, or still feeling pregnant',
];

// What is usually available at each stage in Great Britain (services and their limits vary).
const PATHWAY_OPTIONS_BY_WEEK = [
    [0, 9, 'Up to 9 weeks 6 days', 'Medicines at home, after a phone or video consultation (the tablets can be posted), or at a clinic. A procedure (vacuum aspiration) is also possible.'],
    [10, 13, '10 to 13 weeks', 'Medicines taken at a clinic or hospital, or a procedure (vacuum aspiration, usually up to about 14 weeks).'],
    [14, 23, '14 to 23 weeks', 'A procedure (dilatation and evacuation) or medicines at a clinic or hospital. Fewer services offer this, so you may need to travel.'],
    [24, 99, '24 weeks or more', 'Only in very limited circumstances, such as a serious risk to your life or a severe condition in the baby.'],
];

// Private pages and where their reminders open (all share the encrypted private_records table).
const PRIVATE_PAGES = ['pathway' => 'pathway.php', 'gender' => 'gender.php'];

/** A private page's record (with 'data' decoded), or null. */
function private_record(int $userId, string $kind): ?array
{
    $row = q('SELECT * FROM private_records WHERE user_id = ? AND kind = ?', [$userId, $kind])->fetch();
    if (!$row) {
        return null;
    }
    $row['data'] = json_decode((string) unseal($row['data']), true) ?: [];
    return $row;
}

function pathway_record(int $userId): ?array
{
    return private_record($userId, PATHWAY_KIND);
}

function pathway_save(int $userId, array $data): void
{
    private_record_save($userId, PATHWAY_KIND, $data);
}

/** Encrypt and save; the next reminder time is the only thing kept in the clear. */
function private_record_save(int $userId, string $kind, array $data): void
{
    $next = null;
    foreach ($data['reminders'] ?? [] as $r) {
        if (empty($r['sent']) && ($next === null || $r['at'] < $next)) {
            $next = $r['at'];
        }
    }
    q('INSERT INTO private_records (user_id, kind, data, remind_at) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE data = VALUES(data), remind_at = VALUES(remind_at)',
        [$userId, $kind, seal(json_encode($data, JSON_UNESCAPED_UNICODE)), $next]);
}

/** Delete everything, and forget it was ever unlocked or reminded about. */
function pathway_delete(int $userId): void
{
    private_record_delete($userId, PATHWAY_KIND);
}

function private_record_delete(int $userId, string $kind): void
{
    $record = private_record($userId, $kind);
    foreach ($record['data']['reminders'] ?? [] as $r) {
        q('DELETE FROM push_sent WHERE user_id = ? AND ref = ?', [$userId, 'private|' . $r['id']]);
    }
    q('DELETE FROM private_records WHERE user_id = ? AND kind = ?', [$userId, $kind]);
    unset($_SESSION[$kind . '_unlocked']);
}

/** No PIN, or unlocked in this session within the last few minutes (kept fresh while in use). */
function pathway_unlocked(array $record): bool
{
    return private_unlocked($record);
}

function private_unlocked(array $record): bool
{
    if ($record['pin_hash'] === null) {
        return true;
    }
    $key = $record['kind'] . '_unlocked';
    $at = (int) ($_SESSION[$key] ?? 0);
    if ($at > time() - PATHWAY_UNLOCK_MINUTES * 60) {
        $_SESSION[$key] = time();
        return true;
    }
    return false;
}

/** The pregnancy's start (first day of the last period, or worked back from a scan). */
function pathway_start(array $data): ?DateTimeImmutable
{
    return !empty($data['start']) ? new DateTimeImmutable($data['start']) : null;
}

/** [weeks, days] on $day, or null if no dates were given. */
function pathway_gestation(array $data, DateTimeImmutable $day): ?array
{
    $start = pathway_start($data);
    if (!$start) {
        return null;
    }
    $days = max(0, (int) $start->diff(new DateTimeImmutable($day->format('Y-m-d')))->format('%r%a'));
    return [intdiv($days, 7), $days % 7];
}

/** Add a reminder (UTC 'Y-m-d H:i:s'); its label is only ever shown on the page. */
function pathway_add_reminder(array &$data, string $atUtc, string $label, ?string $ref = null): void
{
    $data['reminders'] = array_values(array_filter($data['reminders'] ?? [], fn ($r) => $ref === null || ($r['ref'] ?? null) !== $ref));
    $data['reminders'][] = ['id' => bin2hex(random_bytes(6)), 'at' => $atUtc, 'label' => $label, 'ref' => $ref, 'sent' => false];
}

/** Scheduled task: due reminders, as "You have a reminder" whatever the user's notification settings. */
function send_private_reminders(DateTimeImmutable $now): array
{
    if (!push_supported()) {
        return [];
    }
    $sent = 0;
    foreach (q('SELECT user_id, kind FROM private_records WHERE remind_at IS NOT NULL AND remind_at <= ?', [$now->format('Y-m-d H:i:s')])->fetchAll() as $row) {
        $uid = (int) $row['user_id'];
        $record = private_record($uid, $row['kind']);
        $user = q('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid])->fetch();
        if (!$record || !$user) {
            continue;
        }
        $data = $record['data'];
        $due = false;
        foreach ($data['reminders'] ?? [] as $i => $r) {
            if (empty($r['sent']) && $r['at'] <= $now->format('Y-m-d H:i:s')) {
                $data['reminders'][$i]['sent'] = true;
                // Only remind about things from the last day (not a backlog after a long outage).
                $due = $due || ($r['at'] >= $now->modify('-1 day')->format('Y-m-d H:i:s') && push_once($uid, 'private|' . $r['id']));
            }
        }
        if ($due) {
            $sent += push_user(['push_private' => 1] + $user, ['title' => 'Pillbox', 'body' => 'You have a reminder.', 'url' => url(PRIVATE_PAGES[$row['kind']] ?? 'index.php'), 'tag' => 'reminder']);
        }
        private_record_save($uid, $row['kind'], $data);
    }
    return $sent ? ["Sent $sent reminder" . ($sent === 1 ? '' : 's') . '.'] : [];
}
