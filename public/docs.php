<?php
/*
 * Documentation: docs.php shows the contents, docs.php?p=<page> a page from docs/*.md.
 * Open to everyone (like the terms and privacy pages), signed in or not.
 */
require __DIR__ . '/../src/bootstrap.php';

$page = get('p') !== '' ? get('p') : 'README';
$source = docs_source($page);
if ($source === null) {
    http_response_code(404);
    $page = 'README';
    $source = (string) docs_source('README');
    flash('error', "That help page doesn't exist. Here's the list of pages.");
}
[$title, $html] = docs_render($source);

render_header($page === 'README' ? 'Help and documentation' : $title);
?>
<article class="card docs">
    <?php if ($page !== 'README'): ?><p class="muted small"><a href="<?= h(url('docs.php')) ?>">Documentation</a> &rsaquo;</p><?php endif; ?>
    <h1><?= h($page === 'README' ? 'Help and documentation' : $title) ?></h1>
    <?= $html ?>
    <?php if ($page !== 'README'): ?><p class="muted small"><a href="<?= h(url('docs.php')) ?>">&larr; All help pages</a></p><?php endif; ?>
</article>
<?php render_footer();
