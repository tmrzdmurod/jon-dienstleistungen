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
$isAdmin = authCheck('admin');

require_once 'db_connect.php';
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/notify.php';
require_once 'employee_workflow.php';

function calendarHasBookingServiceTypeColumn(mysqli $conn): bool {
    $check = $conn->query("SHOW COLUMNS FROM booking LIKE 'service_type'");
    if ($check && $check->num_rows > 0) {
        $check->free();
        return true;
    }
    $check?->free();

    $alter = $conn->query('ALTER TABLE booking ADD COLUMN service_type VARCHAR(80) DEFAULT NULL AFTER phone');
    if (!$alter) {
        return false;
    }

    $verify = $conn->query("SHOW COLUMNS FROM booking LIKE 'service_type'");
    $exists = ($verify && $verify->num_rows > 0);
    $verify?->free();
    return $exists;
}

// Генерация CSRF-токена
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Deutsche Monatsnamen
$deutscheMonate = [
    1 => 'Januar',
    2 => 'Februar',
    3 => 'März',
    4 => 'April',
    5 => 'Mai',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'August',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Dezember'
];

$gebuchteTage = [];
$nachricht = '';
$serviceTypes = workflowReadServiceTypes();
$hasBookingServiceTypeColumn = false;
if (isset($_SESSION['flash_message'])) {
    $nachricht = (string)$_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

try {
    $conn = getDbConnection();
    $hasBookingServiceTypeColumn = calendarHasBookingServiceTypeColumn($conn);

    // Monat und Jahr bestimmen
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    if ($month < 1) {
        $month = 12;
        $year--;
    } elseif ($month > 12) {
        $month = 1;
        $year++;
    }

    // Gebuchte Tage abrufen
    $result = $conn->query('SELECT date, status, booked_by, admin_approved FROM booking WHERE status != "free"');
    if ($result === false) {
        throw new Exception('Fehler beim Abrufen der gebuchten Tage: ' . htmlspecialchars($conn->error));
    }
    while ($row = $result->fetch_assoc()) {
        $gebuchteTage[$row['date']] = [
            'status' => $row['status'],
            'booked_by' => $row['booked_by'],
            'admin_approved' => (bool)$row['admin_approved']
        ];
    }

    // Verarbeitung der Buchungsanfrage
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_date'], $_POST['phone'], $_POST['service_type'], $_POST['csrf_token']) && $isLoggedIn) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $nachricht = 'Ungültiger CSRF-Token.';
        } else {
            $buchungsDatum = $_POST['book_date'];
            $telefon = trim($_POST['phone']);
            $serviceType = trim((string)($_POST['service_type'] ?? ''));
            $heute = date('Y-m-d');

            // Validierung der Telefonnummer
            if (!preg_match('/^\+?[0-9]{10,20}$/', $telefon)) {
                $nachricht = 'Bitte geben Sie eine gültige Telefonnummer ein.';
            } elseif ($serviceType === '' || !in_array($serviceType, $serviceTypes, true)) {
                $nachricht = 'Bitte wählen Sie einen gültigen Service-Typ.';
            } elseif ($buchungsDatum < $heute) {
                $nachricht = 'Vergangene Daten können nicht gebucht werden.';
            } else {
                // Prüfen, ob ein Eintrag für dieses Datum existiert
                $checkStmt = $conn->prepare('SELECT id, status FROM booking WHERE date = ?');
                if ($checkStmt === false) {
                    throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                }
                $checkStmt->bind_param('s', $buchungsDatum);
                $checkStmt->execute();
                $checkStmt->store_result();

                if ($checkStmt->num_rows > 0) {
                    $checkStmt->bind_result($existingBookingId, $aktuellerStatus);
                    $checkStmt->fetch();
                    if ($aktuellerStatus === 'free' || $aktuellerStatus === 'rejected') {
                        if ($hasBookingServiceTypeColumn) {
                            $stmt = $conn->prepare('UPDATE booking SET status = "pending", booked_by = ?, phone = ?, service_type = ?, admin_approved = 0 WHERE date = ?');
                        } else {
                            $stmt = $conn->prepare('UPDATE booking SET status = "pending", booked_by = ?, phone = ?, admin_approved = 0 WHERE date = ?');
                        }
                        if ($stmt === false) {
                            throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                        }
                        if ($hasBookingServiceTypeColumn) {
                            $stmt->bind_param('ssss', $userEmail, $telefon, $serviceType, $buchungsDatum);
                        } else {
                            $stmt->bind_param('sss', $userEmail, $telefon, $buchungsDatum);
                        }
                        if ($stmt->execute()) {
                            if (!$hasBookingServiceTypeColumn) {
                                workflowSetBookingServiceType((int)$existingBookingId, $serviceType);
                            }
                            $nachricht = 'Buchungsanfrage erfolgreich gesendet! Bitte warten Sie auf die Genehmigung.';
                            notifyDefer(static fn() => notifyOwnerNewBooking([
                                'date' => $buchungsDatum, 'service' => $serviceType, 'phone' => $telefon, 'email' => $userEmail,
                            ]));
                        } else {
                            $nachricht = 'Fehler bei der Buchungsanfrage: ' . htmlspecialchars($conn->error);
                        }
                        $stmt->close();
                    } else {
                        $nachricht = 'Dieser Tag ist bereits belegt oder wartet auf Genehmigung.';
                    }
                } else {
                    if ($hasBookingServiceTypeColumn) {
                        $stmt = $conn->prepare('INSERT INTO booking (date, status, booked_by, phone, service_type, admin_approved) VALUES (?, "pending", ?, ?, ?, 0)');
                    } else {
                        $stmt = $conn->prepare('INSERT INTO booking (date, status, booked_by, phone, admin_approved) VALUES (?, "pending", ?, ?, 0)');
                    }
                    if ($stmt === false) {
                        throw new Exception('Fehler bei der Vorbereitung der Abfrage: ' . htmlspecialchars($conn->error));
                    }
                    if ($hasBookingServiceTypeColumn) {
                        $stmt->bind_param('ssss', $buchungsDatum, $userEmail, $telefon, $serviceType);
                    } else {
                        $stmt->bind_param('sss', $buchungsDatum, $userEmail, $telefon);
                    }
                    if ($stmt->execute()) {
                        if (!$hasBookingServiceTypeColumn) {
                            workflowSetBookingServiceType((int)$stmt->insert_id, $serviceType);
                        }
                        $nachricht = 'Buchungsanfrage erfolgreich gesendet! Bitte warten Sie auf die Genehmigung.';
                        notifyDefer(static fn() => notifyOwnerNewBooking([
                            'date' => $buchungsDatum, 'service' => $serviceType, 'phone' => $telefon, 'email' => $userEmail,
                        ]));
                    } else {
                        $nachricht = 'Fehler bei der Buchungsanfrage: ' . htmlspecialchars($conn->error);
                    }
                    $stmt->close();
                }
                $checkStmt->close();
            }
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    $nachricht = 'Fehler: ' . htmlspecialchars($e->getMessage());
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $nachricht !== '') {
    $_SESSION['flash_message'] = $nachricht;
    $redirectYear = isset($year) ? (int)$year : (int)date('Y');
    $redirectMonth = isset($month) ? (int)$month : (int)date('m');
    header('Location: calendar.php?month='.$redirectMonth.'&year='.$redirectYear);
    exit;
}

$year = $year ?? (int)date('Y');
$month = $month ?? (int)date('m');
$heute = date('Y-m-d');
$prevMonth = $month === 1 ? 12 : $month - 1;
$prevYear = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear = $month === 12 ? $year + 1 : $year;
$tagStatus = ['pending' => 'angefragt', 'approved' => 'bestätigt', 'rejected' => 'abgelehnt', 'booked-other' => 'belegt'];
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buchungskalender – Jon Dienstleistungen</title>
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013a">
    <link rel="stylesheet" href="styles/calendar.css?v=20261013a">
</head>
<body>
    <?php renderSiteHeader(['active' => 'calendar', 'isLoggedIn' => $isLoggedIn, 'showAdmin' => $isAdmin]); ?>

    <?php if (!$isLoggedIn): ?>
        <div class="notification" id="registerNotification" role="status">
            <i class="fas fa-user-plus" aria-hidden="true"></i>
            <span>Bitte <a href="signup.php">registrieren</a> Sie sich, um alle Funktionen der Website zu nutzen!</span>
            <button type="button" class="notification__close" aria-label="Hinweis schließen">×</button>
        </div>
        <div class="notification" id="bookingNotification" role="alert">
            <i class="fas fa-lock" aria-hidden="true"></i>
            <span>Ohne Registrierung ist das Buchen nicht möglich. Bitte <a href="signup.php">registrieren</a> Sie sich, um Dienstleistungen zu buchen!</span>
            <button type="button" class="notification__close" aria-label="Hinweis schließen">×</button>
        </div>
    <?php endif; ?>

    <?php renderPageHero('Buchungskalender', 'Freien Tag wählen, Dienstleistung angeben – wir bestätigen den Termin.'); ?>

    <main class="page-main" id="inhalt">
        <div class="wrap booking-layout">
            <section class="calendar">
                <header class="calendar-head">
                    <a class="calendar-step" href="calendar.php?month=<?= $prevMonth ?>&amp;year=<?= $prevYear ?>" aria-label="Vorheriger Monat"><i class="fas fa-chevron-left" aria-hidden="true"></i></a>
                    <h2><?= htmlspecialchars($deutscheMonate[$month] ?? '') ?> <span><?= htmlspecialchars((string)$year) ?></span></h2>
                    <a class="calendar-step" href="calendar.php?month=<?= $nextMonth ?>&amp;year=<?= $nextYear ?>" aria-label="Nächster Monat"><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
                    <div class="calendar-controls">
                        <select aria-label="Monat" onchange="window.location.href='calendar.php?month='+this.value+'&year=<?= htmlspecialchars((string)$year) ?>'">
                            <?php foreach ($deutscheMonate as $m => $monatsname): ?>
                                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= htmlspecialchars($monatsname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select aria-label="Jahr" onchange="window.location.href='calendar.php?month=<?= htmlspecialchars((string)$month) ?>&year='+this.value">
                            <?php
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear - 1; $y <= $currentYear + 1; $y++):
                            ?>
                                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= htmlspecialchars((string)$y) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </header>

                <?php if ($nachricht !== ''): ?>
                    <div class="message <?= strpos($nachricht, 'erfolgreich') !== false ? 'success' : 'error' ?>">
                        <?= htmlspecialchars($nachricht) ?>
                    </div>
                <?php endif; ?>

                <div class="calendar-weekdays" aria-hidden="true">
                    <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>
                </div>
                <div class="calendar-grid">
                    <?php
                    $tageImMonat = cal_days_in_month(CAL_GREGORIAN, $month, $year);
                    $ersterTag = (int)date('w', strtotime("$year-$month-01"));
                    $ersterTag = $ersterTag === 0 ? 7 : $ersterTag;

                    // Leere Zellen vor dem ersten Tag
                    for ($i = 1; $i < $ersterTag; $i++) {
                        echo '<div class="calendar-day calendar-day-hidden"></div>';
                    }

                    for ($tag = 1; $tag <= $tageImMonat; $tag++) {
                        $datum = sprintf('%04d-%02d-%02d', $year, $month, $tag);
                        $klasse = '';
                        if (isset($gebuchteTage[$datum])) {
                            $status = $gebuchteTage[$datum]['status'];
                            $gebuchtVon = $gebuchteTage[$datum]['booked_by'];
                            $genehmigt = $gebuchteTage[$datum]['admin_approved'];

                            if ($status === 'pending' && $gebuchtVon === $userEmail) {
                                $klasse = 'pending';
                            } elseif ($status === 'approved' && ($gebuchtVon === $userEmail || $isAdmin)) {
                                $klasse = 'approved';
                            } elseif ($status === 'rejected' && $gebuchtVon === $userEmail) {
                                $klasse = 'rejected';
                            } elseif ($status === 'approved') {
                                $klasse = 'booked-other';
                            }
                        }
                        $zustand = $tagStatus[$klasse] ?? ($datum < $heute ? '' : 'frei');
                        $extra = ($datum < $heute ? ' past' : '') . ($datum === $heute ? ' today' : '');
                        echo "<button type='button' class='calendar-day $klasse$extra' data-date='$datum'>"
                            . "<span class='day-no'>$tag</span><span class='day-state'>$zustand</span></button>";
                    }
                    ?>
                </div>

                <ul class="calendar-legend">
                    <li><i class="dot dot--free"></i> frei</li>
                    <li><i class="dot dot--pending"></i> Ihre Anfrage</li>
                    <li><i class="dot dot--approved"></i> bestätigt</li>
                    <li><i class="dot dot--rejected"></i> abgelehnt</li>
                    <li><i class="dot dot--other"></i> belegt</li>
                </ul>
            </section>

            <aside class="booking-side">
                <div class="side-card">
                    <h3>So buchen Sie</h3>
                    <ol class="side-steps">
                        <li><b>Tag wählen</b>Einen freien Tag im Kalender anklicken.</li>
                        <li><b>Dienstleistung angeben</b>Leistung und Telefonnummer eintragen.</li>
                        <li><b>Bestätigung abwarten</b>Den Status sehen Sie unter <a href="orders.php">Buchungen</a>.</li>
                    </ol>
                </div>
                <div class="side-card side-card--dark">
                    <i class="fas fa-handshake" aria-hidden="true"></i>
                    <h3>Preis nach Vereinbarung</h3>
                    <p>Mit der Buchung ist noch kein Preis festgelegt. Der Meister nennt ihn nach dem Umfang der Arbeit.</p>
                    <a class="btn btn--sun btn--block" href="<?= htmlspecialchars(siteContact()['whatsapp']) ?>"><i class="fab fa-whatsapp" aria-hidden="true"></i> Vorab fragen</a>
                </div>
            </aside>
        </div>
    </main>

    <?php if ($isLoggedIn): ?>
        <div class="dialog-backdrop" id="buchungsFormular">
            <div class="dialog booking-form" role="dialog" aria-modal="true" aria-labelledby="buchungsTitel">
                <button type="button" class="dialog__close" onclick="schliesseFormular()" aria-label="Schließen">×</button>
                <h2 id="buchungsTitel">Buchung</h2>
                <p class="booking-date"><i class="far fa-calendar-check" aria-hidden="true"></i> <span id="buchungsDatumText"></span></p>
                <form method="POST">
                    <input type="hidden" name="book_date" id="buchungs_datum">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <label for="service_type">Dienstleistung</label>
                    <select name="service_type" id="service_type" required>
                        <option value="" selected disabled>Dienstleistung wählen</option>
                        <?php foreach ($serviceTypes as $serviceType): ?>
                            <option value="<?= htmlspecialchars((string)$serviceType) ?>"><?= htmlspecialchars((string)$serviceType) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="service-quick-list">
                        <?php foreach (array_slice($serviceTypes, 0, 5) as $serviceType): ?>
                            <button type="button" class="service-quick-btn" data-service-quick="<?= htmlspecialchars((string)$serviceType) ?>">
                                <?= htmlspecialchars((string)$serviceType) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="selected-service-note" id="selectedServiceNote">Keine Dienstleistung ausgewählt.</p>
                    <label for="telefon">Telefonnummer</label>
                    <input type="tel" name="phone" id="telefon" placeholder="Telefonnummer eingeben" pattern="\+?[0-9]{10,20}" required>
                    <p class="booking-hint">Der Preis wird nicht online berechnet – der Meister nennt ihn nach Umfang der Arbeit.</p>
                    <div class="booking-actions">
                        <button type="submit" class="btn btn--primary submit">Senden</button>
                        <button type="button" class="btn btn--ghost cancel" onclick="schliesseFormular()">Abbrechen</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php renderSiteFooter(); ?>
    <script>
        const buchungsFormular = document.getElementById('buchungsFormular');
        const serviceSelect = document.getElementById('service_type');
        const serviceQuickButtons = document.querySelectorAll('[data-service-quick]');
        const selectedServiceNote = document.getElementById('selectedServiceNote');

        function updateSelectedServiceNote() {
            if (!selectedServiceNote || !serviceSelect) {
                return;
            }
            const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
            const selectedText = selectedOption ? selectedOption.textContent.trim() : '';
            if (serviceSelect.value === '') {
                selectedServiceNote.textContent = 'Keine Dienstleistung ausgewählt.';
            } else {
                selectedServiceNote.textContent = `Ausgewählt: ${selectedText}`;
            }
            serviceQuickButtons.forEach((button) => {
                button.classList.toggle('is-active', button.getAttribute('data-service-quick') === serviceSelect.value);
            });
        }

        if (serviceSelect) {
            serviceSelect.addEventListener('change', updateSelectedServiceNote);
            updateSelectedServiceNote();
        }

        serviceQuickButtons.forEach((button) => {
            button.addEventListener('click', () => {
                if (!serviceSelect) {
                    return;
                }
                const serviceName = button.getAttribute('data-service-quick') || '';
                serviceSelect.value = serviceName;
                updateSelectedServiceNote();
            });
        });

        // Обработка кликов по дням календаря
        document.querySelectorAll('.calendar-day[data-date]').forEach(tag => {
            tag.addEventListener('click', () => handleCalendarDayClick(tag));
        });

        function handleCalendarDayClick(tag) {
            const datum = tag.getAttribute('data-date');
            const klassen = tag.className.split(' ');
            if (klassen.includes('past')) {
                return;
            }
            <?php if ($isLoggedIn): ?>
                if (!klassen.includes('pending') && !klassen.includes('approved') && !klassen.includes('booked-other')) {
                    document.getElementById('buchungs_datum').value = datum;
                    document.getElementById('buchungsDatumText').textContent = new Date(datum + 'T00:00:00').toLocaleDateString('de-DE', {
                        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
                    });
                    buchungsFormular.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
            <?php else: ?>
                const bookingNotification = document.getElementById('bookingNotification');
                if (bookingNotification) {
                    document.getElementById('registerNotification')?.classList.remove('is-visible');
                    bookingNotification.classList.add('is-visible');
                    setTimeout(() => {
                        bookingNotification.classList.remove('is-visible');
                    }, 10000);
                }
            <?php endif; ?>
        }

        function schliesseFormular() {
            buchungsFormular.style.display = 'none';
            document.body.classList.remove('modal-open');
            document.getElementById('telefon').value = '';
            if (serviceSelect) {
                serviceSelect.value = '';
                updateSelectedServiceNote();
            }
        }

        if (buchungsFormular) {
            buchungsFormular.addEventListener('click', (e) => {
                if (e.target === buchungsFormular) {
                    schliesseFormular();
                }
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && buchungsFormular.style.display === 'block') {
                    schliesseFormular();
                }
            });
        }
    </script>
</body>
</html>
