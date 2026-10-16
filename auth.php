<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

/**
 * Anmeldung per JWT (HS256).
 *
 * Wer angemeldet ist, steht ausschließlich im signierten Token (HttpOnly-Cookie),
 * je Bereich ein eigenes: Kunde, Admin, Mitarbeiter. Die PHP-Session trägt nur noch
 * CSRF-Token und Hinweismeldungen. Damit bestehender Code weiterläuft, spiegelt
 * authBootstrap() die geprüften Claims bei jedem Aufruf in $_SESSION – Identitäts-
 * schlüssel ohne gültiges Token werden dort entfernt.
 */

const AUTH_ISSUER = 'jon-dienstleistungen';
const AUTH_LEEWAY = 30;

/**
 * Bereiche: Cookie, Claim für "sub" (subject), Gültigkeit ohne Aktivität (ttl),
 * Obergrenze seit der Anmeldung (max), gespiegelte Session-Schlüssel (session)
 * und weitere beim Abmelden zu löschende (extra).
 */
function authScopes(): array {
    return [
        'user' => [
            'cookie' => 'jd_user',
            'subject' => 'user_id',
            'ttl' => 12 * 3600,
            'max' => 30 * 86400,
            'session' => ['user_id', 'username', 'email'],
            'extra' => ['password_changed', 'flash_messages'],
        ],
        'admin' => [
            'cookie' => 'jd_admin',
            'subject' => 'admin_username',
            'ttl' => 30 * 60,
            'max' => 12 * 3600,
            'session' => ['admin', 'admin_username'],
            'extra' => ['flash_message', 'flash_message_type'],
        ],
        'employee' => [
            'cookie' => 'jd_employee',
            'subject' => 'employee_id',
            'ttl' => 8 * 3600,
            'max' => 7 * 86400,
            'session' => [
                'employee_auth', 'employee_id', 'employee_name', 'employee_email',
                'employee_role', 'employee_status', 'employee_must_change_password',
            ],
            'extra' => ['employee_profile_data'],
        ],
    ];
}

/* ---------- JWT ---------- */

function jwtBase64UrlEncode(string $binary): string {
    return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function jwtBase64UrlDecode(string $text): ?string {
    if ($text === '' || preg_match('/[^A-Za-z0-9_-]/', $text) === 1) {
        return null;
    }
    $padded = strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4);
    $binary = base64_decode($padded, true);
    return $binary === false ? null : $binary;
}

function jwtSign(string $signingInput, string $secret): string {
    return jwtBase64UrlEncode(hash_hmac('sha256', $signingInput, $secret, true));
}

function jwtEncode(array $claims, string $secret): string {
    $header = jwtBase64UrlEncode('{"alg":"HS256","typ":"JWT"}');
    $payload = jwtBase64UrlEncode(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $header . '.' . $payload . '.' . jwtSign($header . '.' . $payload, $secret);
}

/**
 * Prüft Aufbau, Algorithmus, Signatur und Zeitfenster. Liefert die Claims oder null.
 * Der Algorithmus ist fest HS256 – der Header des Tokens darf ihn nicht bestimmen.
 */
function jwtDecode(string $token, string $secret, ?int $now = null): ?array {
    if ($secret === '' || strlen($token) > 4096) {
        return null;
    }
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    [$headerPart, $payloadPart, $signaturePart] = $parts;

    $headerJson = jwtBase64UrlDecode($headerPart);
    $payloadJson = jwtBase64UrlDecode($payloadPart);
    if ($headerJson === null || $payloadJson === null) {
        return null;
    }
    $header = json_decode($headerJson, true);
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? 'JWT') !== 'JWT') {
        return null;
    }
    if (!hash_equals(jwtSign($headerPart . '.' . $payloadPart, $secret), $signaturePart)) {
        return null;
    }

    $claims = json_decode($payloadJson, true);
    if (!is_array($claims) || !isset($claims['exp']) || !is_int($claims['exp'])) {
        return null;
    }
    $now ??= time();
    if ($claims['exp'] + AUTH_LEEWAY < $now) {
        return null;
    }
    foreach (['nbf', 'iat'] as $notBefore) {
        if (isset($claims[$notBefore]) && (!is_int($claims[$notBefore]) || $claims[$notBefore] - AUTH_LEEWAY > $now)) {
            return null;
        }
    }
    return $claims;
}

/* ---------- Konfiguration ---------- */

function authSecret(): string {
    $secret = defined('JWT_SECRET') ? (string)JWT_SECRET : '';
    if (strlen($secret) < 32) {
        error_log('JWT_SECRET fehlt oder ist kürzer als 32 Zeichen – Anmeldung ist deaktiviert.');
        return '';
    }
    return $secret;
}

function authIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function authSetCookie(string $name, string $value, int $expires): void {
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => authIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ---------- Widerrufene Tokens (Abmeldung) ---------- */

function authRevokedPath(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/jwt_revoked.json';
}

function authRevokedList(): array {
    $raw = @file_get_contents(authRevokedPath());
    $list = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($list) ? $list : [];
}

function authIsRevoked(string $jti): bool {
    return $jti === '' || isset(authRevokedList()[$jti]);
}

function authRevoke(string $jti, int $exp): void {
    if ($jti === '') {
        return;
    }
    $now = time();
    $list = authRevokedList();
    $list[$jti] = $exp;
    // Abgelaufene Einträge braucht niemand mehr – das Token wäre ohnehin ungültig.
    $list = array_filter($list, static fn($until): bool => (int)$until + AUTH_LEEWAY >= $now);
    @file_put_contents(authRevokedPath(), json_encode($list), LOCK_EX);
}

/* ---------- Token je Bereich ---------- */

/**
 * @return array|null Claims des gültigen Tokens für diesen Bereich
 */
function authVerifyToken(string $scope, string $token, ?int $now = null): ?array {
    $claims = jwtDecode($token, authSecret(), $now);
    if ($claims === null
        || ($claims['iss'] ?? '') !== AUTH_ISSUER
        || ($claims['aud'] ?? '') !== $scope
        || !isset($claims['data']) || !is_array($claims['data'])
        || !isset($claims['auth_time']) || !is_int($claims['auth_time'])
        || authIsRevoked((string)($claims['jti'] ?? ''))
    ) {
        return null;
    }
    return $claims;
}

function authBuildToken(string $scope, array $data, int $authTime, ?int $now = null): array {
    $now ??= time();
    $config = authScopes()[$scope];
    $claims = [
        'iss' => AUTH_ISSUER,
        'aud' => $scope,
        'sub' => (string)($data[$config['subject']] ?? ''),
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $config['ttl'],
        'jti' => bin2hex(random_bytes(16)),
        'auth_time' => $authTime,
        'data' => $data,
    ];
    return [jwtEncode($claims, authSecret()), $claims];
}

/**
 * Spiegelt die Claims in $_SESSION. $clearExtra nur beim Abmelden: Schlüssel wie
 * flash_message nutzen auch andere Seiten (z. B. der Kalender) und dürfen nicht
 * bei jedem Aufruf ohne Admin-Token verschwinden.
 */
function authApplyToSession(string $scope, ?array $data, bool $clearExtra = false): void {
    $config = authScopes()[$scope];
    foreach ($config['session'] as $key) {
        if ($data !== null && array_key_exists($key, $data)) {
            $_SESSION[$key] = $data[$key];
        } else {
            unset($_SESSION[$key]);
        }
    }
    if ($data === null && $clearExtra) {
        foreach ($config['extra'] as $key) {
            unset($_SESSION[$key]);
        }
    }
}

/** Geprüfte Claims dieses Aufrufs, je Bereich. */
function &authState(): array {
    static $state = [];
    return $state;
}

/**
 * Stellt ein Token aus und setzt das Cookie. $authTime bleibt beim Verlängern erhalten.
 */
function authIssue(string $scope, array $data, ?int $authTime = null): bool {
    if (!isset(authScopes()[$scope]) || authSecret() === '') {
        return false;
    }
    [$token, $claims] = authBuildToken($scope, $data, $authTime ?? time());
    $cookie = authScopes()[$scope]['cookie'];
    authSetCookie($cookie, $token, $claims['exp']);
    $_COOKIE[$cookie] = $token;
    authState()[$scope] = $claims;
    authApplyToSession($scope, $data);
    return true;
}

/**
 * Anmeldung: neue Session-ID gegen Session-Fixation, dann Token ausstellen.
 */
function authLogin(string $scope, array $data): bool {
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    return authIssue($scope, $data);
}

function authLogout(string $scope): void {
    if (!isset(authScopes()[$scope])) {
        return;
    }
    $cookie = authScopes()[$scope]['cookie'];
    $token = (string)($_COOKIE[$cookie] ?? '');
    if ($token !== '') {
        $claims = jwtDecode($token, authSecret());
        if ($claims !== null) {
            authRevoke((string)($claims['jti'] ?? ''), (int)$claims['exp']);
        }
        authSetCookie($cookie, '', time() - 42000);
        unset($_COOKIE[$cookie]);
    }
    unset(authState()[$scope]);
    authApplyToSession($scope, null, true);
}

/**
 * Einmal je Aufruf nach session_start(): Cookies prüfen, Session spiegeln,
 * Tokens kurz vor Ablauf verlängern.
 */
function authBootstrap(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $now = time();

    foreach (authScopes() as $scope => $config) {
        $token = (string)($_COOKIE[$config['cookie']] ?? '');
        $claims = $token !== '' ? authVerifyToken($scope, $token, $now) : null;

        if ($claims === null) {
            if ($token !== '') {
                authSetCookie($config['cookie'], '', $now - 42000);
                unset($_COOKIE[$config['cookie']]);
            }
            authApplyToSession($scope, null);
            continue;
        }

        authState()[$scope] = $claims;
        authApplyToSession($scope, $claims['data']);

        $halfLifeLeft = $claims['exp'] - $now < $config['ttl'] / 2;
        $withinMaxAge = $now - $claims['auth_time'] < $config['max'];
        if ($halfLifeLeft && $withinMaxAge) {
            authIssue($scope, $claims['data'], $claims['auth_time']);
        }
    }
}

/**
 * @return array|null Angaben aus dem Token (z. B. user_id, username, email) oder null
 */
function authData(string $scope): ?array {
    authBootstrap();
    return authState()[$scope]['data'] ?? null;
}

function authCheck(string $scope): bool {
    return authData($scope) !== null;
}

/**
 * Schützt eine Seite: ohne gültiges Token geht es zur Anmeldung.
 */
function authRequire(string $scope, string $redirect = 'login.php'): array {
    $data = authData($scope);
    if ($data === null) {
        header('Location: ' . $redirect);
        exit;
    }
    return $data;
}

/**
 * Vergleicht eine Anmeldung mit hinterlegtem Namen und Passwort-Hash.
 */
function authCredentialsMatch(string $username, string $password, string $expectedUser, string $expectedHash): bool {
    if ($expectedUser === '' || $expectedHash === '') {
        return false;
    }
    // Beide Prüfungen immer ausführen, damit die Antwortzeit den Benutzernamen nicht verrät.
    $userMatches = hash_equals($expectedUser, $username);
    $passwordMatches = password_verify($password, $expectedHash);
    return $userMatches && $passwordMatches;
}

/**
 * Admin-Zugang aus der Konfiguration (ADMIN_USERNAME, ADMIN_PASSWORD_HASH).
 */
function authVerifyAdminLogin(string $username, string $password): bool {
    return authCredentialsMatch(
        $username,
        $password,
        defined('ADMIN_USERNAME') ? (string)ADMIN_USERNAME : '',
        defined('ADMIN_PASSWORD_HASH') ? (string)ADMIN_PASSWORD_HASH : ''
    );
}

/* ---------- Schutz vor Passwort-Raten ---------- */

const AUTH_THROTTLE_WINDOW = 15 * 60;
const AUTH_THROTTLE_PER_ACCOUNT = 5;
const AUTH_THROTTLE_PER_IP = 20;

function authThrottlePath(): string {
    return dirname(authRevokedPath()) . '/login_attempts.json';
}

function authClientIp(): string {
    // Bewusst nur REMOTE_ADDR: X-Forwarded-For kann jeder Client selbst setzen.
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/**
 * Schlüssel je Konto (IP + Name) und je IP, damit weder ein Konto noch
 * viele Konten von einer Adresse aus durchprobiert werden können.
 */
function authThrottleKeys(string $login): array {
    $ip = authClientIp();
    $name = function_exists('mb_strtolower') ? mb_strtolower(trim($login)) : strtolower(trim($login));
    return [
        'acc:' . hash('sha256', $ip . '|' . $name) => AUTH_THROTTLE_PER_ACCOUNT,
        'ip:' . hash('sha256', $ip) => AUTH_THROTTLE_PER_IP,
    ];
}

/**
 * Liest die Fehlversuche, lässt $change sie ändern und schreibt sie unter Sperre zurück.
 */
function authThrottleUpdate(callable $change, ?int $now = null): array {
    $now ??= time();
    $handle = @fopen(authThrottlePath(), 'c+');
    if ($handle === false) {
        return [];
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($data)) {
        $data = [];
    }
    foreach ($data as $key => $times) {
        $data[$key] = array_values(array_filter((array)$times, static fn($t): bool => (int)$t > $now - AUTH_THROTTLE_WINDOW));
        if ($data[$key] === []) {
            unset($data[$key]);
        }
    }
    $data = $change($data);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $data;
}

/**
 * @return int Sekunden, bis wieder ein Versuch erlaubt ist (0 = sofort)
 */
function authLoginBlockedFor(string $login, ?int $now = null): int {
    $now ??= time();
    $data = authThrottleUpdate(static fn(array $d): array => $d, $now);
    $wait = 0;
    foreach (authThrottleKeys($login) as $key => $limit) {
        $times = $data[$key] ?? [];
        if (count($times) >= $limit) {
            $wait = max($wait, min($times) + AUTH_THROTTLE_WINDOW - $now);
        }
    }
    return max(0, $wait);
}

function authLoginFailed(string $login, ?int $now = null): void {
    $now ??= time();
    $keys = authThrottleKeys($login);
    authThrottleUpdate(static function (array $data) use ($keys, $now): array {
        foreach (array_keys($keys) as $key) {
            $data[$key][] = $now;
        }
        return $data;
    }, $now);
}

function authLoginSucceeded(string $login): void {
    $accountKey = array_key_first(authThrottleKeys($login));
    authThrottleUpdate(static function (array $data) use ($accountKey): array {
        unset($data[$accountKey]);
        return $data;
    });
}
