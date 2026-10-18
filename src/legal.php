<?php
declare(strict_types=1);

/*
 * Terms of use and privacy policy (terms.php, privacy.php; linked in the footer and on the
 * sign-up page). The text is generated from what this Pillbox actually does and the details in
 * config 'legal' (operator, contact, governing law). An admin can replace either text in
 * Admin > Site settings. Simple formatting: "## " headings, "- " bullets, blank lines between
 * paragraphs. It's a starting point, not legal advice.
 */

const LEGAL_UPDATED = '11 October 2026';

/** Who runs this Pillbox, how to contact them, and which law applies. */
function legal_details(): array
{
    $name = (string) config('app.name', 'Pillbox');
    return [
        'app' => $name,
        'operator' => trim((string) config('legal.operator', '')) ?: "the person or organisation that runs this copy of $name",
        'contact' => trim((string) config('legal.contact', '')) ?: (string) config('mail.from', '') ?: 'the administrator of this site',
        'law' => trim((string) config('legal.jurisdiction', '')) ?: 'England and Wales',
        'url' => rtrim(absolute_url(''), '/'),
    ];
}

function legal_text(string $which): string
{
    $custom = trim((string) config("legal.$which", ''));
    return $custom !== '' ? $custom : ($which === 'terms' ? legal_default_terms() : legal_default_privacy());
}

function legal_default_terms(): string
{
    $d = legal_details();
    return <<<TXT
These terms apply to your use of {$d['app']} at {$d['url']}, provided by {$d['operator']} ("we"). By creating an account or using {$d['app']}, you agree to them. If you don't agree, please don't use it.

## Not medical advice
{$d['app']} helps you keep your own record of medicines, health readings, appointments and care. It does not give medical advice, diagnose anything or replace your doctor, nurse, pharmacist or other health professional.

- Always follow the instructions of the people who prescribe and care for you.
- Reminders can be late or fail (for example if your phone is offline or notifications are switched off). Don't rely on {$d['app']} alone for anything critical.
- Information, ranges and suggestions in {$d['app']} are general and may not apply to you.
- In an emergency, call 999. For urgent help that isn't an emergency, call NHS 111. If you're in crisis, the Help page lists people you can contact.

## Your account
- You must give accurate details and keep your sign-in details safe. You're responsible for what happens under your account.
- Accounts are for one person each. If you look after someone else, ask them to share their {$d['app']} with you (Sharing), or create an account for them with their agreement.
- You can delete your account at any time from Account settings.
- New accounts may need an administrator's approval.

## Sharing
If you share your {$d['app']} with someone, they can see (and, if you allow it, change) the parts you choose until you stop sharing or the end date you set. You're responsible for who you share with. People you share with must respect your privacy.

## Acceptable use
Don't use {$d['app']} to break the law, to access other people's data without permission, to upload anything harmful (such as malware), or to try to disrupt or get around the security of the service.

## Availability and changes
We aim to keep {$d['app']} running and your data safe, but we can't promise it will always be available or free of errors. We may change, suspend or stop the service. Keep your own copy of anything important: you can download all your data from Account settings at any time.

## Liability
{$d['app']} is provided "as is". As far as the law allows, we're not liable for any loss arising from using it, including missed or wrong doses, missed appointments, or decisions made using information in {$d['app']}. Nothing in these terms limits liability that can't legally be limited, such as for death or personal injury caused by negligence, or for fraud.

## Ending your use
You can stop using {$d['app']} and delete your account at any time. We may suspend or close an account that breaks these terms, or that has been inactive for a long time, after trying to contact you where we can.

## The software
{$d['app']} runs on Pillbox, free software released under the GNU General Public License v3.0.

## Changes to these terms
We may update these terms. If the changes are significant, we'll tell you (for example in the app or by email). Continuing to use {$d['app']} after a change means you accept the new terms.

## Law
These terms are governed by the law of {$d['law']}, and its courts have jurisdiction.

## Contact
Questions about these terms: {$d['contact']}.
TXT;
}

function legal_default_privacy(): string
{
    $d = legal_details();
    $connections = [];
    foreach (CONNECTORS as $key => $c) {
        if (connector_configured($key)) {
            $connections[] = $c['name'];
        }
    }
    $optional = array_filter([
        mail_enabled() ? '- **Email**: if you turn on email summaries or reminders, they are sent through our email provider to your address.' : null,
        '- **Phone and browser notifications**: if you turn them on, encrypted messages pass through your device maker\'s push service (such as Apple, Google or Mozilla), which can\'t read them.',
        $connections ? '- **Connected apps** (' . implode(', ', $connections) . '): only if you connect one. We then fetch readings from that service using the access you gave, and you can disconnect at any time.' : null,
        dmd_enabled() ? '- **NHS medicines dictionary**: when you search for a medicine, only the words you type are sent to the NHS England Terminology Server, never anything about you.' : null,
        '- **Apple Health and Health Connect**: only if you import a file or set up the sync link yourself.',
        weather_enabled() ? '- **Weather** (Migraine): only if you add your town. Its location, to about 10 km, is sent to Open-Meteo to get the weather there; nothing about you or your headaches is sent.' : null,
        sso_enabled() ? '- **Single sign-on**: if you sign in with a provider (such as Microsoft or Google), they tell us who you are (name, email and an ID). We don\'t get your password.' : null,
        config('app.geoip_lookup', false) ? '- **Active sessions location**: the IP address of each device you\'re signed in on is sent to an IP-lookup service (ipapi.co) to show its approximate city and country under Account → Active sessions.' : null,
    ]);
    $optionalText = implode("\n", $optional);
    return <<<TXT
This policy explains what {$d['app']} at {$d['url']} does with your information. The data controller is {$d['operator']} ("we"). We follow UK data protection law (the UK GDPR and the Data Protection Act 2018).

## What we hold
- **Account details**: your name, username or email, password (stored only as a secure hash), time zone, settings, and how you sign in.
- **Health information** you choose to add: medicines and doses, health readings, appointments, Care records (such as screening, periods, pregnancy or menopause), Mind check-ins and journal, blood test results, and anything else you type in. Optional profile details such as date of birth, pronouns, gender identity and body inventory.
- **Technical information**: a session cookie to keep you signed in, and short-lived records of sign-in attempts (to protect against password guessing) and of what has been sent to you, so nothing is sent twice.

## Why, and our lawful basis
We use your information only to provide {$d['app']} to you: showing your records, reminders and summaries, and the features you switch on. Health information is special category data, so we only process it with your explicit consent, which you give by adding it. You can withdraw consent at any time by deleting the information or your account. Account details are processed to provide the service you asked for (contract).

We don't sell your information, show adverts, use tracking or analytics, or use your information for anything else.

## Where it's kept and who can see it
Your information is stored on the server that runs this {$d['app']}. It's protected by encrypted connections (HTTPS), hashed passwords and access controls.

- **You**, and anyone you choose to share your {$d['app']} with, for the parts and time you choose.
- **Private pages** (Pregnancy options and Gender-affirming care) are encrypted, never shared, and left out of backups and data downloads. Medicines marked Private are hidden from people you share with, and reminders for them don't name them.
- **Administrators** of this site manage accounts. They don't look at your health information, but whoever runs the server can technically access its database and backups.

## Other services
{$optionalText}

## Cookies
We use one cookie, which keeps you signed in. It's strictly necessary, so we don't ask for consent. We don't use any other cookies or third-party tracking.

## How long we keep it
Until you delete it, or delete your account. Deleting your account removes your information from {$d['app']} straight away. Copies may remain in the server's own backups for a limited time, until they're replaced. Accounts that are no longer used may be deleted after we've tried to contact you.

## Your rights
You can:
- **see and download** your information: Account settings, Download all my data
- **correct** it, by editing it in {$d['app']}
- **delete** it, or your whole account, at any time
- **take it elsewhere**: the download can be imported into another Pillbox
- **object to or restrict** how we use it, and **withdraw consent**, by contacting us

If you're unhappy with how we've handled your information, please contact us first. You can also complain to the Information Commissioner's Office (ico.org.uk, 0303 123 1113).

## Children
{$d['app']} isn't intended for children under 13. A child under 13 should only use it with a parent or carer who manages the account for them.

## Changes
We'll update this policy if what we do changes, and tell you about significant changes.

## Contact
Questions about your information or this policy: {$d['contact']}.
TXT;
}

/** Render the simple formatting ("## " headings, "- " bullets, **bold**) as safe HTML. */
function legal_html(string $text): string
{
    $html = '';
    $inList = false;
    foreach (preg_split('/\R/', $text) as $line) {
        $line = rtrim($line);
        $safe = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', h($line));
        if (str_starts_with($line, '- ')) {
            $html .= ($inList ? '' : '<ul>') . '<li>' . substr($safe, 2) . '</li>';
            $inList = true;
            continue;
        }
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
        if (str_starts_with($line, '## ')) {
            $html .= '<h2>' . substr($safe, 3) . '</h2>';
        } elseif ($line !== '') {
            $html .= '<p>' . $safe . '</p>';
        }
    }
    return $html . ($inList ? '</ul>' : '');
}

/** The terms or privacy page (terms.php, privacy.php). */
function render_legal_page(string $which): void
{
    $title = $which === 'terms' ? 'Terms of use' : 'Privacy policy';
    render_header($title);
    ?>
<article class="card legal">
    <h1><?= h($title) ?></h1>
    <p class="muted small">Last updated <?= h(trim((string) config("legal.{$which}_updated", '')) ?: LEGAL_UPDATED) ?></p>
    <?= legal_html(legal_text($which)) ?>
    <p class="muted small"><a href="<?= h(url($which === 'terms' ? 'privacy.php' : 'terms.php')) ?>"><?= $which === 'terms' ? 'Privacy policy' : 'Terms of use' ?></a></p>
</article>
    <?php
    render_footer();
}
