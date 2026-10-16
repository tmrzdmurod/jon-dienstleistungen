<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

/**
 * Подключение к базе данных
 * @return mysqli
 * @throws Exception
 */
function getDbConnection(): mysqli
{
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);

    if ($conn->connect_errno !== 0) {
        $socketCandidates = [
            '/var/run/mysqld/mysqld.sock',
            '/tmp/mysql.sock',
        ];

        foreach ($socketCandidates as $socketPath) {
            if (!is_readable($socketPath)) {
                continue;
            }

            $socketConn = @new mysqli('localhost', DB_USER, DB_PASS, DB_NAME, DB_PORT, $socketPath);
            if ($socketConn->connect_errno === 0) {
                $conn = $socketConn;
                break;
            }
        }
    }

    if ($conn->connect_errno !== 0) {
        http_response_code(500);
        $details = 'Verbindungsfehler (' . (int)$conn->connect_errno . '): ' . $conn->connect_error;
        throw new Exception(htmlspecialchars($details));
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}

/**
 * Проверить наличие колонки password_enc
 */
function hasPasswordEncColumn(mysqli $conn): bool
{
    $colCheck = $conn->query("SHOW COLUMNS FROM user LIKE 'password_enc'");
    $hasColumn = ($colCheck && $colCheck->num_rows > 0);
    $colCheck?->free();
    return $hasColumn;
}

/**
 * Гарантировать наличие колонки password_enc (создать при отсутствии)
 */
function ensurePasswordEncColumn(mysqli $conn): bool
{
    if (hasPasswordEncColumn($conn)) {
        return true;
    }
    $alter = $conn->query("ALTER TABLE user ADD COLUMN password_enc VARCHAR(512) DEFAULT NULL");
    return (bool)$alter;
}

/**
 * Получить ключ шифрования для пароля в админ-панели
 */
function getPasswordEncKey(): string
{
    $envKey = getenv('PASSWORD_ENC_KEY');
    if ($envKey !== false && $envKey !== '') {
        return $envKey;
    }
    return PASSWORD_ENC_KEY;
}

/**
 * Шифрование пароля для отображения администратору
 */
function encryptPasswordForAdmin(string $plain): string
{
    $key = hash('sha256', getPasswordEncKey(), true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new Exception('Fehler beim Verschlüsseln des Passworts.');
    }
    return base64_encode($iv . $tag . $cipher);
}

/**
 * Расшифровка пароля для отображения администратору
 */
function decryptPasswordForAdmin(string $payload): string
{
    $data = base64_decode($payload, true);
    if ($data === false || strlen($data) < 29) {
        return '';
    }
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $cipher = substr($data, 28);
    $key = hash('sha256', getPasswordEncKey(), true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        return '';
    }
    return $plain;
}

