<?php
declare(strict_types=1);

// Aufruf: php tests/auth_test.php
// Prüft die JWT-Anmeldung aus auth.php mit eigenen Testwerten (kein Zugriff auf echte Konten).

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../auth.php';

if (authSecret() === '') {
    fwrite(STDERR, "JWT_SECRET ist nicht konfiguriert (siehe config.local.example.php).\n");
    exit(2);
}

$failed = 0;
function check(string $label, bool $ok): void {
    global $failed;
    if (!$ok) {
        $failed++;
    }
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . PHP_EOL;
}

function forge(array $header, array $claims, string $secret): string {
    $h = jwtBase64UrlEncode(json_encode($header));
    $p = jwtBase64UrlEncode(json_encode($claims));
    return $h . '.' . $p . '.' . jwtSign($h . '.' . $p, $secret);
}

$secret = authSecret();
$now = 1_800_000_000;
$userData = ['user_id' => 7, 'username' => 'kunde', 'email' => 'kunde@example.test'];

echo "JWT\n";
[$token, $claims] = authBuildToken('user', $userData, $now, $now);
check('gültiges Token wird akzeptiert', authVerifyToken('user', $token, $now + 10) !== null);
check('Claims bleiben erhalten', (authVerifyToken('user', $token, $now)['data'] ?? null) === $userData);
check('sub ist die Benutzer-ID', $claims['sub'] === '7');
check('Ablauf = Ausstellung + ttl', $claims['exp'] === $now + authScopes()['user']['ttl']);

check('abgelaufenes Token wird abgelehnt', authVerifyToken('user', $token, $claims['exp'] + AUTH_LEEWAY + 1) === null);
check('Token aus der Zukunft wird abgelehnt', authVerifyToken('user', $token, $now - AUTH_LEEWAY - 1) === null);
check('falscher Bereich wird abgelehnt', authVerifyToken('admin', $token, $now) === null);
check('fremdes Geheimnis wird abgelehnt', jwtDecode($token, $secret . 'x', $now) === null);

[$h, $p, $s] = explode('.', $token);
$tamperedPayload = jwtBase64UrlEncode(json_encode(['data' => ['user_id' => 1]] + $claims));
check('veränderter Inhalt wird abgelehnt', jwtDecode($h . '.' . $tamperedPayload . '.' . $s, $secret, $now) === null);
check('abgeschnittene Signatur wird abgelehnt', jwtDecode($h . '.' . $p . '.' . substr($s, 0, -2), $secret, $now) === null);
check('Token ohne Signatur wird abgelehnt', jwtDecode($h . '.' . $p . '.', $secret, $now) === null);
check('zwei statt drei Teile werden abgelehnt', jwtDecode($h . '.' . $p, $secret, $now) === null);
check('leeres Token wird abgelehnt', jwtDecode('', $secret, $now) === null);

$noneHeader = jwtBase64UrlEncode('{"alg":"none","typ":"JWT"}');
check('alg "none" wird abgelehnt', jwtDecode($noneHeader . '.' . $p . '.', $secret, $now) === null);
check('alg HS512 im Header wird abgelehnt', jwtDecode(forge(['alg' => 'HS512', 'typ' => 'JWT'], $claims, $secret), $secret, $now) === null);
check('Token ohne exp wird abgelehnt', jwtDecode(forge(['alg' => 'HS256', 'typ' => 'JWT'], array_diff_key($claims, ['exp' => 1]), $secret), $secret, $now) === null);
check('fremder Aussteller wird abgelehnt', authVerifyToken('user', forge(['alg' => 'HS256', 'typ' => 'JWT'], ['iss' => 'jemand-anders'] + $claims, $secret), $now) === null);
check('leeres Geheimnis akzeptiert nichts', jwtDecode($token, '', $now) === null);

echo "Admin-Bereich\n";
[$adminToken, $adminClaims] = authBuildToken('admin', ['admin' => true, 'admin_username' => 'test-admin'], $now, $now);
check('Admin-Token gilt für admin', authVerifyToken('admin', $adminToken, $now) !== null);
check('Admin-Token gilt nicht für user', authVerifyToken('user', $adminToken, $now) === null);
check('Kunden-Token mit admin=true im Inhalt gilt nicht für admin',
    authVerifyToken('admin', authBuildToken('user', $userData + ['admin' => true], $now, $now)[0], $now) === null);
check('Admin-Token läuft nach 30 Minuten ab', authVerifyToken('admin', $adminToken, $now + 31 * 60 + AUTH_LEEWAY) === null);

echo "Session-Spiegel\n";
$_SESSION = ['user_id' => 99, 'admin' => true, 'csrf_token' => 'bleibt'];
authApplyToSession('user', $userData);
authApplyToSession('admin', null);
check('Kundendaten kommen aus dem Token', $_SESSION['user_id'] === 7 && $_SESSION['email'] === 'kunde@example.test');
check('Admin-Schlüssel ohne Token werden entfernt', !isset($_SESSION['admin']));
check('CSRF-Token bleibt unberührt', $_SESSION['csrf_token'] === 'bleibt');
$_SESSION['flash_message'] = 'Buchung gespeichert';
authApplyToSession('admin', null);
check('Hinweismeldung anderer Seiten überlebt einen Aufruf ohne Admin-Token', ($_SESSION['flash_message'] ?? '') === 'Buchung gespeichert');
authApplyToSession('admin', null, true);
check('beim Admin-Abmelden wird die Admin-Meldung gelöscht', !isset($_SESSION['flash_message']));

echo "Admin-Login\n";
$testHash = password_hash('test-password', PASSWORD_DEFAULT);
check('richtiger Name und Passwort', authCredentialsMatch('test-admin', 'test-password', 'test-admin', $testHash));
check('falsches Passwort', !authCredentialsMatch('test-admin', 'falsch', 'test-admin', $testHash));
check('falscher Name', !authCredentialsMatch('jemand', 'test-password', 'test-admin', $testHash));
check('leere Eingaben', !authCredentialsMatch('', '', 'test-admin', $testHash));
check('ohne hinterlegten Zugang ist die Anmeldung aus', !authCredentialsMatch('', '', '', ''));
check('Klartext statt Hash in der Konfiguration gilt nicht', !authCredentialsMatch('test-admin', 'test-password', 'test-admin', 'test-password'));

echo "Widerruf\n";
$revokedFile = authRevokedPath();
$backup = is_file($revokedFile) ? file_get_contents($revokedFile) : null;
[$liveToken, $liveClaims] = authBuildToken('user', $userData, time());
check('vor der Abmeldung gültig', authVerifyToken('user', $liveToken) !== null);
authRevoke($liveClaims['jti'], $liveClaims['exp']);
check('nach der Abmeldung ungültig', authVerifyToken('user', $liveToken) === null);
authRevoke('abgelaufen', time() - 3600);
check('abgelaufene Einträge werden aufgeräumt', !isset(authRevokedList()['abgelaufen']));
if ($backup === null) {
    @unlink($revokedFile);
} else {
    file_put_contents($revokedFile, $backup);
}

echo "Fehlversuche
";
$throttleFile = authThrottlePath();
$throttleBackup = is_file($throttleFile) ? file_get_contents($throttleFile) : null;
@unlink($throttleFile);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$t0 = time();
for ($i = 0; $i < AUTH_THROTTLE_PER_ACCOUNT - 1; $i++) {
    authLoginFailed('Kunde', $t0);
}
check('unter der Grenze noch erlaubt', authLoginBlockedFor('kunde', $t0) === 0);
authLoginFailed('Kunde', $t0);
check('nach 5 Fehlversuchen gesperrt (Groß-/Kleinschreibung egal)', authLoginBlockedFor('KUNDE', $t0) > 0);
check('Sperre endet nach 15 Minuten', authLoginBlockedFor('kunde', $t0 + AUTH_THROTTLE_WINDOW + 1) === 0);
check('anderes Konto von derselben IP noch erlaubt', authLoginBlockedFor('anderer', $t0) === 0);
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
check('dasselbe Konto von anderer IP erlaubt', authLoginBlockedFor('kunde', $t0) === 0);
for ($i = 0; $i < AUTH_THROTTLE_PER_IP; $i++) {
    authLoginFailed('konto' . $i, $t0);
}
check('viele Konten von einer IP: IP gesperrt', authLoginBlockedFor('noch-ein-konto', $t0) > 0);
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
authLoginSucceeded('kunde');
check('erfolgreiche Anmeldung löscht die Kontosperre', authLoginBlockedFor('kunde', $t0) === 0);
if ($throttleBackup === null) {
    @unlink($throttleFile);
} else {
    file_put_contents($throttleFile, $throttleBackup);
}

echo PHP_EOL . ($failed === 0 ? 'Alle Prüfungen bestanden.' : $failed . ' Prüfung(en) fehlgeschlagen.') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
