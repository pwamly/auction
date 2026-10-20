<?php
declare(strict_types=1);

/**
 * Sends one plain-text email. Throws on failure.
 * MAIL_TRANSPORT 'log' writes the message to storage/mail/ instead (development).
 */
function send_mail(string $toEmail, string $toName, string $subject, string $body): void
{
    $fromHeader = encode_mail_header(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>';
    $toHeader = $toName !== '' ? encode_mail_header($toName) . ' <' . $toEmail . '>' : $toEmail;
    $headers = [
        'From: ' . $fromHeader,
        'To: ' . $toHeader,
        'Subject: ' . encode_mail_header($subject),
        'Date: ' . date(DATE_RFC2822),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . str_replace(["\r\n", "\n"], "\r\n", $body);

    if (MAIL_TRANSPORT === 'log') {
        $file = dirname(__DIR__) . '/storage/mail/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        if (file_put_contents($file, $message) === false) {
            throw new RuntimeException('Could not write ' . $file);
        }
        return;
    }
    if (MAIL_TRANSPORT !== 'smtp') {
        throw new RuntimeException('Unknown MAIL_TRANSPORT ' . MAIL_TRANSPORT);
    }
    smtp_send($toEmail, $message);
}

function encode_mail_header(string $text): string
{
    $text = str_replace(["\r", "\n"], ' ', $text);
    return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

function smtp_send(string $toEmail, string $message): void
{
    if (SMTP_HOST === '') {
        throw new RuntimeException('SMTP_HOST is not configured.');
    }
    $remote = (SMTP_SECURE === 'ssl' ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . SMTP_PORT;
    $socket = @stream_socket_client($remote, $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    }
    stream_set_timeout($socket, 15);

    $expect = static function (array $codes) use ($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        if (!in_array((int) substr($response, 0, 3), $codes, true)) {
            throw new RuntimeException('SMTP error: ' . trim($response));
        }
        return $response;
    };
    $send = static function (string $command, array $codes) use ($socket, $expect): string {
        fwrite($socket, $command . "\r\n");
        return $expect($codes);
    };

    try {
        $expect([220]);
        $hostname = gethostname() ?: 'localhost';
        $send('EHLO ' . $hostname, [250]);
        if (SMTP_SECURE === 'tls') {
            $send('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('STARTTLS failed.');
            }
            $send('EHLO ' . $hostname, [250]);
        }
        if (SMTP_USER !== '') {
            $send('AUTH LOGIN', [334]);
            $send(base64_encode(SMTP_USER), [334]);
            $send(base64_encode(SMTP_PASS), [235]);
        }
        $send('MAIL FROM:<' . MAIL_FROM . '>', [250]);
        $send('RCPT TO:<' . $toEmail . '>', [250, 251]);
        $send('DATA', [354]);
        // Dot-stuffing: a line starting with "." must be doubled.
        $send(preg_replace('/^\./m', '..', $message) . "\r\n.", [250]);
        $send('QUIT', [221]);
    } finally {
        fclose($socket);
    }
}
