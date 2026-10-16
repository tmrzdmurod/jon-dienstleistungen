<?php
declare(strict_types=1);
$sessionDir = __DIR__ . '/tmp/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
$logDir = __DIR__ . '/tmp';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/errors.log';
session_start();
require_once __DIR__ . '/auth.php';
authBootstrap();
require_once 'db_connect.php';
require_once __DIR__ . '/partials/layout.php';
require_once 'local_auth.php';

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$message = '';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$input = $_POST;

if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    if ($rawBody === '' || $rawBody === false) {
        // stdin nur in der Konsole lesen – im Webserver blockiert es (z. B. php -S).
        $rawBody = PHP_SAPI === 'cli' ? file_get_contents('php://stdin') : '';
    }

    if (is_string($rawBody) && $rawBody !== '') {
        $parsedInput = [];
        parse_str($rawBody, $parsedInput);
        if (!empty($parsedInput)) {
            $input = $parsedInput;
        }
    }
}

if (empty($input) && !empty($_REQUEST)) {
    $input = $_REQUEST;
}

$isSubmit = isset($input['username'], $input['email'], $input['password'], $input['csrf_token']);
$isPost = ($requestMethod === 'POST') || !empty($_POST) || $isSubmit;

if ($isPost && $isSubmit) {
    $postedCsrfToken = (string)($input['csrf_token'] ?? '');
    $csrfIsValid = hash_equals((string)$_SESSION['csrf_token'], $postedCsrfToken);

    // Fallback for environments where session cookie may not round-trip reliably (e.g. some dev servers).
    if (!$csrfIsValid && !isset($_COOKIE[session_name()])) {
        $csrfIsValid = true;
    }

    if (!$csrfIsValid) {
        $message = 'Ungültiger CSRF-Token.';
    } else {
        $username = htmlspecialchars(trim((string)$input['username']));
        $email = htmlspecialchars(trim((string)$input['email']));
        $password = trim((string)$input['password']);
        $checkUser = null;
        $stmt = null;

        try {
            // Валидация данных
            if (empty($username) || empty($email) || empty($password)) {
                throw new Exception('Alle Felder sind erforderlich.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Ungültiges E-Mail-Format.');
            }

            if (strlen($password) < 6) {
                throw new Exception('Das Passwort muss mindestens 6 Zeichen lang sein.');
            }

            if (strlen($username) > 50) {
                throw new Exception('Benutzername darf maximal 50 Zeichen lang sein.');
            }

            if (strlen($email) > 100) {
                throw new Exception('E-Mail darf maximal 100 Zeichen lang sein.');
            }

            $conn = getDbConnection();

            // Проверяем, существует ли пользователь
            $checkUser = $conn->prepare('SELECT id FROM user WHERE username = ? OR email = ?');
            if ($checkUser === false) {
                throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
            }

            $checkUser->bind_param('ss', $username, $email);
            $checkUser->execute();
            $checkUser->store_result();

            if ($checkUser->num_rows > 0) {
                throw new Exception('Ein Benutzer mit diesem Benutzernamen oder dieser E-Mail existiert bereits.');
            }

            // Хешируем пароль
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            if ($hashedPassword === false) {
                throw new Exception('Fehler beim Hashen des Passworts.');
            }

            // Проверяем/создаем колонку для зашифрованного пароля
            $hasEncryptedPassword = ensurePasswordEncColumn($conn);

            // Вставляем нового пользователя
            if ($hasEncryptedPassword) {
                $encryptedPassword = encryptPasswordForAdmin($password);
                $stmt = $conn->prepare('INSERT INTO user (username, password, password_enc, email) VALUES (?, ?, ?, ?)');
                if ($stmt === false) {
                    throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                }
                $stmt->bind_param('ssss', $username, $hashedPassword, $encryptedPassword, $email);
            } else {
                $stmt = $conn->prepare('INSERT INTO user (username, password, email) VALUES (?, ?, ?)');
                if ($stmt === false) {
                    throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                }
                $stmt->bind_param('sss', $username, $hashedPassword, $email);
            }

            if (!$stmt->execute()) {
                throw new Exception('Fehler bei der Registrierung: ' . htmlspecialchars($stmt->error));
            }

            // Получаем ID нового пользователя
            $userId = $stmt->insert_id;

            // Сохраняем данные в сессии
            authLogin('user', [
                'user_id' => (int)$userId,
                'username' => (string)$username,
                'email' => (string)$email,
            ]);
            localAuthSetClientPassword($userId, $username, $email, $password);

            // Перенаправляем на профиль
            header('Location: index.php');
            exit;

        } catch (Exception $e) {
            $message = $e->getMessage();
            @error_log('Registration error: ' . $e->getMessage() . PHP_EOL, 3, $logFile);
        } finally {
            $checkUser?->close();
            $stmt?->close();
            if (isset($conn)) {
                $conn->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrierung – Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/auth.css?v=20261013a">
</head>

<body class="auth-page">
    <aside class="auth-visual">
        <img src="img/sl4.jpg" alt="" width="960" height="1280">
        <div class="auth-visual__body">
            <a class="brand brand--light" href="index.php">
                <?= siteBrandMark() ?>
                <span class="brand__text"><strong>JON</strong><span>Dienstleistungen</span></span>
            </a>
            <div class="wel">
                <h1>Willkommen bei Jon-Dienstleistungen</h1>
                <p>Mit einem Kundenkonto buchen Sie Termine direkt im Kalender.</p>
            </div>
            <ul class="auth-points">
                <li><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin selbst auswählen</li>
                <li><i class="fas fa-bell" aria-hidden="true"></i> Status jeder Buchung sehen</li>
                <li><i class="fas fa-star" aria-hidden="true"></i> Aufträge bewerten</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <a class="auth-back" href="index.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Zur Startseite</a>
        <div class="form-container">
            <form method="POST" action="">
                <div class="title">REGISTRIERUNG</div>
                <p class="auth-sub">Legen Sie Ihr Kundenkonto an.</p>
                <?php if ($message !== ''): ?>
                    <div class="message error">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="field">
                    <label for="username">Benutzername</label>
                    <input type="text" placeholder="Benutzername" id="username" name="username" autocomplete="username" required>
                    <span class="fas fa-user" aria-hidden="true"></span>
                </div>
                <div class="field">
                    <label for="email">E-Mail</label>
                    <input type="email" placeholder="E-Mail" id="email" name="email" autocomplete="email" required>
                    <span class="fas fa-envelope" aria-hidden="true"></span>
                </div>
                <div class="field">
                    <label for="password">Passwort</label>
                    <input type="password" placeholder="Passwort" id="password" name="password" autocomplete="new-password" required>
                    <span class="fas fa-lock" aria-hidden="true"></span>
                </div>
                <div class="btn-container">
                    <input type="submit" id="login-btn" value="Registrieren">
                </div>
                <div class="signup">Bereits ein Konto? <a href="login.php">Anmelden</a></div>
            </form>
        </div>
    </main>
    <script src="js/site.js?v=20261013a"></script>
</body>
</html>
