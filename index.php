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
if (!$isLoggedIn) {
    // Не перенаправляем, чтобы показать страницу для неавторизованных пользователей
}

require_once 'db_connect.php';
require_once 'employee_workflow.php';
require_once __DIR__ . '/partials/layout.php';

// Подсчет посещений страницы
try {
    $conn = getDbConnection();
    $page = 'index.php';

    // Проверяем, есть ли запись для страницы
    $stmt = $conn->prepare('SELECT visit_count FROM page_visits WHERE page = ?');
    if ($stmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param('s', $page);
    $stmt->execute();
    $stmt->bind_result($visit_count);
    if ($stmt->fetch()) {
        // Обновляем счетчик
        $stmt->close();
        $updateStmt = $conn->prepare('UPDATE page_visits SET visit_count = visit_count + 1, last_visit_date = NOW() WHERE page = ?');
        if ($updateStmt === false) {
            throw new Exception('Fehler bei der Vorbereitung des Updates: ' . htmlspecialchars($conn->error));
        }
        $updateStmt->bind_param('s', $page);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        // Создаем новую запись, если страница не найдена
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

// Получение данных пользователя
$userEmail = $_SESSION['email'] ?? '';
$username = $_SESSION['username'] ?? '';
$isAdmin = authCheck('admin');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$benachrichtigungen = [];
$avgRating = 0.0;
$bewertungen = [];
$hasMore = false;
$totalBewertungen = 0;
$limit = 3;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;
$ratingFilter = isset($_GET['rating_filter']) ? (int)$_GET['rating_filter'] : 0;
$serviceTypes = workflowReadServiceTypes();

// Массив для перевода месяцев на немецкий
$deutscheMonate = [
    'January' => 'Januar',
    'February' => 'Februar',
    'March' => 'März',
    'April' => 'April',
    'May' => 'Mai',
    'June' => 'Juni',
    'July' => 'Juli',
    'August' => 'August',
    'September' => 'September',
    'October' => 'Oktober',
    'November' => 'November',
    'December' => 'Dezember'
];

function indexNormalizeText(string $value): string {
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }
    return strtolower($value);
}

/**
 * Icon, Kurzbeschreibung und Akzentfarbe je Dienstleistung.
 */
function indexServiceMeta(string $serviceType): array {
    $normalized = indexNormalizeText($serviceType);

    if (str_contains($normalized, 'solar') || str_contains($normalized, 'photo')) {
        return ['fa-solar-panel', 'Planung, Montage und Inbetriebnahme von Photovoltaik-Anlagen.', 'blue'];
    }
    if (str_contains($normalized, 'garten')) {
        return ['fa-seedling', 'Pflege, Schnitt und saisonale Gartenarbeiten aus einer Hand.', 'green'];
    }
    if (str_contains($normalized, 'entr')) {
        return ['fa-box-open', 'Schnelle Entrümpelung inklusive Sortierung und Entsorgung.', 'clay'];
    }
    if (str_contains($normalized, 'umzug')) {
        return ['fa-truck-fast', 'Organisation, Transport und Tragehilfe für stressfreie Umzüge.', 'sun'];
    }
    if (str_contains($normalized, 'haushalt') || str_contains($normalized, 'wohn')) {
        return ['fa-house-circle-check', 'Diskrete Haushalts- und Wohnungsauflösung mit klaren Abläufen.', 'violet'];
    }
    if (str_contains($normalized, 'elektr')) {
        return ['fa-bolt', 'Sichere Elektroarbeiten, Prüfung und Reparatur vor Ort.', 'sun'];
    }
    if (str_contains($normalized, 'wartung') || str_contains($normalized, 'support')) {
        return ['fa-screwdriver-wrench', 'Regelmäßige Wartung und schneller technischer Support.', 'blue'];
    }

    return ['fa-briefcase', 'Professioneller Service für private und gewerbliche Projekte.', 'blue'];
}

function indexFormatDate(string $timestamp, array $monate, bool $withTime = false): string {
    $time = strtotime($timestamp);
    if ($time === false) {
        return '';
    }
    $month = date('F', $time);
    return str_replace($month, $monate[$month] ?? $month, date($withTime ? 'd. F Y H:i' : 'd. F Y', $time));
}

/**
 * Öffentlich wird nur der Teil vor dem @ gezeigt, nicht die ganze E-Mail-Adresse.
 */
function indexReviewerName(string $email): string {
    $name = trim(strstr($email, '@', true) ?: $email);
    return $name !== '' ? $name : 'Kunde';
}

function indexInitial(string $name): string {
    return function_exists('mb_substr') ? mb_strtoupper(mb_substr($name, 0, 1)) : strtoupper(substr($name, 0, 1));
}

function indexRenderStars(float $rating): void {
    echo '<div class="rating" role="img" aria-label="' . htmlspecialchars((string)$rating) . ' von 5 Sternen">';
    for ($i = 1; $i <= 5; $i++) {
        echo '<i class="fas fa-star' . ($i <= $rating ? ' is-on' : '') . '" aria-hidden="true"></i>';
    }
    echo '</div>';
}

/**
 * Eine Kundenstimme – genutzt vom Seitenaufbau und vom AJAX-Nachladen.
 */
function indexRenderReview(array $row, bool $isAdmin, array $monate, string $csrfToken = ''): void {
    $id = htmlspecialchars((string)$row['id']);
    $name = indexReviewerName((string)$row['user_email']);
    $isNew = strtotime((string)$row['created_at']) >= strtotime('-7 days');
    ?>
    <article class="review-item<?= $isNew ? ' new' : '' ?>" data-review-id="<?= $id ?>">
        <header class="review-head">
            <span class="review-avatar" aria-hidden="true"><?= htmlspecialchars(indexInitial($name)) ?></span>
            <div class="review-who">
                <div class="client-name"<?= $isAdmin ? ' title="' . htmlspecialchars((string)$row['user_email']) . '"' : '' ?>><?= htmlspecialchars($name) ?></div>
                <div class="review-date"><?= htmlspecialchars(indexFormatDate((string)$row['created_at'], $monate)) ?></div>
            </div>
            <?php if ($isNew): ?><span class="review-new">Neu</span><?php endif; ?>
        </header>
        <?php indexRenderStars((float)$row['rating']); ?>
        <p class="review-text"><?= htmlspecialchars((string)$row['review_text']) ?></p>
        <?php if ($isAdmin && empty($row['reply_text'])): ?>
            <div class="reply-section">
                <form method="POST" class="reply-form" data-review-id="<?= $id ?>">
                    <input type="hidden" name="review_id" value="<?= $id ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <textarea name="reply_text" placeholder="Antwort eingeben" required></textarea>
                    <button type="submit" class="btn btn--primary btn--sm">Antwort senden</button>
                </form>
            </div>
        <?php elseif (!empty($row['reply_text'])): ?>
            <div class="reply">
                <strong><i class="fas fa-reply" aria-hidden="true"></i> Antwort von Jon Dienstleistungen</strong>
                <p><?= htmlspecialchars((string)$row['reply_text']) ?></p>
                <div class="reply-date"><?= htmlspecialchars(indexFormatDate((string)$row['reply_date'], $monate, true)) ?></div>
            </div>
        <?php endif; ?>
    </article>
    <?php
}

/**
 * Kundenstimmen samt Admin-Antwort laden; liefert [Zeilen, Gesamtzahl].
 */
function indexLoadReviews(mysqli $conn, int $ratingFilter, int $limit, int $offset): array {
    $query = 'SELECT r.*, rr.reply_text, rr.reply_date FROM reviews r LEFT JOIN review_replies rr ON r.id = rr.review_id WHERE r.booking_id IS NOT NULL';
    $params = [];
    if ($ratingFilter > 0) {
        $query .= ' AND r.rating = ?';
        $params[] = $ratingFilter;
    }
    $query .= ' ORDER BY r.created_at DESC LIMIT ? OFFSET ?';
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param(str_repeat('i', count($params)), ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($ratingFilter > 0) {
        $totalStmt = $conn->prepare('SELECT COUNT(*) FROM reviews WHERE booking_id IS NOT NULL AND rating = ?');
        if ($totalStmt === false) {
            throw new Exception('Fehler bei der Abfrage der Gesamtzahl: ' . htmlspecialchars($conn->error));
        }
        $totalStmt->bind_param('i', $ratingFilter);
        $totalStmt->execute();
        $total = (int)$totalStmt->get_result()->fetch_row()[0];
        $totalStmt->close();
    } else {
        $totalResult = $conn->query('SELECT COUNT(*) FROM reviews WHERE booking_id IS NOT NULL');
        if ($totalResult === false) {
            throw new Exception('Fehler bei der Abfrage der Gesamtzahl: ' . htmlspecialchars($conn->error));
        }
        $total = (int)$totalResult->fetch_row()[0];
    }

    return [$rows, $total];
}

try {
    $conn = getDbConnection();

    // Получение последних 5 уведомлений
    if ($isLoggedIn) {
        $stmt = $conn->prepare('SELECT date, status FROM booking WHERE booked_by = ? AND status IN ("pending", "approved", "rejected") ORDER BY created_at DESC LIMIT 5');
        if ($stmt === false) {
            throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
        }
        $stmt->bind_param('s', $userEmail);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $benachrichtigungen[] = $row;
        }
        $stmt->close();
    }

    // Обработка ответа администратора
    if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_id'], $_POST['reply_text'])) {
        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            exit('Ungültiger CSRF-Token.');
        }
        $reviewId = (int)$_POST['review_id'];
        $replyText = trim($_POST['reply_text'] ?? '');
        if ($replyText !== '') {
            $stmt = $conn->prepare('INSERT INTO review_replies (review_id, reply_text) VALUES (?, ?)');
            if ($stmt === false) {
                throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
            }
            $stmt->bind_param('is', $reviewId, $replyText);
            if (!$stmt->execute()) {
                throw new Exception('Fehler beim Einfügen der Antwort: ' . htmlspecialchars($stmt->error));
            }
            $stmt->close();
            // Перенаправление для предотвращения повторной отправки формы
            header('Location: index.php?offset=' . $offset . '&rating_filter=' . $ratingFilter);
            exit;
        }
    }

    // Получение отзывов с пагинацией и фильтрацией
    [$bewertungen, $totalBewertungen] = indexLoadReviews($conn, $ratingFilter, $limit, $offset);
    $hasMore = ($offset + $limit) < $totalBewertungen;

    // Обработка AJAX-запроса для загрузки отзывов
    if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
        foreach ($bewertungen as $row) {
            indexRenderReview($row, $isAdmin, $deutscheMonate, $csrfToken);
        }
        // Маркер для клиента: есть ли ещё отзывы
        if ($hasMore) {
            echo '<button class="load-more" type="button">Mehr Kundenstimmen</button>';
        }
        exit;
    }

    // Получение средней оценки
    $avgRatingStmt = $conn->query('SELECT AVG(rating) as avg_rating FROM reviews WHERE rating IS NOT NULL');
    if ($avgRatingStmt === false) {
        throw new Exception('Fehler bei der выборке средней оценки: ' . htmlspecialchars($conn->error));
    }
    $avgRatingResult = $avgRatingStmt->fetch_assoc();
    $avgRating = round((float)($avgRatingResult['avg_rating'] ?? 0.0), 1);

} catch (Exception $e) {
    http_response_code(500);
    error_log('Fehler auf der Profilseite: ' . $e->getMessage());
    echo 'Fehler: ' . htmlspecialchars($e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

$contact = siteContact();

// Eigene Projektfotos: [Datei, Titel, Unterzeile, Breite, Höhe, Bildausschnitt]
$projektFotos = [
    ['img/sl1.jpg', 'Wohnhaus mit Ziegeldach', 'Schwarze Module auf dem Satteldach', 960, 1280, 'center 86%'],
    ['img/sl2.jpg', 'Hallendach', 'Großes Modulfeld auf dem Gewerbedach', 1280, 960, 'center'],
    ['img/sl4.jpg', 'Unterkonstruktion', 'Schienen und Dachhaken vor der Belegung', 960, 1280, 'center'],
    ['img/sl5.jpg', 'Verkabelung', 'Modulfeld mit Leitungsführung zum Wechselrichter', 960, 1280, 'center'],
    ['img/sl3.jpg', 'Steildach mit Gerüst', 'Montage mit Fangnetz und Absturzsicherung', 960, 1280, 'center 70%'],
    ['img/sl6.jpg', 'Flachdach', 'Aufgeständerte Module auf dem Flachdach', 1280, 960, 'center'],
];

// Ablauf der Montage: [Foto, Titel, Text, Bildausschnitt]
$ablauf = [
    ['img/sl1.jpg', 'Besichtigung', 'Wir sehen uns Dach, Zählerschrank und Kabelwege direkt vor Ort an.', 'center 80%'],
    ['img/sl6.jpg', 'Angebot', 'Der Meister nennt den Preis – nach Modulanzahl, Dachart und Zusatzarbeiten.', 'center 30%'],
    ['img/sl3.jpg', 'Gerüst & Sicherung', 'Gerüst und Fangnetz stehen, bevor die erste Schiene aufs Dach kommt.', 'center 62%'],
    ['img/sl4.jpg', 'Unterkonstruktion', 'Dachhaken und Schienen werden gesetzt – das Dach bleibt dicht.', '70% 40%'],
    ['img/sl2.jpg', 'Modulmontage', 'Module werden ausgerichtet, geklemmt und in Strängen verkabelt.', 'center 40%'],
    ['img/sl5.jpg', 'Anschluss & Übergabe', 'Leitungen zum Wechselrichter, Funktionsprüfung und Einweisung.', 'center 88%'],
];

// Leistungen rund um die Solarmontage: Gruppe => [Icon, [[Leistung, Abrechnung], ...]]
$leistungen = [
    'Solarmontage auf dem Schrägdach' => ['fa-house-chimney', [
        ['Montage auf Ziegeldach (Dachhaken)', 'nach Modulanzahl'],
        ['Montage auf Trapezblech oder Wellplatten', 'nach Modulanzahl'],
        ['Unterkonstruktion: Schienen und Dachhaken setzen', 'nach Aufwand'],
        ['Demontage und Umsetzen bestehender Module', 'nach Besichtigung'],
    ]],
    'Solarmontage auf dem Flachdach' => ['fa-building', [
        ['Aufständerung und Ballastierung', 'nach Modulanzahl'],
        ['Module ausrichten und klemmen', 'nach Modulanzahl'],
        ['Kabelwege und Durchführungen', 'nach Aufwand'],
    ]],
    'Elektrik, Wartung und Support' => ['fa-bolt', [
        ['Strangverkabelung bis zum Wechselrichter', 'nach Aufwand'],
        ['Inbetriebnahme und Funktionsprüfung', 'nach Absprache'],
        ['Wartung und Fehlersuche an bestehenden Anlagen', 'nach Aufwand'],
    ]],
];

$preisFaktoren = [
    ['fa-solar-panel', 'Modulanzahl', 'Wie viele Module montiert werden.'],
    ['fa-house-chimney', 'Dachart & Neigung', 'Ziegel, Blech oder Flachdach.'],
    ['fa-people-carry-box', 'Zugang & Gerüst', 'Höhe, Zufahrt und Absicherung.'],
    ['fa-plug', 'Kabelwege', 'Strecke bis zum Wechselrichter.'],
];

$faq = [
    ['Was kostet die Montage?', 'Einen festen Stückpreis gibt es bei uns nicht. Der Preis wird vereinbart: Der Meister sieht sich das Objekt an und nennt ihn nach dem Umfang der Arbeit – Modulanzahl, Dachart, Gerüst und Kabelwege. Erst danach entscheiden Sie.'],
    ['Wo können Solarmodule montiert werden?', 'Auf Ziegel- und Blechdächern, auf Flachdächern mit Aufständerung und auf Hallen. Ob Ihr Dach geeignet ist, klären wir bei der Besichtigung.'],
    ['Wie lange dauert die Montage?', 'Das hängt vom Umfang ab. Die Dauer nennen wir Ihnen zusammen mit dem Preis, sobald wir das Objekt gesehen haben.'],
    ['Wie buche ich einen Termin?', 'Im Buchungskalender wählen Sie einen freien Tag und die Dienstleistung. Dafür brauchen Sie ein Kundenkonto. Sobald der Termin bestätigt ist, sehen Sie den Status unter „Buchungen“.'],
    ['Funktioniert eine Solaranlage auch im Winter?', 'Ja. Die Module arbeiten mit Licht, nicht mit Wärme – im Winter fällt der Ertrag wegen der kürzeren Tage niedriger aus.'],
    ['Welche Arbeiten übernehmen Sie außer Solar?', 'Alle Leistungen, die Sie im Buchungskalender auswählen können – aktuell: ' . implode(', ', array_map('strval', $serviceTypes)) . '.'],
];

$statusText = ['pending' => 'Wartet auf Genehmigung', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Montage von Solaranlagen in Erlangen – Jon Dienstleistungen</title>
    <meta name="description" content="Jon Dienstleistungen montiert Solaranlagen in Erlangen und Umgebung. Preis nach Vereinbarung: Der Meister nennt ihn nach Umfang der Arbeit.">
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/index.css?v=20261013a">
</head>

<body>
    <?php renderSiteHeader(['active' => 'home', 'isLoggedIn' => $isLoggedIn]); ?>

    <?php if (!$isLoggedIn): ?>
        <div class="notification" id="registerNotification" role="status">
            <i class="fas fa-user-plus" aria-hidden="true"></i>
            <span>Bitte <a href="signup.php">registrieren</a> Sie sich, um alle Funktionen der Website zu nutzen!</span>
            <button type="button" class="notification__close" aria-label="Hinweis schließen">×</button>
        </div>
    <?php endif; ?>

    <main id="inhalt">
        <!-- Kopfband -->
        <section class="hero">
            <div class="wrap hero__inner">
                <div class="hero__text">
                    <ol class="crumbs crumbs--left" aria-label="Brotkrumen">
                        <li><a href="index.php">Startseite</a></li>
                        <li aria-current="page">Montage von Solaranlagen</li>
                    </ol>
                    <p class="hero__hello">
                        <i class="fas fa-sun" aria-hidden="true"></i>
                        Willkommen<?= $isLoggedIn ? ', ' . htmlspecialchars($username) : '' ?> bei Jon Dienstleistungen
                    </p>
                    <h1>Montage von <span>Solaranlagen</span> in Erlangen</h1>
                    <p class="hero__lead">Wir sind Ihr zuverlässiger Partner für nachhaltige Energielösungen und professionelle
                        Dienstleistungen: Solarmontage, Gartenarbeiten, Entrümpelung, Umzugshilfe und Haushaltsauflösungen.</p>
                    <div class="hero__actions">
                        <a class="btn btn--sun" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
                        <a class="btn btn--glass" href="#anfrage"><i class="fas fa-clipboard-list" aria-hidden="true"></i> Anfrage zusammenstellen</a>
                    </div>
                    <ul class="hero__facts">
                        <li><i class="fas fa-handshake" aria-hidden="true"></i> Preis nach Vereinbarung</li>
                        <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?= htmlspecialchars($contact['address']) ?></li>
                        <li><i class="far fa-calendar-alt" aria-hidden="true"></i> Seit <?= htmlspecialchars($contact['since']) ?></li>
                    </ul>
                </div>
                <div class="hero__photo" aria-hidden="true">
                    <img src="img/sl2.jpg" alt="" width="1280" height="960">
                </div>
            </div>
        </section>

        <?php if ($isLoggedIn): ?>
            <!-- Letzte Buchungen des angemeldeten Kunden -->
            <section class="bookings-strip notifications-section" aria-label="Letzte Benachrichtigungen">
                <div class="wrap bookings-strip__inner">
                    <h2><i class="fas fa-bell" aria-hidden="true"></i> Letzte Benachrichtigungen</h2>
                    <?php if (empty($benachrichtigungen)): ?>
                        <p class="bookings-strip__empty">Keine Benachrichtigungen.</p>
                    <?php else: ?>
                        <ul class="bookings-strip__list">
                            <?php foreach ($benachrichtigungen as $benachrichtigung): ?>
                                <li class="notification-item <?= htmlspecialchars($benachrichtigung['status']) ?>">
                                    <span>Buchung für <b><?= htmlspecialchars($benachrichtigung['date']) ?></b></span>
                                    <span class="badge badge--<?= htmlspecialchars($benachrichtigung['status']) ?>"><?= htmlspecialchars($statusText[$benachrichtigung['status']] ?? $benachrichtigung['status']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="bookings-strip__all" href="orders.php">Alle Buchungen anzeigen <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Einstieg: Fotos + Preiskarte -->
        <section class="section" id="preis">
            <div class="wrap">
                <header class="section-head">
                    <h2>Solaranlage montieren lassen</h2>
                    <p>Preis für die Montage – nach Vereinbarung</p>
                </header>

                <div class="intro">
                    <div class="shots" data-slider>
                        <div class="shots__track" data-slider-track>
                            <?php foreach ($projektFotos as $i => [$src, $titel, $unterzeile, $w, $h, $ausschnitt]): ?>
                                <figure class="shot">
                                    <img src="<?= $src ?>" alt="<?= htmlspecialchars($titel . ' – ' . $unterzeile) ?>" width="<?= $w ?>" height="<?= $h ?>" style="object-position: <?= $ausschnitt ?>"<?= $i > 1 ? ' loading="lazy"' : '' ?>>
                                    <figcaption><?= htmlspecialchars($titel) ?></figcaption>
                                </figure>
                            <?php endforeach; ?>
                        </div>
                        <div class="slider-nav">
                            <button type="button" class="slider-btn" data-slider-prev aria-label="Vorheriges Foto"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                            <div class="slider-dots" data-slider-dots></div>
                            <button type="button" class="slider-btn" data-slider-next aria-label="Nächstes Foto"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                        </div>
                    </div>

                    <aside class="price-card">
                        <h3>So entsteht Ihr Preis</h3>
                        <ol class="price-card__rows">
                            <li><span>Besichtigung: Dach, Zählerschrank, Kabelwege</span><b>vor Ort</b></li>
                            <li><span>Umfang: Modulanzahl, Dachart, Zusatzarbeiten</span><b>wird geprüft</b></li>
                            <li><span>Montage und Anschluss der Module</span><b>nach Aufwand</b></li>
                        </ol>
                        <p class="price-card__total"><i class="fas fa-handshake" aria-hidden="true"></i> Preis nach Vereinbarung</p>
                        <p class="price-card__note">* Es gibt keinen festen Stückpreis. Der Meister nennt den Preis selbst –
                            nach dem Umfang der Arbeit: Anzahl der Module, Schwierigkeit der Montage und zusätzliche Arbeiten.</p>
                        <a class="btn btn--primary btn--block" href="calendar.php">Montage buchen</a>
                        <a class="btn btn--outline btn--block" href="#anfrage">Individuelle Anfrage</a>
                    </aside>
                </div>
            </div>
        </section>

        <!-- Ablauf -->
        <section class="section section--mist" id="ablauf">
            <div class="wrap">
                <header class="section-head">
                    <h2>Ablauf der Montage</h2>
                    <p>von der Besichtigung bis zur Übergabe</p>
                </header>

                <div class="steps" data-slider>
                    <ol class="steps__track" data-slider-track>
                        <?php foreach ($ablauf as $i => [$src, $titel, $text, $ausschnitt]): ?>
                            <li class="step">
                                <img src="<?= $src ?>" alt="" loading="lazy" style="object-position: <?= $ausschnitt ?>">
                                <div class="step__body">
                                    <span class="step__no"><?= sprintf('%02d', $i + 1) ?></span>
                                    <h3><?= htmlspecialchars($titel) ?></h3>
                                    <p><?= htmlspecialchars($text) ?></p>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="slider-nav">
                        <button type="button" class="slider-btn" data-slider-prev aria-label="Vorheriger Schritt"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
                        <div class="slider-dots" data-slider-dots></div>
                        <button type="button" class="slider-btn" data-slider-next aria-label="Nächster Schritt"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
                    </div>
                </div>

                <div class="section-cta">
                    <a class="btn btn--primary" href="<?= htmlspecialchars($contact['whatsapp']) ?>"><i class="fab fa-whatsapp" aria-hidden="true"></i> Beratung per WhatsApp</a>
                </div>
            </div>
        </section>

        <!-- Anfrage in vier Schritten -->
        <section class="section" id="anfrage">
            <div class="wrap">
                <header class="section-head">
                    <h2>Anfrage zusammenstellen</h2>
                    <p>vier kurze Angaben – den Preis nennt der Meister</p>
                </header>

                <form class="quiz" data-quiz data-whatsapp="<?= htmlspecialchars($contact['whatsapp']) ?>" novalidate>
                    <div class="quiz__bar">
                        <span class="quiz__count"><i class="fas fa-circle-question" aria-hidden="true"></i> Schritt <b data-quiz-current>1</b> von 4</span>
                        <div class="quiz__progress" aria-hidden="true"><i data-quiz-progress></i></div>
                    </div>

                    <fieldset class="quiz__pane is-active" data-quiz-pane="Objekt">
                        <legend>Wo soll die Anlage montiert werden? *</legend>
                        <div class="quiz__tiles">
                            <label class="tile"><input type="radio" name="objekt" value="Wohnhaus"><span class="tile__body"><img src="img/sl1.jpg" alt="" loading="lazy" style="object-position: center 82%"><i class="fas fa-house" aria-hidden="true"></i><b>Wohnhaus</b></span></label>
                            <label class="tile"><input type="radio" name="objekt" value="Gewerbe / Halle"><span class="tile__body"><img src="img/sl5.jpg" alt="" loading="lazy"><i class="fas fa-industry" aria-hidden="true"></i><b>Gewerbe / Halle</b></span></label>
                            <label class="tile"><input type="radio" name="objekt" value="Mehrfamilienhaus"><span class="tile__body"><img src="img/sl6.jpg" alt="" loading="lazy"><i class="fas fa-building" aria-hidden="true"></i><b>Mehrfamilienhaus</b></span></label>
                            <label class="tile"><input type="radio" name="objekt" value="Sonstiges"><span class="tile__body tile__body--plain"><i class="fas fa-ellipsis" aria-hidden="true"></i><b>Sonstiges</b></span></label>
                        </div>
                    </fieldset>

                    <fieldset class="quiz__pane" data-quiz-pane="Dach">
                        <legend>Welches Dach hat das Gebäude? *</legend>
                        <div class="quiz__tiles">
                            <label class="tile"><input type="radio" name="dach" value="Ziegeldach"><span class="tile__body"><img src="img/sl4.jpg" alt="" loading="lazy"><i class="fas fa-house-chimney" aria-hidden="true"></i><b>Ziegeldach</b></span></label>
                            <label class="tile"><input type="radio" name="dach" value="Blechdach / Wellplatten"><span class="tile__body"><img src="img/sl2.jpg" alt="" loading="lazy"><i class="fas fa-warehouse" aria-hidden="true"></i><b>Blech / Wellplatten</b></span></label>
                            <label class="tile"><input type="radio" name="dach" value="Flachdach"><span class="tile__body"><img src="img/sl6.jpg" alt="" loading="lazy"><i class="fas fa-layer-group" aria-hidden="true"></i><b>Flachdach</b></span></label>
                            <label class="tile"><input type="radio" name="dach" value="Weiß ich nicht"><span class="tile__body tile__body--plain"><i class="fas fa-question" aria-hidden="true"></i><b>Weiß ich nicht</b></span></label>
                        </div>
                    </fieldset>

                    <fieldset class="quiz__pane" data-quiz-pane="Module">
                        <legend>Wie viele Module sind geplant? *</legend>
                        <div class="quiz__tiles quiz__tiles--count">
                            <label class="tile"><input type="radio" name="module" value="bis 10 Module"><span class="tile__body tile__body--count"><em>bis 10</em><b>Module</b></span></label>
                            <label class="tile"><input type="radio" name="module" value="11–20 Module"><span class="tile__body tile__body--count"><em>11–20</em><b>Module</b></span></label>
                            <label class="tile"><input type="radio" name="module" value="21–40 Module"><span class="tile__body tile__body--count"><em>21–40</em><b>Module</b></span></label>
                            <label class="tile"><input type="radio" name="module" value="über 40 Module"><span class="tile__body tile__body--count"><em>40+</em><b>Module</b></span></label>
                        </div>
                    </fieldset>

                    <fieldset class="quiz__pane" data-quiz-pane="Fertig">
                        <legend>Ihre Anfrage</legend>
                        <div class="quiz__summary">
                            <dl data-quiz-summary></dl>
                            <div>
                                <label for="quizNote">Anmerkung (optional)</label>
                                <textarea id="quizNote" class="input" rows="3" maxlength="300" placeholder="z. B. Module sind schon vorhanden, Dach ist 8 m hoch …"></textarea>
                                <p class="quiz__hint"><i class="fas fa-circle-info" aria-hidden="true"></i> Online wird kein Preis berechnet. Der Meister meldet sich und nennt den Preis nach Umfang der Arbeit.</p>
                            </div>
                        </div>
                        <div class="quiz__send">
                            <a class="btn btn--whatsapp" data-quiz-send href="<?= htmlspecialchars($contact['whatsapp']) ?>" target="_blank" rel="noopener"><i class="fab fa-whatsapp" aria-hidden="true"></i> Per WhatsApp senden</a>
                            <a class="btn btn--outline" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin im Kalender</a>
                        </div>
                    </fieldset>

                    <div class="quiz__nav">
                        <button type="button" class="btn btn--ghost" data-quiz-back hidden><i class="fas fa-arrow-left" aria-hidden="true"></i> Zurück</button>
                        <button type="button" class="btn btn--primary" data-quiz-next disabled>Weiter <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    </div>
                </form>
            </div>
        </section>

        <!-- Projektfotos -->
        <section class="section section--mist projects-section" id="projekte">
            <div class="wrap">
                <header class="section-head">
                    <h2>Unsere Projekte</h2>
                    <p>Fotos von unseren eigenen Baustellen</p>
                </header>

                <div class="mosaic">
                    <?php foreach ($projektFotos as $i => [$src, $titel, $unterzeile, $w, $h, $ausschnitt]): ?>
                        <button type="button" class="mosaic__item<?= $w > $h ? ' mosaic__item--wide' : '' ?>" data-lightbox="<?= $i ?>" data-src="<?= $src ?>" data-caption="<?= htmlspecialchars($titel . ' – ' . $unterzeile) ?>">
                            <img src="<?= $src ?>" alt="<?= htmlspecialchars($titel . ' – ' . $unterzeile) ?>" width="<?= $w ?>" height="<?= $h ?>" style="object-position: <?= $ausschnitt ?>" loading="lazy">
                            <span class="mosaic__label"><b><?= htmlspecialchars($titel) ?></b><small><?= htmlspecialchars($unterzeile) ?></small></span>
                            <i class="fas fa-magnifying-glass-plus" aria-hidden="true"></i>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="section-cta">
                    <a class="btn btn--primary" href="<?= htmlspecialchars($contact['instagram']) ?>" target="_blank" rel="noopener"><i class="fab fa-instagram" aria-hidden="true"></i> Mehr auf Instagram</a>
                </div>
            </div>
        </section>

        <!-- Leistungen und Abrechnung -->
        <section class="section" id="leistungen">
            <div class="wrap">
                <header class="section-head">
                    <h2>Leistungen und Preise</h2>
                    <p>was wir montieren – und wonach sich der Preis richtet</p>
                </header>

                <div class="pricing">
                    <div class="pricing__lists">
                        <?php $first = true; foreach ($leistungen as $gruppe => [$icon, $zeilen]): ?>
                            <details class="acc" name="leistungen"<?= $first ? ' open' : '' ?>>
                                <summary><i class="fas <?= $icon ?>" aria-hidden="true"></i> <?= htmlspecialchars($gruppe) ?></summary>
                                <table class="acc__table">
                                    <thead>
                                        <tr><th scope="col">Leistung</th><th scope="col">Preis</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($zeilen as [$leistung, $abrechnung]): ?>
                                            <tr><td><?= htmlspecialchars($leistung) ?></td><td><?= htmlspecialchars($abrechnung) ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php $first = false; endforeach; ?>
                    </div>

                    <aside class="factors">
                        <h3>Was den Preis bestimmt</h3>
                        <ul>
                            <?php foreach ($preisFaktoren as [$icon, $titel, $text]): ?>
                                <li>
                                    <i class="fas <?= $icon ?>" aria-hidden="true"></i>
                                    <span><b><?= htmlspecialchars($titel) ?></b><?= htmlspecialchars($text) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p>Alle Preise sind Verhandlungssache: Der Meister nennt den Betrag, bevor die Arbeit beginnt.</p>
                        <a class="btn btn--sun btn--block" href="calendar.php">Montage buchen</a>
                    </aside>
                </div>
            </div>
        </section>

        <!-- Weitere Dienstleistungen (aus der Verwaltung) -->
        <section class="section section--night services-section" id="dienste">
            <div class="wrap">
                <header class="section-head section-head--light">
                    <h2>Unsere Dienstleistungen</h2>
                    <p>Alle Leistungen für Ihr Projekt auf einen Blick.</p>
                </header>

                <div class="services-grid">
                    <?php foreach ($serviceTypes as $serviceType): ?>
                        <?php [$serviceIcon, $serviceDescription, $serviceTone] = indexServiceMeta((string)$serviceType); ?>
                        <article class="service-card service-card--<?= $serviceTone ?>">
                            <div class="service-icon" aria-hidden="true">
                                <i class="fas <?= htmlspecialchars($serviceIcon) ?>"></i>
                            </div>
                            <h3><?= htmlspecialchars((string)$serviceType) ?></h3>
                            <p><?= htmlspecialchars($serviceDescription) ?></p>
                            <a href="calendar.php">Termin anfragen <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Über uns -->
        <section class="section" id="ueber-uns">
            <div class="wrap about">
                <div class="about__text">
                    <header class="section-head section-head--left">
                        <h2>Warum Jon Dienstleistungen</h2>
                        <p>Junges Team für nachhaltige Energie</p>
                    </header>
                    <p class="about__lead">Zuverlässig, fair, termintreu. Wir beraten klar und setzen Projekte sauber um.</p>
                    <ul class="about__facts">
                        <li><i class="fas fa-building" aria-hidden="true"></i> Jon Dienstleistungen</li>
                        <li><i class="fas fa-solar-panel" aria-hidden="true"></i> Installation von Solaranlagen</li>
                        <li><i class="far fa-calendar-alt" aria-hidden="true"></i> Seit <?= htmlspecialchars($contact['since']) ?></li>
                        <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?= htmlspecialchars($contact['address']) ?></li>
                    </ul>
                    <div class="about__contact ceo-socials">
                        <a class="btn btn--dark" href="tel:<?= htmlspecialchars($contact['phone']) ?>"><i class="fas fa-phone" aria-hidden="true"></i> <?= htmlspecialchars($contact['phoneText']) ?></a>
                        <a class="btn btn--whatsapp" href="<?= htmlspecialchars($contact['whatsapp']) ?>"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>
                        <a class="btn btn--ghost" href="<?= htmlspecialchars($contact['instagram']) ?>"><i class="fab fa-instagram" aria-hidden="true"></i> Instagram</a>
                    </div>
                </div>

                <ul class="perks">
                    <li class="perk perk--blue"><i class="fas fa-euro-sign" aria-hidden="true"></i><h3>Transparent</h3><p>Der Preis wird genannt, bevor die Arbeit beginnt.</p></li>
                    <li class="perk perk--sun"><i class="fas fa-bolt" aria-hidden="true"></i><h3>Flexibel</h3><p>Termine wählen Sie selbst im Buchungskalender.</p></li>
                    <li class="perk perk--green"><i class="fas fa-recycle" aria-hidden="true"></i><h3>Nachhaltig</h3><p>Unser Schwerpunkt ist Strom vom eigenen Dach.</p></li>
                    <li class="perk perk--clay"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><h3>Regional</h3><p>Wir arbeiten in Erlangen und der Umgebung.</p></li>
                </ul>
            </div>
        </section>

        <!-- Team -->
        <section class="section section--mist team-section" id="team">
            <div class="wrap">
                <header class="section-head">
                    <h2>Unsere Mitarbeiter</h2>
                    <p>die Menschen auf Ihrer Baustelle</p>
                </header>
                <div class="team-grid">
                    <article class="team-card team-card--blue">
                        <span class="team-avatar" aria-hidden="true">MB</span>
                        <h3>Max Becker</h3>
                        <p class="team-role"><i class="fas fa-solar-panel" aria-hidden="true"></i> Solarmodul-Montage</p>
                        <p><i class="fas fa-phone" aria-hidden="true"></i> +49 111 1111111</p>
                        <p><i class="fas fa-envelope" aria-hidden="true"></i> employee1@example.com</p>
                    </article>
                    <article class="team-card team-card--sun">
                        <span class="team-avatar" aria-hidden="true">LW</span>
                        <h3>Leon Weber</h3>
                        <p class="team-role"><i class="fas fa-bolt" aria-hidden="true"></i> Elektriker</p>
                        <p><i class="fas fa-phone" aria-hidden="true"></i> +49 222 2222222</p>
                        <p><i class="fas fa-envelope" aria-hidden="true"></i> employee2@example.com</p>
                    </article>
                    <article class="team-card team-card--clay">
                        <span class="team-avatar" aria-hidden="true">SH</span>
                        <h3>Sofia Hartmann</h3>
                        <p class="team-role"><i class="fas fa-clipboard-check" aria-hidden="true"></i> Projektmanagerin</p>
                        <p><i class="fas fa-phone" aria-hidden="true"></i> +49 333 3333333</p>
                        <p><i class="fas fa-envelope" aria-hidden="true"></i> employee3@example.com</p>
                    </article>
                    <article class="team-card team-card--green">
                        <span class="team-avatar" aria-hidden="true">DK</span>
                        <h3>Daniel Koenig</h3>
                        <p class="team-role"><i class="fas fa-headset" aria-hidden="true"></i> Service und Support</p>
                        <p><i class="fas fa-phone" aria-hidden="true"></i> +49 444 4444444</p>
                        <p><i class="fas fa-envelope" aria-hidden="true"></i> employee4@example.com</p>
                    </article>
                </div>
            </div>
        </section>

        <!-- Fragen und Antworten -->
        <section class="section" id="faq">
            <div class="wrap faq">
                <div class="faq__media">
                    <img src="img/sl1.jpg" alt="Fertig montierte Solaranlage auf einem Wohnhaus" width="960" height="1280" loading="lazy">
                    <div class="faq__call">
                        <b>Solarmontage geplant?</b>
                        <a href="tel:<?= htmlspecialchars($contact['phone']) ?>">Jetzt anrufen: <?= htmlspecialchars($contact['phoneText']) ?></a>
                    </div>
                </div>
                <div class="faq__list">
                    <header class="section-head">
                        <h2>Fragen und Antworten</h2>
                    </header>
                    <?php foreach ($faq as $i => [$frage, $antwort]): ?>
                        <details class="faq__item" name="faq"<?= $i === 0 ? ' open' : '' ?>>
                            <summary><i class="far fa-circle-question" aria-hidden="true"></i> <?= htmlspecialchars($frage) ?></summary>
                            <p><?= htmlspecialchars($antwort) ?></p>
                        </details>
                    <?php endforeach; ?>
                    <div class="faq__actions">
                        <a class="btn btn--primary" href="calendar.php">Termin buchen</a>
                        <a class="btn btn--outline" href="<?= htmlspecialchars($contact['whatsapp']) ?>">Frage stellen</a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Kundenstimmen -->
        <section class="section section--mist reviews-section" id="kunden">
            <div class="wrap">
                <header class="section-head">
                    <h2>Unsere Kunden</h2>
                    <p>Erfahrungen und Bewertungen aus echten Projekten</p>
                </header>

                <div class="reviews-bar"<?= $avgRating > 0 ? '' : ' hidden' ?>>
                    <div class="average-rating">
                        <strong><?= htmlspecialchars(number_format($avgRating, 1, ',', '')) ?></strong>
                        <div>
                            <?php indexRenderStars($avgRating); ?>
                            <span>Kundenzufriedenheit</span>
                        </div>
                    </div>
                    <label class="reviews-filter">
                        <span>Anzeigen</span>
                        <select class="rating-filter" id="ratingFilter">
                            <option value="0" <?= $ratingFilter === 0 ? 'selected' : '' ?>>Alle Kundenstimmen</option>
                            <option value="5" <?= $ratingFilter === 5 ? 'selected' : '' ?>>5 Sterne</option>
                            <option value="4" <?= $ratingFilter === 4 ? 'selected' : '' ?>>4 Sterne</option>
                            <option value="3" <?= $ratingFilter === 3 ? 'selected' : '' ?>>3 Sterne</option>
                            <option value="2" <?= $ratingFilter === 2 ? 'selected' : '' ?>>2 Sterne</option>
                            <option value="1" <?= $ratingFilter === 1 ? 'selected' : '' ?>>1 Stern</option>
                        </select>
                    </label>
                </div>

                <div class="reviews-list" id="bewertungen-liste" aria-live="polite">
                    <?php foreach ($bewertungen as $bewertung): ?>
                        <?php indexRenderReview($bewertung, $isAdmin, $deutscheMonate, $csrfToken); ?>
                    <?php endforeach; ?>
                </div>
                <p class="reviews-empty" id="bewertungenLeer"<?= empty($bewertungen) ? '' : ' hidden' ?>>
                    <i class="far fa-comment-dots" aria-hidden="true"></i> Noch keine Kundenstimmen vorhanden.
                    <span>Bewerten können Kunden nach einem bestätigten Auftrag unter <a href="orders.php">Buchungen</a>.</span>
                </p>
                <div class="section-cta">
                    <button class="btn btn--outline load-more" id="mehrBewertungen" type="button"<?= $hasMore ? '' : ' hidden' ?>>Mehr Kundenstimmen</button>
                </div>
            </div>
        </section>

        <!-- Abschluss -->
        <section class="closing">
            <div class="wrap closing__inner">
                <div>
                    <h2>Kontaktieren Sie uns</h2>
                    <p>Lassen Sie uns Ihr Projekt gemeinsam verwirklichen – der Meister nennt Ihnen den Preis nach Umfang der Arbeit.</p>
                </div>
                <div class="closing__actions">
                    <a class="btn btn--sun" href="calendar.php"><i class="fas fa-calendar-check" aria-hidden="true"></i> Termin buchen</a>
                    <a class="btn btn--glass" href="mailto:<?= htmlspecialchars($contact['email']) ?>"><i class="fas fa-envelope" aria-hidden="true"></i> <?= htmlspecialchars($contact['email']) ?></a>
                </div>
            </div>
        </section>
    </main>

    <dialog class="lightbox" id="lightbox" aria-label="Projektfoto">
        <button type="button" class="lightbox__close" data-lightbox-close aria-label="Schließen">×</button>
        <button type="button" class="lightbox__arrow lightbox__arrow--prev" data-lightbox-step="-1" aria-label="Vorheriges Foto"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
        <figure>
            <img src="" alt="">
            <figcaption></figcaption>
        </figure>
        <button type="button" class="lightbox__arrow lightbox__arrow--next" data-lightbox-step="1" aria-label="Nächstes Foto"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
    </dialog>

    <?php renderSiteFooter(); ?>
    <script>
        window.JD_REVIEWS = { offset: <?= (int)$offset ?>, limit: <?= (int)$limit ?>, ratingFilter: <?= (int)$ratingFilter ?> };
    </script>
    <script src="js/index.js?v=20261013a"></script>
</body>
</html>
