<?php
declare(strict_types=1);

/*
 * The documentation in docs/*.md, shown in the app at docs.php?p=<page> (and readable on GitHub).
 * A small, safe Markdown renderer: headings (with anchors), paragraphs, lists (one level of
 * nesting), code blocks, `code`, **bold** and [links]; links to other .md pages stay in the app.
 * help_link() makes the small "?" next to headings that opens the right page and section.
 */

const DOCS_DIR = APP_ROOT . '/docs';

// Care categories and the documentation page for each.
const CARE_DOC_PAGES = ['pregnancy' => 'pregnancy', 'pathway' => 'pregnancy-options', 'womens' => 'periods', 'menopause' => 'menopause', 'thyroid' => 'thyroid',
    'diabetes' => 'diabetes', 'bp' => 'blood-pressure', 'migraine' => 'migraine', 'neuro' => 'adhd-autism', 'gender' => 'gender-affirming-care', 'bloods' => 'blood-tests'];
// Everything else is a section of care-categories.md, headed with the category's title.

/** The "?" link to a documentation page (and section). */
function help_link(string $page, string $section = '', string $about = ''): string
{
    $href = url('docs.php?p=' . rawurlencode($page)) . ($section !== '' ? '#' . rawurlencode($section) : '');
    $label = 'Help' . ($about !== '' ? ': ' . $about : '');
    return '<a class="help-q" href="' . h($href) . '" title="' . h($label) . '" aria-label="' . h($label) . '">?</a>';
}

/** A page's Markdown, or null if there's no such page. */
function docs_source(string $page): ?string
{
    if (!preg_match('/^[A-Za-z0-9-]+$/', $page) || !is_file(DOCS_DIR . "/$page.md")) {
        return null;
    }
    return (string) file_get_contents(DOCS_DIR . "/$page.md");
}

function docs_slug(string $text): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(strip_tags($text))), '-');
}

/** Inline formatting on already-escaped text: `code`, **bold**, [text](link). */
function docs_inline(string $escaped): string
{
    $codes = [];
    $escaped = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . $m[1] . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $escaped);
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
    $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $target = html_entity_decode($m[2], ENT_QUOTES);
        if (preg_match('#^https?://#', $target)) {
            return '<a href="' . h($target) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
        }
        if (preg_match('#^([A-Za-z0-9-]+)\.md(\#[a-z0-9-]+)?$#', $target, $t)) {
            return '<a href="' . h(url('docs.php?p=' . $t[1]) . ($t[2] ?? '')) . '">' . $m[1] . '</a>';
        }
        if (str_starts_with($target, '#')) {
            return '<a href="' . h($target) . '">' . $m[1] . '</a>';
        }
        // Anything else (e.g. the project README): to the repository, if there is one.
        $repo = github_repo_url();
        return $repo ? '<a href="' . h($repo . '#readme') . '" target="_blank" rel="noopener">' . $m[1] . '</a>' : $m[1];
    }, $escaped);
    return preg_replace_callback("/\x01(\d+)\x01/", fn ($m) => $codes[(int) $m[1]], $escaped);
}

/** Render a page's Markdown as HTML. @return array{0: string, 1: string} [title, html] */
function docs_render(string $markdown): array
{
    $html = '';
    $title = '';
    $list = [];      // open list tags, by depth
    $para = [];
    $inCode = false;
    $flush = function () use (&$para, &$html) {
        if ($para) {
            $html .= '<p>' . docs_inline(h(implode(' ', $para))) . '</p>';
            $para = [];
        }
    };
    $closeLists = function (int $to = 0) use (&$list, &$html) {
        while (count($list) > $to) {
            $html .= '</li></' . array_pop($list) . '>';
        }
    };
    foreach (preg_split('/\R/', $markdown) as $line) {
        if (str_starts_with(ltrim($line), '```')) {
            $flush();
            $closeLists();
            $html .= $inCode ? '</code></pre>' : '<pre class="code"><code>';
            $inCode = !$inCode;
            continue;
        }
        if ($inCode) {
            $html .= h($line) . "\n";
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.*)$/', $line, $m)) {
            $flush();
            $closeLists();
            $level = strlen($m[1]);
            if ($level === 1 && $title === '') {
                $title = $m[2];
                continue; // the page heading is shown by the page itself
            }
            $html .= sprintf('<h%d id="%s">%s</h%d>', $level, h(docs_slug($m[2])), docs_inline(h($m[2])), $level);
            continue;
        }
        if (preg_match('/^(\s*)([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
            $flush();
            $depth = min(2, intdiv(strlen($m[1]), 2) + 1);
            $tag = ctype_digit(rtrim($m[2], '.')) ? 'ol' : 'ul';
            if (count($list) < $depth) {
                $html .= "<$tag>";
                $list[] = $tag;
            } else {
                $closeLists($depth);
                $html .= '</li>';
            }
            $html .= '<li>' . docs_inline(h($m[3]));
            continue;
        }
        if (trim($line) === '') {
            $flush();
            $closeLists();
            continue;
        }
        if ($list && preg_match('/^\s+\S/', $line)) {
            $html .= ' ' . docs_inline(h(trim($line))); // a list item's continuation line
            continue;
        }
        $closeLists();
        $para[] = trim($line);
    }
    $flush();
    $closeLists();
    if ($inCode) {
        $html .= '</code></pre>';
    }
    return [$title, $html];
}
