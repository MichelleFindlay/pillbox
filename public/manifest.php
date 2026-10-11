<?php
/*
 * The web app manifest when the person has chosen a disguised name and icon (Account > Privacy).
 * Otherwise layout.php links the static manifest.webmanifest.
 */
require __DIR__ . '/../src/bootstrap.php';

$manifest = json_decode((string) file_get_contents(__DIR__ . '/manifest.webmanifest'), true);
if ($d = user_disguise()) {
    $icon = fn (string $size, string $purpose) => ['src' => asset('assets/icons/' . $d['icon'] . "-$size.png"), 'sizes' => "{$size}x$size", 'type' => 'image/png', 'purpose' => $purpose];
    $manifest = ['name' => $d['name'], 'short_name' => $d['name'], 'description' => $d['name'], 'categories' => ['productivity'], 'theme_color' => $d['color'],
        'icons' => [$icon('192', 'any'), $icon('512', 'any'), $icon('512', 'maskable')], 'shortcuts' => []] + $manifest;
    $manifest['start_url'] = url('index.php?source=app');
    $manifest['scope'] = url('');
}
header('Content-Type: application/manifest+json');
header('Cache-Control: no-store');
echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
