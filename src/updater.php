<?php
declare(strict_types=1);

/*
 * Built-in updater (Admin page): download the latest GitHub release, check it, back up
 * the current files to storage/backups/, then copy the new files over the old ones.
 * config/config.php, storage/ and .git are never touched. The database updates itself on
 * the next page load (ensure_schema). Backups can be restored the same way.
 */

const UPDATE_KEEP = ['config/config.php', 'storage', '.git'];   // never overwritten or backed up
const UPDATE_MAX_BYTES = 60 * 1024 * 1024;

/** What stops the updater working here, if anything. @return string[] problems */
function updater_problems(): array
{
    $problems = [];
    if (!class_exists('ZipArchive')) {
        $problems[] = "PHP's zip extension isn't installed, so releases can't be unpacked.";
    }
    if (!function_exists('curl_init')) {
        $problems[] = "PHP's curl extension isn't installed, so releases can't be downloaded.";
    }
    if (is_dir(APP_ROOT . '/.git')) {
        $problems[] = 'This copy is a git checkout: update it with "git pull" instead.';
    }
    foreach (['', '/public', '/src', '/database', '/config', '/bin'] as $dir) {
        if (is_dir(APP_ROOT . $dir) && !is_writable(APP_ROOT . $dir)) {
            $problems[] = 'The web server can\'t write to ' . ($dir === '' ? 'the Pillbox folder' : ltrim($dir, '/') . '/') . ', so it can\'t replace the files.';
        }
    }
    return $problems;
}

function updater_dir(string $sub): string
{
    $dir = storage_path($sub);
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
    return $dir;
}

/** Is this path (relative to the Pillbox folder) one the updater must leave alone? */
function update_kept(string $relative): bool
{
    foreach (UPDATE_KEEP as $keep) {
        if ($relative === $keep || str_starts_with($relative, $keep . '/')) {
            return true;
        }
    }
    return false;
}

/** Download and install the latest release. @return string what happened */
function updater_install(array $latest): string
{
    if ($problems = updater_problems()) {
        throw new RuntimeException($problems[0]);
    }
    if (!update_available($latest) || !$latest['zip']) {
        throw new RuntimeException('There is no newer release to install.');
    }
    $zipFile = updater_dir('updates') . '/pillbox-' . preg_replace('/[^0-9A-Za-z.\-]/', '', $latest['version']) . '.zip';
    updater_download($latest['zip'], $zipFile);

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('The download is not a valid zip file.');
    }
    // GitHub wraps everything in one top-level folder ("Owner-repo-abc123/").
    $prefix = explode('/', (string) $zip->getNameIndex(0))[0] . '/';
    $version = trim((string) $zip->getFromName($prefix . 'VERSION'));
    if ($version !== $latest['version'] || $zip->locateName($prefix . 'src/bootstrap.php') === false || $zip->locateName($prefix . 'public/index.php') === false) {
        $zip->close();
        throw new RuntimeException("The download doesn't look like Pillbox {$latest['version']}, so nothing was changed.");
    }

    $backup = updater_backup();

    $written = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $relative = substr($name, strlen($prefix));
        if (!str_starts_with($name, $prefix) || $relative === '' || str_ends_with($name, '/') || str_contains($relative, '..') || update_kept($relative)) {
            continue;
        }
        $target = APP_ROOT . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        // Write next to the file, then swap it in, so a page never reads half a file.
        $tmp = $target . '.pillbox-update';
        if (file_put_contents($tmp, $zip->getFromIndex($i)) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            $zip->close();
            throw new RuntimeException("Couldn't write $relative. Some files may have been updated: restore the backup " . basename($backup) . ' if Pillbox misbehaves.');
        }
        $written++;
    }
    $zip->close();
    @unlink($zipFile);
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    updater_log("Updated from " . pillbox_version() . " to {$latest['version']} ($written files). Backup: " . basename($backup));
    return "Updated to {$latest['version']}. A backup of the previous version was saved as " . basename($backup) . '.';
}

function updater_download(string $url, string $file): void
{
    $out = fopen($file, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array_filter(['User-Agent: Pillbox/' . pillbox_version(), 'Accept: application/vnd.github+json',
            (string) config('app.github_token', '') !== '' ? 'Authorization: Bearer ' . config('app.github_token') : null]),
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn ($ch, $total, $done) => $done > UPDATE_MAX_BYTES ? 1 : 0,
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    fclose($out);
    if (!$ok || $status !== 200 || filesize($file) < 1000) {
        @unlink($file);
        throw new RuntimeException("The release couldn't be downloaded from GitHub (HTTP $status). Try again later.");
    }
}

/** Zip the current files (not config.php, storage/ or .git). @return string the backup's path */
function updater_backup(): string
{
    $file = updater_dir('backups') . '/pillbox-' . preg_replace('/[^0-9A-Za-z.\-]/', '', pillbox_version()) . '-' . gmdate('Ymd-His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException("Couldn't create a backup in storage/backups/, so nothing was changed.");
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $path => $info) {
        $relative = substr($path, strlen(APP_ROOT) + 1);
        if ($info->isFile() && !update_kept(str_replace('\\', '/', $relative))) {
            $zip->addFile($path, str_replace('\\', '/', $relative));
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException("Couldn't finish the backup, so nothing was changed.");
    }
    return $file;
}

/** Backups available to restore, newest first. */
function updater_backups(): array
{
    $files = glob(storage_path('backups') . '/pillbox-*.zip') ?: [];
    usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}

/** Put a backup's files back. */
function updater_restore(string $name): string
{
    if (!preg_match('/^pillbox-[0-9A-Za-z.\-]+\.zip$/', $name) || !is_file($file = storage_path('backups') . '/' . $name)) {
        throw new RuntimeException('That backup was not found.');
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true || $zip->locateName('src/bootstrap.php') === false) {
        throw new RuntimeException("That backup can't be read.");
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $relative = (string) $zip->getNameIndex($i);
        if (str_ends_with($relative, '/') || str_contains($relative, '..') || update_kept($relative)) {
            continue;
        }
        $target = APP_ROOT . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        file_put_contents($target . '.pillbox-update', $zip->getFromIndex($i));
        rename($target . '.pillbox-update', $target);
    }
    $version = trim((string) $zip->getFromName('VERSION'));
    $zip->close();
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    updater_log("Restored backup $name (version $version).");
    return "Restored version $version from $name.";
}

function updater_log(string $line): void
{
    @file_put_contents(storage_path('update.log'), gmdate('Y-m-d H:i:s') . " UTC  $line\n", FILE_APPEND | LOCK_EX);
}
