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

/**
 * The latest release on GitHub, cached for 12 hours (1 hour after a failure).
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
    if (!$refresh && is_file($cache)) {
        $cached = json_decode((string) file_get_contents($cache), true);
        if (is_array($cached) && array_key_exists('zip', $cached) && $cached['checked_at'] > time() - ($cached['error'] ? 3600 : 43200)) {
            return $cached;
        }
    }

    $headers = ['Accept: application/vnd.github+json', 'User-Agent: Pillbox/' . pillbox_version(), 'X-GitHub-Api-Version: 2022-11-28'];
    if ((string) config('app.github_token', '') !== '') {
        $headers[] = 'Authorization: Bearer ' . config('app.github_token');
    }
    $ch = curl_init('https://api.github.com/repos/' . $repo . '/releases/latest');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_HTTPHEADER => $headers]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $release = is_string($body) ? json_decode($body, true) : null;

    $result = ['version' => null, 'tag' => null, 'zip' => null, 'url' => null, 'name' => null, 'published_at' => null, 'checked_at' => time(), 'error' => null];
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
    }
    @file_put_contents($cache, json_encode($result), LOCK_EX);
    return $result;
}

function update_available(?array $latest): bool
{
    return $latest !== null && $latest['version'] !== null && version_compare($latest['version'], pillbox_version(), '>');
}
