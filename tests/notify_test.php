<?php
declare(strict_types=1);

// Aufruf: php tests/notify_test.php
// Prüft Texte, WhatsApp-Anfragen (ohne echten Versand) und den Aufbau der E-Mail.
// Optionaler Versandtest gegen einen lokalen SMTP-Server (z. B. Mailpit):
//   php tests/notify_test.php --smtp=127.0.0.1:1025 --to=test@example.test

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../notify.php';

$failed = 0;
function check(string $label, bool $ok): void {
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
}

function testConfig(array $mail = [], array $whatsapp = []): array {
    $cfg = notifyConfig();
    $cfg['site_url'] = 'https://www.example.de';
    $cfg['owner_email'] = 'meister@example.test';
    $cfg['mail'] = $mail + ['host' => '', 'port' => 25, 'encryption' => '', 'user' => '', 'pass' => '', 'from' => '', 'from_name' => 'Jon Dienstleistungen'];
    $cfg['whatsapp'] = $whatsapp + [
        'provider' => '', 'callmebot_phone' => '', 'callmebot_apikey' => '',
        'cloud_token' => '', 'cloud_phone_id' => '', 'cloud_to' => '', 'cloud_template' => '', 'cloud_lang' => 'de',
    ];
    return $cfg;
}

// Protokoll dieses Laufs nicht in das echte Log schreiben.
$logFile = __DIR__ . '/../tmp/notifications.log';
$logBackup = is_file($logFile) ? file_get_contents($logFile) : null;

$booking = ['date' => '2026-11-18', 'service' => 'Gartenarbeiten', 'phone' => '+4915700000000', 'email' => 'kunde@example.test'];

echo "Texte\n";
$msg = notifyNewBookingMessage($booking, testConfig());
check('Betreff mit deutschem Datum', $msg['subject'] === 'Neue Buchungsanfrage für 18.11.2026');
check('Text enthält Dienstleistung, Kunde und Telefon',
    str_contains($msg['text'], 'Gartenarbeiten') && str_contains($msg['text'], 'kunde@example.test') && str_contains($msg['text'], '+4915700000000'));
check('Link zu offenen Buchungen', str_contains($msg['text'], 'https://www.example.de/admin.php?tab=bookings&booking_status=pending'));
$yes = notifyDecisionMessage('2026-11-18', 'Umzugshilfe', true, testConfig());
$no = notifyDecisionMessage('2026-11-18', 'Umzugshilfe', false, testConfig());
check('Zusage nennt Termin und Preis nach Umfang', str_contains($yes['text'], '18.11.2026') && str_contains($yes['text'], 'Preis nach Umfang'));
check('Absage verweist auf den Kalender', str_contains($no['text'], '/calendar.php') && str_starts_with($no['subject'], 'Termin nicht möglich'));

$wa = notifyNewBookingWhatsapp($booking, testConfig());
check('WhatsApp-Kurzfassung ohne E-Mail und Telefon des Kunden',
    !str_contains($wa, 'kunde@example.test') && !str_contains($wa, '+4915700000000') && str_contains($wa, '18.11.2026'));

echo "E-Mail-Aufbau\n";
$mailCfg = testConfig(['host' => 'smtp.example.test', 'from' => 'info@example.test'])['mail'];
$raw = mailBuildMessage($mailCfg, 'kunde@example.test', "Termin bestätigt\r\nBcc: angreifer@example.test", "Zeile 1\nÄußerst wichtig");
[$head, $body] = explode("\r\n\r\n", $raw, 2);
check('Zeilenumbruch im Betreff erzeugt keine eigene Kopfzeile', preg_match('/^Bcc:/mi', $head) === 0);
check('Umlaute im Betreff werden kodiert', str_contains($head, 'Subject: =?UTF-8?B?'));
check('Text ist base64 und lässt sich zurücklesen', base64_decode(str_replace("\r\n", '', $body)) === "Zeile 1\nÄußerst wichtig");
check('Kopfzeilen enden mit CRLF', !preg_match("/[^\r]\n/", $head));
check('ohne SMTP-Daten wird nichts versucht', mailSend('kunde@example.test', 'x', 'y', testConfig()) === false);
check('ungültiger Empfänger wird abgelehnt', mailSend("x@example.test\r\nRCPT TO:<b@c.d>", 'x', 'y', testConfig($mailCfg)) === false);

echo "WhatsApp\n";
$requests = [];
$GLOBALS['notifyHttpTransport'] = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
    $requests[] = compact('method', 'url', 'headers', 'body');
    return [200, 'Message queued'];
};

check('ohne Anbieter: nichts gesendet', whatsappSend('Hallo', testConfig()) === false && $requests === []);

$cmb = testConfig([], ['provider' => 'callmebot', 'callmebot_phone' => '+4915700000000', 'callmebot_apikey' => '123456']);
check('CallMeBot: Versand meldet Erfolg', whatsappSend("Neue Anfrage\nDatum: 18.11.2026 & mehr", $cmb));
$url = parse_url($requests[0]['url'] ?? '');
parse_str($url['query'] ?? '', $query);
check('CallMeBot: GET an api.callmebot.com', ($requests[0]['method'] ?? '') === 'GET' && ($url['host'] ?? '') === 'api.callmebot.com');
check('CallMeBot: Text samt Zeilenumbruch und & sauber kodiert', ($query['text'] ?? '') === "Neue Anfrage\nDatum: 18.11.2026 & mehr");
check('CallMeBot: Nummer und Schlüssel übergeben', ($query['phone'] ?? '') === '+4915700000000' && ($query['apikey'] ?? '') === '123456');

$requests = [];
$cloud = testConfig([], ['provider' => 'cloud', 'cloud_token' => 'TOKEN', 'cloud_phone_id' => '1098', 'cloud_to' => '+49 157 0000 0000', 'cloud_template' => 'neue_anfrage']);
check('Cloud API: Versand meldet Erfolg', whatsappSend("Neue Anfrage\nDatum: 18.11.2026", $cloud));
$payload = json_decode((string)($requests[0]['body'] ?? ''), true);
check('Cloud API: POST an graph.facebook.com/…/1098/messages', ($requests[0]['method'] ?? '') === 'POST' && str_ends_with((string)($requests[0]['url'] ?? ''), '/1098/messages'));
check('Cloud API: Bearer-Token im Kopf', in_array('Authorization: Bearer TOKEN', $requests[0]['headers'] ?? [], true));
check('Cloud API: Empfänger nur Ziffern', ($payload['to'] ?? '') === '4915700000000');
check('Cloud API: Vorlage und Sprache', ($payload['template']['name'] ?? '') === 'neue_anfrage' && ($payload['template']['language']['code'] ?? '') === 'de');
check('Cloud API: Parameter ohne Zeilenumbruch',
    ($payload['template']['components'][0]['parameters'][0]['text'] ?? '') === 'Neue Anfrage · Datum: 18.11.2026');

$GLOBALS['notifyHttpTransport'] = static fn(): array => [401, '{"error":"invalid token"}'];
check('Fehlerantwort wird als Misserfolg gemeldet', whatsappSend('x', $cloud) === false);
unset($GLOBALS['notifyHttpTransport']);

// Optional: echter SMTP-Versand gegen lokalen Testserver.
$options = getopt('', ['smtp:', 'to:']);
if (isset($options['smtp'])) {
    echo "SMTP\n";
    [$host, $port] = explode(':', (string)$options['smtp']) + [1 => '1025'];
    $smtpCfg = testConfig(['host' => $host, 'port' => (int)$port, 'encryption' => 'none', 'from' => 'info@example.test']);
    $to = (string)($options['to'] ?? 'test@example.test');
    $message = notifyNewBookingMessage($booking, $smtpCfg);
    check('E-Mail über ' . $host . ':' . $port . ' angenommen', mailSend($to, $message['subject'], $message['text'], $smtpCfg));
}

if ($logBackup === null) {
    @unlink($logFile);
} else {
    file_put_contents($logFile, $logBackup);
}

echo PHP_EOL . ($failed === 0 ? 'Alle Prüfungen bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
