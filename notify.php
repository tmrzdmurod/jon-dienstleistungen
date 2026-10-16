<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

/**
 * Benachrichtigungen per E-Mail (SMTP) und WhatsApp.
 *
 * Fehler werden nur protokolliert (tmp/notifications.log) und nie an den Aufrufer
 * weitergereicht: Eine Buchung darf nicht scheitern, weil ein Mailserver hängt.
 * Zugangsdaten stehen in config.local.php, siehe config.local.example.php.
 */

const NOTIFY_TIMEOUT = 8;

function notifyConfig(): array {
    $get = static fn(string $name): string => defined($name) ? trim((string)constant($name)) : '';
    return [
        'site_url' => rtrim($get('SITE_URL'), '/'),
        'owner_email' => $get('NOTIFY_EMAIL'),
        'mail' => [
            'host' => $get('MAIL_HOST'),
            'port' => (int)($get('MAIL_PORT') ?: 587),
            // 'tls' (STARTTLS, Standard), 'ssl' (Port 465) oder 'none' (nur lokale Testserver).
            'encryption' => strtolower($get('MAIL_ENCRYPTION') ?: 'tls'),
            'user' => $get('MAIL_USER'),
            'pass' => defined('MAIL_PASS') ? (string)MAIL_PASS : '',
            'from' => $get('MAIL_FROM'),
            'from_name' => $get('MAIL_FROM_NAME') ?: 'Jon Dienstleistungen',
        ],
        'whatsapp' => [
            'provider' => strtolower($get('WHATSAPP_PROVIDER')),
            'callmebot_phone' => $get('CALLMEBOT_PHONE'),
            'callmebot_apikey' => $get('CALLMEBOT_APIKEY'),
            'cloud_token' => $get('WA_CLOUD_TOKEN'),
            'cloud_phone_id' => $get('WA_CLOUD_PHONE_ID'),
            'cloud_to' => $get('WA_CLOUD_TO'),
            'cloud_template' => $get('WA_CLOUD_TEMPLATE'),
            'cloud_lang' => $get('WA_CLOUD_LANG') ?: 'de',
        ],
    ];
}

function notifyLog(string $channel, string $event, bool $ok, string $detail = ''): void {
    $entry = [
        'at' => date('c'),
        'channel' => $channel,
        'event' => $event,
        'ok' => $ok,
        'detail' => function_exists('mb_substr') ? mb_substr($detail, 0, 300) : substr($detail, 0, 300),
    ];
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($dir . '/notifications.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Führt Arbeit erst nach der Antwort an den Browser aus (wo der Server das kann)
 * und gibt vorher die Session frei, damit der nächste Seitenaufruf nicht wartet.
 */
function notifyDefer(callable $job): void {
    register_shutdown_function(static function () use ($job): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            $job();
        } catch (Throwable $e) {
            notifyLog('system', 'deferred', false, $e->getMessage());
        }
    });
}

/* ---------- E-Mail über SMTP ---------- */

function mailIsConfigured(?array $cfg = null): bool {
    $mail = ($cfg ?? notifyConfig())['mail'];
    return $mail['host'] !== '' && filter_var($mail['from'], FILTER_VALIDATE_EMAIL) !== false;
}

function mailEncodeHeader(string $value): string {
    return preg_match('/[^\x20-\x7E]/', $value) === 1 ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/**
 * Baut die vollständige Nachricht (Kopf + Text). Zeilenumbrüche in Kopfzeilen werden
 * entfernt, damit niemand über Betreff oder Namen weitere Kopfzeilen einschleusen kann.
 */
function mailBuildMessage(array $mail, string $to, string $subject, string $text): string {
    $clean = static fn(string $v): string => str_replace(["\r", "\n"], ' ', $v);
    $domain = substr((string)strrchr($mail['from'], '@'), 1) ?: 'localhost';
    $headers = [
        'Date: ' . date('r'),
        'From: ' . mailEncodeHeader($clean($mail['from_name'])) . ' <' . $clean($mail['from']) . '>',
        'To: <' . $clean($to) . '>',
        'Subject: ' . mailEncodeHeader($clean($subject)),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
    ];
    $body = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $text)), 76, "\r\n");
    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * @param resource $socket
 * @param int[] $expect erlaubte Antwortcodes
 * @param string $label was im Fehlerfall protokolliert wird (nie Zugangsdaten)
 */
function smtpCommand($socket, ?string $command, array $expect, string $label): string {
    if ($command !== null) {
        fwrite($socket, $command . "\r\n");
    }
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        // Mehrzeilige Antworten: "250-…" geht weiter, "250 …" ist die letzte Zeile.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expect, true)) {
        throw new RuntimeException($label . ': ' . ($response === '' ? 'keine Antwort' : trim($response)));
    }
    return $response;
}

function mailSend(string $to, string $subject, string $text, ?array $cfg = null): bool {
    $mail = ($cfg ?? notifyConfig())['mail'];
    if (!mailIsConfigured($cfg)) {
        notifyLog('email', 'skip', false, 'SMTP nicht konfiguriert');
        return false;
    }
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        notifyLog('email', 'skip', false, 'ungültiger Empfänger');
        return false;
    }

    $scheme = $mail['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $socket = @stream_socket_client($scheme . $mail['host'] . ':' . $mail['port'], $errno, $errstr, NOTIFY_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        notifyLog('email', 'connect', false, $mail['host'] . ':' . $mail['port'] . ' – ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, NOTIFY_TIMEOUT);

    try {
        $helo = 'EHLO ' . (gethostname() ?: 'localhost');
        smtpCommand($socket, null, [220], 'Begrüßung');
        smtpCommand($socket, $helo, [250], 'EHLO');
        if ($mail['encryption'] === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS-Aushandlung fehlgeschlagen');
            }
            smtpCommand($socket, $helo, [250], 'EHLO nach TLS');
        }
        if ($mail['user'] !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334], 'AUTH');
            smtpCommand($socket, base64_encode($mail['user']), [334], 'AUTH Benutzer');
            smtpCommand($socket, base64_encode($mail['pass']), [235], 'AUTH Passwort');
        }
        smtpCommand($socket, 'MAIL FROM:<' . $mail['from'] . '>', [250], 'MAIL FROM');
        smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtpCommand($socket, 'DATA', [354], 'DATA');
        // Base64-Text beginnt nie mit "." – ein Punkt-Escaping ist deshalb nicht nötig.
        smtpCommand($socket, mailBuildMessage($mail, $to, $subject, $text) . "\r\n.", [250], 'Nachricht');
        try {
            smtpCommand($socket, 'QUIT', [221], 'QUIT');
        } catch (RuntimeException) {
            // Die Nachricht ist bereits angenommen.
        }
        notifyLog('email', 'sent', true, $subject);
        return true;
    } catch (RuntimeException $e) {
        notifyLog('email', 'smtp', false, $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}

/* ---------- WhatsApp ---------- */

/**
 * HTTP-Aufruf; Tests ersetzen ihn über $GLOBALS['notifyHttpTransport'].
 *
 * @return array{0:int,1:string} Statuscode und Antworttext
 */
function notifyHttp(string $method, string $url, array $headers = [], ?string $body = null): array {
    if (isset($GLOBALS['notifyHttpTransport']) && is_callable($GLOBALS['notifyHttpTransport'])) {
        return ($GLOBALS['notifyHttpTransport'])($method, $url, $headers, $body);
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => NOTIFY_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => NOTIFY_TIMEOUT,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    return [$status, $response === false ? $error : (string)$response];
}

function whatsappIsConfigured(?array $cfg = null): bool {
    $wa = ($cfg ?? notifyConfig())['whatsapp'];
    return match ($wa['provider']) {
        'callmebot' => $wa['callmebot_phone'] !== '' && $wa['callmebot_apikey'] !== '',
        'cloud' => $wa['cloud_token'] !== '' && $wa['cloud_phone_id'] !== '' && $wa['cloud_to'] !== '' && $wa['cloud_template'] !== '',
        'log' => true,
        default => false,
    };
}

/**
 * Nachricht an den Inhaber (Meister). Anbieter:
 * - callmebot: kostenlos, nur an die eigene, einmal freigeschaltete Nummer;
 * - cloud: offizielle WhatsApp Cloud API von Meta mit genehmigter Vorlage (1 Textparameter);
 * - log: nur protokollieren (Entwicklung).
 */
function whatsappSend(string $text, ?array $cfg = null): bool {
    $wa = ($cfg ?? notifyConfig())['whatsapp'];
    if (!whatsappIsConfigured($cfg)) {
        notifyLog('whatsapp', 'skip', false, 'WhatsApp nicht konfiguriert');
        return false;
    }

    if ($wa['provider'] === 'log') {
        notifyLog('whatsapp', 'logged', true, $text);
        return true;
    }

    if ($wa['provider'] === 'callmebot') {
        $url = 'https://api.callmebot.com/whatsapp.php?' . http_build_query([
            'phone' => $wa['callmebot_phone'],
            'text' => $text,
            'apikey' => $wa['callmebot_apikey'],
        ]);
        [$status, $body] = notifyHttp('GET', $url);
    } else {
        // Vorlagen-Parameter dürfen keine Zeilenumbrüche enthalten.
        $param = trim((string)preg_replace('/\s*\n\s*/', ' · ', $text));
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => preg_replace('/\D/', '', $wa['cloud_to']),
            'type' => 'template',
            'template' => [
                'name' => $wa['cloud_template'],
                'language' => ['code' => $wa['cloud_lang']],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $param]],
                ]],
            ],
        ];
        [$status, $body] = notifyHttp(
            'POST',
            'https://graph.facebook.com/v21.0/' . rawurlencode($wa['cloud_phone_id']) . '/messages',
            ['Authorization: Bearer ' . $wa['cloud_token'], 'Content-Type: application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    $ok = $status >= 200 && $status < 300;
    notifyLog('whatsapp', $wa['provider'], $ok, $ok ? 'gesendet' : 'HTTP ' . $status . ': ' . $body);
    return $ok;
}

/* ---------- Anlässe ---------- */

function notifyFormatDate(string $date): string {
    $time = strtotime($date);
    return $time === false ? $date : date('d.m.Y', $time);
}

/**
 * @param array{date:string,service:string,phone:string,email:string} $booking
 * @return array{subject:string,text:string}
 */
function notifyNewBookingMessage(array $booking, ?array $cfg = null): array {
    $cfg ??= notifyConfig();
    $date = notifyFormatDate($booking['date']);
    $lines = [
        'Neue Buchungsanfrage',
        'Datum: ' . $date,
        'Dienstleistung: ' . $booking['service'],
        'Kunde: ' . $booking['email'],
        'Telefon: ' . $booking['phone'],
        'Bitte im Admin-Bereich genehmigen oder ablehnen.',
    ];
    if ($cfg['site_url'] !== '') {
        $lines[] = $cfg['site_url'] . '/admin.php?tab=bookings&booking_status=pending';
    }
    return ['subject' => 'Neue Buchungsanfrage für ' . $date, 'text' => implode("\n", $lines)];
}

/**
 * Kurzfassung für WhatsApp – ohne E-Mail und Telefonnummer des Kunden: Die Nachricht läuft
 * über Meta bzw. CallMeBot, Kontaktdaten sieht der Meister im Admin-Bereich.
 */
function notifyNewBookingWhatsapp(array $booking, ?array $cfg = null): string {
    $cfg ??= notifyConfig();
    $text = 'Neue Buchungsanfrage für ' . notifyFormatDate($booking['date']) . ' – ' . $booking['service']
        . '. Details im Admin-Bereich.';
    if ($cfg['site_url'] !== '') {
        $text .= "
" . $cfg['site_url'] . '/admin.php?tab=bookings&booking_status=pending';
    }
    return $text;
}

/**
 * @return array{subject:string,text:string}
 */
function notifyDecisionMessage(string $date, string $service, bool $approved, ?array $cfg = null): array {
    $cfg ??= notifyConfig();
    $date = notifyFormatDate($date);
    if ($approved) {
        $lines = [
            'Guten Tag,',
            '',
            'Ihr Termin am ' . $date . ' (' . $service . ') ist bestätigt.',
            'Der Meister meldet sich bei Ihnen und nennt den Preis nach Umfang der Arbeit.',
        ];
        $subject = 'Termin bestätigt: ' . $date;
    } else {
        $lines = [
            'Guten Tag,',
            '',
            'leider können wir den Termin am ' . $date . ' (' . $service . ') nicht übernehmen.',
            'Bitte wählen Sie im Buchungskalender einen anderen Tag.',
        ];
        $subject = 'Termin nicht möglich: ' . $date;
    }
    if ($cfg['site_url'] !== '') {
        $lines[] = $cfg['site_url'] . ($approved ? '/orders.php' : '/calendar.php');
    }
    $lines[] = '';
    $lines[] = 'Viele Grüße';
    $lines[] = 'Jon Dienstleistungen';
    return ['subject' => $subject, 'text' => implode("\n", $lines)];
}

/**
 * Neue Anfrage → Inhaber per E-Mail und WhatsApp.
 */
function notifyOwnerNewBooking(array $booking): void {
    $cfg = notifyConfig();
    $message = notifyNewBookingMessage($booking, $cfg);
    if ($cfg['owner_email'] !== '') {
        mailSend($cfg['owner_email'], $message['subject'], $message['text'], $cfg);
    }
    whatsappSend(notifyNewBookingWhatsapp($booking, $cfg), $cfg);
}

/**
 * Entscheidung des Admins → Kunde per E-Mail.
 */
function notifyClientDecision(string $email, string $date, string $service, bool $approved): void {
    $cfg = notifyConfig();
    $message = notifyDecisionMessage($date, $service, $approved, $cfg);
    mailSend($email, $message['subject'], $message['text'], $cfg);
}
