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
authBootstrap();

// Проверка авторизации
$isLoggedIn = authCheck('user');
$userEmail = $_SESSION['email'] ?? '';
$username = $_SESSION['username'] ?? '';

require_once 'db_connect.php';
require_once __DIR__ . '/partials/layout.php';

// Подсчет посещений страницы
try {
    $conn = getDbConnection();
    $page = 'dobro.php';

    $stmt = $conn->prepare('SELECT visit_count FROM page_visits WHERE page = ?');
    if ($stmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param('s', $page);
    $stmt->execute();
    $stmt->bind_result($visit_count);
    if ($stmt->fetch()) {
        $stmt->close();
        $updateStmt = $conn->prepare('UPDATE page_visits SET visit_count = visit_count + 1, last_visit_date = NOW() WHERE page = ?');
        if ($updateStmt === false) {
            throw new Exception('Fehler bei der Vorbereitung des Updates: ' . htmlspecialchars($conn->error));
        }
        $updateStmt->bind_param('s', $page);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $stmt->close();
        $insertStmt = $conn->prepare('INSERT INTO page_visits (page, visit_count, last_visit_date) VALUES (?, 1, NOW())');
        if ($insertStmt === false) {
            throw new Exception('Fehler bei der Vorbereitung der Einfügung: ' . htmlspecialchars($conn->error));
        }
        $insertStmt->bind_param('s', $page);
        $insertStmt->execute();
        $insertStmt->close();
    }
} catch (Exception $e) {
    error_log('Fehler beim Zählen der Besuche: ' . $e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontakte - Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/dobro.css?v=20261013a">
</head>
<body>
    <?php renderSiteHeader(['active' => 'contact', 'isLoggedIn' => $isLoggedIn]); ?>

    <?php if (!$isLoggedIn): ?>
        <div class="notification" id="registerNotification" role="status">
            <i class="fas fa-user-plus" aria-hidden="true"></i>
            <span>Bitte <a href="signup.php">registrieren</a> Sie sich, um alle Funktionen der Website zu nutzen!</span>
            <button type="button" class="notification__close" aria-label="Hinweis schließen">×</button>
        </div>
    <?php endif; ?>

    <?php renderPageHero('Kontakte', 'Rufen Sie an oder schreiben Sie uns – wir melden uns zurück.'); ?>

    <main class="page-main" id="inhalt">
        <div class="wrap contact-layout">
            <section class="contact-section">
                <a class="contact-item contact-item--phone" href="tel:+4915758256067">
                    <i class="fas fa-phone" aria-hidden="true"></i>
                    <span><small>Telefon</small><b>+4915758256067</b></span>
                </a>
                <a class="contact-item contact-item--mail" href="mailto:suhrob.ch@gmx.de">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    <span><small>E-Mail</small><b>suhrob.ch@gmx.de</b></span>
                </a>
                <div class="contact-item contact-item--place">
                    <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                    <span><small>Standort</small><b>91054 Erlangen</b></span>
                </div>

                <div class="social-links">
                    <h2>Soziale Netzwerke</h2>
                    <a class="social social--whatsapp" href="https://wa.me/4915758256067"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>
                    <a class="social social--instagram" href="https://www.instagram.com/jon_dienstleistungen"><i class="fab fa-instagram" aria-hidden="true"></i> Instagram</a>
                    <a class="social social--facebook" href="https://www.facebook.com/jondienstleistungen"><i class="fab fa-facebook-f" aria-hidden="true"></i> Facebook</a>
                    <a class="social social--telegram" href="https://t.me/I20I2007"><i class="fab fa-telegram-plane" aria-hidden="true"></i> Telegram</a>
                </div>
            </section>

            <aside class="contact-aside">
                <img src="img/sl6.jpg" alt="Aufgeständerte Solarmodule auf einem Flachdach" width="1280" height="960">
                <div class="contact-aside__body">
                    <h2>Preis nach Vereinbarung</h2>
                    <p>Beschreiben Sie kurz das Objekt: Dachart, Anzahl der Module, Ort. Der Meister sieht es sich an und nennt den Preis nach Umfang der Arbeit.</p>
                    <div class="contact-aside__actions">
                        <a class="btn btn--sun" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
                        <a class="btn btn--glass" href="index.php#anfrage">Anfrage zusammenstellen</a>
                    </div>
                </div>
            </aside>
        </div>
    </main>

    <?php renderSiteFooter(); ?>
</body>
</html>
