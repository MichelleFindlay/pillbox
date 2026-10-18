<?php
declare(strict_types=1);

/*
 * Version number and update check. The installed version is in the VERSION file; the
 * latest is the newest published release on GitHub (config: app.github_repo), whose tag
 * is the version number, e.g. "v1.1.0" or "1.1.0". Drafts and pre-releases are ignored.
 * app.github_token is optional: only needed if the repository is private.
 */

function pillbox_version(): string
{
    static $version = null;
    return $version ??= trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: 'unknown';
}

function github_repo_url(): ?string
{
    $repo = trim((string) config('app.github_repo', 'MichelleFindlay/pillbox'), '/');
    return preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? 'https://github.com/' . $repo : null;
}

// Licenses shown under Admin > Version > Licenses, and the file each 'key' reads (license.php),
// kept in the app rather than linking off to an external copy. Update both when vendoring
// something new (see vendor/<name>/).
const LICENSE_FILES = [
    'pillbox' => '/LICENSE',
    'qrcode-generator' => '/vendor/qrcode-generator/LICENSE',
];

/** @return list<array{key: string, name: string, license: string, note: string}> */
function software_licenses(): array
{
    return [
        ['key' => 'pillbox', 'name' => (string) config('app.name', 'Pillbox'), 'license' => 'GNU General Public License v3.0',
            'note' => 'The application itself.'],
        ['key' => 'qrcode-generator', 'name' => 'qrcode-generator', 'license' => 'MIT License',
            'note' => 'Renders the QR code for setting up an authenticator app (Account → Password & security). Copyright (c) 2009 Kazuhiko Arase.'],
    ];
}

/**
 * The latest release on GitHub, cached for 12 hours (1 hour after a failure). The scheduled
 * tasks refresh it on every run (GitHub allows 60 requests an hour without a token; with
 * app.github_token, unchanged answers sent back for the ETag don't count). A failed check keeps
 * the last release that was found.
 * @return array{version: ?string, tag: ?string, zip: ?string, url: ?string, name: ?string, published_at: ?string, checked_at: int, error: ?string}|null
 *         null when checking is switched off
 */
function latest_version(bool $refresh = false): ?array
{
    $repo = trim((string) config('app.github_repo', 'MichelleFindlay/pillbox'), '/');
    if (!config('app.update_check', true) || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        return null;
    }
    $cache = storage_path('version_check.json');
    $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    $cached = is_array($cached) && array_key_exists('zip', $cached) ? $cached : null;
    if (!$refresh && $cached && $cached['checked_at'] > time() - ($cached['error'] ? 3600 : 43200)) {
        return $cached;
    }

    $headers = ['Accept: application/vnd.github+json', 'User-Agent: Pillbox/' . pillbox_version(), 'X-GitHub-Api-Version: 2022-11-28'];
    if ((string) config('app.github_token', '') !== '') {
        $headers[] = 'Authorization: Bearer ' . config('app.github_token');
    }
    if ($cached && $cached['version'] !== null && !empty($cached['etag'])) {
        $headers[] = 'If-None-Match: ' . $cached['etag'];
    }
    $ch = curl_init('https://api.github.com/repos/' . $repo . '/releases/latest');
    $etag = null;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$etag) {
            if (preg_match('/^etag:\s*(\S+)/i', $line, $m)) {
                $etag = $m[1];
            }
            return strlen($line);
        }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $release = is_string($body) ? json_decode($body, true) : null;

    if ($status === 304 && $cached) {
        $result = ['checked_at' => time(), 'error' => null] + $cached;
        @file_put_contents($cache, json_encode($result), LOCK_EX);
        return $result;
    }
    $result = ['version' => null, 'tag' => null, 'zip' => null, 'url' => null, 'name' => null, 'published_at' => null, 'checked_at' => time(), 'error' => null, 'etag' => $etag];
    $tag = is_array($release) ? preg_replace('/^v(?=\d)/i', '', trim((string) ($release['tag_name'] ?? ''))) : '';
    if ($status === 200 && preg_match('/^\d+(\.\d+){0,3}([-+][\w.]+)?$/', $tag)) {
        $result['version'] = $tag;
        $result['tag'] = (string) $release['tag_name'];
        $result['zip'] = (string) ($release['zipball_url'] ?? '');
        $result['url'] = (string) ($release['html_url'] ?? '');
        $result['name'] = (string) ($release['name'] ?? '') ?: null;
        $result['published_at'] = (string) ($release['published_at'] ?? '') ?: null;
    } else {
        $result['error'] = match (true) {
            $body === false => 'GitHub could not be reached.',
            $status === 200 => "The latest release's tag (" . ($release['tag_name'] ?? '?') . ") isn't a version number like v1.2.0.",
            $status === 404 => 'No release has been published on GitHub yet.',
            $status === 403, $status === 429 => "GitHub's rate limit was reached; Pillbox will try again later.",
            $status === 401 => 'GitHub refused the request; check the GitHub token.',
            default => "GitHub returned an unexpected response (HTTP $status).",
        };
        if ($cached && $cached['version'] !== null) {
            // Keep the last release found, so a failed check doesn't hide an available update.
            $result = ['checked_at' => time(), 'error' => $result['error']] + $cached;
        }
    }
    @file_put_contents($cache, json_encode($result), LOCK_EX);
    return $result;
}

function update_available(?array $latest): bool
{
    return $latest !== null && $latest['version'] !== null && version_compare($latest['version'], pillbox_version(), '>');
}

/** True when the installed VERSION is newer than the latest published release: a development checkout. */
function version_is_dev(?array $latest): bool
{
    return $latest !== null && $latest['version'] !== null && version_compare(pillbox_version(), $latest['version'], '>');
}

/**
 * The last 15 published releases on GitHub, with their release notes, cached for 12 hours
 * (1 hour after a failure) so the changelog can be shown in the app instead of linking out.
 * @return array{releases: list<array{version: string, tag: string, name: ?string, body: string, published_at: ?string, url: string}>, checked_at: int, error: ?string}|null
 *         null when checking is switched off
 */
function changelog_releases(bool $refresh = false): ?array
{
    $repo = trim((string) config('app.github_repo', 'MichelleFindlay/pillbox'), '/');
    if (!config('app.update_check', true) || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        return null;
    }
    $cache = storage_path('changelog.json');
    $cached = is_file($cache) ? json_decode((string) file_get_contents($cache), true) : null;
    $cached = is_array($cached) && array_key_exists('releases', $cached) ? $cached : null;
    if (!$refresh && $cached && $cached['checked_at'] > time() - ($cached['error'] ? 3600 : 43200)) {
        return $cached;
    }

    $headers = ['Accept: application/vnd.github+json', 'User-Agent: Pillbox/' . pillbox_version(), 'X-GitHub-Api-Version: 2022-11-28'];
    if ((string) config('app.github_token', '') !== '') {
        $headers[] = 'Authorization: Bearer ' . config('app.github_token');
    }
    $ch = curl_init('https://api.github.com/repos/' . $repo . '/releases?per_page=15');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $list = is_string($body) ? json_decode($body, true) : null;

    $result = ['releases' => [], 'checked_at' => time(), 'error' => null];
    if ($status === 200 && is_array($list)) {
        foreach ($list as $release) {
            if (!empty($release['draft']) || !empty($release['prerelease'])) {
                continue;
            }
            $tag = preg_replace('/^v(?=\d)/i', '', trim((string) ($release['tag_name'] ?? '')));
            if (!preg_match('/^\d+(\.\d+){0,3}([-+][\w.]+)?$/', $tag)) {
                continue;
            }
            $result['releases'][] = ['version' => $tag, 'tag' => (string) $release['tag_name'], 'name' => (string) ($release['name'] ?? '') ?: null,
                'body' => (string) ($release['body'] ?? ''), 'published_at' => (string) ($release['published_at'] ?? '') ?: null, 'url' => (string) ($release['html_url'] ?? '')];
        }
    } else {
        $result['error'] = match (true) {
            $body === false => 'GitHub could not be reached.',
            $status === 403, $status === 429 => "GitHub's rate limit was reached; Pillbox will try again later.",
            $status === 401 => 'GitHub refused the request; check the GitHub token.',
            default => "GitHub returned an unexpected response (HTTP $status).",
        };
        if ($cached && $cached['releases']) {
            $result['releases'] = $cached['releases']; // keep the last changelog found
        }
    }
    @file_put_contents($cache, json_encode($result), LOCK_EX);
    return $result;
}
