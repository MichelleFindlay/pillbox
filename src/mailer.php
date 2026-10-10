<?php
declare(strict_types=1);

/*
 * Sending email: PHP's mail(), or a small SMTP client (STARTTLS / SSL, AUTH LOGIN)
 * so no library is needed. Messages are multipart text + HTML.
 */

function mail_enabled(): bool
{
    return (bool) config('mail.enabled', false) && filter_var((string) config('mail.from', ''), FILTER_VALIDATE_EMAIL);
}

/**
 * Send an email. $html is optional; a plain-text part is always included.
 * @return string|null null on success, or why it failed
 */
function send_mail(string $to, string $subject, string $text, ?string $html = null): ?string
{
    if (!mail_enabled()) {
        return 'Email is not set up (mail settings in config/config.php).';
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return 'Not a valid email address.';
    }
    $from = (string) config('mail.from');
    $fromName = (string) config('mail.from_name', 'Pillbox');
    $boundary = 'pb_' . bin2hex(random_bytes(12));
    $host = parse_url(absolute_url(), PHP_URL_HOST) ?: 'pillbox';
    $headers = [
        'From' => mail_header_name($fromName) . " <$from>",
        'Date' => date(DATE_RFC2822),
        'Message-ID' => '<' . bin2hex(random_bytes(16)) . "@$host>",
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
        'Auto-Submitted' => 'auto-generated',
    ];
    $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . ($html !== null ? "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) : '')
        . "--$boundary--\r\n";
    $encodedSubject = mail_header_name($subject);

    try {
        if (config('mail.transport', 'mail') === 'smtp') {
            smtp_send($from, $to, $encodedSubject, $headers, $body);
        } else {
            $headerLines = implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers));
            if (!mail($to, $encodedSubject, $body, $headerLines, '-f' . $from)) {
                throw new RuntimeException('PHP mail() refused the message. Check the server can send email, or use SMTP.');
            }
        }
    } catch (Throwable $e) {
        error_log('Pillbox mail to ' . $to . ' failed: ' . $e->getMessage());
        return $e->getMessage();
    }
    return null;
}

/** RFC 2047 encode a header value if it isn't plain ASCII. */
function mail_header_name(string $value): string
{
    return preg_match('/[^\x20-\x7e]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

function smtp_send(string $from, string $to, string $subject, array $headers, string $body): void
{
    $host = (string) config('mail.smtp.host');
    $port = (int) config('mail.smtp.port', 587);
    $secure = (string) config('mail.smtp.secure', 'tls');
    $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException("Could not connect to $host:$port ($errstr).");
    }
    stream_set_timeout($socket, 20);
    $read = function () use ($socket): string {
        $reply = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $reply;
    };
    $cmd = function (?string $line, array $ok) use ($socket, $read): string {
        if ($line !== null) {
            fwrite($socket, $line . "\r\n");
        }
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException('The mail server said: ' . trim($reply ?: 'nothing'));
        }
        return $reply;
    };
    $me = parse_url(absolute_url(), PHP_URL_HOST) ?: 'localhost';
    $cmd(null, [220]);
    $cmd("EHLO $me", [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Could not start TLS with the mail server.');
        }
        $cmd("EHLO $me", [250]);
    }
    if ((string) config('mail.smtp.username', '') !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode((string) config('mail.smtp.username')), [334]);
        $cmd(base64_encode((string) config('mail.smtp.password')), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);
    $message = implode("\r\n", array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers))
        . "\r\nTo: <$to>\r\nSubject: $subject\r\n\r\n" . $body;
    // Dot-stuffing: a line starting with "." gets another.
    fwrite($socket, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
    $cmd(null, [250]);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
}

/** Wrap simple HTML content in a plain, email-client-friendly layout. */
function mail_html(string $title, string $inner): string
{
    return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f3ef;font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#1d1f1c">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:10px;padding:24px">'
        . '<h1 style="font-size:20px;margin:0 0 16px">' . h($title) . '</h1>' . $inner
        . '<p style="margin-top:24px;font-size:12px;color:#666">Sent by Pillbox. Change which emails you get under Account settings.</p>'
        . '</div></body></html>';
}
