<?php
declare(strict_types=1);
$sessionDir = __DIR__ . '/tmp/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
session_start();
require_once __DIR__ . '/auth.php';

$scope = (string)($_GET['scope'] ?? 'all');
if (!in_array($scope, ['all', 'user', 'admin', 'employee'], true)) {
    $scope = 'all';
}

if ($scope === 'all') {
    // Alle Tokens widerrufen und die Session samt Cookie verwerfen.
    foreach (array_keys(authScopes()) as $name) {
        authLogout($name);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }
    session_destroy();
} else {
    authLogout($scope);
    session_regenerate_id(true);
}

header('Location: login.php');
exit;
