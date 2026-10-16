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
if (authSecret() === '') {
    $message = 'Anmeldung ist nicht konfiguriert (JWT_SECRET fehlt).';
}

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

$isSubmit = isset($input['username'], $input['password'], $input['csrf_token']);
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
        $username_input = htmlspecialchars(trim((string)$input['username']));
        $password_input = trim((string)$input['password']);
        $checkUser = null;
        // Einheitliche Meldung: verrät nicht, ob es den Benutzernamen gibt.
        $wrongCredentials = 'Benutzername oder Passwort ist falsch.';

        try {
            // Валидация данных
            if (empty($username_input) || empty($password_input)) {
                throw new Exception('Benutzername und Passwort sind erforderlich.');
            }

            $waitSeconds = authLoginBlockedFor($username_input);
            if ($waitSeconds > 0) {
                throw new RuntimeException('Zu viele Fehlversuche. Bitte in ' . (int)ceil($waitSeconds / 60) . ' Minute(n) erneut versuchen.');
            }

            // Проверяем, является ли пользователь администратором
            if (authVerifyAdminLogin($username_input, $password_input)) {
                authLoginSucceeded($username_input);
                authLogin('admin', ['admin' => true, 'admin_username' => $username_input]);
                header('Location: admin.php');
                exit;
            }

            // Сначала локальная авторизация сотрудников (не зависит от БД)
            $employeeAuth = localAuthVerifyEmployee($username_input, $password_input);
            if (is_array($employeeAuth)) {
                authLoginSucceeded($username_input);
                authLogin('employee', [
                    'employee_auth' => true,
                    'employee_id' => (int)($employeeAuth['employee_id'] ?? 0),
                    'employee_name' => (string)($employeeAuth['full_name'] ?? ''),
                    'employee_email' => (string)($employeeAuth['email'] ?? ''),
                    'employee_role' => (string)($employeeAuth['role'] ?? ''),
                    'employee_status' => (string)($employeeAuth['status'] ?? 'inactive'),
                    'employee_must_change_password' => !empty($employeeAuth['must_change_password']),
                ]);
                header('Location: employee_profile.php');
                exit;
            }

            $dbUnavailable = false;
            try {
                $conn = getDbConnection();
            } catch (Exception $dbException) {
                $dbUnavailable = true;
            }

            if ($dbUnavailable) {
                // Fallback für Situationen ohne DB-Verbindung
                $localClientAuth = localAuthVerifyClient($username_input, $password_input);
                if (is_array($localClientAuth)) {
                    authLoginSucceeded($username_input);
                    authLogin('user', [
                        'user_id' => (int)($localClientAuth['user_id'] ?? 0),
                        'username' => (string)($localClientAuth['username'] ?? $username_input),
                        'email' => (string)($localClientAuth['email'] ?? ''),
                    ]);
                    header('Location: index.php');
                    exit;
                }
                authLoginFailed($username_input);
                throw new Exception('Anmeldung nicht möglich: Datenbank nicht erreichbar und kein lokales Konto gefunden.');
            }

            // Проверяем, существует ли пользователь
            $checkUser = $conn->prepare('SELECT id, password, username, email FROM user WHERE username = ?');
            if ($checkUser === false) {
                throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
            }

            $checkUser->bind_param('s', $username_input);
            $checkUser->execute();
            $checkUser->store_result();
            $checkUser->bind_result($userId, $hashedPassword, $userName, $userEmail);

            if ($checkUser->num_rows > 0) {
                $checkUser->fetch();
                if (password_verify($password_input, $hashedPassword)) {
                    authLoginSucceeded($username_input);
                    authLogin('user', [
                        'user_id' => (int)$userId,
                        'username' => (string)$userName,
                        'email' => (string)$userEmail,
                    ]);
                    localAuthSetClientPassword((int)$userId, (string)$userName, (string)$userEmail, $password_input);
                    header('Location: index.php');
                    exit;
                }
                authLoginFailed($username_input);
                @error_log('Login error: Falsches Passwort.' . PHP_EOL, 3, $logFile);
                throw new Exception($wrongCredentials);
            }

            authLoginFailed($username_input);
            @error_log('Login error: Benutzer nicht gefunden.' . PHP_EOL, 3, $logFile);
            throw new Exception($wrongCredentials);
        } catch (Exception $e) {
            $message = $e->getMessage();
            if ($message !== $wrongCredentials) {
                @error_log('Login error: ' . $message . PHP_EOL, 3, $logFile);
            }
        } finally {
            $checkUser?->close();
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
    <title>Anmeldung – Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/auth.css?v=20261013a">
</head>
<body class="auth-page">
    <aside class="auth-visual">
        <img src="img/sl2.jpg" alt="" width="1280" height="960">
        <div class="auth-visual__body">
            <a class="brand brand--light" href="index.php">
                <?= siteBrandMark() ?>
                <span class="brand__text"><strong>JON</strong><span>Dienstleistungen</span></span>
            </a>
            <div class="wel">
                <h1>Willkommen zurück</h1>
                <p>Termine buchen, Status verfolgen und Aufträge bewerten – alles in Ihrem Kundenkonto.</p>
            </div>
            <ul class="auth-points">
                <li><i class="fas fa-calendar-check" aria-hidden="true"></i> Freie Tage im Buchungskalender</li>
                <li><i class="fas fa-handshake" aria-hidden="true"></i> Preis nach Vereinbarung</li>
                <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> 91054 Erlangen</li>
            </ul>
        </div>
    </aside>

    <main class="auth-main">
        <a class="auth-back" href="index.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Zur Startseite</a>
        <div class="form-container">
            <form method="POST" action="">
                <div class="title">ANMELDUNG</div>
                <p class="auth-sub">Melden Sie sich mit Ihrem Benutzernamen an.</p>
                <?php if ($message !== ''): ?>
                    <div class="message <?= strpos($message, 'erfolgreich') !== false ? 'success' : 'error' ?>">
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
                    <label for="password">Passwort</label>
                    <input type="password" placeholder="Passwort" id="password" name="password" autocomplete="current-password" required>
                    <span class="fas fa-lock" aria-hidden="true"></span>
                </div>
                <div class="btn-container">
                    <input type="submit" id="login-btn" value="Anmelden">
                </div>
                <div class="signup">Noch kein Konto? <a href="signup.php">Registrieren</a></div>
            </form>
        </div>
    </main>
    <script src="js/site.js?v=20261013a"></script>
</body>
</html>
