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
    header('Location: login.php');
    exit;
}

// Подключение к БД
require_once 'db_connect.php';
require_once __DIR__ . '/partials/layout.php';

// Данные пользователя
$userEmail = $_SESSION['email'] ?? '';
$username = $_SESSION['username'] ?? '';

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$messages = [];
if (isset($_SESSION['flash_messages']) && is_array($_SESSION['flash_messages'])) {
    $messages = $_SESSION['flash_messages'];
    unset($_SESSION['flash_messages']);
}
$bookings = [];

try {
    $conn = getDbConnection();

    // Обработка отправки отзыва
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_text'], $_POST['booking_id'], $_POST['rating'], $_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $messages[$_POST['booking_id']] = 'Ungültiger CSRF-Token.';
        } else {
            $reviewText = trim($_POST['review_text']);
            $bookingId = (int)$_POST['booking_id'];
            $rating = (int)$_POST['rating'];

            if (empty($reviewText) || strlen($reviewText) > 500 || $rating < 1 || $rating > 5) {
                $messages[$bookingId] = empty($reviewText) ? 'Bewertung darf nicht leer sein.' : (strlen($reviewText) > 500 ? 'Bewertung darf 500 Zeichen nicht überschreiten.' : 'Bewertung muss zwischen 1 und 5 liegen.');
            } else {
                $checkStmt = $conn->prepare('SELECT has_review FROM booking WHERE id = ? AND booked_by = ? AND status = "approved"');
                if ($checkStmt === false) {
                    throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                }
                $checkStmt->bind_param('is', $bookingId, $userEmail);
                $checkStmt->execute();
                $checkStmt->bind_result($hasReview);
                $checkStmt->fetch();
                $checkStmt->close();

                if ($hasReview == 0) {
                    $stmt = $conn->prepare('INSERT INTO reviews (user_email, review_text, booking_id, rating, created_at) VALUES (?, ?, ?, ?, NOW())');
                    if ($stmt === false) {
                        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                    }
                    $stmt->bind_param('ssii', $userEmail, $reviewText, $bookingId, $rating);
                    if ($stmt->execute()) {
                        $updateStmt = $conn->prepare('UPDATE booking SET has_review = 1 WHERE id = ?');
                        if ($updateStmt === false) {
                            throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                        }
                        $updateStmt->bind_param('i', $bookingId);
                        $updateStmt->execute();
                        $updateStmt->close();
                        $messages[$bookingId] = 'Bewertung erfolgreich gesendet!';
                    } else {
                        $messages[$bookingId] = 'Fehler beim Senden der Bewertung: ' . htmlspecialchars($conn->error);
                    }
                    $stmt->close();
                } else {
                    $messages[$bookingId] = 'Für diese Buchung wurde bereits eine Bewertung abgegeben.';
                }
            }
        }
    }

    // Получение списка бронирований пользователя
    $stmt = $conn->prepare('SELECT id, date, status, phone, created_at, has_review FROM booking WHERE booked_by = ? ORDER BY created_at DESC');
    if ($stmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $stmt->bind_param('s', $userEmail);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    $messages[0] = 'Fehler: ' . htmlspecialchars($e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($messages)) {
    $_SESSION['flash_messages'] = $messages;
    header('Location: orders.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meine Buchungen - Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/orders.css?v=20261014a">
</head>
<body>
    <?php renderSiteHeader(['active' => 'orders', 'isLoggedIn' => $isLoggedIn]); ?>
    <?php renderPageHero('Meine Buchungen', 'Status Ihrer Anfragen und Bewertungen zu abgeschlossenen Aufträgen.'); ?>

    <main class="page-main" id="inhalt">
        <div class="wrap orders-section">
            <?php
            $orderStatus = ['pending' => 'Wartet auf Genehmigung', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt'];
            ?>
            <?php if (empty($bookings)): ?>
                <div class="orders-empty">
                    <i class="far fa-calendar-plus" aria-hidden="true"></i>
                    <h2>Sie haben keine Buchungen.</h2>
                    <p>Wählen Sie im Kalender einen freien Tag – den Preis nennt der Meister nach Umfang der Arbeit.</p>
                    <a class="btn btn--primary" href="calendar.php">Zum Buchungskalender</a>
                </div>
            <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                    <article class="order-item <?= htmlspecialchars($booking['status']) ?>">
                        <div class="order-date">
                            <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                            <span><small>Datum</small><b><?= htmlspecialchars($booking['date']) ?></b></span>
                        </div>
                        <div class="order-meta">
                            <p><i class="fas fa-phone" aria-hidden="true"></i> Telefon: <?= htmlspecialchars($booking['phone'] ?? 'Nicht angegeben') ?></p>
                            <p><i class="fas fa-clock" aria-hidden="true"></i> Erstellt: <?= date('d.m.Y H:i', strtotime($booking['created_at'])) ?></p>
                        </div>
                        <div class="order-side">
                            <span class="badge badge--<?= htmlspecialchars($booking['status']) ?>"><?= htmlspecialchars($orderStatus[$booking['status']] ?? $booking['status']) ?></span>
                            <?php if ($booking['status'] === 'approved' && (int)($booking['has_review'] ?? 0) === 1): ?>
                                <span class="order-reviewed"><i class="fas fa-star" aria-hidden="true"></i> Bewertet</span>
                            <?php elseif ($booking['status'] === 'approved'): ?>
                                <button class="btn btn--outline btn--sm" onclick="openReviewModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['date']) ?>', '<?= date('H:i', strtotime($booking['created_at'])) ?>')"><i class="fas fa-star" aria-hidden="true"></i> Bewertung abgeben</button>
                            <?php endif; ?>
                        </div>
                        <?php if (isset($messages[$booking['id']])): ?>
                            <div class="message <?= strpos($messages[$booking['id']], 'erfolgreich') !== false ? 'success' : 'error' ?>">
                                <?= htmlspecialchars($messages[$booking['id']]) ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <div id="reviewModal" class="dialog-backdrop modal">
        <div class="dialog modal-content" role="dialog" aria-modal="true">
            <button type="button" class="dialog__close" onclick="closeModal()" aria-label="Schließen">×</button>
            <h3>Bewertung für Ihren Auftrag</h3>
            <p class="modal-meta">Datum: <span id="modalDate"></span> · Zeit: <span id="modalTime"></span></p>
            <form method="POST" id="reviewForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="booking_id" id="modalBookingId">
                <input type="hidden" name="rating" id="selectedRating">
                <div class="rating" id="starRating" aria-label="Sterne">
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <span class="star" data-value="<?= $i ?>"><i class="fas fa-star"></i></span>
                    <?php endfor; ?>
                </div>
                <textarea name="review_text" placeholder="Geben Sie Ihre Bewertung ein" required maxlength="500" oninput="updateCharCounter(this)"></textarea>
                <div class="char-counter" id="charCounter">0 / 500</div>
                <button type="submit" class="btn btn--primary btn--block">Bewertung senden</button>
            </form>
        </div>
    </div>

    <?php renderSiteFooter(); ?>
    <script>
        const modal = document.getElementById('reviewModal');
        let activeForm = null;

        function openReviewModal(bookingId, date, time) {
            document.getElementById('modalBookingId').value = bookingId;
            document.getElementById('modalDate').textContent = date;
            document.getElementById('modalTime').textContent = time;
            document.getElementById('selectedRating').value = '';
            document.querySelectorAll('#starRating .star').forEach(star => {
                star.classList.remove('active');
            });
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            activeForm = document.getElementById('reviewForm');
        }

        function closeModal() {
            modal.style.display = 'none';
            document.body.classList.remove('modal-open');
            activeForm = null;
        }

        window.onclick = function(event) {
            if (event.target === modal) {
                closeModal();
            }
        };

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeModal();
            }
        });

        function updateCharCounter(textarea) {
            const charCount = textarea.value.length;
            const counter = document.getElementById('charCounter');
            counter.textContent = `${charCount} / 500`;
            counter.classList.toggle('warning', charCount > 500);
        }

        document.querySelectorAll('#starRating .star').forEach(star => {
            star.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                document.getElementById('selectedRating').value = value;
                document.querySelectorAll('#starRating .star').forEach(s => {
                    s.classList.toggle('active', s.getAttribute('data-value') <= value);
                });
            });
        });

        // Normales Absenden statt fetch: so bleibt die Erfolgsmeldung nach der Weiterleitung sichtbar.
        document.getElementById('reviewForm').addEventListener('submit', function(e) {
            if (!document.getElementById('selectedRating').value) {
                e.preventDefault();
                alert('Bitte wählen Sie eine Bewertung aus (1-5 Sterne).');
            }
        });
    </script>
</body>
</html>
