<?php
declare(strict_types=1);

/*
 * "Fill in from a document" (Private health care): read the text of a policy document and pick out
 * the plan's details, for the person to check before saving. It all happens on this server: the
 * document isn't kept, or sent anywhere.
 * - document_text(): text from a PDF (text-based, as insurers send them: FlateDecode streams,
 *   object streams, fonts with ToUnicode maps or WinAnsi), a Word .docx, or plain text / HTML.
 *   Scans (images only) have no text to read.
 * - policy_details(): the plan fields found in that text, by label and keyword.
 */

const POLICY_MAX_BYTES = 15 * 1024 * 1024;

// UK health insurers, cash plans and dental plans, to recognise the provider.
const POLICY_PROVIDERS = ['Bupa', 'AXA Health', 'AXA PPP', 'Aviva', 'Vitality', 'WPA', 'Freedom Health', 'The Exeter', 'Exeter Friendly', 'National Friendly',
    'Saga', 'CS Healthcare', 'Benenden Health', 'Cigna', 'Allianz Care', 'Simplyhealth', 'Westfield Health', 'Medicash', 'Health Shield', 'Paycare',
    'BHSF', 'Sovereign Health Care', 'HealthSure', 'Hospital Saturday Fund', 'Denplan', 'Practice Plan', 'Dentalplan', 'Bupa Dental', 'Simplyhealth Professionals',
    'Aviva Expert Select', 'General & Medical', 'Equipsme', 'Holloway Friendly'];

/** The text of an uploaded document, or null if it has none that can be read. */
function document_text(string $path, string $filename = ''): ?string
{
    $head = (string) file_get_contents($path, false, null, 0, 8);
    if (str_starts_with($head, '%PDF')) {
        $text = pdf_text((string) file_get_contents($path));
    } elseif (str_starts_with($head, "PK\x03\x04") && class_exists('ZipArchive')) {
        $text = docx_text($path);
    } else {
        $raw = (string) file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $text = preg_match('/<(html|body|p|div|table)\b/i', $raw) ? html_text($raw) : $raw;
    }
    $text = trim((string) preg_replace(["/[ \t\x{00A0}]+/u", "/\n\s*\n+/"], [' ', "\n"], (string) $text));
    return mb_strlen($text) >= 20 ? $text : null;
}

function html_text(string $html): string
{
    $html = preg_replace(['#<(script|style)\b.*?</\1>#is', '#<br\s*/?>|</(p|div|tr|li|h\d)>#i'], ['', "\n"], $html);
    return html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function docx_text(string $path): ?string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return null;
    }
    $xml = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br/>#'], ["\n", "\t", "\n"], $xml);
    return html_entity_decode(strip_tags((string) $xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ---------- PDF ---------- */

/** All objects in a PDF: number => ['dict' => string, 'stream' => ?string (decoded)]. */
function pdf_objects(string $pdf): array
{
    $objects = [];
    preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $pdf, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as $i => [$header, $start]) {
        $num = (int) $m[1][$i][0];
        $bodyStart = $start + strlen($header);
        $end = strpos($pdf, 'endobj', $bodyStart);
        if ($end === false) {
            continue;
        }
        $body = substr($pdf, $bodyStart, $end - $bodyStart);
        $stream = null;
        $dict = $body;
        if (($s = strpos($body, 'stream')) !== false && preg_match('/^stream\r?\n/', substr($body, $s, 8), $sm)) {
            $dict = substr($body, 0, $s);
            $dataStart = $s + strlen($sm[0]);
            $length = preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $lm) ? (int) $lm[1] : null;
            $raw = $length !== null && $length <= strlen($body) - $dataStart ? substr($body, $dataStart, $length)
                : substr($body, $dataStart, max(0, (int) strrpos($body, 'endstream') - $dataStart));
            $stream = pdf_decode_stream($dict, $raw);
        }
        $objects[$num] = ['dict' => $dict, 'stream' => $stream];
    }
    // PDF 1.5 object streams: dictionaries packed inside a stream.
    foreach ($objects as $obj) {
        if ($obj['stream'] !== null && str_contains($obj['dict'], '/ObjStm') && preg_match('#/N\s+(\d+)#', $obj['dict'], $n) && preg_match('#/First\s+(\d+)#', $obj['dict'], $f)) {
            $pairs = preg_split('/\s+/', trim(substr($obj['stream'], 0, (int) $f[1])));
            for ($k = 0; $k + 1 < count($pairs) && $k < 2 * (int) $n[1]; $k += 2) {
                $from = (int) $f[1] + (int) $pairs[$k + 1];
                $to = isset($pairs[$k + 3]) ? (int) $f[1] + (int) $pairs[$k + 3] : strlen($obj['stream']);
                $objects[(int) $pairs[$k]] ??= ['dict' => substr($obj['stream'], $from, $to - $from), 'stream' => null];
            }
        }
    }
    return $objects;
}

function pdf_decode_stream(string $dict, string $raw): ?string
{
    if (!str_contains($dict, '/Filter')) {
        return $raw;
    }
    if (preg_match('#/Filter\s*\[?\s*/FlateDecode\s*\]?#', $dict)) {
        $out = @gzuncompress($raw);
        if ($out === false) {
            $out = @gzinflate(substr($raw, 2));
        }
        return $out === false ? null : $out;
    }
    return null; // other filters (images, mostly) aren't needed for text
}

function pdf_ref(string $dict, string $key): ?int
{
    return preg_match('#/' . $key . '\s+(\d+)\s+\d+\s+R#', $dict, $m) ? (int) $m[1] : null;
}

/** A sub-dictionary "<< ... >>" after /Key, inline or by reference. */
function pdf_subdict(array $objects, string $dict, string $key): string
{
    if ($ref = pdf_ref($dict, $key)) {
        return $objects[$ref]['dict'] ?? '';
    }
    $p = strpos($dict, '/' . $key);
    if ($p === false || ($o = strpos($dict, '<<', $p)) === false) {
        return '';
    }
    for ($depth = 0, $i = $o, $n = strlen($dict); $i < $n - 1; $i++) {
        if ($dict[$i] === '<' && $dict[$i + 1] === '<') {
            $depth++;
            $i++;
        } elseif ($dict[$i] === '>' && $dict[$i + 1] === '>') {
            if (--$depth === 0) {
                return substr($dict, $o, $i + 2 - $o);
            }
            $i++;
        }
    }
    return '';
}

/** A ToUnicode CMap: [bytes per code, code => text]. */
function pdf_cmap(string $cmap): array
{
    $map = [];
    $bytes = preg_match('/begincodespacerange\s*<([0-9a-fA-F]+)>/', $cmap, $cs) ? max(1, intdiv(strlen($cs[1]), 2)) : 2;
    $hex = fn (string $h) => mb_convert_encoding((string) hex2bin(strlen($h) % 2 ? '0' . $h : $h), 'UTF-8', 'UTF-16BE');
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $b) {
            preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]*)>/', $b, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $p) {
                $map[hexdec($p[1])] = $hex($p[2]);
            }
        }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
        foreach ($blocks[1] as $b) {
            preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*(<[0-9a-fA-F]+>|\[[^\]]*\])/', $b, $ranges, PREG_SET_ORDER);
            foreach ($ranges as [, $lo, $hi, $dst]) {
                $lo = hexdec($lo);
                $hi = min(hexdec($hi), $lo + 5000);
                if ($dst[0] === '[') {
                    preg_match_all('/<([0-9a-fA-F]+)>/', $dst, $items);
                    foreach ($items[1] as $k => $h) {
                        $map[$lo + $k] = $hex($h);
                    }
                } else {
                    $start = hexdec(trim($dst, '<>'));
                    for ($c = $lo; $c <= $hi; $c++) {
                        $map[$c] = (string) mb_chr($start + $c - $lo, 'UTF-8'); // not ?:, which would drop "0"
                    }
                }
            }
        }
    }
    return [$bytes, $map];
}

/** Bytes from a PDF literal string's contents (escapes resolved). */
function pdf_literal(string $s): string
{
    return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/', function ($m) {
        $c = $m[1];
        return match (true) {
            ctype_digit($c) => chr(octdec($c) & 255),
            $c === 'n' => "\n", $c === 'r' => "\r", $c === 't' => "\t", $c === 'b' => "\x08", $c === 'f' => "\x0C",
            $c[0] === "\r" || $c[0] === "\n" => '',
            default => $c,
        };
    }, $s) ?? $s;
}

/** Text shown by a string in a font: through its ToUnicode map, or as Windows-1252. */
function pdf_show(string $bytes, ?array $font): string
{
    if ($font) {
        [$n, $map] = $font;
        $out = '';
        for ($i = 0, $len = strlen($bytes); $i + $n <= $len; $i += $n) {
            $out .= $map[hexdec(bin2hex(substr($bytes, $i, $n)))] ?? '';
        }
        return $out;
    }
    return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
}

/** The text of a content stream, with line breaks where the text moves down the page. */
function pdf_content_text(string $content, array $fonts): string
{
    $out = '';
    $stack = [];
    $font = null;
    $size = 12.0;
    $lastY = null;
    $lineX = null; // where the current piece of text started along the line
    $run = 0;      // characters shown since then (about half the font size wide each)
    $n = strlen($content);
    for ($i = 0; $i < $n; $i++) {
        $c = $content[$i];
        if ($c === '%') {
            $i = strpos($content, "\n", $i) ?: $n;
        } elseif ($c === '(') {
            $depth = 1;
            $s = '';
            for ($i++; $i < $n && $depth; $i++) {
                $ch = $content[$i];
                if ($ch === '\\') {
                    $s .= $ch . ($content[++$i] ?? '');
                    continue;
                }
                $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
                if ($depth) {
                    $s .= $ch;
                }
            }
            $i--;
            $stack[] = ['s', pdf_literal($s)];
        } elseif ($c === '<' && ($content[$i + 1] ?? '') !== '<') {
            $end = strpos($content, '>', $i);
            $h = preg_replace('/[^0-9a-fA-F]/', '', substr($content, $i + 1, $end - $i - 1));
            $stack[] = ['s', (string) hex2bin(strlen($h) % 2 ? $h . '0' : $h)];
            $i = $end;
        } elseif ($c === '[') {
            $stack[] = ['['];
        } elseif ($c === ']') {
            $arr = [];
            while ($stack && end($stack)[0] !== '[') {
                array_unshift($arr, array_pop($stack));
            }
            array_pop($stack);
            $stack[] = ['a', $arr];
        } elseif ($c === '/') {
            preg_match('/\/[^\s\/\[\]()<>{}%]*/', $content, $m, 0, $i);
            $stack[] = ['n', substr($m[0], 1)];
            $i += strlen($m[0]) - 1;
        } elseif (preg_match('/[0-9+\-.]/', $c)) {
            preg_match('/[0-9+\-.]+/', $content, $m, 0, $i);
            $stack[] = ['#', (float) $m[0]];
            $i += strlen($m[0]) - 1;
        } elseif (ctype_alpha($c) || $c === "'" || $c === '"' || $c === '*') {
            preg_match('/[A-Za-z*\'"0-9]+/', $content, $m, 0, $i);
            $op = $m[0];
            $i += strlen($op) - 1;
            $nums = array_values(array_map(fn ($t) => $t[1], array_filter($stack, fn ($t) => $t[0] === '#')));
            switch ($op) {
                case 'Tf':
                    foreach ($stack as $t) {
                        if ($t[0] === 'n') {
                            $font = $fonts[$t[1]] ?? null;
                        }
                    }
                    $size = abs((float) ($nums[0] ?? 12)) ?: 12.0;
                    break;
                case 'Tj':
                case "'":
                case '"':
                    if ($op !== 'Tj') {
                        $out .= "\n";
                    }
                    foreach ($stack as $t) {
                        if ($t[0] === 's') {
                            $shown = pdf_show($t[1], $font);
                            $out .= $shown;
                            $run += mb_strlen($shown);
                        }
                    }
                    break;
                case 'TJ':
                    foreach ($stack as $t) {
                        if ($t[0] === 'a') {
                            foreach ($t[1] as $item) {
                                $shown = $item[0] === 's' ? pdf_show($item[1], $font) : ($item[0] === '#' && $item[1] < -200 ? ' ' : '');
                                $out .= $shown;
                                $run += mb_strlen($shown);
                            }
                        }
                    }
                    break;
                case 'Td':
                case 'TD':
                    // Down a line: a new line. Along it: letters placed one by one (no space), unless it's a jump wider than a letter.
                    // Down a line: a new line. Along it: a space only if it jumps clearly past the text just shown
                    // (letters are often placed one at a time, and real spaces are characters of their own).
                    $out .= count($nums) >= 2 && abs($nums[1]) > 0.01 ? "\n" : (($nums[0] ?? 0) > $run * $size * 0.65 + $size * 0.3 ? ' ' : '');
                    $lineX = $lineX === null ? null : $lineX + ($nums[0] ?? 0);
                    $run = 0;
                    break;
                case 'Tm':
                    $y = $nums[5] ?? null;
                    $x = $nums[4] ?? null;
                    if ($y !== null && $lastY !== null && abs($y - $lastY) > 1) {
                        $out .= "\n";
                    } elseif ($x === null || $lineX === null || $x - ($lineX + $run * $size * 0.5) > $size * 0.3) {
                        $out .= ' ';
                    }
                    [$lastY, $lineX, $run] = [$y, $x, 0];
                    break;
                case 'T*':
                    $out .= "\n";
                    break;
                case 'ET':
                    $out .= ' ';
                    break;
            }
            $stack = [];
        }
    }
    return $out;
}

/** The text of a PDF, page by page. Empty for scans (images only). */
function pdf_text(string $pdf): string
{
    $objects = pdf_objects($pdf);
    $cmaps = [];
    $text = '';
    foreach ($objects as $num => $obj) {
        if (!preg_match('#/Type\s*/Page(?![s\w])#', $obj['dict'])) {
            continue;
        }
        // Resources can be inherited from the page tree.
        $resources = '';
        for ($node = $obj['dict'], $hops = 0; $resources === '' && $node !== '' && $hops < 10; $hops++) {
            $resources = pdf_subdict($objects, $node, 'Resources');
            $parent = pdf_ref($node, 'Parent');
            $node = $parent ? ($objects[$parent]['dict'] ?? '') : '';
        }
        $fonts = [];
        if (preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', pdf_subdict($objects, $resources, 'Font'), $fm, PREG_SET_ORDER)) {
            foreach ($fm as [, $name, $ref]) {
                $tu = pdf_ref($objects[(int) $ref]['dict'] ?? '', 'ToUnicode');
                if ($tu && isset($objects[$tu]['stream'])) {
                    $fonts[$name] = $cmaps[$tu] ??= pdf_cmap($objects[$tu]['stream']);
                }
            }
        }
        $refs = array_filter([pdf_ref($obj['dict'], 'Contents')]);
        if (preg_match('#/Contents\s*\[([^\]]*)\]#', $obj['dict'], $cm)) {
            preg_match_all('/(\d+)\s+\d+\s+R/', $cm[1], $rm);
            $refs = array_map('intval', $rm[1]);
        }
        foreach ($refs as $ref) {
            if (isset($objects[$ref]['stream'])) {
                $text .= pdf_content_text($objects[$ref]['stream'], $fonts) . "\n";
            }
        }
        $text .= "\n";
        if (strlen($text) > 2_000_000) {
            break;
        }
    }
    return $text;
}

/* ---------- Picking out the details ---------- */

/** A date in common UK formats, as Y-m-d. */
function policy_date(string $s): ?string
{
    $s = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', trim($s));
    foreach (['d/m/Y', 'd/m/y', 'd-m-Y', 'd.m.Y', 'j F Y', 'j M Y', 'd F Y', 'd M Y', 'Y-m-d', 'F j Y', 'F j, Y'] as $f) {
        $d = DateTimeImmutable::createFromFormat('!' . $f, $s);
        if ($d && $d->format('Y') > 2000 && $d->format('Y') < 2100) {
            return $d->format('Y-m-d');
        }
    }
    return null;
}

const POLICY_DATE = '(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|\d{4}-\d{2}-\d{2})';
const POLICY_MONEY = '£\s?(\d{1,3}(?:,\d{3})*(?:\.\d{2})?|\d+(?:\.\d{2})?)';

/**
 * Plan fields found in a policy document's text, in the shape plan_fields() takes, and which were found.
 * @return array{plan: array, found: string[]}
 */
function policy_details(string $text): array
{
    $t = preg_replace('/[‐-―]/u', '-', $text);
    $flat = preg_replace('/\s+/', ' ', $t);
    $lower = mb_strtolower($flat);
    $plan = ['kind' => 'insurance', 'cost_period' => 'month', 'excess_per' => 'year', 'through_employer' => 0, 'six_week' => 0, 'covers' => '', 'cover_limits' => null];
    $found = [];
    $money = fn (string $m) => (float) str_replace(',', '', $m);

    // Provider: the best-known name mentioned most.
    $best = 0;
    foreach (POLICY_PROVIDERS as $p) {
        $n = preg_match_all('/\b' . preg_quote($p, '/') . '\b/i', $flat);
        if ($n > $best || ($n === $best && $n > 0 && strlen($p) > strlen($plan['provider'] ?? ''))) {
            [$best, $plan['provider']] = [$n, $p];
        }
    }
    if (!empty($plan['provider'])) {
        $found[] = 'provider';
    }
    // Kind.
    $plan['kind'] = match (true) {
        (bool) preg_match('/\bcash plan\b|health cash|money back|claim back up to/', $lower) => 'cash_plan',
        (bool) preg_match('/\bdental (plan|insurance|payment plan)\b|denplan/', $lower) && !preg_match('/medical insurance/', $lower) => 'dental_plan',
        default => 'insurance',
    };
    // Membership / policy number.
    if (preg_match('/\b(?:membership|member|policy|scheme|certificate|account)\s*(?:number|no\.?|#|ref(?:erence)?)\s*[:\-]?\s*([A-Z0-9][A-Z0-9\-\/]{4,24})\b/i', $flat, $m) && preg_match('/\d/', $m[1])) {
        $plan['member_number'] = $m[1];
        $found[] = 'membership number';
    }
    // Plan name.
    if (preg_match('/\b(?:plan|product|cover level|scheme|policy) name\s*[:\-]\s*([^\n:]{3,60}?)\s*(?:\n|$)/i', $t, $m)
        || preg_match('/\b(?:your|the)\s+(?:plan|level of cover|cover level)\s*(?:is)?\s*[:\-]\s*([^\n]{3,50})/i', $t, $m)) {
        $plan['plan_name'] = trim($m[1], " .\t");
        $found[] = 'plan name';
    } elseif (preg_match('/\b(?:[Yy]our|[Tt]he)\s+((?:[A-Z][\w&]*\s+){0,3}(?:Level\s+\d|Gold|Silver|Bronze|Platinum|Essentials?|Premier|Plus|Comprehensive|Classic|Core|Select)(?:\s+\d)?)\s+(?:plan|policy|cover)\b/', $flat, $m)) {
        $plan['plan_name'] = trim($m[1]);
        $found[] = 'plan name';
    }
    // Cost.
    if (preg_match('/\b(monthly|annual|yearly|total)\s+(?:premium|payment|subscription|contribution|cost|price)s?\b[^£]{0,40}' . POLICY_MONEY . '/i', $flat, $m)) {
        $plan['cost_amount'] = $money($m[2]);
        $plan['cost_period'] = strtolower($m[1]) === 'monthly' ? 'month' : 'year';
        $found[] = 'cost';
    } elseif (preg_match('/' . POLICY_MONEY . '\s*(?:per|a|each|\/)\s*(month|year|annum)\b/i', $flat, $m)) {
        $plan['cost_amount'] = $money($m[1]);
        $plan['cost_period'] = strtolower($m[2]) === 'month' ? 'month' : 'year';
        $found[] = 'cost';
    } elseif (preg_match('/\b(?:premium|you pay|your payment)s?\b[^£]{0,30}' . POLICY_MONEY . '[^.\n]{0,20}\b(month|monthly|year|annual)/i', $flat, $m)) {
        $plan['cost_amount'] = $money($m[1]);
        $plan['cost_period'] = str_starts_with(strtolower($m[2]), 'month') ? 'month' : 'year';
        $found[] = 'cost';
    }
    // Excess.
    if (preg_match('/\bexcess\b[^£]{0,60}' . POLICY_MONEY . '([^.\n]{0,40})/i', $flat, $m) && $money($m[1]) < 10000) {
        $plan['excess_amount'] = $money($m[1]);
        $plan['excess_per'] = preg_match('/per (claim|condition|treatment)|each claim/i', $m[2] . ' ' . $m[0]) ? 'claim' : 'year';
        $found[] = 'excess';
    } elseif (preg_match('/\b(?:no|nil|£0|zero) excess\b/i', $flat)) {
        $plan['excess_amount'] = 0.0;
        $found[] = 'excess';
    }
    // Renewal: a renewal or end date, or a start date plus a year.
    if (preg_match('/\b(?:renewal date|renews on|renewal due|policy end date|end date|expiry date|expires on|cover ends|end of cover|period of cover[^0-9]{0,30}to)\s*(?:is)?\s*[:\-]?\s*' . POLICY_DATE . '/i', $flat, $m) && ($d = policy_date($m[1]))) {
        $plan['renews_on'] = $d;
        $found[] = 'renewal date';
    } elseif (preg_match('/\b(?:start date|cover start|policy start|commencement date|effective date|start of cover)\s*(?:is)?\s*[:\-]?\s*' . POLICY_DATE . '/i', $flat, $m) && ($d = policy_date($m[1]))) {
        $plan['renews_on'] = (new DateTimeImmutable($d))->modify('+1 year')->format('Y-m-d');
        $found[] = 'renewal date (a year after the start date)';
    }
    // Underwriting, six-week option, hospital list, employer.
    foreach (['mhd' => '/medical history disregarded|\bMHD\b/i', 'cpme' => '/continued personal medical exclusions|\bCPME\b/i', 'fmu' => '/full medical underwriting|\bFMU\b/i', 'moratorium' => '/\bmoratorium\b/i'] as $k => $re) {
        if (preg_match($re, $flat)) {
            $plan['underwriting'] = $k;
            $found[] = 'underwriting';
            break;
        }
    }
    if (preg_match('/\b(six|6)[\s-]week (option|wait|rule)/i', $flat)) {
        $plan['six_week'] = 1;
        $found[] = 'six-week option';
    }
    if (preg_match('/\b([A-Z][A-Za-z]+(?: [A-Z][A-Za-z]+){0,3}) (hospital list|hospital network|network of hospitals|hospital directory)/', $flat, $m)) {
        $plan['hospital_list'] = $m[1] . ' ' . strtolower($m[2]);
        $found[] = 'hospital list';
    }
    if (preg_match('/\b(your employer|company[- ]paid|corporate (?:plan|scheme|policy)|group (?:scheme|policy|plan)|paid for by your employer|staff (?:scheme|benefit))\b/i', $flat)) {
        $plan['through_employer'] = 1;
        $found[] = 'through an employer';
    }
    // Phone: a UK number next to claims / pre-authorisation, or any freephone number.
    if (preg_match('/\b(?:claims?|pre-?authori[sz]ation|authori[sz]ations?|helpline|call us|customer services?|member services|contact us)\b[^0-9+]{0,50}((?:\+44\s?|0)\d{2,4}[\s\d]{6,11}\d)/i', $flat, $m)
        || preg_match('/\b(?:call|phone|tel(?:ephone)?|ring)\b[^0-9+]{0,25}((?:\+44\s?|0)\d{2,4}[\s\d]{6,11}\d)/i', $flat, $m)
        || preg_match('/\b(0800[\s\d]{6,9}\d|0808[\s\d]{6,9}\d|0345[\s\d]{6,9}\d|0330[\s\d]{6,9}\d|0300[\s\d]{6,9}\d)/', $flat, $m)) {
        $plan['claims_phone'] = trim(preg_replace('/\s+/', ' ', $m[1]));
        $found[] = 'claims phone';
    }
    // Website: prefer one with the provider's name in it.
    if (preg_match_all('/\b((?:https?:\/\/)?(?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:co\.uk|com|uk|org\.uk|org)(?:\/[^\s)]*)?)/i', $flat, $wm)) {
        $sites = array_values(array_filter($wm[1], fn ($w) => !preg_match('/@|fca\.org|ico\.org|financial-ombudsman|gov\.uk|nhs\.uk/i', $w)));
        $key = strtolower(preg_replace('/[^a-z]/i', '', explode(' ', (string) ($plan['provider'] ?? ''))[0]));
        usort($sites, fn ($a, $b) => (int) ($key !== '' && stripos($b, $key) !== false) <=> (int) ($key !== '' && stripos($a, $key) !== false));
        if ($sites) {
            $plan['website'] = rtrim($sites[0], '.,;:');
            $found[] = 'website';
        }
    }

    // What's covered, and yearly limits. Ignore mentions in sentences about what isn't covered.
    $coverWords = [
        'inpatient' => 'in-?patient|day-?patient', 'outpatient' => 'out-?patient', 'diagnostics' => 'diagnostic tests?|\bscans?\b|\bMRI\b|\bCT\b|\bPET\b',
        'therapies' => 'physiotherap|osteopath|chiropract|\\btherapies\\b', 'mental' => 'mental health|psychiatric|psycholog', 'cancer' => '\bcancer\b|oncolog',
        'gp' => '(?:virtual|digital|remote|online|private|24\/7) gp|gp (?:service|appointments?|consultations?) (?:by|via|online|phone|video)', 'dental' => '\bdental\b|dentist',
        'optical' => '\boptical\b|eye tests?|glasses|spectacles|contact lenses', 'hearing' => 'hearing aids?|audiolog', 'podiatry' => 'chiropody|podiatr',
        'prescriptions' => 'prescription charges?|prescription costs?', 'maternity' => 'maternity|childbirth|birth of', 'complementary' => 'acupunct|homeopath|complementary therap',
        'health_check' => 'health (?:assessment|check|screening)s?', 'hospital_cash' => 'hospital cash|in-?patient cash|cash (?:benefit )?(?:for each|per) night',
    ];
    $sentences = preg_split('/(?<=[.;!?])\s+|\n/', $t);
    $covers = [];
    $limits = [];
    foreach ($coverWords as $key => $re) {
        $hits = 0;
        foreach ($sentences as $s) {
            if (!preg_match('/' . $re . '/i', $s)) {
                continue;
            }
            if (preg_match('/not covered|exclu|we (?:do not|don\'t|will not|won\'t) (?:cover|pay)|not included|isn\'t covered|aren\'t covered|no cover/i', $s)) {
                continue;
            }
            $hits++;
            // A limit in the same line: "Dental £250 a year", "out-patient cover up to £1,000".
            if (!isset($limits[$key]) && $key !== 'hospital_cash' && preg_match('/(?:' . $re . ')[^£\n]{0,60}' . POLICY_MONEY . '/i', $s, $lm) && $money($lm[1]) >= 10 && $money($lm[1]) < 100000
                && !preg_match('/excess|premium/i', $lm[0])) {
                $limits[$key] = $money($lm[1]);
            }
        }
        if ($hits) {
            $covers[] = $key;
        }
    }
    if ($covers) {
        $plan['covers'] = implode(',', $covers);
        $found[] = 'what it covers';
    }
    if ($limits) {
        $plan['cover_limits'] = json_encode($limits);
        $found[] = 'yearly limits';
    }
    return ['plan' => $plan, 'found' => $found];
}

/**
 * Read an uploaded policy document (input "policy_file") or pasted text ("policy_text").
 * @return array{plan: array, found: string[]}|string the details, or a message for the person
 */
function scan_policy_from_request(): array|string
{
    $file = $_FILES['policy_file'] ?? null;
    $text = null;
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE ? 'That file is too big to upload.' : "That file couldn't be uploaded. Please try again.";
        }
        if ($file['size'] > POLICY_MAX_BYTES) {
            return 'That file is too big (the limit is 15 MB).';
        }
        $text = document_text($file['tmp_name'], (string) $file['name']);
        @unlink($file['tmp_name']); // not kept
        if ($text === null) {
            return "Pillbox couldn't find any text in that document. If it's a scan or a photo, open it, copy the text (or type the key details) and paste it in instead.";
        }
    } elseif (trim((string) ($_POST['policy_text'] ?? '')) !== '') {
        $text = mb_substr((string) $_POST['policy_text'], 0, 200000);
    } else {
        return 'Choose a document, or paste its text.';
    }
    $details = policy_details($text);
    return $details['found'] ? $details : "Pillbox read the document but couldn't find any plan details in it. You can fill them in yourself.";
}
