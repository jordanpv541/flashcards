<?php
declare(strict_types=1);

// Envío de correo sin dependencias: SMTP (SSL o STARTTLS), mail() de PHP o registro en archivo (pruebas).

function send_mail(string $to, string $subject, string $text): bool
{
    if (preg_match('/[\x00-\x1F\x7F<>]/', $to . $subject)) {
        app_log('warning', 'Correo rechazado por caracteres no válidos.');
        return false;
    }
    $driver = (string) config('mail.driver', 'mail');
    try {
        return match ($driver) {
            'smtp' => smtp_send($to, $subject, $text),
            'log' => log_mail($to, $subject, $text),
            default => php_mail($to, $subject, $text),
        };
    } catch (Throwable $e) {
        app_log('error', 'Correo no enviado a ' . $to . ': ' . $e->getMessage());
        return false;
    }
}

function mail_encode_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

function mail_from_header(): string
{
    $name = (string) config('mail.from_name', 'Flashcards');
    $email = (string) config('mail.from_email', 'no-responder@localhost');
    return mail_encode_header($name) . ' <' . $email . '>';
}

function mail_domain(): string
{
    $from = (string) config('mail.from_email', 'no-responder@localhost');
    return substr(strrchr($from, '@') ?: '@localhost', 1);
}

function log_mail(string $to, string $subject, string $text): bool
{
    $entry = sprintf("=== %s\nPara: %s\nAsunto: %s\n\n%s\n\n", gmdate('c'), $to, $subject, $text);
    return file_put_contents(APP_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX) !== false;
}

function php_mail(string $to, string $subject, string $text): bool
{
    $headers = [
        'From: ' . mail_from_header(),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $from = (string) config('mail.from_email', '');
    return mail($to, mail_encode_header($subject), chunk_split(base64_encode($text)), implode("\r\n", $headers), $from !== '' ? '-f' . $from : '');
}

function smtp_send(string $to, string $subject, string $text): bool
{
    $host = (string) config('mail.smtp_host');
    $port = (int) config('mail.smtp_port', 465);
    $secure = (string) config('mail.smtp_secure', 'ssl');
    $user = (string) config('mail.smtp_user');
    $pass = (string) config('mail.smtp_pass');
    $from = (string) config('mail.from_email');

    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $target = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException("SMTP: no se pudo conectar a $host:$port ($errstr)");
    }
    stream_set_timeout($socket, 15);

    $read = function () use ($socket): array {
        $lines = '';
        while (($line = fgets($socket, 515)) !== false) {
            $lines .= $line;
            // Una línea con espacio después del código es la última de la respuesta.
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return [(int) substr($lines, 0, 3), $lines];
    };
    $cmd = function (string $command, array $expect) use ($socket, $read): string {
        fwrite($socket, $command . "\r\n");
        [$code, $response] = $read();
        if (!in_array($code, $expect, true)) {
            $shown = str_starts_with($command, 'AUTH') || strlen($command) > 60 ? strtok($command, ' ') : $command;
            throw new RuntimeException('SMTP: respuesta inesperada a ' . $shown . ': ' . trim($response));
        }
        return $response;
    };

    [$code, $greeting] = $read();
    if ($code !== 220) {
        throw new RuntimeException('SMTP: saludo inesperado: ' . trim($greeting));
    }
    $ehloName = parse_url((string) config('app_url', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
    $cmd('EHLO ' . $ehloName, [250]);
    if ($secure === 'tls') {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('SMTP: no se pudo activar TLS.');
        }
        $cmd('EHLO ' . $ehloName, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }
    $cmd('MAIL FROM:<' . $from . '>', [250]);
    $cmd('RCPT TO:<' . $to . '>', [250, 251]);
    $cmd('DATA', [354]);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . mail_from_header(),
        'To: <' . $to . '>',
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . mail_domain() . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];
    $message = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode($text), 76, "\r\n")) . "\r\n.";
    $cmd($message, [250]);
    fwrite($socket, "QUIT\r\n");
    fclose($socket);
    return true;
}
