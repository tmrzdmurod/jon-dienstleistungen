<?php
declare(strict_types=1);

// Admin-Zugang setzen, ohne Code anzufassen. Gespeichert wird nur der Hash.
//
//   php bin/set-admin-password.php                 – fragt Name und Passwort ab
//   php bin/set-admin-password.php --logout-all    – zusätzlich neues JWT_SECRET (meldet alle ab)
//   php bin/set-admin-password.php --config=PFAD   – andere Konfigurationsdatei (für Tests)

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MIN_PASSWORD_LENGTH = 12;

$root = dirname(__DIR__);
$options = getopt('', ['config:', 'logout-all']);
$configPath = (string)($options['config'] ?? $root . '/config.local.php');
$rotateSecret = array_key_exists('logout-all', $options);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function ask(string $label, bool $hidden = false): string {
    fwrite(STDOUT, $label);
    // Unter Linux/macOS Eingabe verbergen; die Windows-Konsole kann das ohne Zusatzprogramm nicht.
    $canHide = $hidden && DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($canHide) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($canHide) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $line === false ? '' : rtrim($line, "\r\n");
}

/**
 * Ersetzt "const NAME = …;" oder hängt die Zeile an.
 */
function setConstant(string $source, string $name, string $value): string {
    $line = 'const ' . $name . ' = ' . var_export($value, true) . ';';
    $pattern = '/^const\s+' . preg_quote($name, '/') . '\s*=.*;\s*$/m';
    if (preg_match($pattern, $source) === 1) {
        return (string)preg_replace_callback($pattern, static fn(): string => $line, $source, 1);
    }
    return rtrim($source) . PHP_EOL . $line . PHP_EOL;
}

if (!is_file($configPath)) {
    $example = $root . '/config.local.example.php';
    if (!is_file($example) || !copy($example, $configPath)) {
        fail('Konfiguration nicht gefunden und Vorlage nicht kopierbar: ' . $configPath);
    }
    echo 'config.local.php aus der Vorlage angelegt – Datenbankzugang dort noch eintragen.' . PHP_EOL;
}

if (DIRECTORY_SEPARATOR !== '/' && stream_isatty(STDIN)) {
    echo 'Hinweis: In der Windows-Konsole ist das Passwort bei der Eingabe sichtbar.' . PHP_EOL;
}

$username = trim(ask('Admin-Benutzername: '));
if ($username === '' || strlen($username) > 60) {
    fail('Benutzername fehlt oder ist länger als 60 Zeichen.');
}
$password = ask('Neues Passwort (mind. ' . MIN_PASSWORD_LENGTH . ' Zeichen): ', true);
if (strlen($password) < MIN_PASSWORD_LENGTH) {
    fail('Passwort ist zu kurz.');
}
if (ask('Passwort wiederholen: ', true) !== $password) {
    fail('Passwörter stimmen nicht überein.');
}

$source = (string)file_get_contents($configPath);
$source = setConstant($source, 'ADMIN_USERNAME', $username);
$source = setConstant($source, 'ADMIN_PASSWORD_HASH', password_hash($password, PASSWORD_DEFAULT));
if ($rotateSecret || !preg_match("/^const\\s+JWT_SECRET\\s*=\\s*'[0-9a-f]{32,}'/m", $source)) {
    $source = setConstant($source, 'JWT_SECRET', bin2hex(random_bytes(32)));
    $rotateSecret = true;
}

if (file_put_contents($configPath, $source, LOCK_EX) === false) {
    fail('Konfiguration konnte nicht geschrieben werden: ' . $configPath);
}

echo 'Admin-Zugang gespeichert.' . PHP_EOL;
if ($rotateSecret) {
    echo 'Neues JWT_SECRET erzeugt – alle bisherigen Anmeldungen sind ungültig.' . PHP_EOL;
}
