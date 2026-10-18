<?php
/*
 * Full text of a license listed under Admin > Version > Licenses (software_licenses() and
 * LICENSE_FILES in src/version.php), kept in the app instead of linking off to an external copy.
 */
require __DIR__ . '/../src/bootstrap.php';

require_admin();
$which = get('which');
$path = LICENSE_FILES[$which] ?? null;
$text = $path ? @file_get_contents(APP_ROOT . $path) : false;

if ($text === false) {
    http_response_code(404);
    render_header('License not found');
    echo '<section class="card"><h1>License not found</h1><p>That license isn\'t available. <a href="' . h(url('admin.php#version')) . '">Back to Version</a>.</p></section>';
    render_footer();
    exit;
}

$name = 'License';
foreach (software_licenses() as $lic) {
    if ($lic['key'] === $which) {
        $name = $lic['name'] . ' license';
        break;
    }
}

render_header($name);
?>
<section class="card">
    <h1><?= h($name) ?></h1>
    <p><a href="<?= h(url('admin.php#version')) ?>">&larr; Back to Version</a></p>
    <pre class="code"><?= h($text) ?></pre>
</section>
<?php render_footer();
