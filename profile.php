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
if (!authCheck('user')) {
    header('Location: login.php');
    exit;
}

require_once 'db_connect.php';
require_once __DIR__ . '/partials/layout.php';
require_once 'local_auth.php';

// CSRF-токен
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$userId = (int)($_SESSION['user_id'] ?? 0);
$userEmail = $_SESSION['email'] ?? '';
$username = $_SESSION['username'] ?? '';

$userInfo = [
    'username' => $username,
    'email' => $userEmail,
    'created_at' => null
];
$bookings = [];
$reviews = [];
$totalBookings = 0;
$totalReviews = 0;
$passwordMessageGlobal = '';
$passwordMessageModal = '';
$passwordMessageModalType = 'error';
$openPasswordModal = false;

if (isset($_SESSION['password_changed'])) {
    $passwordMessageGlobal = (string)$_SESSION['password_changed'];
    unset($_SESSION['password_changed']);
}

try {
    $conn = getDbConnection();

    $isPasswordChangeRequest = ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'change_password');

    if ($isPasswordChangeRequest) {
        $openPasswordModal = true;
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $passwordMessageModal = 'Ungültiger CSRF-Token.';
        } else {
            $currentPassword = trim((string)($_POST['current_password'] ?? ''));
            $newPassword = trim((string)($_POST['new_password'] ?? ''));
            $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

            if ($newPassword !== $confirmPassword) {
                $passwordMessageModal = 'Die neuen Passwörter stimmen nicht überein.';
            } elseif (strlen($newPassword) < 6) {
                $passwordMessageModal = 'Das neue Passwort muss mindestens 6 Zeichen lang sein.';
            } else {
                $stmt = $conn->prepare('SELECT password FROM user WHERE id = ?');
                if ($stmt === false) {
                    throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                }
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->bind_result($hashedPassword);

                if ($stmt->fetch()) {
                    $stmt->close();
                    if (!password_verify($currentPassword, $hashedPassword)) {
                        $passwordMessageModal = 'Aktuelles Passwort ist falsch.';
                    } else {
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        if ($newHash === false) {
                            throw new Exception('Fehler beim Hashen des Passworts.');
                        }

                        $hasEncryptedPassword = ensurePasswordEncColumn($conn);

                        if ($hasEncryptedPassword) {
                            $encryptedPassword = encryptPasswordForAdmin($newPassword);
                            $updateStmt = $conn->prepare('UPDATE user SET password = ?, password_enc = ? WHERE id = ?');
                            if ($updateStmt === false) {
                                throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                            }
                            $updateStmt->bind_param('ssi', $newHash, $encryptedPassword, $userId);
                        } else {
                            $updateStmt = $conn->prepare('UPDATE user SET password = ? WHERE id = ?');
                            if ($updateStmt === false) {
                                throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                            }
                            $updateStmt->bind_param('si', $newHash, $userId);
                        }

                        if ($updateStmt->execute()) {
                            $updateStmt->close();
                            localAuthSetClientPassword($userId, (string)$username, (string)$userEmail, $newPassword);
                            $_SESSION['password_changed'] = 'Passwort erfolgreich geändert.';
                            $conn->close();
                            header('Location: profile.php');
                            exit;
                        } else {
                            $passwordMessageModal = 'Fehler beim Aktualisieren des Passworts.';
                        }
                        $updateStmt->close();
                    }
                } else {
                    $stmt->close();
                    $passwordMessageModal = 'Benutzer nicht gefunden.';
                }
            }
        }
    }

    $userStmt = $conn->prepare('SELECT username, email, created_at FROM user WHERE id = ?');
    if ($userStmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $result = $userStmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $userInfo = $row;
    }
    $userStmt->close();

    $countBookingsStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM booking WHERE booked_by = ?');
    if ($countBookingsStmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $countBookingsStmt->bind_param('s', $userEmail);
    $countBookingsStmt->execute();
    $countBookingsResult = $countBookingsStmt->get_result()->fetch_assoc();
    $totalBookings = (int)($countBookingsResult['cnt'] ?? 0);
    $countBookingsStmt->close();

    $countReviewsStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM reviews WHERE user_email = ?');
    if ($countReviewsStmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $countReviewsStmt->bind_param('s', $userEmail);
    $countReviewsStmt->execute();
    $countReviewsResult = $countReviewsStmt->get_result()->fetch_assoc();
    $totalReviews = (int)($countReviewsResult['cnt'] ?? 0);
    $countReviewsStmt->close();

    $bookingsStmt = $conn->prepare('SELECT id, date, status, phone, created_at FROM booking WHERE booked_by = ? ORDER BY created_at DESC LIMIT 10');
    if ($bookingsStmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $bookingsStmt->bind_param('s', $userEmail);
    $bookingsStmt->execute();
    $bookingsResult = $bookingsStmt->get_result();
    while ($row = $bookingsResult->fetch_assoc()) {
        $bookings[] = $row;
    }
    $bookingsStmt->close();

    $reviewsStmt = $conn->prepare(
        'SELECT r.review_text, r.rating, r.created_at, rr.reply_text, rr.reply_date, b.date AS booking_date
         FROM reviews r
         LEFT JOIN review_replies rr ON r.id = rr.review_id
         LEFT JOIN booking b ON r.booking_id = b.id
         WHERE r.user_email = ?
         ORDER BY r.created_at DESC
         LIMIT 10'
    );
    if ($reviewsStmt === false) {
        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
    }
    $reviewsStmt->bind_param('s', $userEmail);
    $reviewsStmt->execute();
    $reviewsResult = $reviewsStmt->get_result();
    while ($row = $reviewsResult->fetch_assoc()) {
        $reviews[] = $row;
    }
    $reviewsStmt->close();

} catch (Exception $e) {
    http_response_code(500);
    $errorMessage = 'Fehler: ' . htmlspecialchars($e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

function formatStatus(string $status): string {
    return match ($status) {
        'approved' => 'Genehmigt',
        'pending' => 'Ausstehend',
        'rejected' => 'Abgelehnt',
        default => ucfirst($status)
    };
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/profile.css?v=20261013a">
</head>
<body>
    <?php renderSiteHeader(['active' => 'profile', 'isLoggedIn' => true]); ?>
    <?php renderPageHero('Mein Profil'); ?>

    <main class="page-main" id="inhalt">
    <div class="wrap profile-wrapper">
        <section class="profile-hero">
            <div class="profile-card">
                <div class="avatar">
                    <?= htmlspecialchars(strtoupper(substr($userInfo['username'] ?? 'U', 0, 1))) ?>
                </div>
                <div class="profile-info">
                    <h1><?= htmlspecialchars($userInfo['username'] ?? $username) ?></h1>
                    <p class="profile-email"><i class="fas fa-envelope"></i> <?= htmlspecialchars($userInfo['email'] ?? $userEmail) ?></p>
                    <p class="profile-date"><i class="fas fa-calendar-check"></i> Registriert am: <?= !empty($userInfo['created_at']) ? date('d.m.Y', strtotime($userInfo['created_at'])) : '—' ?></p>
                </div>
                <div class="profile-side">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <i class="fas fa-clipboard-list"></i>
                            <div class="stat-text">
                                <span class="stat-value"><?= $totalBookings ?></span>
                                <span class="stat-label">Buchungen</span>
                            </div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-star"></i>
                            <div class="stat-text">
                                <span class="stat-value"><?= $totalReviews ?></span>
                                <span class="stat-label">Bewertungen</span>
                            </div>
                        </div>
                    </div>
                    <div class="quick-actions">
                        <a href="calendar.php" class="action-link icon-only" aria-label="Neue Buchung" title="Neue Buchung"><i class="fas fa-calendar-plus"></i></a>
                        <a href="orders.php" class="action-link icon-only" aria-label="Meine Buchungen" title="Meine Buchungen"><i class="fas fa-receipt"></i></a>
                        <button type="button" class="action-link action-btn icon-only" id="openPasswordModal" aria-label="Passwort ändern" title="Passwort ändern"><i class="fas fa-key"></i></button>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!empty($errorMessage ?? '')): ?>
            <div class="error-box"><?= $errorMessage ?></div>
        <?php endif; ?>

        <?php if (!empty($passwordMessageGlobal)): ?>
            <div class="toast success" id="passwordToast" role="status" aria-live="polite">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($passwordMessageGlobal) ?></span>
            </div>
        <?php endif; ?>

        <section class="list-card">
            <div class="section-header">
                <h2>Letzte Buchungen</h2>
                <span class="section-meta">Max. 10 Einträge</span>
            </div>
            <?php if (empty($bookings)): ?>
                <p class="empty-state">Noch keine Buchungen vorhanden.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Datum</th>
                                <th>Status</th>
                                <th>Telefon</th>
                                <th>Erstellt am</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$b['id']) ?></td>
                                    <td><?= htmlspecialchars($b['date']) ?></td>
                                    <td><span class="status <?= htmlspecialchars($b['status']) ?>"><?= htmlspecialchars(formatStatus($b['status'])) ?></span></td>
                                    <td><?= htmlspecialchars($b['phone'] ?? 'Nicht angegeben') ?></td>
                                    <td><?= date('d.m.Y H:i', strtotime($b['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="list-card">
            <div class="section-header">
                <h2>Meine Bewertungen</h2>
                <span class="section-meta">Max. 10 Einträge</span>
            </div>
            <?php if (empty($reviews)): ?>
                <p class="empty-state">Noch keine Bewertungen vorhanden.</p>
            <?php else: ?>
                <div class="reviews-grid">
                    <?php foreach ($reviews as $r): ?>
                        <article class="review-card">
                            <div class="review-top">
                                <div>
                                    <h3><?= htmlspecialchars($r['booking_date'] ?? 'Buchung') ?></h3>
                                    <span class="review-date"><?= date('d.m.Y', strtotime($r['created_at'])) ?></span>
                                </div>
                                <div class="stars">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star" style="color: <?= $i <= (int)$r['rating'] ? '#ffb61b' : '#cdd4df' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <p class="review-text"><?= htmlspecialchars($r['review_text']) ?></p>
                            <?php if (!empty($r['reply_text'])): ?>
                                <div class="reply-box">
                                    <strong>Antwort vom Admin:</strong>
                                    <p><?= htmlspecialchars($r['reply_text']) ?></p>
                                    <span class="reply-date"><?= date('d.m.Y H:i', strtotime($r['reply_date'])) ?></span>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    </main>

    <div id="passwordModal" class="password-modal" aria-hidden="true">
        <div class="password-modal-content">
            <div class="password-modal-header">
                <h3>Passwort ändern</h3>
                <button type="button" class="password-modal-close" id="closePasswordModal">×</button>
            </div>
            <?php if (!empty($passwordMessageModal)): ?>
                <div class="inline-message <?= $passwordMessageModalType === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($passwordMessageModal) ?>
                </div>
            <?php endif; ?>
            <form method="POST" class="password-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-row">
                    <label>Aktuelles Passwort</label>
                    <input type="password" name="current_password" required>
                </div>
                <div class="form-row">
                    <label>Neues Passwort</label>
                    <input type="password" name="new_password" required>
                </div>
                <div class="form-row">
                    <label>Neues Passwort erneut</label>
                    <input type="password" name="confirm_password" required>
                </div>
                <div class="password-actions">
                    <button type="submit" class="btn btn--primary password-btn">Ändern</button>
                </div>
            </form>
        </div>
    </div>

    <?php renderSiteFooter(); ?>
    <script>
        const passwordModal = document.getElementById('passwordModal');
        const openPasswordModal = document.getElementById('openPasswordModal');
        const closePasswordModal = document.getElementById('closePasswordModal');

        if (openPasswordModal && passwordModal) {
            openPasswordModal.addEventListener('click', () => {
                passwordModal.classList.add('open');
                document.body.classList.add('modal-open');
            });
        }

        if (closePasswordModal && passwordModal) {
            closePasswordModal.addEventListener('click', () => {
                passwordModal.classList.remove('open');
                document.body.classList.remove('modal-open');
            });
        }

        if (passwordModal) {
            passwordModal.addEventListener('click', (event) => {
                if (event.target === passwordModal) {
                    passwordModal.classList.remove('open');
                    document.body.classList.remove('modal-open');
                }
            });
        }

        const passwordToast = document.getElementById('passwordToast');
        if (passwordToast) {
            setTimeout(() => passwordToast.classList.add('hide'), 10000);
            setTimeout(() => passwordToast.remove(), 10450);
        }
    </script>
    <?php if ($openPasswordModal): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('passwordModal');
            if (modal) {
                modal.classList.add('open');
                document.body.classList.add('modal-open');
            }
        });
    </script>
    <?php endif; ?>
</body>
</html>
