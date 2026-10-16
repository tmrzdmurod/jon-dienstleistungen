<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/errors.log');

if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__FILE__, true);
}
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
clearstatcache(true, __FILE__);

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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$adminAuth = authRequire('admin');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

require_once 'db_connect.php';
require_once 'local_auth.php';
require_once 'employee_workflow.php';
require_once __DIR__ . '/partials/layout.php';
require_once __DIR__ . '/notify.php';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function validDate(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return '';
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y) ? $date : '';
}

function tableExists(mysqli $conn, string $tableName): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function tableColumnExists(mysqli $conn, string $tableName, string $columnName): bool {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS cnt
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ((int)($row['cnt'] ?? 0)) > 0;
}

function ensureEmployeeProfilesTable(mysqli $conn): bool {
    if (tableExists($conn, 'employee_profiles')) {
        return true;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS employee_profiles (
    id INT NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    role VARCHAR(40) NOT NULL,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_employee_email (email),
    KEY idx_employee_role (role),
    KEY idx_employee_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    return (bool)$conn->query($sql);
}

function ensureEmployeeProfileColumns(mysqli $conn): bool {
    if (!tableExists($conn, 'employee_profiles')) {
        return false;
    }

    if (!tableColumnExists($conn, 'employee_profiles', 'profile_data')) {
        $ok = $conn->query('ALTER TABLE employee_profiles ADD COLUMN profile_data LONGTEXT DEFAULT NULL AFTER notes');
        if (!$ok) {
            return false;
        }
    }

    if (!tableColumnExists($conn, 'employee_profiles', 'profile_ready')) {
        $ok = $conn->query('ALTER TABLE employee_profiles ADD COLUMN profile_ready TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_data');
        if (!$ok) {
            return false;
        }
    }

    return true;
}

function ensureBookingServiceTypeColumn(mysqli $conn): bool {
    if (!tableExists($conn, 'booking')) {
        return false;
    }

    if (tableColumnExists($conn, 'booking', 'service_type')) {
        return true;
    }

    $ok = $conn->query('ALTER TABLE booking ADD COLUMN service_type VARCHAR(80) DEFAULT NULL AFTER phone');
    if (!$ok) {
        return false;
    }

    return tableColumnExists($conn, 'booking', 'service_type');
}

function buildEmployeeProfileTemplate(string $role, string $fullName, string $email, string $phone): array {
    $common = [
        'employee' => [
            'name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'timezone' => 'Europe/Berlin',
            'language' => 'de',
        ],
        'availability' => [
            'status' => 'active',
            'work_mode' => 'field',
        ],
        'workflow_statuses' => ['assigned', 'accepted', 'in_progress', 'done', 'blocked', 'canceled'],
        'onboarding' => [
            'profile_status' => 'ready',
            'created_at' => date('c'),
            'next_steps' => [
                'Profil prüfen',
                'Erste Aufgabe annehmen',
                'Status täglich aktualisieren',
            ],
        ],
    ];

    $roleTemplates = [
        'project_manager' => [
            'responsibilities' => [
                'Aufgaben zuweisen und priorisieren',
                'Termine koordinieren',
                'Fortschritt der Teams überwachen',
                'Kundenkommunikation zu Projektstatus',
            ],
            'checklist' => [
                'Neue Buchung bewertet',
                'Team zugewiesen',
                'Zeitplan bestätigt',
                'Abschlussbericht erstellt',
            ],
            'kpi_targets' => [
                'on_time_delivery_pct' => 95,
                'blocked_tasks_max' => 3,
            ],
            'permissions' => ['assign_tasks', 'change_deadlines', 'view_reports'],
        ],
        'installer' => [
            'responsibilities' => [
                'Montage der Solarmodule',
                'Foto-Report vor/nach Arbeit',
                'Materialverbrauch dokumentieren',
            ],
            'checklist' => [
                'Standortprüfung abgeschlossen',
                'Montage durchgeführt',
                'Foto-Report hochgeladen',
                'Aufgabe als done markiert',
            ],
            'kpi_targets' => [
                'tasks_completed_per_week' => 8,
                'rework_rate_pct_max' => 3,
            ],
            'permissions' => ['update_task_status', 'upload_files', 'comment_task'],
        ],
        'electrician' => [
            'responsibilities' => [
                'Elektrische Inbetriebnahme',
                'Sicherheitsprüfung dokumentieren',
                'Technische Werte erfassen',
            ],
            'checklist' => [
                'Sicherheitscheck bestanden',
                'Messwerte eingetragen',
                'Freigabe erteilt',
            ],
            'kpi_targets' => [
                'safety_issues_max' => 0,
                'tasks_completed_per_week' => 7,
            ],
            'permissions' => ['update_task_status', 'upload_files', 'comment_task'],
        ],
        'support' => [
            'responsibilities' => [
                'Service- und Garantieanfragen bearbeiten',
                'Tickets priorisieren',
                'Kundenkommunikation nach Installation',
            ],
            'checklist' => [
                'Ticket angenommen',
                'Kunde kontaktiert',
                'Lösung dokumentiert',
                'Ticket geschlossen',
            ],
            'kpi_targets' => [
                'first_response_hours_max' => 4,
                'ticket_resolution_pct' => 90,
            ],
            'permissions' => ['manage_tickets', 'comment_task', 'view_customer_history'],
        ],
        'operations' => [
            'responsibilities' => [
                'Operative Planung',
                'Ressourcen und Auslastung kontrollieren',
                'Qualitäts- und Prozesskontrolle',
            ],
            'checklist' => [
                'Tagesplanung freigegeben',
                'Kapazitäten geprüft',
                'Abweichungen dokumentiert',
            ],
            'kpi_targets' => [
                'capacity_utilization_pct' => 85,
                'process_violations_max' => 2,
            ],
            'permissions' => ['view_reports', 'update_task_status', 'assign_tasks'],
        ],
    ];

    $template = $roleTemplates[$role] ?? $roleTemplates['operations'];
    $template['role_key'] = $role;

    return array_merge($common, $template);
}

function buildEmployeeProfileSummary(string $roleLabel): string {
    return 'Auto-Profil erstellt für Rolle: ' . $roleLabel . '.';
}

function employeeProfilesFilePath(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/employee_profiles.json';
}

function readEmployeeProfilesFromFile(): array {
    $path = employeeProfilesFilePath();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'id' => (int)($row['id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'role' => (string)($row['role'] ?? ''),
            'email' => (string)($row['email'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'status' => (string)($row['status'] ?? 'inactive'),
            'notes' => (string)($row['notes'] ?? ''),
            'profile_data' => (string)($row['profile_data'] ?? '{}'),
            'profile_ready' => (int)($row['profile_ready'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
        ];
    }

    return $rows;
}

function writeEmployeeProfilesToFile(array $rows): bool {
    $path = employeeProfilesFilePath();
    $json = json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

/**
 * Mitarbeiter aus der Zeit ohne DB-Tabelle (tmp/employee_profiles.json) einmalig mit
 * derselben ID in die Datenbank übernehmen. Sonst vergibt die Tabelle diese ID neu,
 * und Anmeldedaten (local_auth.json) sowie Aufgaben gehören plötzlich zwei Personen.
 */
function migrateEmployeeFileProfilesToDb(mysqli $conn): int {
    $path = employeeProfilesFilePath();
    if (!is_file($path)) {
        return 0;
    }
    $rows = readEmployeeProfilesFromFile();
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO employee_profiles
         (id, full_name, role, email, phone, status, notes, profile_data, profile_ready, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if ($stmt === false) {
        return 0;
    }
    $moved = 0;
    foreach ($rows as $row) {
        if ($row['id'] <= 0) {
            continue;
        }
        $stmt->bind_param(
            'isssssssiss',
            $row['id'], $row['full_name'], $row['role'], $row['email'], $row['phone'], $row['status'],
            $row['notes'], $row['profile_data'], $row['profile_ready'], $row['created_at'], $row['updated_at']
        );
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $moved++;
        } else {
            error_log('Mitarbeiter #' . $row['id'] . ' aus employee_profiles.json nicht übernommen (ID oder E-Mail bereits vergeben).');
        }
    }
    $stmt->close();
    // Datei bleibt als Nachweis erhalten, wird aber nicht erneut eingelesen.
    @rename($path, dirname($path) . '/employee_profiles.migrated.json');
    return $moved;
}

function nextEmployeeFileId(array $rows): int {
    $maxId = 0;
    foreach ($rows as $row) {
        $id = (int)($row['id'] ?? 0);
        if ($id > $maxId) {
            $maxId = $id;
        }
    }
    return $maxId + 1;
}

function fetchEmployeesDataFromFile(string $roleFilter, string $statusFilter, string $search, int $limit = 0, int $offset = 0): array {
    $rows = readEmployeeProfilesFromFile();

    $filtered = array_values(array_filter($rows, static function (array $row) use ($roleFilter, $statusFilter, $search): bool {
        if ($roleFilter !== 'all' && (string)($row['role'] ?? '') !== $roleFilter) {
            return false;
        }
        if (($statusFilter === 'active' || $statusFilter === 'inactive') && (string)($row['status'] ?? '') !== $statusFilter) {
            return false;
        }
        if ($search !== '') {
            $toLower = static function (string $value): string {
                return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
            };
            $needle = $toLower($search);
            $haystack = $toLower(
                (string)($row['id'] ?? '') . ' '
                . (string)($row['full_name'] ?? '') . ' '
                . (string)($row['email'] ?? '') . ' '
                . (string)($row['phone'] ?? '')
            );
            if (strpos($haystack, $needle) === false) {
                return false;
            }
        }
        return true;
    }));

    usort($filtered, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    $total = count($filtered);
    if ($limit > 0) {
        $filtered = array_slice($filtered, $offset, $limit);
    }

    return [$filtered, $total];
}

function fetchEmployeeByIdFromFile(int $employeeId): ?array {
    if ($employeeId <= 0) {
        return null;
    }

    $rows = readEmployeeProfilesFromFile();
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $employeeId) {
            return $row;
        }
    }

    return null;
}

function fetchEmployeeById(mysqli $conn, bool $hasTable, int $employeeId): ?array {
    if ($employeeId <= 0) {
        return null;
    }

    if (!$hasTable) {
        return fetchEmployeeByIdFromFile($employeeId);
    }

    $stmt = $conn->prepare(
        'SELECT id, full_name, role, email, phone, status, profile_data
         FROM employee_profiles
         WHERE id = ?'
    );
    if ($stmt === false) {
        return null;
    }

    $stmt->bind_param('i', $employeeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

function buildAdminUrl(array $overrides = [], array $remove = []): string {
    $params = $_GET;
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    $query = http_build_query($params);
    return 'admin.php' . ($query !== '' ? '?' . $query : '');
}

function buildReturnUrl(string $fallbackTab, string $returnQuery, array $allowedTabs): string {
    $params = ['tab' => $fallbackTab];
    if ($returnQuery !== '') {
        parse_str($returnQuery, $parsed);
        if (is_array($parsed)) {
            foreach ($parsed as $k => $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $key = (string)$k;
                if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                    continue;
                }
                $params[$key] = (string)$v;
            }
        }
    }

    unset($params['export']);
    if (!isset($params['tab']) || !in_array($params['tab'], $allowedTabs, true)) {
        $params['tab'] = $fallbackTab;
    }

    return 'admin.php?' . http_build_query($params);
}

function renderPagination(int $total, int $perPage, int $currentPage): string {
    if ($total <= $perPage) {
        return '';
    }

    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    $html = '<div class="pagination">';
    if ($currentPage > 1) {
        $html .= '<a class="page-link" href="' . h(buildAdminUrl(['page' => $currentPage - 1], ['export'])) . '">«</a>';
    }

    for ($i = $start; $i <= $end; $i++) {
        $activeClass = $i === $currentPage ? ' active' : '';
        $html .= '<a class="page-link' . $activeClass . '" href="' . h(buildAdminUrl(['page' => $i], ['export'])) . '">' . $i . '</a>';
    }

    if ($currentPage < $totalPages) {
        $html .= '<a class="page-link" href="' . h(buildAdminUrl(['page' => $currentPage + 1], ['export'])) . '">»</a>';
    }

    $html .= '</div>';
    return $html;
}

function outputCsv(string $filename, array $headers, array $rows): void {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        exit;
    }

    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';');

    foreach ($rows as $row) {
        fputcsv($out, $row, ';');
    }

    fclose($out);
    exit;
}

function bookingStatusLabel(string $status): string {
    return match ($status) {
        'pending' => 'Ausstehend',
        'approved' => 'Genehmigt',
        'rejected' => 'Abgelehnt',
        default => ucfirst($status),
    };
}

function reviewReplyStatusLabel(bool $hasReply): string {
    return $hasReply ? 'Beantwortet' : 'Offen';
}

function taskStatusLabel(string $status): string {
    $labels = workflowTaskStatusLabels();
    return (string)($labels[$status] ?? $status);
}

function taskStatusBadgeClass(string $status): string {
    return match ($status) {
        'done' => 'status-approved',
        'blocked', 'canceled' => 'status-rejected',
        'assigned', 'new' => 'status-open',
        'accepted', 'in_progress' => 'status-pending',
        default => 'status-pending',
    };
}

/**
 * Kontakt und Termin einer Buchung – vor dem Ablehnen lesen, weil die Zeile danach gelöscht ist.
 */
function bookingNotifyData(mysqli $conn, int $bookingId): ?array {
    $stmt = $conn->prepare('SELECT booked_by, date, status, service_type FROM booking WHERE id = ?');
    if ($stmt === false) {
        return null;
    }
    $stmt->bind_param('i', $bookingId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!is_array($row)) {
        return null;
    }
    $service = trim((string)($row['service_type'] ?? ''));
    if ($service === '') {
        $service = workflowGetBookingServiceType($bookingId) ?: 'Dienstleistung';
    }
    return [
        'email' => (string)($row['booked_by'] ?? ''),
        'date' => (string)$row['date'],
        'status' => (string)$row['status'],
        'service' => $service,
    ];
}

/**
 * Kunde per E-Mail über die Entscheidung informieren (nach der Antwort an den Browser).
 * Eine bereits genehmigte Buchung erneut zu genehmigen, löst keine zweite E-Mail aus.
 */
function notifyBookingDecision(?array $booking, bool $approved): void {
    if ($booking === null || filter_var($booking['email'], FILTER_VALIDATE_EMAIL) === false) {
        return;
    }
    if ($approved && $booking['status'] === 'approved') {
        return;
    }
    notifyDefer(static fn() => notifyClientDecision($booking['email'], $booking['date'], $booking['service'], $approved));
}

function approveBooking(mysqli $conn, int $bookingId): bool {
    $approved = 'approved';
    $stmt = $conn->prepare('UPDATE booking SET status = ?, admin_approved = 1 WHERE id = ?');
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('si', $approved, $bookingId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}

function rejectBooking(mysqli $conn, int $bookingId): bool {
    try {
        $conn->begin_transaction();

        $delReplies = $conn->prepare('DELETE rr FROM review_replies rr INNER JOIN reviews r ON rr.review_id = r.id WHERE r.booking_id = ?');
        if ($delReplies !== false) {
            $delReplies->bind_param('i', $bookingId);
            $delReplies->execute();
            $delReplies->close();
        }

        $delReviews = $conn->prepare('DELETE FROM reviews WHERE booking_id = ?');
        if ($delReviews !== false) {
            $delReviews->bind_param('i', $bookingId);
            $delReviews->execute();
            $delReviews->close();
        }

        $delBooking = $conn->prepare('DELETE FROM booking WHERE id = ?');
        if ($delBooking === false) {
            throw new RuntimeException('delete booking failed');
        }
        $delBooking->bind_param('i', $bookingId);
        $ok = $delBooking->execute();
        $delBooking->close();

        if (!$ok) {
            throw new RuntimeException('booking reject failed');
        }

        workflowDeleteBookingServiceType($bookingId);
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

function deleteReviewById(mysqli $conn, int $reviewId): bool {
    $bookingId = null;

    $select = $conn->prepare('SELECT booking_id FROM reviews WHERE id = ?');
    if ($select === false) {
        return false;
    }
    $select->bind_param('i', $reviewId);
    $select->execute();
    $select->bind_result($bookingId);
    $found = $select->fetch();
    $select->close();

    if (!$found) {
        return false;
    }

    try {
        $conn->begin_transaction();

        $delReplies = $conn->prepare('DELETE FROM review_replies WHERE review_id = ?');
        if ($delReplies !== false) {
            $delReplies->bind_param('i', $reviewId);
            $delReplies->execute();
            $delReplies->close();
        }

        $delReview = $conn->prepare('DELETE FROM reviews WHERE id = ?');
        if ($delReview === false) {
            throw new RuntimeException('review delete failed');
        }
        $delReview->bind_param('i', $reviewId);
        $ok = $delReview->execute();
        $delReview->close();

        if (!$ok) {
            throw new RuntimeException('review delete failed');
        }

        if ($bookingId !== null) {
            $updBooking = $conn->prepare('UPDATE booking SET has_review = 0 WHERE id = ?');
            if ($updBooking !== false) {
                $updBooking->bind_param('i', $bookingId);
                $updBooking->execute();
                $updBooking->close();
            }
        }

        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

function deleteUserById(mysqli $conn, int $userId): bool {
    $stmt = $conn->prepare('DELETE FROM user WHERE id = ?');
    if ($stmt === false) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $ok = $stmt->execute() && $stmt->affected_rows >= 0;
    $stmt->close();
    return $ok;
}

function fetchBookingsData(mysqli $conn, bool $hasServiceTypeColumn, string $statusFilter, string $search, int $limit = 0, int $offset = 0): array {
    $where = ['b.status != "free"'];
    $params = [];
    $types = '';

    if (in_array($statusFilter, ['pending', 'approved', 'rejected'], true)) {
        $where[] = 'b.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        if ($hasServiceTypeColumn) {
            $where[] = '(CAST(b.id AS CHAR) LIKE ? OR b.booked_by LIKE ? OR b.phone LIKE ? OR DATE_FORMAT(b.date, "%Y-%m-%d") LIKE ? OR b.service_type LIKE ?)';
            $params[] = $like;
            $types .= 's';
        } else {
            $where[] = '(CAST(b.id AS CHAR) LIKE ? OR b.booked_by LIKE ? OR b.phone LIKE ? OR DATE_FORMAT(b.date, "%Y-%m-%d") LIKE ?)';
        }
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) AS cnt FROM booking b' . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt === false) {
        return [[], 0];
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total = (int)($countResult['cnt'] ?? 0);
    $countStmt->close();

    $serviceSelect = $hasServiceTypeColumn ? 'COALESCE(b.service_type, "") AS service_type' : '"" AS service_type';
    $dataSql = 'SELECT b.id, b.date, b.booked_by, b.phone, ' . $serviceSelect . ', b.status, b.admin_approved, b.created_at, b.has_review '
        . 'FROM booking b' . $whereSql . ' ORDER BY b.created_at DESC, b.id DESC';

    $dataParams = $params;
    $dataTypes = $types;
    if ($limit > 0) {
        $dataSql .= ' LIMIT ? OFFSET ?';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataTypes .= 'ii';
    }

    $stmt = $conn->prepare($dataSql);
    if ($stmt === false) {
        return [[], $total];
    }
    if ($dataTypes !== '') {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    if (!$hasServiceTypeColumn && !empty($rows)) {
        foreach ($rows as &$row) {
            $bookingId = (int)($row['id'] ?? 0);
            $row['service_type'] = workflowGetBookingServiceType($bookingId);
        }
        unset($row);
    }

    return [$rows, $total];
}

function normalizePhoneForWhatsApp(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits) || $digits === '') {
        return '';
    }
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }
    return $digits;
}

function whatsappNotificationsLogPath(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/whatsapp_notifications.log';
}

function formatSlaHoursLabel(int $hours): string {
    if ($hours < 24) {
        return $hours . 'h';
    }
    $days = intdiv($hours, 24);
    $restHours = $hours % 24;
    return $days . 'd ' . $restHours . 'h';
}

function assignmentSlaMeta(string $createdAt): array {
    $createdTs = strtotime($createdAt);
    if ($createdTs === false) {
        return [
            'hours' => 0,
            'label' => '-',
            'level' => 'ok',
        ];
    }

    $hours = max(0, (int)floor((time() - $createdTs) / 3600));
    $level = 'ok';
    if ($hours >= 72) {
        $level = 'critical';
    } elseif ($hours >= 24) {
        $level = 'warning';
    }

    return [
        'hours' => $hours,
        'label' => formatSlaHoursLabel($hours),
        'level' => $level,
    ];
}

function inferRoleForServiceType(string $serviceType): string {
    $needle = workflowNormalize($serviceType);
    $roleKeywords = [
        'electrician' => ['elektr', 'strom', 'wechselrichter', 'verkabel', 'anschluss', 'pv'],
        'installer' => ['montage', 'solaran', 'solar', 'panel', 'installation'],
        'support' => ['wartung', 'support', 'service', 'reparatur', 'stoerung'],
        'project_manager' => ['projekt', 'planung', 'koordination', 'angebot'],
    ];

    foreach ($roleKeywords as $role => $keywords) {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($needle, $keyword) !== false) {
                return $role;
            }
        }
    }

    return 'operations';
}

function taskTemplateForServiceType(string $serviceType): array {
    $cleanService = trim($serviceType);
    $serviceType = $cleanService !== '' ? $cleanService : 'Allgemein';
    $role = inferRoleForServiceType($serviceType);

    return match ($role) {
        'electrician' => [
            'title' => 'Elektrik-Check fuer Buchung',
            'description' => 'Sicherheitspruefung durchfuehren, Werte dokumentieren und Status aktualisieren.',
        ],
        'installer' => [
            'title' => 'Montageeinsatz vorbereiten',
            'description' => 'Material und Werkzeug pruefen, Montage vor Ort durchfuehren und Fotodoku abschliessen.',
        ],
        'support' => [
            'title' => 'Serviceeinsatz fuer Kundenauftrag',
            'description' => 'Problem analysieren, Servicearbeiten erledigen und Ergebnis fuer Kunden dokumentieren.',
        ],
        'project_manager' => [
            'title' => 'Projektkoordination fuer Buchung',
            'description' => 'Ablauf planen, Team koordinieren und Terminabstimmung mit Kunde sichern.',
        ],
        default => [
            'title' => 'Auftrag aus Buchung bearbeiten',
            'description' => 'Auftragsdetails pruefen, passenden Einsatz organisieren und Fortschritt melden.',
        ],
    };
}

function buildTaskTemplateCatalog(array $serviceTypes): array {
    $catalog = [];
    foreach ($serviceTypes as $serviceType) {
        $label = trim((string)$serviceType);
        if ($label === '') {
            continue;
        }
        $catalog[$label] = taskTemplateForServiceType($label);
    }
    if (!isset($catalog['Allgemein'])) {
        $catalog['Allgemein'] = taskTemplateForServiceType('Allgemein');
    }
    return $catalog;
}

function hasTaskForBooking(array $tasks, int $bookingId): bool {
    if ($bookingId <= 0) {
        return false;
    }
    foreach ($tasks as $task) {
        if ((int)($task['booking_id'] ?? 0) === $bookingId) {
            return true;
        }
    }
    return false;
}

function fetchBookingsByIds(mysqli $conn, bool $hasServiceTypeColumn, array $bookingIds): array {
    if (!tableExists($conn, 'booking')) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $bookingIds), static fn(int $id): bool => $id > 0)));
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $serviceSelect = $hasServiceTypeColumn ? 'COALESCE(b.service_type, "") AS service_type' : '"" AS service_type';
    $sql = 'SELECT b.id, b.date, b.booked_by, b.phone, ' . $serviceSelect . ', b.status, b.created_at '
        . 'FROM booking b WHERE b.id IN (' . $placeholders . ')';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return [];
    }

    $types = str_repeat('i', count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[(int)($row['id'] ?? 0)] = $row;
    }
    $stmt->close();

    if (!$hasServiceTypeColumn && !empty($rows)) {
        foreach ($rows as &$row) {
            $bookingId = (int)($row['id'] ?? 0);
            $row['service_type'] = workflowGetBookingServiceType($bookingId);
        }
        unset($row);
    }

    return $rows;
}

function sortUnassignedBookings(array $rows, string $sort): array {
    $sort = trim($sort);
    if (!in_array($sort, ['urgent_oldest', 'oldest', 'newest', 'date_asc', 'date_desc', 'sla_desc'], true)) {
        $sort = 'urgent_oldest';
    }

    $priorityWeight = static function (array $row): int {
        $status = (string)($row['status'] ?? '');
        $slaLevel = (string)($row['sla_level'] ?? 'ok');
        $statusWeight = $status === 'pending' ? 3 : ($status === 'approved' ? 2 : 1);
        $slaWeight = $slaLevel === 'critical' ? 30 : ($slaLevel === 'warning' ? 20 : 10);
        return $statusWeight + $slaWeight;
    };

    usort($rows, static function (array $a, array $b) use ($sort, $priorityWeight): int {
        $aCreated = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $bCreated = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        $aDate = strtotime((string)($a['date'] ?? '')) ?: 0;
        $bDate = strtotime((string)($b['date'] ?? '')) ?: 0;
        $aSla = (int)($a['sla_hours'] ?? 0);
        $bSla = (int)($b['sla_hours'] ?? 0);

        return match ($sort) {
            'oldest' => $aCreated <=> $bCreated,
            'newest' => $bCreated <=> $aCreated,
            'date_asc' => $aDate <=> $bDate,
            'date_desc' => $bDate <=> $aDate,
            'sla_desc' => $bSla <=> $aSla,
            default => (($priorityWeight($b) <=> $priorityWeight($a)) !== 0
                ? ($priorityWeight($b) <=> $priorityWeight($a))
                : (($bSla <=> $aSla) !== 0 ? ($bSla <=> $aSla) : ($aCreated <=> $bCreated))),
        };
    });

    return $rows;
}

function fetchUnassignedBookings(mysqli $conn, bool $hasServiceTypeColumn, array $tasks, array $filters = []): array {
    if (!tableExists($conn, 'booking')) {
        return [];
    }

    $queueStatus = (string)($filters['status'] ?? 'open');
    if (!in_array($queueStatus, ['open', 'all', 'pending', 'approved', 'rejected'], true)) {
        $queueStatus = 'open';
    }
    $queueService = trim((string)($filters['service_type'] ?? 'all'));
    $queueSearch = trim((string)($filters['search'] ?? ''));
    $queueDateFrom = validDate((string)($filters['date_from'] ?? ''));
    $queueDateTo = validDate((string)($filters['date_to'] ?? ''));
    $queueSort = (string)($filters['sort'] ?? 'urgent_oldest');

    $assignedBookingIds = [];
    foreach ($tasks as $task) {
        $bookingId = (int)($task['booking_id'] ?? 0);
        if ($bookingId > 0) {
            $assignedBookingIds[$bookingId] = true;
        }
    }

    $serviceSelect = $hasServiceTypeColumn ? 'COALESCE(b.service_type, "") AS service_type' : '"" AS service_type';
    $sql = 'SELECT b.id, b.date, b.booked_by, b.phone, ' . $serviceSelect . ', b.status, b.created_at '
        . 'FROM booking b WHERE b.status != "free"';

    $params = [];
    $types = '';

    if ($queueStatus === 'open') {
        $sql .= ' AND b.status IN ("pending", "approved")';
    } elseif ($queueStatus !== 'all') {
        $sql .= ' AND b.status = ?';
        $params[] = $queueStatus;
        $types .= 's';
    }

    if ($queueDateFrom !== '') {
        $sql .= ' AND b.date >= ?';
        $params[] = $queueDateFrom;
        $types .= 's';
    }
    if ($queueDateTo !== '') {
        $sql .= ' AND b.date <= ?';
        $params[] = $queueDateTo;
        $types .= 's';
    }

    if ($queueSearch !== '') {
        $like = '%' . $queueSearch . '%';
        if ($hasServiceTypeColumn) {
            $sql .= ' AND (CAST(b.id AS CHAR) LIKE ? OR b.booked_by LIKE ? OR b.phone LIKE ? OR DATE_FORMAT(b.date, "%Y-%m-%d") LIKE ? OR b.service_type LIKE ?)';
            $params[] = $like;
            $types .= 's';
        } else {
            $sql .= ' AND (CAST(b.id AS CHAR) LIKE ? OR b.booked_by LIKE ? OR b.phone LIKE ? OR DATE_FORMAT(b.date, "%Y-%m-%d") LIKE ?)';
        }
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    if ($hasServiceTypeColumn && $queueService !== '' && $queueService !== 'all') {
        $sql .= ' AND b.service_type = ?';
        $params[] = $queueService;
        $types .= 's';
    }

    if (!empty($assignedBookingIds)) {
        $placeholders = implode(',', array_fill(0, count($assignedBookingIds), '?'));
        $sql .= ' AND b.id NOT IN (' . $placeholders . ')';
        foreach (array_keys($assignedBookingIds) as $bookingId) {
            $params[] = (int)$bookingId;
            $types .= 'i';
        }
    }

    $sql .= ' ORDER BY b.created_at DESC, b.id DESC';
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        return [];
    }
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    if (!$hasServiceTypeColumn && !empty($rows)) {
        foreach ($rows as &$row) {
            $bookingId = (int)($row['id'] ?? 0);
            $row['service_type'] = workflowGetBookingServiceType($bookingId);
        }
        unset($row);
    }

    $rows = array_values(array_filter($rows, static function (array $row) use ($queueService, $queueSearch): bool {
        $serviceType = trim((string)($row['service_type'] ?? ''));
        if ($queueService !== '' && $queueService !== 'all' && workflowNormalize($serviceType) !== workflowNormalize($queueService)) {
            return false;
        }
        if ($queueSearch !== '') {
            $needle = workflowNormalize($queueSearch);
            $haystack = workflowNormalize(
                (string)($row['id'] ?? '') . ' '
                . (string)($row['booked_by'] ?? '') . ' '
                . (string)($row['phone'] ?? '') . ' '
                . (string)($row['date'] ?? '') . ' '
                . $serviceType
            );
            if (strpos($haystack, $needle) === false) {
                return false;
            }
        }
        return true;
    }));

    foreach ($rows as &$row) {
        $serviceType = trim((string)($row['service_type'] ?? ''));
        $serviceType = $serviceType !== '' ? $serviceType : 'Allgemein';
        $template = taskTemplateForServiceType($serviceType);
        $slaMeta = assignmentSlaMeta((string)($row['created_at'] ?? ''));
        $row['service_type'] = $serviceType;
        $row['template_title'] = $template['title'];
        $row['template_description'] = $template['description'];
        $row['sla_hours'] = $slaMeta['hours'];
        $row['sla_label'] = $slaMeta['label'];
        $row['sla_level'] = $slaMeta['level'];
        $row['recommended_role'] = inferRoleForServiceType($serviceType);
    }
    unset($row);

    return sortUnassignedBookings($rows, $queueSort);
}

function summarizeUnassignedByService(array $rows): array {
    $counts = [];
    foreach ($rows as $row) {
        $service = trim((string)($row['service_type'] ?? ''));
        $label = $service !== '' ? $service : 'Allgemein';
        if (!isset($counts[$label])) {
            $counts[$label] = 0;
        }
        $counts[$label]++;
    }
    arsort($counts);
    return $counts;
}

function buildEmployeeLoadMap(array $employees, array $tasks): array {
    $map = [];
    $today = date('Y-m-d');

    foreach ($employees as $employee) {
        $employeeId = (int)($employee['id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }
        $map[$employeeId] = [
            'employee_id' => $employeeId,
            'name' => (string)($employee['full_name'] ?? ''),
            'role' => (string)($employee['role'] ?? ''),
            'status' => (string)($employee['status'] ?? 'inactive'),
            'active' => 0,
            'urgent' => 0,
            'due_today' => 0,
            'service_counts' => [],
            'date_counts' => [],
        ];
    }

    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }
        $employeeId = (int)($task['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }
        if (!isset($map[$employeeId])) {
            $map[$employeeId] = [
                'employee_id' => $employeeId,
                'name' => (string)($task['employee_name'] ?? ''),
                'role' => (string)($task['employee_role'] ?? ''),
                'status' => 'active',
                'active' => 0,
                'urgent' => 0,
                'due_today' => 0,
                'service_counts' => [],
                'date_counts' => [],
            ];
        }

        $status = (string)($task['status'] ?? 'assigned');
        if (in_array($status, ['done', 'canceled'], true)) {
            continue;
        }

        $map[$employeeId]['active']++;

        $serviceType = trim((string)($task['service_type'] ?? ''));
        $serviceKey = $serviceType !== '' ? workflowNormalize($serviceType) : 'allgemein';
        if (!isset($map[$employeeId]['service_counts'][$serviceKey])) {
            $map[$employeeId]['service_counts'][$serviceKey] = 0;
        }
        $map[$employeeId]['service_counts'][$serviceKey]++;

        $dueDate = (string)($task['due_date'] ?? '');
        if ($dueDate !== '') {
            if (!isset($map[$employeeId]['date_counts'][$dueDate])) {
                $map[$employeeId]['date_counts'][$dueDate] = 0;
            }
            $map[$employeeId]['date_counts'][$dueDate]++;
            if ($dueDate === $today) {
                $map[$employeeId]['due_today']++;
            }
        }

        if ((string)($task['priority'] ?? '') === 'urgent' || ($dueDate !== '' && $dueDate < $today)) {
            $map[$employeeId]['urgent']++;
        }
    }

    return $map;
}

function recommendEmployeeIdForServiceType(string $serviceType, array $employees, array $loadMap): int {
    $preferredRole = inferRoleForServiceType($serviceType);
    $activeEmployees = array_values(array_filter($employees, static fn(array $row): bool => (string)($row['status'] ?? '') === 'active'));
    if (empty($activeEmployees)) {
        return 0;
    }

    $sameRole = array_values(array_filter(
        $activeEmployees,
        static fn(array $row): bool => (string)($row['role'] ?? '') === $preferredRole
    ));
    $pool = !empty($sameRole) ? $sameRole : $activeEmployees;

    usort($pool, static function (array $a, array $b) use ($loadMap): int {
        $aId = (int)($a['id'] ?? 0);
        $bId = (int)($b['id'] ?? 0);
        $aLoad = $loadMap[$aId] ?? ['active' => 0, 'urgent' => 0];
        $bLoad = $loadMap[$bId] ?? ['active' => 0, 'urgent' => 0];
        if ((int)$aLoad['active'] !== (int)$bLoad['active']) {
            return (int)$aLoad['active'] <=> (int)$bLoad['active'];
        }
        if ((int)$aLoad['urgent'] !== (int)$bLoad['urgent']) {
            return (int)$aLoad['urgent'] <=> (int)$bLoad['urgent'];
        }
        return $aId <=> $bId;
    });

    return (int)($pool[0]['id'] ?? 0);
}

function buildEmployeeMapById(array $employees): array {
    $map = [];
    foreach ($employees as $employee) {
        $employeeId = (int)($employee['id'] ?? 0);
        if ($employeeId > 0) {
            $map[$employeeId] = $employee;
        }
    }
    return $map;
}

function buildAssignmentWarnings(int $employeeId, string $serviceType, string $dueDate, array $employeesById, array $employeeLoadMap): array {
    if ($employeeId <= 0 || !isset($employeesById[$employeeId])) {
        return [];
    }

    $employee = $employeesById[$employeeId];
    $load = $employeeLoadMap[$employeeId] ?? [
        'active' => 0,
        'urgent' => 0,
        'service_counts' => [],
        'date_counts' => [],
    ];

    $warnings = [];
    if ((int)($load['active'] ?? 0) >= 8) {
        $warnings[] = 'Mitarbeiter hat hohe Auslastung (' . (int)$load['active'] . ' offene Aufgaben).';
    }
    if ((int)($load['urgent'] ?? 0) >= 3) {
        $warnings[] = 'Mitarbeiter hat bereits mehrere dringende Aufgaben (' . (int)$load['urgent'] . ').';
    }

    if ($dueDate !== '') {
        $sameDate = (int)(($load['date_counts'][$dueDate] ?? 0));
        if ($sameDate >= 2) {
            $warnings[] = 'Am Fälligkeitsdatum ' . $dueDate . ' sind bereits ' . $sameDate . ' Aufgaben eingeplant.';
        }
    }

    $serviceKey = workflowNormalize($serviceType !== '' ? $serviceType : 'allgemein');
    $sameService = (int)(($load['service_counts'][$serviceKey] ?? 0));
    if ($sameService >= 4) {
        $warnings[] = 'Mitarbeiter bearbeitet bereits viele Aufgaben dieses Service-Typs (' . $sameService . ').';
    }

    $preferredRole = inferRoleForServiceType($serviceType);
    $employeeRole = (string)($employee['role'] ?? '');
    if ($preferredRole !== 'operations' && $employeeRole !== '' && $employeeRole !== $preferredRole) {
        $warnings[] = 'Service-Typ passt eher zur Rolle ' . $preferredRole . ', Mitarbeiter hat Rolle ' . $employeeRole . '.';
    }

    return $warnings;
}

function queueWhatsAppTaskNotification(array $employee, array $task, string $event = 'assigned'): array {
    $phoneRaw = (string)($employee['phone'] ?? '');
    $phone = normalizePhoneForWhatsApp($phoneRaw);
    if ($phone === '') {
        return [
            'queued' => false,
            'reason' => 'missing_phone',
            'url' => '',
        ];
    }

    $taskId = (int)($task['id'] ?? 0);
    $bookingId = (int)($task['booking_id'] ?? 0);
    $dueDate = (string)($task['due_date'] ?? '');
    $message = "Neue Aufgabe (" . $event . ")\n"
        . "Task #" . $taskId . "\n"
        . "Service: " . (string)($task['service_type'] ?? '-') . "\n"
        . "Titel: " . (string)($task['title'] ?? '-') . "\n"
        . "Buchung: " . ($bookingId > 0 ? '#' . $bookingId : '-') . "\n"
        . "Faellig: " . ($dueDate !== '' ? $dueDate : '-') . "\n"
        . "Bitte im Mitarbeiterprofil annehmen.";
    $url = 'https://wa.me/' . $phone . '?text=' . rawurlencode($message);

    $payload = [
        'created_at' => date('Y-m-d H:i:s'),
        'event' => $event,
        'employee_id' => (int)($employee['id'] ?? 0),
        'employee_name' => (string)($employee['full_name'] ?? ''),
        'phone' => $phoneRaw,
        'phone_normalized' => $phone,
        'task_id' => $taskId,
        'booking_id' => $bookingId > 0 ? $bookingId : null,
        'message' => $message,
        'whatsapp_url' => $url,
    ];

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return [
            'queued' => false,
            'reason' => 'encode_failed',
            'url' => $url,
        ];
    }

    $written = @file_put_contents(whatsappNotificationsLogPath(), $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    return [
        'queued' => $written,
        'reason' => $written ? 'ok' : 'write_failed',
        'url' => $url,
    ];
}

function readRecentWhatsAppNotifications(int $limit = 8): array {
    $path = whatsappNotificationsLogPath();
    if (!is_file($path) || $limit <= 0) {
        return [];
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || empty($lines)) {
        return [];
    }

    $slice = array_slice($lines, -$limit);
    $rows = [];
    foreach (array_reverse($slice) as $line) {
        $decoded = json_decode((string)$line, true);
        if (!is_array($decoded)) {
            continue;
        }
        $rows[] = $decoded;
    }

    return $rows;
}

function buildUnassignedAnalytics(array $unassignedBookings, array $allTasks, array $bookingRowsById): array {
    $total = count($unassignedBookings);
    $critical = 0;
    $waitHoursTotal = 0;
    $maxWaitHours = 0;

    foreach ($unassignedBookings as $booking) {
        $hours = (int)($booking['sla_hours'] ?? 0);
        $waitHoursTotal += $hours;
        $maxWaitHours = max($maxWaitHours, $hours);
        if ((string)($booking['sla_level'] ?? '') === 'critical') {
            $critical++;
        }
    }

    $avgWaitHours = $total > 0 ? round($waitHoursTotal / $total, 1) : 0.0;

    $assignDurations = [];
    foreach ($allTasks as $task) {
        $bookingId = (int)($task['booking_id'] ?? 0);
        if ($bookingId <= 0 || !isset($bookingRowsById[$bookingId])) {
            continue;
        }
        $bookingCreated = strtotime((string)($bookingRowsById[$bookingId]['created_at'] ?? ''));
        $taskCreated = strtotime((string)($task['created_at'] ?? ''));
        if ($bookingCreated === false || $taskCreated === false || $taskCreated < $bookingCreated) {
            continue;
        }
        $assignDurations[] = ($taskCreated - $bookingCreated) / 3600;
    }

    $avgAssignHours = !empty($assignDurations) ? round(array_sum($assignDurations) / count($assignDurations), 1) : 0.0;

    return [
        'unassigned_total' => $total,
        'sla_critical' => $critical,
        'avg_wait_hours' => $avgWaitHours,
        'oldest_wait_hours' => $maxWaitHours,
        'avg_assign_hours' => $avgAssignHours,
    ];
}

function buildTaskDataFromBooking(array $booking): array {
    $bookingId = (int)($booking['id'] ?? 0);
    $serviceType = trim((string)($booking['service_type'] ?? ''));
    $serviceType = $serviceType !== '' ? $serviceType : 'Allgemein';
    $template = taskTemplateForServiceType($serviceType);
    $date = validDate((string)($booking['date'] ?? ''));

    $title = $template['title'] . ' #' . $bookingId;
    $description = $template['description'];
    $bookedBy = trim((string)($booking['booked_by'] ?? ''));
    if ($bookedBy !== '') {
        $description .= ' Kunde: ' . $bookedBy . '.';
    }
    if ($date !== '') {
        $description .= ' Einsatzdatum: ' . $date . '.';
    }

    return [
        'booking_id' => $bookingId,
        'service_type' => $serviceType,
        'title' => $title,
        'description' => $description,
        'due_date' => $date,
    ];
}

function fetchReviewsData(mysqli $conn, int $ratingFilter, string $replyFilter, string $search, int $limit = 0, int $offset = 0): array {
    $latestReplyJoin = ' LEFT JOIN ( '
        . 'SELECT rr1.review_id, rr1.reply_text, rr1.reply_date '
        . 'FROM review_replies rr1 '
        . 'INNER JOIN (SELECT review_id, MAX(id) AS max_id FROM review_replies GROUP BY review_id) rr2 '
        . 'ON rr1.review_id = rr2.review_id AND rr1.id = rr2.max_id '
        . ') lr ON lr.review_id = r.id ';

    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($ratingFilter >= 1 && $ratingFilter <= 5) {
        $where[] = 'r.rating = ?';
        $params[] = $ratingFilter;
        $types .= 'i';
    }

    if ($replyFilter === 'with') {
        $where[] = 'lr.reply_text IS NOT NULL';
    } elseif ($replyFilter === 'without') {
        $where[] = 'lr.reply_text IS NULL';
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(CAST(r.id AS CHAR) LIKE ? OR r.user_email LIKE ? OR r.review_text LIKE ? OR DATE_FORMAT(b.date, "%Y-%m-%d") LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) AS cnt FROM reviews r LEFT JOIN booking b ON b.id = r.booking_id' . $latestReplyJoin . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt === false) {
        return [[], 0];
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total = (int)($countResult['cnt'] ?? 0);
    $countStmt->close();

    $dataSql = 'SELECT r.id, r.user_email, r.review_text, r.rating, r.created_at, b.date AS booking_date, '
        . 'lr.reply_text, lr.reply_date '
        . 'FROM reviews r '
        . 'LEFT JOIN booking b ON b.id = r.booking_id'
        . $latestReplyJoin
        . $whereSql
        . ' ORDER BY r.created_at DESC, r.id DESC';

    $dataParams = $params;
    $dataTypes = $types;
    if ($limit > 0) {
        $dataSql .= ' LIMIT ? OFFSET ?';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataTypes .= 'ii';
    }

    $stmt = $conn->prepare($dataSql);
    if ($stmt === false) {
        return [[], $total];
    }
    if ($dataTypes !== '') {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return [$rows, $total];
}

function fetchUsersData(mysqli $conn, string $fromDate, string $toDate, string $search, int $limit = 0, int $offset = 0): array {
    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($fromDate !== '') {
        $where[] = 'u.created_at >= ?';
        $params[] = $fromDate . ' 00:00:00';
        $types .= 's';
    }

    if ($toDate !== '') {
        $where[] = 'u.created_at <= ?';
        $params[] = $toDate . ' 23:59:59';
        $types .= 's';
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM booking b2 WHERE b2.booked_by = u.email AND b2.phone LIKE ?))';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) AS cnt FROM user u' . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt === false) {
        return [[], 0];
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total = (int)($countResult['cnt'] ?? 0);
    $countStmt->close();

    $dataSql = 'SELECT u.id, u.username, u.email, u.created_at, '
        . 'COALESCE(bp.phones, "Nicht angegeben") AS phones, '
        . 'COALESCE(bp.bookings_count, 0) AS bookings_count, '
        . 'COALESCE(rv.reviews_count, 0) AS reviews_count '
        . 'FROM user u '
        . 'LEFT JOIN ( '
        . '   SELECT booked_by, '
        . '   GROUP_CONCAT(DISTINCT phone ORDER BY created_at DESC SEPARATOR ", ") AS phones, '
        . '   COUNT(*) AS bookings_count '
        . '   FROM booking '
        . '   WHERE booked_by IS NOT NULL '
        . '   GROUP BY booked_by '
        . ') bp ON bp.booked_by = u.email '
        . 'LEFT JOIN ( '
        . '   SELECT user_email, COUNT(*) AS reviews_count '
        . '   FROM reviews '
        . '   GROUP BY user_email '
        . ') rv ON rv.user_email = u.email '
        . $whereSql
        . ' ORDER BY u.created_at DESC, u.id DESC';

    $dataParams = $params;
    $dataTypes = $types;
    if ($limit > 0) {
        $dataSql .= ' LIMIT ? OFFSET ?';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataTypes .= 'ii';
    }

    $stmt = $conn->prepare($dataSql);
    if ($stmt === false) {
        return [[], $total];
    }
    if ($dataTypes !== '') {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return [$rows, $total];
}

function fetchEmployeesData(mysqli $conn, bool $hasTable, string $roleFilter, string $statusFilter, string $search, int $limit = 0, int $offset = 0): array {
    if (!$hasTable) {
        return fetchEmployeesDataFromFile($roleFilter, $statusFilter, $search, $limit, $offset);
    }

    $where = ['1=1'];
    $params = [];
    $types = '';

    if ($roleFilter !== 'all') {
        $where[] = 'e.role = ?';
        $params[] = $roleFilter;
        $types .= 's';
    }

    if ($statusFilter === 'active' || $statusFilter === 'inactive') {
        $where[] = 'e.status = ?';
        $params[] = $statusFilter;
        $types .= 's';
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(CAST(e.id AS CHAR) LIKE ? OR e.full_name LIKE ? OR e.email LIKE ? OR e.phone LIKE ?)';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'ssss';
    }

    $whereSql = ' WHERE ' . implode(' AND ', $where);

    $countSql = 'SELECT COUNT(*) AS cnt FROM employee_profiles e' . $whereSql;
    $countStmt = $conn->prepare($countSql);
    if ($countStmt === false) {
        return [[], 0];
    }
    if ($types !== '') {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result()->fetch_assoc();
    $total = (int)($countResult['cnt'] ?? 0);
    $countStmt->close();

    $dataSql = 'SELECT e.id, e.full_name, e.role, e.email, e.phone, e.status, e.notes, e.profile_data, e.profile_ready, e.created_at, e.updated_at '
        . 'FROM employee_profiles e'
        . $whereSql
        . ' ORDER BY e.created_at DESC, e.id DESC';

    $dataParams = $params;
    $dataTypes = $types;
    if ($limit > 0) {
        $dataSql .= ' LIMIT ? OFFSET ?';
        $dataParams[] = $limit;
        $dataParams[] = $offset;
        $dataTypes .= 'ii';
    }

    $stmt = $conn->prepare($dataSql);
    if ($stmt === false) {
        return [[], $total];
    }
    if ($dataTypes !== '') {
        $stmt->bind_param($dataTypes, ...$dataParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return [$rows, $total];
}

$tabLabels = [
    'dashboard' => 'Übersicht',
    'bookings' => 'Buchungen',
    'reviews' => 'Bewertungen',
    'users' => 'Benutzer',
    'employees' => 'Mitarbeiter',
    'tasks' => 'Aufgaben',
    'reports' => 'Berichte',
];
$tabIcons = [
    'dashboard' => 'fa-gauge-high',
    'bookings' => 'fa-calendar-check',
    'reviews' => 'fa-star',
    'users' => 'fa-users',
    'employees' => 'fa-id-badge',
    'tasks' => 'fa-list-check',
    'reports' => 'fa-chart-column',
];
$tabHints = [
    'dashboard' => 'Buchungen, Bewertungen, Benutzer und Mitarbeiter auf einen Blick.',
    'bookings' => 'Anfragen prüfen, genehmigen oder ablehnen.',
    'reviews' => 'Kundenstimmen lesen und beantworten.',
    'users' => 'Kundenkonten und Passwörter verwalten.',
    'employees' => 'Mitarbeiter anlegen, Rollen und Status pflegen.',
    'tasks' => 'Genehmigte Buchungen an Mitarbeiter verteilen.',
    'reports' => 'Kennzahlen für einen frei gewählten Zeitraum.',
];
$allowedTabs = array_keys($tabLabels);

$employeeRoles = [
    'project_manager' => 'Projektmanager',
    'installer' => 'Solarmodul-Montage',
    'electrician' => 'Elektriker',
    'support' => 'Service und Support',
    'operations' => 'Operations',
];
$employeeStatuses = [
    'active' => 'Aktiv',
    'inactive' => 'Inaktiv',
];
$taskStatusLabels = workflowTaskStatusLabels();
$taskPriorityLabels = workflowTaskPriorityLabels();

$activeTab = (string)($_GET['tab'] ?? ($_POST['return_tab'] ?? 'dashboard'));
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'dashboard';
}

$allowedPerPage = [10, 20, 50, 100];
$perPage = (int)($_GET['per_page'] ?? 10);
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 10;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$bookingSearch = trim((string)($_GET['booking_q'] ?? ''));
$bookingStatusFilter = (string)($_GET['booking_status'] ?? 'all');
if (!in_array($bookingStatusFilter, ['all', 'pending', 'approved', 'rejected'], true)) {
    $bookingStatusFilter = 'all';
}

$reviewSearch = trim((string)($_GET['review_q'] ?? ''));
$reviewRatingFilter = (int)($_GET['review_rating'] ?? 0);
if ($reviewRatingFilter < 0 || $reviewRatingFilter > 5) {
    $reviewRatingFilter = 0;
}
$reviewReplyFilter = (string)($_GET['review_reply'] ?? 'all');
if (!in_array($reviewReplyFilter, ['all', 'with', 'without'], true)) {
    $reviewReplyFilter = 'all';
}

$userSearch = trim((string)($_GET['user_q'] ?? ''));
$usersFromDate = validDate((string)($_GET['users_from'] ?? ''));
$usersToDate = validDate((string)($_GET['users_to'] ?? ''));

$employeeSearch = trim((string)($_GET['employee_q'] ?? ''));
$employeeRoleFilter = (string)($_GET['employee_role'] ?? 'all');
if (!in_array($employeeRoleFilter, array_merge(['all'], array_keys($employeeRoles)), true)) {
    $employeeRoleFilter = 'all';
}
$employeeStatusFilter = (string)($_GET['employee_status'] ?? 'all');
if (!in_array($employeeStatusFilter, ['all', 'active', 'inactive'], true)) {
    $employeeStatusFilter = 'all';
}
$taskSearch = trim((string)($_GET['task_q'] ?? ''));
$taskEmployeeFilter = max(0, (int)($_GET['task_employee'] ?? 0));
$taskStatusFilter = (string)($_GET['task_status'] ?? 'all');
if (!in_array($taskStatusFilter, array_merge(['all'], array_keys($taskStatusLabels)), true)) {
    $taskStatusFilter = 'all';
}

$reportFromDate = validDate((string)($_GET['report_from'] ?? date('Y-m-01')));
$reportToDate = validDate((string)($_GET['report_to'] ?? date('Y-m-d')));
if ($reportFromDate === '') {
    $reportFromDate = date('Y-m-01');
}
if ($reportToDate === '') {
    $reportToDate = date('Y-m-d');
}
if ($reportFromDate > $reportToDate) {
    [$reportFromDate, $reportToDate] = [$reportToDate, $reportFromDate];
}

$exportType = (string)($_GET['export'] ?? '');
if (!in_array($exportType, ['', 'bookings', 'reviews', 'users', 'employees', 'reports'], true)) {
    $exportType = '';
}

$message = '';
$messageType = '';
if (isset($_SESSION['flash_message'])) {
    $message = (string)$_SESSION['flash_message'];
    $messageType = (string)($_SESSION['flash_message_type'] ?? '');
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

$dashboardStats = [
    'bookings_total' => 0,
    'bookings_pending' => 0,
    'bookings_approved' => 0,
    'bookings_rejected' => 0,
    'bookings_today' => 0,
    'reviews_total' => 0,
    'reviews_open' => 0,
    'reviews_avg' => 0,
    'users_total' => 0,
    'employees_total' => 0,
    'employees_active' => 0,
];
$dashboardRecentBookings = [];
$dashboardRecentReviews = [];

$reportSummary = [
    'bookings_total' => 0,
    'bookings_pending' => 0,
    'bookings_approved' => 0,
    'bookings_rejected' => 0,
    'reviews_total' => 0,
    'reviews_avg' => 0,
    'users_new' => 0,
    'employees_new' => 0,
];
$reportTopCustomers = [];
$reportBookingTrend = [];

$bookingsRows = [];
$bookingsTotal = 0;
$reviewsRows = [];
$reviewsTotal = 0;
$usersRows = [];
$usersTotal = 0;
$employeesRows = [];
$employeesTotal = 0;
$employeesAllRows = [];
$serviceTypes = workflowReadServiceTypes();
$tasksRows = [];
$tasksByEmployeeStats = [];
$unassignedBookings = [];
$employeeLoadMap = [];

$hasEmployeesTable = false;
$employeesStorageMode = 'file';
$hasBookingServiceType = false;

try {
    $conn = getDbConnection();
    $hasBookingServiceType = ensureBookingServiceTypeColumn($conn);
    $hasEmployeesTable = ensureEmployeeProfilesTable($conn);
    if ($hasEmployeesTable) {
        $hasEmployeesTable = ensureEmployeeProfileColumns($conn);
    }
    $employeesStorageMode = $hasEmployeesTable ? 'db' : 'file';
    if ($hasEmployeesTable) {
        migrateEmployeeFileProfilesToDb($conn);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = (string)$_POST['action'];
        $returnTab = (string)($_POST['return_tab'] ?? $activeTab);
        if (!in_array($returnTab, $allowedTabs, true)) {
            $returnTab = 'dashboard';
        }
        $returnQuery = (string)($_POST['return_query'] ?? '');
        $redirectUrl = buildReturnUrl($returnTab, $returnQuery, $allowedTabs);

        if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
            $_SESSION['flash_message'] = 'Ungültiger CSRF-Token.';
            $_SESSION['flash_message_type'] = 'error';
            header('Location: ' . $redirectUrl);
            exit;
        }

        switch ($action) {
            case 'booking_single': {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $decision = (string)($_POST['decision'] ?? '');
                if ($bookingId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
                    $message = 'Ungültige Buchungsdaten.';
                    $messageType = 'error';
                    break;
                }

                $bookingInfo = bookingNotifyData($conn, $bookingId);
                if ($decision === 'approve') {
                    if (approveBooking($conn, $bookingId)) {
                        notifyBookingDecision($bookingInfo, true);
                        $message = 'Buchung wurde genehmigt.';
                        $messageType = 'success';
                    } else {
                        $message = 'Buchung konnte nicht genehmigt werden.';
                        $messageType = 'error';
                    }
                } else {
                    if (rejectBooking($conn, $bookingId)) {
                        notifyBookingDecision($bookingInfo, false);
                        $message = 'Buchung wurde abgelehnt und entfernt.';
                        $messageType = 'success';
                    } else {
                        $message = 'Buchung konnte nicht abgelehnt werden.';
                        $messageType = 'error';
                    }
                }
                break;
            }

            case 'booking_bulk': {
                $bookingIds = array_map('intval', $_POST['booking_ids'] ?? []);
                $bookingIds = array_values(array_unique(array_filter($bookingIds, static fn(int $id): bool => $id > 0)));
                $decision = (string)($_POST['bulk_decision'] ?? '');

                if (empty($bookingIds) || !in_array($decision, ['approve', 'reject'], true)) {
                    $message = 'Bitte wählen Sie Buchungen und eine gültige Aktion.';
                    $messageType = 'error';
                    break;
                }

                $success = 0;
                foreach ($bookingIds as $id) {
                    $bookingInfo = bookingNotifyData($conn, $id);
                    $ok = $decision === 'approve' ? approveBooking($conn, $id) : rejectBooking($conn, $id);
                    if ($ok) {
                        notifyBookingDecision($bookingInfo, $decision === 'approve');
                        $success++;
                    }
                }

                if ($success > 0) {
                    $message = $success . ' Buchung(en) erfolgreich verarbeitet.';
                    $messageType = 'success';
                } else {
                    $message = 'Keine Buchung konnte verarbeitet werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'notify_test': {
                $cfg = notifyConfig();
                $results = [];
                if ($cfg['owner_email'] !== '' && mailIsConfigured($cfg)) {
                    $sent = mailSend($cfg['owner_email'], 'Testnachricht von Jon Dienstleistungen', "Diese Testnachricht bestätigt, dass der E-Mail-Versand funktioniert.", $cfg);
                    $results[] = 'E-Mail ' . ($sent ? 'gesendet' : 'fehlgeschlagen');
                } else {
                    $results[] = 'E-Mail nicht eingerichtet';
                }
                if (whatsappIsConfigured($cfg)) {
                    $results[] = 'WhatsApp ' . (whatsappSend('Testnachricht von Jon Dienstleistungen: WhatsApp-Benachrichtigungen funktionieren.', $cfg) ? 'gesendet' : 'fehlgeschlagen');
                } else {
                    $results[] = 'WhatsApp nicht eingerichtet';
                }
                $message = 'Test: ' . implode(', ', $results) . '.';
                $messageType = str_contains($message, 'gesendet') && !str_contains($message, 'fehlgeschlagen') ? 'success' : 'error';
                break;
            }

            case 'review_reply': {
                $reviewId = (int)($_POST['review_id'] ?? 0);
                $replyText = trim((string)($_POST['reply_text'] ?? ''));
                if ($reviewId <= 0 || $replyText === '') {
                    $message = 'Ungültige Antwortdaten.';
                    $messageType = 'error';
                    break;
                }

                $stmt = $conn->prepare('INSERT INTO review_replies (review_id, reply_text) VALUES (?, ?)');
                if ($stmt === false) {
                    $message = 'Fehler beim Vorbereiten der Antwort.';
                    $messageType = 'error';
                    break;
                }
                $stmt->bind_param('is', $reviewId, $replyText);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    $message = 'Antwort erfolgreich gespeichert.';
                    $messageType = 'success';
                } else {
                    $message = 'Antwort konnte nicht gespeichert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'review_delete': {
                $reviewId = (int)($_POST['review_id'] ?? 0);
                if ($reviewId <= 0) {
                    $message = 'Ungültige Bewertung.';
                    $messageType = 'error';
                    break;
                }

                if (deleteReviewById($conn, $reviewId)) {
                    $message = 'Bewertung wurde gelöscht.';
                    $messageType = 'success';
                } else {
                    $message = 'Bewertung konnte nicht gelöscht werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'review_bulk_delete': {
                $reviewIds = array_map('intval', $_POST['review_ids'] ?? []);
                $reviewIds = array_values(array_unique(array_filter($reviewIds, static fn(int $id): bool => $id > 0)));
                if (empty($reviewIds)) {
                    $message = 'Bitte wählen Sie Bewertungen aus.';
                    $messageType = 'error';
                    break;
                }

                $success = 0;
                foreach ($reviewIds as $id) {
                    if (deleteReviewById($conn, $id)) {
                        $success++;
                    }
                }

                if ($success > 0) {
                    $message = $success . ' Bewertung(en) gelöscht.';
                    $messageType = 'success';
                } else {
                    $message = 'Keine Bewertung konnte gelöscht werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'user_delete': {
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) {
                    $message = 'Ungültiger Benutzer.';
                    $messageType = 'error';
                    break;
                }

                if (deleteUserById($conn, $userId)) {
                    localAuthRemoveClient($userId);
                    $message = 'Benutzer wurde gelöscht.';
                    $messageType = 'success';
                } else {
                    $message = 'Benutzer konnte nicht gelöscht werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'user_reset_password': {
                $userId = (int)($_POST['user_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $newPassword = trim((string)($_POST['new_password'] ?? ''));

                if ($userId <= 0 || $username === '' || $email === '') {
                    $message = 'Ungültige Benutzerdaten für Passwort-Reset.';
                    $messageType = 'error';
                    break;
                }
                if (strlen($newPassword) < 6) {
                    $message = 'Neues Passwort muss mindestens 6 Zeichen lang sein.';
                    $messageType = 'error';
                    break;
                }

                $localOk = localAuthSetClientPassword($userId, $username, $email, $newPassword);
                $dbOk = false;

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                if ($newHash !== false) {
                    $hasEncryptedPassword = hasPasswordEncColumn($conn);
                    if ($hasEncryptedPassword) {
                        $encryptedPassword = encryptPasswordForAdmin($newPassword);
                        $stmt = $conn->prepare('UPDATE user SET password = ?, password_enc = ? WHERE id = ?');
                        if ($stmt !== false) {
                            $stmt->bind_param('ssi', $newHash, $encryptedPassword, $userId);
                            $dbOk = $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        $stmt = $conn->prepare('UPDATE user SET password = ? WHERE id = ?');
                        if ($stmt !== false) {
                            $stmt->bind_param('si', $newHash, $userId);
                            $dbOk = $stmt->execute();
                            $stmt->close();
                        }
                    }
                }

                if ($dbOk && $localOk) {
                    $message = 'Passwort für Kundenkonto wurde in DB und lokal zurückgesetzt.';
                    $messageType = 'success';
                } elseif ($dbOk) {
                    $message = 'Passwort für Kundenkonto wurde in DB zurückgesetzt.';
                    $messageType = 'success';
                } elseif ($localOk) {
                    $message = 'Passwort für Kundenkonto wurde lokal zurückgesetzt (Fallback ohne DB).';
                    $messageType = 'success';
                } else {
                    $message = 'Passwort konnte nicht zurückgesetzt werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'user_bulk_delete': {
                $userIds = array_map('intval', $_POST['user_ids'] ?? []);
                $userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));
                if (empty($userIds)) {
                    $message = 'Bitte wählen Sie Benutzer aus.';
                    $messageType = 'error';
                    break;
                }

                $success = 0;
                foreach ($userIds as $id) {
                    if (deleteUserById($conn, $id)) {
                        localAuthRemoveClient($id);
                        $success++;
                    }
                }

                if ($success > 0) {
                    $message = $success . ' Benutzer gelöscht.';
                    $messageType = 'success';
                } else {
                    $message = 'Keine Benutzer gelöscht.';
                    $messageType = 'error';
                }
                break;
            }

            case 'employee_create': {
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $role = (string)($_POST['role'] ?? '');
                $email = trim((string)($_POST['email'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));
                $status = (string)($_POST['status'] ?? 'active');
                $notes = trim((string)($_POST['notes'] ?? ''));
                $initialPassword = trim((string)($_POST['initial_password'] ?? ''));

                if ($fullName === '' || !isset($employeeRoles[$role]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Ungültige Mitarbeiterdaten.';
                    $messageType = 'error';
                    break;
                }
                if (strlen($initialPassword) < 6) {
                    $message = 'Startpasswort muss mindestens 6 Zeichen lang sein.';
                    $messageType = 'error';
                    break;
                }
                if (!isset($employeeStatuses[$status])) {
                    $status = 'active';
                }

                $roleLabel = (string)($employeeRoles[$role] ?? $role);
                $template = buildEmployeeProfileTemplate($role, $fullName, $email, $phone);
                $profileData = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($profileData === false) {
                    $profileData = '{}';
                }
                if ($notes === '') {
                    $notes = buildEmployeeProfileSummary($roleLabel);
                }
                $profileReady = 1;

                if ($hasEmployeesTable) {
                    $stmt = $conn->prepare(
                        'INSERT INTO employee_profiles (full_name, role, email, phone, status, notes, profile_data, profile_ready)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    if ($stmt === false) {
                        $message = 'Mitarbeiter konnte nicht vorbereitet werden.';
                        $messageType = 'error';
                        break;
                    }
                    $stmt->bind_param('sssssssi', $fullName, $role, $email, $phone, $status, $notes, $profileData, $profileReady);
                    $ok = $stmt->execute();
                    $newEmployeeId = (int)$stmt->insert_id;
                    $stmt->close();

                    if ($ok) {
                        $authOk = localAuthSetEmployeePassword(
                            $newEmployeeId,
                            $fullName,
                            $role,
                            $email,
                            $phone,
                            $status,
                            $profileData,
                            $initialPassword,
                            true
                        );
                        if ($authOk) {
                            $message = 'Mitarbeiter wurde hinzugefügt. Fertiges Profil und Startpasswort wurden erstellt.';
                            $messageType = 'success';
                        } else {
                            $message = 'Mitarbeiter wurde erstellt, aber Startpasswort konnte nicht gespeichert werden.';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Mitarbeiter konnte nicht hinzugefügt werden (E-Mail evtl. bereits vorhanden).';
                        $messageType = 'error';
                    }
                    break;
                }

                $rows = readEmployeeProfilesFromFile();
                $toLower = static function (string $value): string {
                    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
                };
                foreach ($rows as $row) {
                    if ($toLower((string)($row['email'] ?? '')) === $toLower($email)) {
                        $message = 'Mitarbeiter konnte nicht hinzugefügt werden (E-Mail evtl. bereits vorhanden).';
                        $messageType = 'error';
                        break 2;
                    }
                }

                $now = date('Y-m-d H:i:s');
                $newEmployeeId = nextEmployeeFileId($rows);
                $rows[] = [
                    'id' => $newEmployeeId,
                    'full_name' => $fullName,
                    'role' => $role,
                    'email' => $email,
                    'phone' => $phone,
                    'status' => $status,
                    'notes' => $notes,
                    'profile_data' => $profileData,
                    'profile_ready' => $profileReady,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (writeEmployeeProfilesToFile($rows)) {
                    $authOk = localAuthSetEmployeePassword(
                        $newEmployeeId,
                        $fullName,
                        $role,
                        $email,
                        $phone,
                        $status,
                        $profileData,
                        $initialPassword,
                        true
                    );
                    if ($authOk) {
                        $message = 'Mitarbeiter wurde lokal hinzugefügt. Fertiges Profil und Startpasswort wurden erstellt.';
                        $messageType = 'success';
                    } else {
                        $message = 'Mitarbeiter wurde lokal erstellt, aber Startpasswort konnte nicht gespeichert werden.';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Mitarbeiter konnte lokal nicht gespeichert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'employee_set_status': {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');
                if ($employeeId <= 0 || !isset($employeeStatuses[$status])) {
                    $message = 'Ungültige Mitarbeiterdaten.';
                    $messageType = 'error';
                    break;
                }

                if ($hasEmployeesTable) {
                    $stmt = $conn->prepare('UPDATE employee_profiles SET status = ? WHERE id = ?');
                    if ($stmt === false) {
                        $message = 'Status konnte nicht vorbereitet werden.';
                        $messageType = 'error';
                        break;
                    }
                    $stmt->bind_param('si', $status, $employeeId);
                    $ok = $stmt->execute();
                    $stmt->close();

                    if ($ok) {
                        $message = 'Mitarbeiterstatus aktualisiert.';
                        $messageType = 'success';
                        localAuthUpdateEmployeeStatus($employeeId, $status);
                    } else {
                        $message = 'Mitarbeiterstatus konnte nicht aktualisiert werden.';
                        $messageType = 'error';
                    }
                    break;
                }

                $rows = readEmployeeProfilesFromFile();
                $updated = false;
                foreach ($rows as &$row) {
                    if ((int)($row['id'] ?? 0) === $employeeId) {
                        $row['status'] = $status;
                        $row['updated_at'] = date('Y-m-d H:i:s');
                        $updated = true;
                        break;
                    }
                }
                unset($row);

                if ($updated && writeEmployeeProfilesToFile($rows)) {
                    $message = 'Mitarbeiterstatus aktualisiert.';
                    $messageType = 'success';
                    localAuthUpdateEmployeeStatus($employeeId, $status);
                } else {
                    $message = 'Mitarbeiterstatus konnte nicht aktualisiert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'employee_reset_password': {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $newPassword = trim((string)($_POST['new_password'] ?? ''));

                if ($employeeId <= 0) {
                    $message = 'Ungültige Mitarbeiterdaten für Passwort-Reset.';
                    $messageType = 'error';
                    break;
                }
                if (strlen($newPassword) < 6) {
                    $message = 'Neues Passwort muss mindestens 6 Zeichen lang sein.';
                    $messageType = 'error';
                    break;
                }

                $employee = fetchEmployeeById($conn, $hasEmployeesTable, $employeeId);
                if (!is_array($employee)) {
                    $message = 'Mitarbeiterprofil für Passwort-Reset nicht gefunden.';
                    $messageType = 'error';
                    break;
                }

                $ok = localAuthSetEmployeePassword(
                    $employeeId,
                    (string)($employee['full_name'] ?? ''),
                    (string)($employee['role'] ?? 'operations'),
                    (string)($employee['email'] ?? ''),
                    (string)($employee['phone'] ?? ''),
                    (string)($employee['status'] ?? 'active'),
                    (string)($employee['profile_data'] ?? '{}'),
                    $newPassword,
                    true
                );

                if ($ok) {
                    $message = 'Passwort für Mitarbeiterprofil wurde zurückgesetzt.';
                    $messageType = 'success';
                } else {
                    $message = 'Mitarbeiter-Passwort konnte nicht zurückgesetzt werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'employee_delete': {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                if ($employeeId <= 0) {
                    $message = 'Ungültiger Mitarbeiter.';
                    $messageType = 'error';
                    break;
                }

                if ($hasEmployeesTable) {
                    $stmt = $conn->prepare('DELETE FROM employee_profiles WHERE id = ?');
                    if ($stmt === false) {
                        $message = 'Mitarbeiter konnte nicht vorbereitet werden.';
                        $messageType = 'error';
                        break;
                    }
                    $stmt->bind_param('i', $employeeId);
                    $ok = $stmt->execute();
                    $stmt->close();

                    if ($ok) {
                        $message = 'Mitarbeiter gelöscht.';
                        $messageType = 'success';
                        localAuthRemoveEmployee($employeeId);
                    } else {
                        $message = 'Mitarbeiter konnte nicht gelöscht werden.';
                        $messageType = 'error';
                    }
                    break;
                }

                $rows = readEmployeeProfilesFromFile();
                $before = count($rows);
                $rows = array_values(array_filter($rows, static fn(array $row): bool => (int)($row['id'] ?? 0) !== $employeeId));
                $after = count($rows);

                if ($after < $before && writeEmployeeProfilesToFile($rows)) {
                    $message = 'Mitarbeiter gelöscht.';
                    $messageType = 'success';
                    localAuthRemoveEmployee($employeeId);
                } else {
                    $message = 'Mitarbeiter konnte nicht gelöscht werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'employee_bulk': {
                $employeeIds = array_map('intval', $_POST['employee_ids'] ?? []);
                $employeeIds = array_values(array_unique(array_filter($employeeIds, static fn(int $id): bool => $id > 0)));
                $bulkAction = (string)($_POST['bulk_action'] ?? '');

                if (empty($employeeIds) || !in_array($bulkAction, ['activate', 'deactivate', 'delete'], true)) {
                    $message = 'Bitte wählen Sie Mitarbeiter und eine gültige Aktion.';
                    $messageType = 'error';
                    break;
                }

                $success = 0;
                if ($hasEmployeesTable) {
                    foreach ($employeeIds as $id) {
                        if ($bulkAction === 'delete') {
                            $stmt = $conn->prepare('DELETE FROM employee_profiles WHERE id = ?');
                            if ($stmt === false) {
                                continue;
                            }
                            $stmt->bind_param('i', $id);
                            $ok = $stmt->execute();
                            $stmt->close();
                            if ($ok) {
                                $success++;
                                localAuthRemoveEmployee($id);
                            }
                            continue;
                        }

                        $status = $bulkAction === 'activate' ? 'active' : 'inactive';
                        $stmt = $conn->prepare('UPDATE employee_profiles SET status = ? WHERE id = ?');
                        if ($stmt === false) {
                            continue;
                        }
                        $stmt->bind_param('si', $status, $id);
                        $ok = $stmt->execute();
                        $stmt->close();
                        if ($ok) {
                            $success++;
                            localAuthUpdateEmployeeStatus($id, $status);
                        }
                    }
                } else {
                    $rows = readEmployeeProfilesFromFile();
                    $set = array_flip($employeeIds);
                    $changed = false;
                    if ($bulkAction === 'delete') {
                        $before = count($rows);
                        $rows = array_values(array_filter($rows, static fn(array $row): bool => !isset($set[(int)($row['id'] ?? 0)])));
                        $success = max(0, $before - count($rows));
                        $changed = $success > 0;
                        foreach ($employeeIds as $id) {
                            localAuthRemoveEmployee((int)$id);
                        }
                    } else {
                        $newStatus = $bulkAction === 'activate' ? 'active' : 'inactive';
                        foreach ($rows as &$row) {
                            $id = (int)($row['id'] ?? 0);
                            if (isset($set[$id])) {
                                $row['status'] = $newStatus;
                                $row['updated_at'] = date('Y-m-d H:i:s');
                                $success++;
                                $changed = true;
                                localAuthUpdateEmployeeStatus($id, $newStatus);
                            }
                        }
                        unset($row);
                    }

                    if ($changed && !writeEmployeeProfilesToFile($rows)) {
                        $success = 0;
                    }
                }

                if ($success > 0) {
                    $message = $success . ' Mitarbeiter aktualisiert.';
                    $messageType = 'success';
                } else {
                    $message = 'Keine Mitarbeiter aktualisiert.';
                    $messageType = 'error';
                }
                break;
            }

            case 'service_type_add': {
                $serviceTypeName = trim((string)($_POST['service_type_name'] ?? ''));
                if ($serviceTypeName === '') {
                    $message = 'Bitte geben Sie einen Service-Typ ein.';
                    $messageType = 'error';
                    break;
                }

                if (workflowAddServiceType($serviceTypeName)) {
                    $message = 'Service-Typ wurde gespeichert.';
                    $messageType = 'success';
                } else {
                    $message = 'Service-Typ konnte nicht gespeichert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'service_type_delete': {
                $serviceTypeName = trim((string)($_POST['service_type_name'] ?? ''));
                if ($serviceTypeName === '') {
                    $message = 'Ungültiger Service-Typ.';
                    $messageType = 'error';
                    break;
                }

                if (workflowDeleteServiceType($serviceTypeName)) {
                    $message = 'Service-Typ wurde entfernt.';
                    $messageType = 'success';
                } else {
                    $message = 'Service-Typ konnte nicht entfernt werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'task_create': {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $serviceType = trim((string)($_POST['service_type'] ?? ''));
                $title = trim((string)($_POST['title'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $dueDate = validDate((string)($_POST['due_date'] ?? ''));
                $priority = (string)($_POST['priority'] ?? 'normal');
                $bookingId = max(0, (int)($_POST['booking_id'] ?? 0));
                $autoAssign = ((int)($_POST['auto_assign'] ?? 0)) === 1;
                $notifyWhatsApp = isset($_POST['notify_whatsapp']) && (int)$_POST['notify_whatsapp'] === 1;

                if (!isset($taskPriorityLabels[$priority])) {
                    $priority = 'normal';
                }

                if ($bookingId <= 0) {
                    $message = 'Aufgabe kann nur aus einer realen, genehmigten Buchung erstellt werden.';
                    $messageType = 'error';
                    break;
                }

                $allTasks = workflowReadEmployeeTasks();
                if ($bookingId > 0 && hasTaskForBooking($allTasks, $bookingId)) {
                    $message = 'Diese Buchung wurde bereits einem Mitarbeiter zugewiesen.';
                    $messageType = 'error';
                    break;
                }

                [$employeesForAssign] = fetchEmployeesData($conn, $hasEmployeesTable, 'all', 'all', '', 0, 0);
                $employeeLoad = buildEmployeeLoadMap($employeesForAssign, $allTasks);
                $employeeMap = buildEmployeeMapById($employeesForAssign);

                $bookingRows = fetchBookingsByIds($conn, $hasBookingServiceType, [$bookingId]);
                if (!isset($bookingRows[$bookingId])) {
                    $message = 'Buchung für Aufgabenverteilung nicht gefunden.';
                    $messageType = 'error';
                    break;
                }

                $bookingRow = $bookingRows[$bookingId];
                if ((string)($bookingRow['status'] ?? '') !== 'approved') {
                    $message = 'Aufgabe darf nur aus genehmigten Buchungen erstellt werden.';
                    $messageType = 'error';
                    break;
                }

                $taskData = buildTaskDataFromBooking($bookingRow);
                if ($serviceType === '') {
                    $serviceType = (string)$taskData['service_type'];
                }
                if ($title === '') {
                    $title = (string)$taskData['title'];
                }
                if ($description === '') {
                    $description = (string)$taskData['description'];
                }
                if ($dueDate === '') {
                    $dueDate = validDate((string)$taskData['due_date']);
                }

                if ($serviceType === '') {
                    $serviceType = 'Allgemein';
                }

                if ($autoAssign && $employeeId <= 0) {
                    $employeeId = recommendEmployeeIdForServiceType($serviceType, $employeesForAssign, $employeeLoad);
                }

                if ($employeeId <= 0 || $title === '') {
                    $message = 'Bitte Mitarbeiter und Aufgabentitel angeben.';
                    $messageType = 'error';
                    break;
                }

                $employee = fetchEmployeeById($conn, $hasEmployeesTable, $employeeId);
                if (!is_array($employee)) {
                    $message = 'Mitarbeiter für Aufgabenverteilung nicht gefunden.';
                    $messageType = 'error';
                    break;
                }

                workflowAddServiceType($serviceType);

                $warnings = buildAssignmentWarnings($employeeId, $serviceType, $dueDate, $employeeMap, $employeeLoad);

                $task = workflowCreateEmployeeTask([
                    'employee_id' => $employeeId,
                    'employee_name' => (string)($employee['full_name'] ?? ''),
                    'employee_role' => (string)($employee['role'] ?? ''),
                    'service_type' => $serviceType,
                    'title' => $title,
                    'description' => $description,
                    'due_date' => $dueDate,
                    'priority' => $priority,
                    'booking_id' => $bookingId > 0 ? $bookingId : null,
                    'created_by' => (string)($_SESSION['admin_username'] ?? ($_SESSION['username'] ?? 'admin')),
                ]);

                if (is_array($task)) {
                    $message = 'Aufgabe wurde erfolgreich zugewiesen.';
                    if (!empty($warnings)) {
                        $message .= ' Warnung: ' . implode(' ', $warnings);
                    }

                    if ($notifyWhatsApp) {
                        $wa = queueWhatsAppTaskNotification($employee, $task, 'assigned');
                        if ($wa['queued']) {
                            $message .= ' WhatsApp-Benachrichtigung wurde vorbereitet.';
                        } elseif (($wa['reason'] ?? '') === 'missing_phone') {
                            $message .= ' Hinweis: Keine WhatsApp-Benachrichtigung, da keine Telefonnummer vorliegt.';
                        } else {
                            $message .= ' Hinweis: WhatsApp-Benachrichtigung konnte nicht protokolliert werden.';
                        }
                    }

                    $messageType = 'success';
                } else {
                    $message = 'Aufgabe konnte nicht gespeichert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'task_bulk_assign': {
                $employeeId = (int)($_POST['employee_id'] ?? 0);
                $priority = (string)($_POST['priority'] ?? 'normal');
                $bookingIds = array_map('intval', $_POST['booking_ids'] ?? []);
                $bookingIds = array_values(array_unique(array_filter($bookingIds, static fn(int $id): bool => $id > 0)));
                $notifyWhatsApp = isset($_POST['notify_whatsapp']) && (int)$_POST['notify_whatsapp'] === 1;

                if (!isset($taskPriorityLabels[$priority])) {
                    $priority = 'normal';
                }

                if ($employeeId <= 0 || empty($bookingIds)) {
                    $message = 'Bitte Mitarbeiter und mindestens eine Buchung für Massenverteilung wählen.';
                    $messageType = 'error';
                    break;
                }

                $employee = fetchEmployeeById($conn, $hasEmployeesTable, $employeeId);
                if (!is_array($employee)) {
                    $message = 'Mitarbeiter für Massenverteilung nicht gefunden.';
                    $messageType = 'error';
                    break;
                }

                $allTasks = workflowReadEmployeeTasks();
                [$employeesForAssign] = fetchEmployeesData($conn, $hasEmployeesTable, 'all', 'all', '', 0, 0);
                $employeeLoad = buildEmployeeLoadMap($employeesForAssign, $allTasks);
                $employeeMap = buildEmployeeMapById($employeesForAssign);
                $warnings = buildAssignmentWarnings($employeeId, 'Allgemein', '', $employeeMap, $employeeLoad);

                $bookingRows = fetchBookingsByIds($conn, $hasBookingServiceType, $bookingIds);
                $created = 0;
                $skippedAssigned = 0;
                $skippedMissing = 0;
                $waQueued = 0;

                foreach ($bookingIds as $bookingId) {
                    if (!isset($bookingRows[$bookingId])) {
                        $skippedMissing++;
                        continue;
                    }
                    if (hasTaskForBooking($allTasks, $bookingId)) {
                        $skippedAssigned++;
                        continue;
                    }

                    $taskData = buildTaskDataFromBooking($bookingRows[$bookingId]);
                    workflowAddServiceType((string)$taskData['service_type']);

                    $task = workflowCreateEmployeeTask([
                        'employee_id' => $employeeId,
                        'employee_name' => (string)($employee['full_name'] ?? ''),
                        'employee_role' => (string)($employee['role'] ?? ''),
                        'service_type' => (string)$taskData['service_type'],
                        'title' => (string)$taskData['title'],
                        'description' => (string)$taskData['description'],
                        'due_date' => validDate((string)$taskData['due_date']),
                        'priority' => $priority,
                        'booking_id' => $bookingId,
                        'created_by' => (string)($_SESSION['admin_username'] ?? ($_SESSION['username'] ?? 'admin')),
                    ]);

                    if (!is_array($task)) {
                        continue;
                    }

                    $created++;
                    $allTasks[] = $task;

                    if ($notifyWhatsApp) {
                        $wa = queueWhatsAppTaskNotification($employee, $task, 'assigned_bulk');
                        if ($wa['queued']) {
                            $waQueued++;
                        }
                    }
                }

                if ($created > 0) {
                    $message = $created . ' Aufgaben wurden verteilt.';
                    if ($skippedAssigned > 0) {
                        $message .= ' ' . $skippedAssigned . ' bereits zugewiesen.';
                    }
                    if ($skippedMissing > 0) {
                        $message .= ' ' . $skippedMissing . ' Buchungen nicht gefunden.';
                    }
                    if (!empty($warnings)) {
                        $message .= ' Warnung: ' . implode(' ', $warnings);
                    }
                    if ($notifyWhatsApp) {
                        $message .= ' WhatsApp-Queue: ' . $waQueued . '.';
                    }
                    $messageType = 'success';
                } else {
                    $message = 'Keine Aufgaben wurden verteilt.';
                    if ($skippedAssigned > 0 || $skippedMissing > 0) {
                        $message .= ' Bereits zugewiesen: ' . $skippedAssigned . ', nicht gefunden: ' . $skippedMissing . '.';
                    }
                    $messageType = 'error';
                }
                break;
            }

            case 'task_set_status': {
                $taskId = (int)($_POST['task_id'] ?? 0);
                $status = (string)($_POST['status'] ?? '');

                if ($taskId <= 0 || !isset($taskStatusLabels[$status])) {
                    $message = 'Ungültige Task-Daten.';
                    $messageType = 'error';
                    break;
                }

                $task = workflowFindEmployeeTaskById($taskId);
                if (!is_array($task)) {
                    $message = 'Task wurde nicht gefunden.';
                    $messageType = 'error';
                    break;
                }
                if ((string)($task['status'] ?? '') === 'done') {
                    $message = 'Erledigte Aufgaben können nicht mehr vom Admin geändert werden.';
                    $messageType = 'error';
                    break;
                }

                if (workflowUpdateEmployeeTaskStatus($taskId, $status)) {
                    $message = 'Task-Status wurde aktualisiert.';
                    $messageType = 'success';
                } else {
                    $message = 'Task-Status konnte nicht aktualisiert werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'task_delete': {
                $taskId = (int)($_POST['task_id'] ?? 0);
                if ($taskId <= 0) {
                    $message = 'Ungültiger Task.';
                    $messageType = 'error';
                    break;
                }

                $task = workflowFindEmployeeTaskById($taskId);
                if (!is_array($task)) {
                    $message = 'Task wurde nicht gefunden.';
                    $messageType = 'error';
                    break;
                }
                if ((string)($task['status'] ?? '') === 'done') {
                    $message = 'Erledigte Aufgaben können nicht mehr gelöscht werden.';
                    $messageType = 'error';
                    break;
                }

                if (workflowDeleteEmployeeTask($taskId)) {
                    $message = 'Task wurde gelöscht.';
                    $messageType = 'success';
                } else {
                    $message = 'Task konnte nicht gelöscht werden.';
                    $messageType = 'error';
                }
                break;
            }

            case 'task_reassign': {
                $taskId = (int)($_POST['task_id'] ?? 0);
                $newEmployeeId = (int)($_POST['employee_id'] ?? 0);
                $notifyWhatsApp = isset($_POST['notify_whatsapp']) && (int)$_POST['notify_whatsapp'] === 1;
                if ($taskId <= 0 || $newEmployeeId <= 0) {
                    $message = 'Ungültige Daten für Neuzuweisung.';
                    $messageType = 'error';
                    break;
                }

                $task = workflowFindEmployeeTaskById($taskId);
                if (!is_array($task)) {
                    $message = 'Task wurde nicht gefunden.';
                    $messageType = 'error';
                    break;
                }
                if ((string)($task['status'] ?? '') === 'done') {
                    $message = 'Erledigte Aufgaben können nicht neu zugewiesen werden.';
                    $messageType = 'error';
                    break;
                }

                $currentEmployeeId = (int)($task['employee_id'] ?? 0);
                if ($currentEmployeeId === $newEmployeeId) {
                    $message = 'Diese Aufgabe ist bereits diesem Mitarbeiter zugewiesen.';
                    $messageType = 'error';
                    break;
                }

                $employee = fetchEmployeeById($conn, $hasEmployeesTable, $newEmployeeId);
                if (!is_array($employee)) {
                    $message = 'Neuer Mitarbeiter nicht gefunden.';
                    $messageType = 'error';
                    break;
                }

                [$employeesForAssign] = fetchEmployeesData($conn, $hasEmployeesTable, 'all', 'all', '', 0, 0);
                $allTasks = workflowReadEmployeeTasks();
                $employeeLoad = buildEmployeeLoadMap($employeesForAssign, $allTasks);
                $employeeMap = buildEmployeeMapById($employeesForAssign);
                $warnings = buildAssignmentWarnings(
                    $newEmployeeId,
                    (string)($task['service_type'] ?? ''),
                    validDate((string)($task['due_date'] ?? '')),
                    $employeeMap,
                    $employeeLoad
                );

                if (workflowReassignEmployeeTask(
                    $taskId,
                    $newEmployeeId,
                    (string)($employee['full_name'] ?? ''),
                    (string)($employee['role'] ?? '')
                )) {
                    $message = 'Aufgabe wurde erfolgreich einem anderen Mitarbeiter zugewiesen.';
                    if (!empty($warnings)) {
                        $message .= ' Warnung: ' . implode(' ', $warnings);
                    }
                    if ($notifyWhatsApp) {
                        $updatedTask = workflowFindEmployeeTaskById($taskId);
                        if (is_array($updatedTask)) {
                            $wa = queueWhatsAppTaskNotification($employee, $updatedTask, 'reassigned');
                            if ($wa['queued']) {
                                $message .= ' WhatsApp-Benachrichtigung wurde vorbereitet.';
                            } elseif (($wa['reason'] ?? '') === 'missing_phone') {
                                $message .= ' Hinweis: Keine WhatsApp-Benachrichtigung, da keine Telefonnummer vorliegt.';
                            } else {
                                $message .= ' Hinweis: WhatsApp-Benachrichtigung konnte nicht protokolliert werden.';
                            }
                        }
                    }
                    $messageType = 'success';
                } else {
                    $message = 'Aufgabe konnte nicht neu zugewiesen werden.';
                    $messageType = 'error';
                }
                break;
            }

            default:
                $message = 'Unbekannte Aktion.';
                $messageType = 'error';
                break;
        }

        $_SESSION['flash_message'] = $message;
        $_SESSION['flash_message_type'] = $messageType;
        header('Location: ' . $redirectUrl);
        exit;
    }

    $countResult = $conn->query('SELECT COUNT(*) AS cnt FROM booking WHERE status != "free"');
    if ($countResult) {
        $dashboardStats['bookings_total'] = (int)($countResult->fetch_assoc()['cnt'] ?? 0);
        $countResult->free();
    }

    $pendingResult = $conn->query('SELECT COUNT(*) AS cnt FROM booking WHERE status = "pending"');
    if ($pendingResult) {
        $dashboardStats['bookings_pending'] = (int)($pendingResult->fetch_assoc()['cnt'] ?? 0);
        $pendingResult->free();
    }

    $approvedResult = $conn->query('SELECT COUNT(*) AS cnt FROM booking WHERE status = "approved"');
    if ($approvedResult) {
        $dashboardStats['bookings_approved'] = (int)($approvedResult->fetch_assoc()['cnt'] ?? 0);
        $approvedResult->free();
    }

    $rejectedResult = $conn->query('SELECT COUNT(*) AS cnt FROM booking WHERE status = "rejected"');
    if ($rejectedResult) {
        $dashboardStats['bookings_rejected'] = (int)($rejectedResult->fetch_assoc()['cnt'] ?? 0);
        $rejectedResult->free();
    }

    $todayResult = $conn->query('SELECT COUNT(*) AS cnt FROM booking WHERE date = CURDATE() AND status != "free"');
    if ($todayResult) {
        $dashboardStats['bookings_today'] = (int)($todayResult->fetch_assoc()['cnt'] ?? 0);
        $todayResult->free();
    }

    $reviewsResult = $conn->query('SELECT COUNT(*) AS cnt, ROUND(AVG(rating), 2) AS avg_rating FROM reviews');
    if ($reviewsResult) {
        $reviewRow = $reviewsResult->fetch_assoc();
        $dashboardStats['reviews_total'] = (int)($reviewRow['cnt'] ?? 0);
        $dashboardStats['reviews_avg'] = (float)($reviewRow['avg_rating'] ?? 0);
        $reviewsResult->free();
    }

    $openReviewsResult = $conn->query(
        'SELECT COUNT(*) AS cnt FROM reviews r WHERE NOT EXISTS (SELECT 1 FROM review_replies rr WHERE rr.review_id = r.id)'
    );
    if ($openReviewsResult) {
        $dashboardStats['reviews_open'] = (int)($openReviewsResult->fetch_assoc()['cnt'] ?? 0);
        $openReviewsResult->free();
    }

    $usersResult = $conn->query('SELECT COUNT(*) AS cnt FROM user');
    if ($usersResult) {
        $dashboardStats['users_total'] = (int)($usersResult->fetch_assoc()['cnt'] ?? 0);
        $usersResult->free();
    }

    if ($hasEmployeesTable) {
        $employeesResult = $conn->query('SELECT COUNT(*) AS cnt FROM employee_profiles');
        if ($employeesResult) {
            $dashboardStats['employees_total'] = (int)($employeesResult->fetch_assoc()['cnt'] ?? 0);
            $employeesResult->free();
        }

        $employeesActiveResult = $conn->query('SELECT COUNT(*) AS cnt FROM employee_profiles WHERE status = "active"');
        if ($employeesActiveResult) {
            $dashboardStats['employees_active'] = (int)($employeesActiveResult->fetch_assoc()['cnt'] ?? 0);
            $employeesActiveResult->free();
        }
    } else {
        $employeeFileRows = readEmployeeProfilesFromFile();
        $dashboardStats['employees_total'] = count($employeeFileRows);
        $dashboardStats['employees_active'] = count(array_filter(
            $employeeFileRows,
            static fn(array $row): bool => (string)($row['status'] ?? '') === 'active'
        ));
    }

    $recentBookingsResult = $conn->query(
        'SELECT id, date, booked_by, status, created_at FROM booking WHERE status != "free" ORDER BY created_at DESC LIMIT 8'
    );
    if ($recentBookingsResult) {
        while ($row = $recentBookingsResult->fetch_assoc()) {
            $dashboardRecentBookings[] = $row;
        }
        $recentBookingsResult->free();
    }

    $recentReviewsResult = $conn->query(
        'SELECT r.id, r.user_email, r.rating, r.created_at, '
        . 'CASE WHEN EXISTS(SELECT 1 FROM review_replies rr WHERE rr.review_id = r.id) THEN 1 ELSE 0 END AS has_reply '
        . 'FROM reviews r ORDER BY r.created_at DESC LIMIT 8'
    );
    if ($recentReviewsResult) {
        while ($row = $recentReviewsResult->fetch_assoc()) {
            $dashboardRecentReviews[] = $row;
        }
        $recentReviewsResult->free();
    }

    $reportFromTs = $reportFromDate . ' 00:00:00';
    $reportToTs = $reportToDate . ' 23:59:59';

    $reportBookingStmt = $conn->prepare(
        'SELECT status, COUNT(*) AS cnt FROM booking WHERE created_at BETWEEN ? AND ? AND status != "free" GROUP BY status'
    );
    if ($reportBookingStmt !== false) {
        $reportBookingStmt->bind_param('ss', $reportFromTs, $reportToTs);
        $reportBookingStmt->execute();
        $result = $reportBookingStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $status = (string)$row['status'];
            $count = (int)($row['cnt'] ?? 0);
            $reportSummary['bookings_total'] += $count;
            if ($status === 'pending') {
                $reportSummary['bookings_pending'] = $count;
            } elseif ($status === 'approved') {
                $reportSummary['bookings_approved'] = $count;
            } elseif ($status === 'rejected') {
                $reportSummary['bookings_rejected'] = $count;
            }
        }
        $reportBookingStmt->close();
    }

    $reportReviewsStmt = $conn->prepare('SELECT COUNT(*) AS cnt, ROUND(AVG(rating), 2) AS avg_rating FROM reviews WHERE created_at BETWEEN ? AND ?');
    if ($reportReviewsStmt !== false) {
        $reportReviewsStmt->bind_param('ss', $reportFromTs, $reportToTs);
        $reportReviewsStmt->execute();
        $row = $reportReviewsStmt->get_result()->fetch_assoc();
        $reportSummary['reviews_total'] = (int)($row['cnt'] ?? 0);
        $reportSummary['reviews_avg'] = (float)($row['avg_rating'] ?? 0);
        $reportReviewsStmt->close();
    }

    $reportUsersStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM user WHERE created_at BETWEEN ? AND ?');
    if ($reportUsersStmt !== false) {
        $reportUsersStmt->bind_param('ss', $reportFromTs, $reportToTs);
        $reportUsersStmt->execute();
        $reportSummary['users_new'] = (int)($reportUsersStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        $reportUsersStmt->close();
    }

    if ($hasEmployeesTable) {
        $reportEmployeesStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM employee_profiles WHERE created_at BETWEEN ? AND ?');
        if ($reportEmployeesStmt !== false) {
            $reportEmployeesStmt->bind_param('ss', $reportFromTs, $reportToTs);
            $reportEmployeesStmt->execute();
            $reportSummary['employees_new'] = (int)($reportEmployeesStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
            $reportEmployeesStmt->close();
        }
    } else {
        $employeeFileRows = readEmployeeProfilesFromFile();
        $reportSummary['employees_new'] = count(array_filter(
            $employeeFileRows,
            static function (array $row) use ($reportFromTs, $reportToTs): bool {
                $createdAt = (string)($row['created_at'] ?? '');
                return $createdAt >= $reportFromTs && $createdAt <= $reportToTs;
            }
        ));
    }

    $topCustomersStmt = $conn->prepare(
        'SELECT booked_by, COUNT(*) AS cnt '
        . 'FROM booking '
        . 'WHERE created_at BETWEEN ? AND ? AND status != "free" AND booked_by IS NOT NULL AND booked_by != "" '
        . 'GROUP BY booked_by ORDER BY cnt DESC LIMIT 10'
    );
    if ($topCustomersStmt !== false) {
        $topCustomersStmt->bind_param('ss', $reportFromTs, $reportToTs);
        $topCustomersStmt->execute();
        $result = $topCustomersStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $reportTopCustomers[] = $row;
        }
        $topCustomersStmt->close();
    }

    $bookingTrendStmt = $conn->prepare(
        'SELECT DATE_FORMAT(created_at, "%Y-%m") AS ym, COUNT(*) AS cnt '
        . 'FROM booking '
        . 'WHERE created_at BETWEEN ? AND ? AND status != "free" '
        . 'GROUP BY DATE_FORMAT(created_at, "%Y-%m") '
        . 'ORDER BY ym ASC'
    );
    if ($bookingTrendStmt !== false) {
        $bookingTrendStmt->bind_param('ss', $reportFromTs, $reportToTs);
        $bookingTrendStmt->execute();
        $result = $bookingTrendStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $reportBookingTrend[] = $row;
        }
        $bookingTrendStmt->close();
    }

    if ($activeTab === 'bookings' || $exportType === 'bookings') {
        [$bookingsRows, $bookingsTotal] = fetchBookingsData($conn, $hasBookingServiceType, $bookingStatusFilter, $bookingSearch, $exportType === 'bookings' ? 0 : $perPage, $offset);
    }

    if ($activeTab === 'reviews' || $exportType === 'reviews') {
        [$reviewsRows, $reviewsTotal] = fetchReviewsData($conn, $reviewRatingFilter, $reviewReplyFilter, $reviewSearch, $exportType === 'reviews' ? 0 : $perPage, $offset);
    }

    if ($activeTab === 'users' || $exportType === 'users') {
        [$usersRows, $usersTotal] = fetchUsersData($conn, $usersFromDate, $usersToDate, $userSearch, $exportType === 'users' ? 0 : $perPage, $offset);
    }

    if ($activeTab === 'employees' || $exportType === 'employees') {
        [$employeesRows, $employeesTotal] = fetchEmployeesData($conn, $hasEmployeesTable, $employeeRoleFilter, $employeeStatusFilter, $employeeSearch, $exportType === 'employees' ? 0 : $perPage, $offset);
    }
    if ($activeTab === 'tasks') {
        [$employeesAllRows] = fetchEmployeesData($conn, $hasEmployeesTable, 'all', 'all', '', 0, 0);
        $serviceTypes = workflowReadServiceTypes();
        $allTasks = workflowReadEmployeeTasks();
        $tasksRows = workflowFilterEmployeeTasks($allTasks, $taskEmployeeFilter, $taskStatusFilter, $taskSearch);
        $tasksByEmployeeStats = workflowEmployeeTaskStats($allTasks);
        $employeeLoadMap = buildEmployeeLoadMap($employeesAllRows, $allTasks);
        $unassignedBookings = fetchUnassignedBookings($conn, $hasBookingServiceType, $allTasks, [
            'status' => 'approved',
            'service_type' => 'all',
            'search' => '',
            'date_from' => '',
            'date_to' => '',
            'sort' => 'oldest',
        ]);
    }

    if ($exportType === 'bookings') {
        $csvRows = [];
        foreach ($bookingsRows as $row) {
            $csvRows[] = [
                (string)$row['id'],
                (string)$row['date'],
                (string)($row['booked_by'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($row['service_type'] ?? ''),
                bookingStatusLabel((string)$row['status']),
                (string)($row['created_at'] ?? ''),
            ];
        }

        outputCsv(
            'bookings-' . date('Ymd-His') . '.csv',
            ['ID', 'Datum', 'Kunde', 'Telefon', 'Service', 'Status', 'Erstellt am'],
            $csvRows
        );
    }

    if ($exportType === 'reviews') {
        $csvRows = [];
        foreach ($reviewsRows as $row) {
            $csvRows[] = [
                (string)$row['id'],
                (string)($row['user_email'] ?? ''),
                (string)($row['booking_date'] ?? ''),
                (string)($row['rating'] ?? ''),
                (string)($row['review_text'] ?? ''),
                (string)($row['reply_text'] ?? ''),
                (string)($row['created_at'] ?? ''),
            ];
        }

        outputCsv(
            'reviews-' . date('Ymd-His') . '.csv',
            ['ID', 'Kunde', 'Buchungsdatum', 'Rating', 'Bewertung', 'Antwort', 'Erstellt am'],
            $csvRows
        );
    }

    if ($exportType === 'users') {
        $csvRows = [];
        foreach ($usersRows as $row) {
            $csvRows[] = [
                (string)$row['id'],
                (string)($row['username'] ?? ''),
                (string)($row['email'] ?? ''),
                (string)($row['phones'] ?? ''),
                (string)($row['bookings_count'] ?? 0),
                (string)($row['reviews_count'] ?? 0),
                (string)($row['created_at'] ?? ''),
            ];
        }

        outputCsv(
            'users-' . date('Ymd-His') . '.csv',
            ['ID', 'Benutzername', 'E-Mail', 'Telefon(e)', 'Buchungen', 'Bewertungen', 'Registriert am'],
            $csvRows
        );
    }

    if ($exportType === 'employees') {
        $csvRows = [];
        foreach ($employeesRows as $row) {
            $roleKey = (string)($row['role'] ?? '');
            $statusKey = (string)($row['status'] ?? '');
            $profileReady = ((int)($row['profile_ready'] ?? 0)) === 1 ? 'ready' : 'draft';
            $csvRows[] = [
                (string)$row['id'],
                (string)($row['full_name'] ?? ''),
                (string)($employeeRoles[$roleKey] ?? $roleKey),
                (string)($row['email'] ?? ''),
                (string)($row['phone'] ?? ''),
                (string)($employeeStatuses[$statusKey] ?? $statusKey),
                $profileReady,
                (string)($row['created_at'] ?? ''),
            ];
        }

        outputCsv(
            'employees-' . date('Ymd-His') . '.csv',
            ['ID', 'Name', 'Rolle', 'E-Mail', 'Telefon', 'Status', 'Profilstatus', 'Erstellt am'],
            $csvRows
        );
    }

    if ($exportType === 'reports') {
        $csvRows = [
            ['Zeitraum von', $reportFromDate],
            ['Zeitraum bis', $reportToDate],
            ['Buchungen gesamt', (string)$reportSummary['bookings_total']],
            ['Buchungen ausstehend', (string)$reportSummary['bookings_pending']],
            ['Buchungen genehmigt', (string)$reportSummary['bookings_approved']],
            ['Buchungen abgelehnt', (string)$reportSummary['bookings_rejected']],
            ['Bewertungen gesamt', (string)$reportSummary['reviews_total']],
            ['Durchschnittliche Bewertung', (string)$reportSummary['reviews_avg']],
            ['Neue Benutzer', (string)$reportSummary['users_new']],
            ['Neue Mitarbeiter', (string)$reportSummary['employees_new']],
            ['', ''],
            ['Top Kunden', 'Anzahl Buchungen'],
        ];

        foreach ($reportTopCustomers as $row) {
            $csvRows[] = [(string)($row['booked_by'] ?? ''), (string)($row['cnt'] ?? 0)];
        }

        $csvRows[] = ['', ''];
        $csvRows[] = ['Monat', 'Buchungen'];
        foreach ($reportBookingTrend as $row) {
            $csvRows[] = [(string)($row['ym'] ?? ''), (string)($row['cnt'] ?? 0)];
        }

        outputCsv('reports-' . date('Ymd-His') . '.csv', ['Kennzahl', 'Wert'], $csvRows);
    }
} catch (Throwable $e) {
    http_response_code(500);
    $message = 'Fehler: ' . $e->getMessage();
    $messageType = 'error';
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

$returnQuery = http_build_query($_GET);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($tabLabels[$activeTab] ?? 'Verwaltung') ?> – Verwaltung Jon Dienstleistungen</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013b">
    <link rel="stylesheet" href="styles/admin.css?v=20261015a">
</head>
<body class="admin-body">
<?php
$navBadges = [
    'bookings' => (int)$dashboardStats['bookings_pending'],
    'reviews' => (int)$dashboardStats['reviews_open'],
];
?>
<div class="admin-layout">
    <aside class="admin-side" id="adminSide">
        <a class="brand brand--light" href="admin.php">
            <?= siteBrandMark() ?>
            <span class="brand__text"><strong>JON</strong><span>Verwaltung</span></span>
        </a>

        <nav class="admin-nav" aria-label="Admin Navigation">
            <?php foreach ($tabLabels as $tabKey => $tabLabel): ?>
                <a href="<?= h(buildAdminUrl(['tab' => $tabKey, 'page' => 1], ['export'])) ?>" class="tab-link <?= $activeTab === $tabKey ? 'active' : '' ?>"<?= $activeTab === $tabKey ? ' aria-current="page"' : '' ?>>
                    <i class="fas <?= h($tabIcons[$tabKey] ?? 'fa-circle') ?>" aria-hidden="true"></i>
                    <span><?= h($tabLabel) ?></span>
                    <?php if (($navBadges[$tabKey] ?? 0) > 0): ?>
                        <b class="nav-count" title="Offen"><?= $navBadges[$tabKey] ?></b>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="admin-side__foot">
            <div class="admin-user">
                <span class="admin-user__avatar" aria-hidden="true"><i class="fas fa-user-shield"></i></span>
                <span>
                    <b><?= h((string)($adminAuth['admin_username'] ?? 'Admin')) ?></b>
                    <small>Administrator</small>
                </span>
            </div>
            <a href="index.php" class="side-link"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Zur Website</a>
            <a href="logout.php?scope=admin" class="side-link logout-link"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> Ausloggen</a>
        </div>
    </aside>
    <div class="admin-side__veil" data-side-close></div>

    <div class="admin-main">
    <header class="admin-header">
        <button type="button" class="side-toggle" data-side-toggle aria-label="Menü öffnen" aria-controls="adminSide"><i class="fas fa-bars" aria-hidden="true"></i></button>
        <div class="admin-header__title">
            <h1><?= h($tabLabels[$activeTab] ?? 'Verwaltung') ?></h1>
            <p><?= h($tabHints[$activeTab] ?? '') ?></p>
        </div>
        <div class="header-actions">
            <span class="header-date"><i class="far fa-calendar" aria-hidden="true"></i> <?= date('d.m.Y') ?></span>
            <span class="header-session" title="Die Anmeldung endet nach 30 Minuten ohne Aktivität."><i class="fas fa-shield-halved" aria-hidden="true"></i> Sitzung geschützt</span>
        </div>
    </header>

    <div class="admin-shell">
    <?php if ($message !== ''): ?>
        <div class="flash-message <?= $messageType === 'success' ? 'success' : 'error' ?>" role="status" aria-live="polite">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'dashboard'): ?>
        <?php
        $kpis = [
            ['Buchungen gesamt', (string)$dashboardStats['bookings_total'], 'fa-calendar-days', 'blue', 'admin.php?tab=bookings'],
            ['Ausstehend', (string)$dashboardStats['bookings_pending'], 'fa-hourglass-half', 'sun', 'admin.php?tab=bookings&booking_status=pending'],
            ['Genehmigt', (string)$dashboardStats['bookings_approved'], 'fa-circle-check', 'green', 'admin.php?tab=bookings&booking_status=approved'],
            ['Abgelehnt', (string)$dashboardStats['bookings_rejected'], 'fa-circle-xmark', 'red', 'admin.php?tab=bookings&booking_status=rejected'],
            ['Bewertungen', (string)$dashboardStats['reviews_total'], 'fa-star', 'sun', 'admin.php?tab=reviews'],
            ['Ø Bewertung', number_format((float)$dashboardStats['reviews_avg'], 2, ',', ''), 'fa-chart-simple', 'blue', 'admin.php?tab=reviews'],
            ['Benutzer', (string)$dashboardStats['users_total'], 'fa-users', 'clay', 'admin.php?tab=users'],
            ['Mitarbeiter aktiv', $dashboardStats['employees_active'] . ' / ' . $dashboardStats['employees_total'], 'fa-id-badge', 'green', 'admin.php?tab=employees'],
        ];
        $statusParts = [
            ['Ausstehend', (int)$dashboardStats['bookings_pending'], 'pending'],
            ['Genehmigt', (int)$dashboardStats['bookings_approved'], 'approved'],
            ['Abgelehnt', (int)$dashboardStats['bookings_rejected'], 'rejected'],
        ];
        $statusSum = array_sum(array_column($statusParts, 1));
        ?>
        <section class="todo-grid" aria-label="Offene Punkte">
            <a class="todo-card todo-card--sun" href="admin.php?tab=bookings&amp;booking_status=pending">
                <i class="fas fa-inbox" aria-hidden="true"></i>
                <span><b><?= (int)$dashboardStats['bookings_pending'] ?></b> Buchungen warten auf Ihre Entscheidung</span>
                <em>Prüfen <i class="fas fa-arrow-right" aria-hidden="true"></i></em>
            </a>
            <a class="todo-card todo-card--blue" href="admin.php?tab=reviews&amp;review_reply=without">
                <i class="fas fa-comment-dots" aria-hidden="true"></i>
                <span><b><?= (int)$dashboardStats['reviews_open'] ?></b> Bewertungen ohne Antwort</span>
                <em>Antworten <i class="fas fa-arrow-right" aria-hidden="true"></i></em>
            </a>
            <a class="todo-card todo-card--ink" href="admin.php?tab=tasks">
                <i class="fas fa-calendar-day" aria-hidden="true"></i>
                <span><b><?= (int)$dashboardStats['bookings_today'] ?></b> Buchungen für heute</span>
                <em>Aufgaben verteilen <i class="fas fa-arrow-right" aria-hidden="true"></i></em>
            </a>
        </section>

        <section class="kpi-grid">
            <?php foreach ($kpis as [$kpiLabel, $kpiValue, $kpiIcon, $kpiTone, $kpiHref]): ?>
                <a class="kpi-card kpi-card--<?= $kpiTone ?>" href="<?= h($kpiHref) ?>">
                    <span class="kpi-icon" aria-hidden="true"><i class="fas <?= $kpiIcon ?>"></i></span>
                    <span class="kpi-label"><?= h($kpiLabel) ?></span>
                    <strong class="kpi-value"><?= h($kpiValue) ?></strong>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="admin-card">
            <div class="card-header">
                <h2>Buchungen nach Status</h2>
                <span class="bulk-hint">Gesamt: <?= $statusSum ?></span>
            </div>
            <?php if ($statusSum === 0): ?>
                <p class="inline-note">Noch keine Buchungen vorhanden.</p>
            <?php else: ?>
                <div class="split-bar" role="img" aria-label="Verteilung der Buchungen nach Status">
                    <?php foreach ($statusParts as [$partLabel, $partCount, $partKey]): ?>
                        <?php if ($partCount > 0): ?>
                            <span class="split-bar__part split-bar__part--<?= $partKey ?>" style="flex-grow: <?= $partCount ?>" title="<?= h($partLabel) ?>: <?= $partCount ?>"></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <ul class="split-legend">
                    <?php foreach ($statusParts as [$partLabel, $partCount, $partKey]): ?>
                        <li><i class="split-dot split-bar__part--<?= $partKey ?>"></i> <?= h($partLabel) ?> <b><?= $partCount ?></b> <small><?= number_format($partCount / $statusSum * 100, 0) ?> %</small></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <?php
        $notifyCfg = notifyConfig();
        $mailReady = mailIsConfigured($notifyCfg);
        $notifyChannels = [
            ['fa-envelope', 'E-Mail an den Inhaber bei neuer Anfrage', $mailReady && $notifyCfg['owner_email'] !== '',
                $notifyCfg['owner_email'] !== '' ? $notifyCfg['owner_email'] : 'NOTIFY_EMAIL fehlt'],
            ['fa-brands fa-whatsapp', 'WhatsApp an den Inhaber bei neuer Anfrage', whatsappIsConfigured($notifyCfg),
                $notifyCfg['whatsapp']['provider'] !== '' ? 'Anbieter: ' . $notifyCfg['whatsapp']['provider'] : 'WHATSAPP_PROVIDER fehlt'],
            ['fa-paper-plane', 'E-Mail an Kunden bei Genehmigung oder Ablehnung', $mailReady,
                $mailReady ? 'über ' . $notifyCfg['mail']['host'] : 'SMTP fehlt (MAIL_HOST, MAIL_FROM)'],
        ];
        $notifyLogLines = is_file(__DIR__ . '/tmp/notifications.log')
            ? array_slice(file(__DIR__ . '/tmp/notifications.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -5)
            : [];
        ?>
        <section class="admin-card">
            <div class="card-header">
                <h2>Benachrichtigungen</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="notify_test">
                    <input type="hidden" name="return_tab" value="dashboard">
                    <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                    <button type="submit" class="btn btn-small"><i class="fas fa-paper-plane" aria-hidden="true"></i> Test senden</button>
                </form>
            </div>
            <ul class="notify-list">
                <?php foreach ($notifyChannels as [$icon, $label, $ready, $detail]): ?>
                    <li>
                        <i class="<?= str_starts_with($icon, 'fa-brands') ? h($icon) : 'fas ' . h($icon) ?>" aria-hidden="true"></i>
                        <span><b><?= h($label) ?></b><small><?= h($detail) ?></small></span>
                        <span class="status-badge <?= $ready ? 'status-approved' : 'status-rejected' ?>"><?= $ready ? 'Aktiv' : 'Nicht eingerichtet' ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($notifyLogLines)): ?>
                <ul class="notify-log">
                    <?php foreach (array_reverse($notifyLogLines) as $logLine): ?>
                        <?php $entry = json_decode($logLine, true); if (!is_array($entry)) { continue; } ?>
                        <li class="<?= !empty($entry['ok']) ? 'is-ok' : 'is-fail' ?>">
                            <time><?= h(date('d.m. H:i', strtotime((string)($entry['at'] ?? '')) ?: time())) ?></time>
                            <b><?= h((string)($entry['channel'] ?? '')) ?></b>
                            <span><?= h((string)($entry['detail'] ?? $entry['event'] ?? '')) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p class="inline-note">Zugangsdaten für E-Mail und WhatsApp stehen in <code>config.local.php</code> – Vorlage: <code>config.local.example.php</code>.</p>
        </section>

        <div class="dash-grid">
        <section class="admin-card">
            <div class="card-header">
                <h2>Neueste Buchungen</h2>
                <a class="btn btn-small" href="<?= h(buildAdminUrl(['tab' => 'bookings'])) ?>">Alle anzeigen</a>
            </div>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Datum</th>
                        <th>Kunde</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dashboardRecentBookings)): ?>
                        <tr><td colspan="5" class="empty-row">Keine Buchungen vorhanden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dashboardRecentBookings as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= h((string)$row['date']) ?></td>
                                <td><?= h((string)($row['booked_by'] ?? '')) ?></td>
                                <td><span class="status-badge status-<?= h((string)$row['status']) ?>"><?= h(bookingStatusLabel((string)$row['status'])) ?></span></td>
                                <td><?= h((string)$row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="admin-card">
            <div class="card-header">
                <h2>Neueste Bewertungen</h2>
                <a class="btn btn-small" href="<?= h(buildAdminUrl(['tab' => 'reviews'])) ?>">Alle anzeigen</a>
            </div>
            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Kunde</th>
                        <th>Rating</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($dashboardRecentReviews)): ?>
                        <tr><td colspan="5" class="empty-row">Keine Bewertungen vorhanden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($dashboardRecentReviews as $row): ?>
                            <tr>
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= h((string)($row['user_email'] ?? '')) ?></td>
                                <td><?= (int)($row['rating'] ?? 0) ?>/5</td>
                                <td>
                                    <span class="status-badge <?= (int)($row['has_reply'] ?? 0) === 1 ? 'status-done' : 'status-open' ?>">
                                        <?= h(reviewReplyStatusLabel((int)($row['has_reply'] ?? 0) === 1)) ?>
                                    </span>
                                </td>
                                <td><?= h((string)$row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        </div>
    <?php endif; ?>

    <?php if ($activeTab === 'bookings'): ?>
        <section class="admin-card">
            <div class="card-header">
                <h2>Buchungen</h2>
                <a class="btn" href="<?= h(buildAdminUrl(['export' => 'bookings'], ['page'])) ?>"><i class="fas fa-file-csv"></i> CSV Export</a>
            </div>

            <form method="GET" class="filters-grid">
                <input type="hidden" name="tab" value="bookings">
                <label>
                    Suche
                    <input type="text" name="booking_q" value="<?= h($bookingSearch) ?>" placeholder="ID, Kunde, Telefon, Service, Datum">
                </label>
                <label>
                    Status
                    <select name="booking_status">
                        <option value="all" <?= $bookingStatusFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                        <option value="pending" <?= $bookingStatusFilter === 'pending' ? 'selected' : '' ?>>Ausstehend</option>
                        <option value="approved" <?= $bookingStatusFilter === 'approved' ? 'selected' : '' ?>>Genehmigt</option>
                        <option value="rejected" <?= $bookingStatusFilter === 'rejected' ? 'selected' : '' ?>>Abgelehnt</option>
                    </select>
                </label>
                <label>
                    Pro Seite
                    <select name="per_page">
                        <?php foreach ($allowedPerPage as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-secondary">Anwenden</button>
                    <a href="admin.php?tab=bookings" class="btn btn-ghost">Reset</a>
                </div>
            </form>

            <form id="bookingBulkForm" method="POST" class="bulk-toolbar">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="booking_bulk">
                <input type="hidden" name="return_tab" value="bookings">
                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                <select name="bulk_decision" required>
                    <option value="">Massenaktion wählen</option>
                    <option value="approve">Markierte genehmigen</option>
                    <option value="reject">Markierte ablehnen</option>
                </select>
                <button type="submit" class="btn btn-secondary">Ausführen</button>
                <span class="bulk-hint">Gesamt: <?= $bookingsTotal ?></span>
            </form>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" data-select-all="booking"></th>
                        <th>ID</th>
                        <th>Datum</th>
                        <th>Kunde</th>
                        <th>Telefon</th>
                        <th>Service</th>
                        <th>Status</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($bookingsRows)): ?>
                        <tr><td colspan="9" class="empty-row">Keine Buchungen gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($bookingsRows as $row): ?>
                            <?php $bookingId = (int)$row['id']; ?>
                            <tr>
                                <td><input type="checkbox" name="booking_ids[]" value="<?= $bookingId ?>" form="bookingBulkForm" data-select-item="booking"></td>
                                <td><?= $bookingId ?></td>
                                <td><?= h((string)$row['date']) ?></td>
                                <td><?= h((string)($row['booked_by'] ?? '')) ?></td>
                                <td><?= h((string)($row['phone'] ?? 'Nicht angegeben')) ?></td>
                                <td><?= h((string)($row['service_type'] ?? 'Nicht angegeben')) ?></td>
                                <td><span class="status-badge status-<?= h((string)$row['status']) ?>"><?= h(bookingStatusLabel((string)$row['status'])) ?></span></td>
                                <td><?= h((string)$row['created_at']) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ((string)$row['status'] !== 'approved'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="booking_single">
                                                <input type="hidden" name="decision" value="approve">
                                                <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                                <input type="hidden" name="return_tab" value="bookings">
                                                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                <button type="submit" class="btn btn-success btn-small">Genehmigen</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" onsubmit="return confirm('Buchung wirklich ablehnen und entfernen?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="booking_single">
                                            <input type="hidden" name="decision" value="reject">
                                            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">
                                            <input type="hidden" name="return_tab" value="bookings">
                                            <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Ablehnen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?= renderPagination($bookingsTotal, $perPage, $page) ?>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'reviews'): ?>
        <section class="admin-card">
            <div class="card-header">
                <h2>Bewertungen</h2>
                <a class="btn" href="<?= h(buildAdminUrl(['export' => 'reviews'], ['page'])) ?>"><i class="fas fa-file-csv"></i> CSV Export</a>
            </div>

            <form method="GET" class="filters-grid">
                <input type="hidden" name="tab" value="reviews">
                <label>
                    Suche
                    <input type="text" name="review_q" value="<?= h($reviewSearch) ?>" placeholder="ID, Kunde, Text, Datum">
                </label>
                <label>
                    Rating
                    <select name="review_rating">
                        <option value="0" <?= $reviewRatingFilter === 0 ? 'selected' : '' ?>>Alle</option>
                        <?php for ($r = 5; $r >= 1; $r--): ?>
                            <option value="<?= $r ?>" <?= $reviewRatingFilter === $r ? 'selected' : '' ?>><?= $r ?> Sterne</option>
                        <?php endfor; ?>
                    </select>
                </label>
                <label>
                    Antwortstatus
                    <select name="review_reply">
                        <option value="all" <?= $reviewReplyFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                        <option value="with" <?= $reviewReplyFilter === 'with' ? 'selected' : '' ?>>Mit Antwort</option>
                        <option value="without" <?= $reviewReplyFilter === 'without' ? 'selected' : '' ?>>Ohne Antwort</option>
                    </select>
                </label>
                <label>
                    Pro Seite
                    <select name="per_page">
                        <?php foreach ($allowedPerPage as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-secondary">Anwenden</button>
                    <a href="admin.php?tab=reviews" class="btn btn-ghost">Reset</a>
                </div>
            </form>

            <form id="reviewBulkForm" method="POST" class="bulk-toolbar" onsubmit="return confirm('Markierte Bewertungen wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="review_bulk_delete">
                <input type="hidden" name="return_tab" value="reviews">
                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                <button type="submit" class="btn btn-danger">Markierte löschen</button>
                <span class="bulk-hint">Gesamt: <?= $reviewsTotal ?></span>
            </form>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" data-select-all="review"></th>
                        <th>ID</th>
                        <th>Kunde</th>
                        <th>Buchung</th>
                        <th>Rating</th>
                        <th>Text</th>
                        <th>Status</th>
                        <th>Antwort</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($reviewsRows)): ?>
                        <tr><td colspan="9" class="empty-row">Keine Bewertungen gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($reviewsRows as $row): ?>
                            <?php
                            $reviewId = (int)$row['id'];
                            $hasReply = !empty($row['reply_text']);
                            ?>
                            <tr>
                                <td><input type="checkbox" name="review_ids[]" value="<?= $reviewId ?>" form="reviewBulkForm" data-select-item="review"></td>
                                <td><?= $reviewId ?></td>
                                <td><?= h((string)($row['user_email'] ?? '')) ?></td>
                                <td><?= h((string)($row['booking_date'] ?? 'N/A')) ?></td>
                                <td><?= (int)($row['rating'] ?? 0) ?>/5</td>
                                <td class="cell-text"><?= h((string)($row['review_text'] ?? '')) ?></td>
                                <td>
                                    <span class="status-badge <?= $hasReply ? 'status-done' : 'status-open' ?>">
                                        <?= h(reviewReplyStatusLabel($hasReply)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($hasReply): ?>
                                        <div class="reply-view">
                                            <p><?= h((string)$row['reply_text']) ?></p>
                                            <small><?= h((string)($row['reply_date'] ?? '')) ?></small>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="reply-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="review_reply">
                                            <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                            <input type="hidden" name="return_tab" value="reviews">
                                            <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                            <textarea name="reply_text" rows="2" placeholder="Antwort schreiben" required></textarea>
                                            <button type="submit" class="btn btn-small btn-secondary">Senden</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('Bewertung wirklich löschen?');">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="action" value="review_delete">
                                        <input type="hidden" name="review_id" value="<?= $reviewId ?>">
                                        <input type="hidden" name="return_tab" value="reviews">
                                        <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                        <button type="submit" class="btn btn-danger btn-small">Löschen</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?= renderPagination($reviewsTotal, $perPage, $page) ?>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'users'): ?>
        <section class="admin-card">
            <div class="card-header">
                <h2>Benutzer</h2>
                <a class="btn" href="<?= h(buildAdminUrl(['export' => 'users'], ['page'])) ?>"><i class="fas fa-file-csv"></i> CSV Export</a>
            </div>

            <form method="GET" class="filters-grid">
                <input type="hidden" name="tab" value="users">
                <label>
                    Suche
                    <input type="text" name="user_q" value="<?= h($userSearch) ?>" placeholder="Name, E-Mail, Telefon">
                </label>
                <label>
                    Registriert von
                    <input type="date" name="users_from" value="<?= h($usersFromDate) ?>">
                </label>
                <label>
                    Registriert bis
                    <input type="date" name="users_to" value="<?= h($usersToDate) ?>">
                </label>
                <label>
                    Pro Seite
                    <select name="per_page">
                        <?php foreach ($allowedPerPage as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-secondary">Anwenden</button>
                    <a href="admin.php?tab=users" class="btn btn-ghost">Reset</a>
                </div>
            </form>

            <form id="usersBulkForm" method="POST" class="bulk-toolbar" onsubmit="return confirm('Markierte Benutzer wirklich löschen?');">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="user_bulk_delete">
                <input type="hidden" name="return_tab" value="users">
                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                <button type="submit" class="btn btn-danger">Markierte löschen</button>
                <span class="bulk-hint">Gesamt: <?= $usersTotal ?></span>
            </form>

            <div class="table-scroll">
                <table class="admin-table">
                    <thead>
                    <tr>
                        <th><input type="checkbox" data-select-all="user"></th>
                        <th>ID</th>
                        <th>Benutzername</th>
                        <th>E-Mail</th>
                        <th>Telefon(e)</th>
                        <th>Buchungen</th>
                        <th>Reviews</th>
                        <th>Registriert</th>
                        <th>Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($usersRows)): ?>
                        <tr><td colspan="9" class="empty-row">Keine Benutzer gefunden.</td></tr>
                    <?php else: ?>
                        <?php foreach ($usersRows as $row): ?>
                            <?php $userId = (int)$row['id']; ?>
                            <tr>
                                <td><input type="checkbox" name="user_ids[]" value="<?= $userId ?>" form="usersBulkForm" data-select-item="user"></td>
                                <td><?= $userId ?></td>
                                <td><?= h((string)($row['username'] ?? '')) ?></td>
                                <td><?= h((string)($row['email'] ?? '')) ?></td>
                                <td><?= h((string)($row['phones'] ?? 'Nicht angegeben')) ?></td>
                                <td><?= (int)($row['bookings_count'] ?? 0) ?></td>
                                <td><?= (int)($row['reviews_count'] ?? 0) ?></td>
                                <td><?= h((string)$row['created_at']) ?></td>
                                <td>
                                    <div class="row-actions">
                                        <form method="POST" class="inline-action-form" onsubmit="return confirm('Kunden-Passwort wirklich zurücksetzen?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="user_reset_password">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <input type="hidden" name="username" value="<?= h((string)($row['username'] ?? '')) ?>">
                                            <input type="hidden" name="email" value="<?= h((string)($row['email'] ?? '')) ?>">
                                            <input type="hidden" name="return_tab" value="users">
                                            <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                            <input type="password" name="new_password" minlength="6" autocomplete="new-password" placeholder="Neues Passwort" required>
                                            <button type="submit" class="btn btn-small btn-secondary">Passwort reset</button>
                                        </form>
                                        <form method="POST" onsubmit="return confirm('Benutzer wirklich löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="user_delete">
                                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                                            <input type="hidden" name="return_tab" value="users">
                                            <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                            <button type="submit" class="btn btn-danger btn-small">Löschen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?= renderPagination($usersTotal, $perPage, $page) ?>
        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'employees' || $activeTab === 'tasks'): ?>
        <section class="admin-card">
            <div class="card-header">
                <h2><?= $activeTab === 'tasks' ? 'Aufgaben' : 'Mitarbeiter' ?></h2>
                <?php if ($activeTab === 'employees'): ?>
                    <a class="btn" href="<?= h(buildAdminUrl(['export' => 'employees'], ['page'])) ?>"><i class="fas fa-file-csv"></i> CSV Export</a>
                <?php endif; ?>
            </div>

            <?php if ($activeTab === 'employees'): ?>
                <?php if ($employeesStorageMode === 'file'): ?>
                    <p class="inline-note">DB-Tabelle nicht verfügbar. Employee-Daten werden lokal in <code>tmp/employee_profiles.json</code> gespeichert.</p>
                <?php endif; ?>
                <p class="inline-note">Beim Erstellen wird automatisch ein fertiges Profil nach Rollen-Template erzeugt.</p>
                <p class="inline-note">Startpasswort wird beim Erstellen durch den Admin gesetzt. Mitarbeiter kann es im Mitarbeiterprofil selbst ändern.</p>
                    <form method="POST" class="employee-create-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="employee_create">
                        <input type="hidden" name="return_tab" value="employees">
                        <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">

                        <label>
                            Name
                            <input type="text" name="full_name" required maxlength="120">
                        </label>
                        <label>
                            Rolle
                            <select name="role" required>
                                <?php foreach ($employeeRoles as $key => $label): ?>
                                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            E-Mail
                            <input type="email" name="email" required maxlength="120">
                        </label>
                        <label>
                            Telefon
                            <input type="text" name="phone" maxlength="30">
                        </label>
                        <label>
                            Status
                            <select name="status">
                                <?php foreach ($employeeStatuses as $key => $label): ?>
                                    <option value="<?= h($key) ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            Startpasswort
                            <input type="password" name="initial_password" minlength="6" maxlength="120" autocomplete="new-password" required>
                        </label>
                        <label class="wide">
                            Notizen
                            <input type="text" name="notes" maxlength="255" placeholder="Optional">
                        </label>
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-secondary">Mitarbeiter hinzufügen</button>
                        </div>
                    </form>
            <?php endif; ?>

                <?php if ($activeTab === 'tasks'): ?>
                <form method="GET" class="filters-grid">
                    <input type="hidden" name="tab" value="tasks">
                    <label>
                        Task Suche
                        <input type="text" name="task_q" value="<?= h($taskSearch) ?>" placeholder="Task-ID, Titel, Service, Buchung">
                    </label>
                    <label>
                        Task Mitarbeiter
                        <select name="task_employee">
                            <option value="0" <?= $taskEmployeeFilter === 0 ? 'selected' : '' ?>>Alle</option>
                            <?php foreach ($employeesAllRows as $employeeRow): ?>
                                <?php $employeeRowId = (int)($employeeRow['id'] ?? 0); ?>
                                <option value="<?= $employeeRowId ?>" <?= $taskEmployeeFilter === $employeeRowId ? 'selected' : '' ?>>
                                    #<?= $employeeRowId ?> - <?= h((string)($employeeRow['full_name'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Task Status
                        <select name="task_status">
                            <option value="all" <?= $taskStatusFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                            <?php foreach ($taskStatusLabels as $statusKey => $statusLabel): ?>
                                <option value="<?= h($statusKey) ?>" <?= $taskStatusFilter === $statusKey ? 'selected' : '' ?>><?= h((string)$statusLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-secondary">Anwenden</button>
                        <a href="admin.php?tab=tasks" class="btn btn-ghost">Reset</a>
                    </div>
                </form>

                <section class="admin-subcard simple-unassigned">
                    <div class="simple-unassigned__header">
                        <h3>Nicht verteilte Aufgaben</h3>
                        <span class="bulk-hint">Offen: <?= count($unassignedBookings) ?></span>
                    </div>
                    <p class="inline-note">Hier erscheinen automatisch nur genehmigte Buchungen ohne zugewiesene Aufgabe.</p>

                    <div class="table-scroll">
                        <table class="admin-table simple-unassigned-table">
                            <thead>
                            <tr>
                                <th>Buchung</th>
                                <th>Datum</th>
                                <th>Kunde</th>
                                <th>Telefon</th>
                                <th>Service-Typ</th>
                                <th>Status</th>
                                <th>Aktion</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($unassignedBookings)): ?>
                                <tr><td colspan="7" class="empty-row">Keine genehmigten, nicht verteilten Aufgaben gefunden.</td></tr>
                            <?php else: ?>
                                <?php foreach ($unassignedBookings as $booking): ?>
                                    <?php
                                    $bookingId = (int)($booking['id'] ?? 0);
                                    $bookingDate = (string)($booking['date'] ?? '');
                                    $bookingStatus = (string)($booking['status'] ?? 'approved');
                                    $bookingServiceType = trim((string)($booking['service_type'] ?? ''));
                                    $bookingServiceType = $bookingServiceType !== '' ? $bookingServiceType : 'Allgemein';
                                    $quickTitle = (string)($booking['template_title'] ?? ('Auftrag aus Buchung #' . $bookingId));
                                    $quickDescription = (string)($booking['template_description'] ?? ('Auftrag aus Buchung #' . $bookingId));
                                    ?>
                                    <tr>
                                        <td>#<?= $bookingId ?></td>
                                        <td><?= h($bookingDate) ?></td>
                                        <td><?= h((string)($booking['booked_by'] ?? '')) ?></td>
                                        <td><?= h((string)($booking['phone'] ?? 'Nicht angegeben')) ?></td>
                                        <td><?= h($bookingServiceType) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= h($bookingStatus) ?>">
                                                <?= h(bookingStatusLabel($bookingStatus)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                class="btn btn-small btn-secondary"
                                                data-open-assign-modal
                                                data-booking-id="<?= $bookingId ?>"
                                                data-service-type="<?= h($bookingServiceType) ?>"
                                                data-title="<?= h($quickTitle) ?>"
                                                data-description="<?= h($quickDescription) ?>"
                                                data-due-date="<?= h($bookingDate) ?>"
                                            >
                                                Mitarbeiter zuweisen
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="task-assign-modal" id="taskAssignModal" hidden>
                    <div class="task-assign-modal__backdrop" data-close-assign-modal></div>
                    <div class="task-assign-modal__panel" role="dialog" aria-modal="true" aria-labelledby="taskAssignModalTitle">
                        <div class="task-assign-modal__header">
                            <div>
                                <h4 id="taskAssignModalTitle">Aufgabe zuweisen</h4>
                                <p class="profile-meta" id="taskAssignModalBooking">Buchung: -</p>
                            </div>
                            <button type="button" class="btn btn-small btn-ghost" data-close-assign-modal>Schließen</button>
                        </div>

                        <form method="POST" class="task-create-form assignment-modal-form assignment-submit-check" id="taskAssignmentForm">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="action" value="task_create">
                            <input type="hidden" name="return_tab" value="tasks">
                            <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                            <input type="hidden" name="booking_id" id="taskAssignBookingId" value="">

                            <label>
                                Mitarbeiter
                                <select name="employee_id" id="taskAssignEmployee" required data-employee-select>
                                    <option value="">Mitarbeiter wählen</option>
                                    <?php foreach ($employeesAllRows as $employeeRow): ?>
                                        <?php
                                        $employeeRowId = (int)($employeeRow['id'] ?? 0);
                                        $employeeRowStatus = (string)($employeeRow['status'] ?? 'inactive');
                                        if ($employeeRowId <= 0 || $employeeRowStatus !== 'active') {
                                            continue;
                                        }
                                        $employeeLoad = $employeeLoadMap[$employeeRowId] ?? ['active' => 0, 'urgent' => 0];
                                        ?>
                                        <option
                                            value="<?= $employeeRowId ?>"
                                            data-role="<?= h((string)($employeeRow['role'] ?? '')) ?>"
                                            data-status="<?= h($employeeRowStatus) ?>"
                                            data-active="<?= (int)($employeeLoad['active'] ?? 0) ?>"
                                            data-urgent="<?= (int)($employeeLoad['urgent'] ?? 0) ?>"
                                        >
                                            #<?= $employeeRowId ?> - <?= h((string)($employeeRow['full_name'] ?? '')) ?> (offen: <?= (int)($employeeLoad['active'] ?? 0) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Service-Typ
                                <select name="service_type" id="taskAssignServiceType" required>
                                    <?php if (empty($serviceTypes)): ?>
                                        <?php $template = taskTemplateForServiceType('Allgemein'); ?>
                                        <option
                                            value="Allgemein"
                                            data-template-title="<?= h((string)($template['title'] ?? '')) ?>"
                                            data-template-description="<?= h((string)($template['description'] ?? '')) ?>"
                                        >
                                            Allgemein
                                        </option>
                                    <?php else: ?>
                                        <?php foreach ($serviceTypes as $serviceTypeOption): ?>
                                            <?php
                                            $serviceTypeValue = trim((string)$serviceTypeOption);
                                            if ($serviceTypeValue === '') {
                                                continue;
                                            }
                                            $template = taskTemplateForServiceType($serviceTypeValue);
                                            ?>
                                            <option
                                                value="<?= h($serviceTypeValue) ?>"
                                                data-template-title="<?= h((string)($template['title'] ?? '')) ?>"
                                                data-template-description="<?= h((string)($template['description'] ?? '')) ?>"
                                            >
                                                <?= h($serviceTypeValue) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </label>
                            <label>
                                Priorität
                                <select name="priority">
                                    <?php foreach ($taskPriorityLabels as $priorityKey => $priorityLabel): ?>
                                        <option value="<?= h($priorityKey) ?>"><?= h((string)$priorityLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                Fällig bis
                                <input type="date" name="due_date" id="taskAssignDueDate">
                            </label>
                            <label class="wide">
                                Titel
                                <input type="text" name="title" id="taskAssignTitle" maxlength="160" required>
                            </label>
                            <label class="wide">
                                Beschreibung
                                <input type="text" name="description" id="taskAssignDescription" maxlength="500" placeholder="Kurze Beschreibung">
                            </label>
                            <div class="filter-actions">
                                <label class="queue-checkbox">
                                    <input type="checkbox" name="notify_whatsapp" value="1" checked>
                                    WhatsApp
                                </label>
                                <button type="button" class="btn btn-ghost" data-close-assign-modal>Abbrechen</button>
                                <button type="submit" class="btn btn-secondary">Aufgabe zuweisen</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($activeTab === 'employees'): ?>
                <form method="GET" class="filters-grid">
                    <input type="hidden" name="tab" value="employees">
                    <label>
                        Suche
                        <input type="text" name="employee_q" value="<?= h($employeeSearch) ?>" placeholder="ID, Name, E-Mail, Telefon">
                    </label>
                    <label>
                        Rolle
                        <select name="employee_role">
                            <option value="all" <?= $employeeRoleFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                            <?php foreach ($employeeRoles as $key => $label): ?>
                                <option value="<?= h($key) ?>" <?= $employeeRoleFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Status
                        <select name="employee_status">
                            <option value="all" <?= $employeeStatusFilter === 'all' ? 'selected' : '' ?>>Alle</option>
                            <option value="active" <?= $employeeStatusFilter === 'active' ? 'selected' : '' ?>>Aktiv</option>
                            <option value="inactive" <?= $employeeStatusFilter === 'inactive' ? 'selected' : '' ?>>Inaktiv</option>
                        </select>
                    </label>
                    <label>
                        Pro Seite
                        <select name="per_page">
                            <?php foreach ($allowedPerPage as $option): ?>
                                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="filter-actions">
                        <button type="submit" class="btn btn-secondary">Anwenden</button>
                        <a href="admin.php?tab=employees" class="btn btn-ghost">Reset</a>
                    </div>
                </form>

                <form id="employeeBulkForm" method="POST" class="bulk-toolbar">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="employee_bulk">
                    <input type="hidden" name="return_tab" value="employees">
                    <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                    <select name="bulk_action" required>
                        <option value="">Massenaktion wählen</option>
                        <option value="activate">Markierte aktivieren</option>
                        <option value="deactivate">Markierte deaktivieren</option>
                        <option value="delete">Markierte löschen</option>
                    </select>
                    <button type="submit" class="btn btn-secondary">Ausführen</button>
                    <span class="bulk-hint">Gesamt: <?= $employeesTotal ?></span>
                </form>

                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                        <tr>
                            <th><input type="checkbox" data-select-all="employee"></th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Rolle</th>
                            <th>E-Mail</th>
                            <th>Telefon</th>
                            <th>Status</th>
                            <th>Profil</th>
                            <th>Notizen</th>
                            <th>Aktionen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($employeesRows)): ?>
                            <tr><td colspan="10" class="empty-row">Keine Mitarbeiter gefunden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($employeesRows as $row): ?>
                                <?php
                                $employeeId = (int)$row['id'];
                                $roleKey = (string)($row['role'] ?? '');
                                $statusKey = (string)($row['status'] ?? 'inactive');
                                $profileReady = ((int)($row['profile_ready'] ?? 0)) === 1;
                                $profilePayload = json_decode((string)($row['profile_data'] ?? ''), true);
                                if (!is_array($profilePayload)) {
                                    $profilePayload = [];
                                }
                                $profileChecklist = isset($profilePayload['checklist']) && is_array($profilePayload['checklist'])
                                    ? count($profilePayload['checklist'])
                                    : 0;
                                $profileResponsibilities = isset($profilePayload['responsibilities']) && is_array($profilePayload['responsibilities'])
                                    ? count($profilePayload['responsibilities'])
                                    : 0;
                                ?>
                                <tr>
                                    <td><input type="checkbox" name="employee_ids[]" value="<?= $employeeId ?>" form="employeeBulkForm" data-select-item="employee"></td>
                                    <td><?= $employeeId ?></td>
                                    <td><?= h((string)($row['full_name'] ?? '')) ?></td>
                                    <td><?= h($employeeRoles[$roleKey] ?? $roleKey) ?></td>
                                    <td><?= h((string)($row['email'] ?? '')) ?></td>
                                    <td><?= h((string)($row['phone'] ?? '')) ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusKey === 'active' ? 'status-approved' : 'status-rejected' ?>">
                                            <?= h($employeeStatuses[$statusKey] ?? $statusKey) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?= $profileReady ? 'status-approved' : 'status-open' ?>">
                                            <?= $profileReady ? 'Bereit' : 'Entwurf' ?>
                                        </span>
                                        <small class="profile-meta">
                                            <?= $profileResponsibilities ?> Aufgabenbereiche
                                            • <?= $profileChecklist ?> Checkpoints
                                        </small>
                                    </td>
                                    <td class="cell-text"><?= h((string)($row['notes'] ?? '')) ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <form method="POST">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="employee_set_status">
                                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                                <input type="hidden" name="status" value="<?= $statusKey === 'active' ? 'inactive' : 'active' ?>">
                                                <input type="hidden" name="return_tab" value="employees">
                                                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                <button type="submit" class="btn btn-small btn-secondary">
                                                    <?= $statusKey === 'active' ? 'Deaktivieren' : 'Aktivieren' ?>
                                                </button>
                                            </form>
                                            <form method="POST" class="inline-action-form" onsubmit="return confirm('Mitarbeiter-Passwort wirklich zurücksetzen?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="employee_reset_password">
                                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                                <input type="hidden" name="return_tab" value="employees">
                                                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                <input type="password" name="new_password" minlength="6" autocomplete="new-password" placeholder="Neues Passwort" required>
                                                <button type="submit" class="btn btn-small btn-secondary">Passwort reset</button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Mitarbeiter wirklich löschen?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                <input type="hidden" name="action" value="employee_delete">
                                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                                <input type="hidden" name="return_tab" value="employees">
                                                <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                <button type="submit" class="btn btn-danger btn-small">Löschen</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?= renderPagination($employeesTotal, $perPage, $page) ?>
                <?php endif; ?>

                <?php if ($activeTab === 'tasks'): ?>
                <section class="admin-subcard">
                    <h3>Leistungsübersicht pro Mitarbeiter</h3>
                    <div class="table-scroll">
                        <table class="admin-table">
                            <thead>
                            <tr>
                                <th>Mitarbeiter</th>
                                <th>Rolle</th>
                                <th>Aufträge gesamt</th>
                                <th>Erledigt</th>
                                <th>Nicht erledigt</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($employeesAllRows)): ?>
                                <tr><td colspan="5" class="empty-row">Keine Mitarbeiter für Statistik vorhanden.</td></tr>
                            <?php else: ?>
                                <?php foreach ($employeesAllRows as $employeeRow): ?>
                                    <?php
                                    $employeeRowId = (int)($employeeRow['id'] ?? 0);
                                    $stats = $tasksByEmployeeStats[$employeeRowId] ?? ['total' => 0, 'done' => 0, 'not_done' => 0];
                                    $roleKey = (string)($employeeRow['role'] ?? '');
                                    ?>
                                    <tr>
                                        <td>#<?= $employeeRowId ?> - <?= h((string)($employeeRow['full_name'] ?? '')) ?></td>
                                        <td><?= h($employeeRoles[$roleKey] ?? $roleKey) ?></td>
                                        <td><?= (int)($stats['total'] ?? 0) ?></td>
                                        <td><?= (int)($stats['done'] ?? 0) ?></td>
                                        <td><?= (int)($stats['not_done'] ?? 0) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="admin-subcard">
                    <h3>Mitarbeiter-Aufgaben</h3>
                    <div class="table-scroll">
                        <table class="admin-table">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Mitarbeiter</th>
                                <th>Service</th>
                                <th>Buchung</th>
                                <th>Titel</th>
                                <th>Priorität</th>
                                <th>Fällig</th>
                                <th>Status</th>
                                <th>Erledigt</th>
                                <th>Aktualisiert</th>
                                <th>Aktionen</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($tasksRows)): ?>
                                <tr><td colspan="11" class="empty-row">Keine Aufgaben gefunden.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tasksRows as $task): ?>
                                    <?php
                                    $taskId = (int)($task['id'] ?? 0);
                                    $taskStatus = (string)($task['status'] ?? 'assigned');
                                    $taskPriority = (string)($task['priority'] ?? 'normal');
                                    $taskDone = $taskStatus === 'done';
                                    ?>
                                    <tr>
                                        <td><?= $taskId ?></td>
                                        <td>#<?= (int)($task['employee_id'] ?? 0) ?> - <?= h((string)($task['employee_name'] ?? '')) ?></td>
                                        <td><?= h((string)($task['service_type'] ?? '')) ?></td>
                                        <td><?= (int)($task['booking_id'] ?? 0) > 0 ? '#' . (int)$task['booking_id'] : '-' ?></td>
                                        <td>
                                            <strong><?= h((string)($task['title'] ?? '')) ?></strong>
                                            <?php if ((string)($task['description'] ?? '') !== ''): ?>
                                                <small class="profile-meta"><?= h((string)$task['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h((string)($taskPriorityLabels[$taskPriority] ?? $taskPriority)) ?></td>
                                        <td><?= h((string)($task['due_date'] ?? '')) !== '' ? h((string)$task['due_date']) : '-' ?></td>
                                        <td>
                                            <span class="status-badge <?= h(taskStatusBadgeClass($taskStatus)) ?>">
                                                <?= h(taskStatusLabel($taskStatus)) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $taskDone ? 'status-approved' : 'status-rejected' ?>">
                                                <?= $taskDone ? 'Ja' : 'Nein' ?>
                                            </span>
                                        </td>
                                        <td><?= h((string)($task['updated_at'] ?? '')) ?></td>
                                        <td>
                                            <div class="row-actions">
                                                <?php if ($taskDone): ?>
                                                    <span class="profile-meta">Erledigt: nur Ansicht</span>
                                                <?php else: ?>
                                                    <form method="POST" class="inline-manage-form">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="task_set_status">
                                                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                        <input type="hidden" name="return_tab" value="tasks">
                                                        <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                        <select name="status" required>
                                                            <?php foreach ($taskStatusLabels as $statusKey => $statusLabel): ?>
                                                                <option value="<?= h($statusKey) ?>" <?= $taskStatus === $statusKey ? 'selected' : '' ?>><?= h((string)$statusLabel) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-small btn-secondary">Status</button>
                                                    </form>
                                                    <form method="POST" class="inline-manage-form">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="task_reassign">
                                                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                        <input type="hidden" name="return_tab" value="tasks">
                                                        <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                        <input type="hidden" name="notify_whatsapp" value="1">
                                                        <select name="employee_id" required>
                                                            <option value="">Mitarbeiter...</option>
                                                            <?php foreach ($employeesAllRows as $employeeOption): ?>
                                                                <?php
                                                                $employeeOptionId = (int)($employeeOption['id'] ?? 0);
                                                                if ($employeeOptionId <= 0 || $employeeOptionId === (int)($task['employee_id'] ?? 0)) {
                                                                    continue;
                                                                }
                                                                if ((string)($employeeOption['status'] ?? 'inactive') !== 'active') {
                                                                    continue;
                                                                }
                                                                $employeeOptionLoad = $employeeLoadMap[$employeeOptionId] ?? ['active' => 0];
                                                                ?>
                                                                <option value="<?= $employeeOptionId ?>">
                                                                    #<?= $employeeOptionId ?> - <?= h((string)($employeeOption['full_name'] ?? '')) ?> (offen: <?= (int)($employeeOptionLoad['active'] ?? 0) ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <button type="submit" class="btn btn-small btn-secondary">Direkt neu zuweisen</button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Aufgabe wirklich löschen?');">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="action" value="task_delete">
                                                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                                        <input type="hidden" name="return_tab" value="tasks">
                                                        <input type="hidden" name="return_query" value="<?= h($returnQuery) ?>">
                                                        <button type="submit" class="btn btn-danger btn-small">Löschen</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
                <?php endif; ?>

        </section>
    <?php endif; ?>

    <?php if ($activeTab === 'reports'): ?>
        <section class="admin-card">
            <div class="card-header">
                <h2>Berichte</h2>
                <a class="btn" href="<?= h(buildAdminUrl(['export' => 'reports'], ['page'])) ?>"><i class="fas fa-file-csv"></i> CSV Export</a>
            </div>

            <form method="GET" class="filters-grid">
                <input type="hidden" name="tab" value="reports">
                <label>
                    Von
                    <input type="date" name="report_from" value="<?= h($reportFromDate) ?>" required>
                </label>
                <label>
                    Bis
                    <input type="date" name="report_to" value="<?= h($reportToDate) ?>" required>
                </label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-secondary">Anwenden</button>
                    <a href="admin.php?tab=reports" class="btn btn-ghost">Reset</a>
                </div>
            </form>

            <div class="kpi-grid compact">
                <article class="kpi-card">
                    <span class="kpi-label">Buchungen</span>
                    <strong class="kpi-value"><?= $reportSummary['bookings_total'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Ausstehend</span>
                    <strong class="kpi-value"><?= $reportSummary['bookings_pending'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Genehmigt</span>
                    <strong class="kpi-value"><?= $reportSummary['bookings_approved'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Abgelehnt</span>
                    <strong class="kpi-value"><?= $reportSummary['bookings_rejected'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Bewertungen</span>
                    <strong class="kpi-value"><?= $reportSummary['reviews_total'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Ø Bewertung</span>
                    <strong class="kpi-value"><?= number_format((float)$reportSummary['reviews_avg'], 2) ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Neue Benutzer</span>
                    <strong class="kpi-value"><?= $reportSummary['users_new'] ?></strong>
                </article>
                <article class="kpi-card">
                    <span class="kpi-label">Neue Mitarbeiter</span>
                    <strong class="kpi-value"><?= $reportSummary['employees_new'] ?></strong>
                </article>
            </div>

            <div class="reports-grid">
                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                        <tr>
                            <th>Top Kunden</th>
                            <th>Buchungen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($reportTopCustomers)): ?>
                            <tr><td colspan="2" class="empty-row">Keine Daten im Zeitraum.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reportTopCustomers as $row): ?>
                                <tr>
                                    <td><?= h((string)($row['booked_by'] ?? '')) ?></td>
                                    <td><?= (int)($row['cnt'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-scroll">
                    <table class="admin-table">
                        <thead>
                        <tr>
                            <th>Monat</th>
                            <th>Buchungen</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($reportBookingTrend)): ?>
                            <tr><td colspan="2" class="empty-row">Keine Trenddaten im Zeitraum.</td></tr>
                        <?php else: ?>
                            <?php $trendMax = max(1, max(array_map(static fn($r) => (int)($r['cnt'] ?? 0), $reportBookingTrend))); ?>
                            <?php foreach ($reportBookingTrend as $row): ?>
                                <tr>
                                    <td><?= h((string)($row['ym'] ?? '')) ?></td>
                                    <td class="trend-cell">
                                        <span class="trend-bar" style="width: <?= round((int)($row['cnt'] ?? 0) / $trendMax * 100) ?>%"></span>
                                        <b><?= (int)($row['cnt'] ?? 0) ?></b>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
    </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sideToggle = document.querySelector('[data-side-toggle]');
    const closeSide = () => document.body.classList.remove('side-open');
    if (sideToggle) {
        sideToggle.addEventListener('click', () => document.body.classList.toggle('side-open'));
    }
    document.querySelectorAll('[data-side-close]').forEach((node) => node.addEventListener('click', closeSide));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeSide();
        }
    });

    const flash = document.querySelector('.flash-message');
    if (flash) {
        setTimeout(() => {
            flash.classList.add('fade-out');
            setTimeout(() => flash.remove(), 450);
        }, 10000);
    }

    document.querySelectorAll('[data-select-all]').forEach((toggle) => {
        const group = toggle.getAttribute('data-select-all');
        if (!group) return;

        const syncItems = () => {
            const items = document.querySelectorAll('[data-select-item="' + group + '"]');
            items.forEach((item) => {
                item.checked = toggle.checked;
            });
        };

        toggle.addEventListener('change', syncItems);

        document.querySelectorAll('[data-select-item="' + group + '"]').forEach((item) => {
            item.addEventListener('change', () => {
                if (!item.checked) {
                    toggle.checked = false;
                    return;
                }
                const allChecked = Array.from(document.querySelectorAll('[data-select-item="' + group + '"]')).every((node) => node.checked);
                toggle.checked = allChecked;
            });
        });
    });

    const bookingInput = document.getElementById('taskAssignBookingId');
    const serviceSelect = document.getElementById('taskAssignServiceType');
    const titleInput = document.getElementById('taskAssignTitle');
    const dueDateInput = document.getElementById('taskAssignDueDate');
    const descriptionInput = document.getElementById('taskAssignDescription');
    const employeeSelect = document.getElementById('taskAssignEmployee');
    const taskAssignModal = document.getElementById('taskAssignModal');
    const taskAssignModalBooking = document.getElementById('taskAssignModalBooking');

    const applyTemplateFromService = () => {
        if (!(serviceSelect instanceof HTMLSelectElement)) {
            return;
        }
        const selected = serviceSelect.selectedOptions[0];
        if (!selected) {
            return;
        }
        const templateTitle = selected.getAttribute('data-template-title') || '';
        const templateDescription = selected.getAttribute('data-template-description') || '';
        if (titleInput && titleInput.value.trim() === '' && templateTitle !== '') {
            titleInput.value = templateTitle;
        }
        if (descriptionInput && descriptionInput.value.trim() === '' && templateDescription !== '') {
            descriptionInput.value = templateDescription;
        }
    };

    const closeTaskAssignModal = () => {
        if (!taskAssignModal) {
            return;
        }
        taskAssignModal.setAttribute('hidden', 'hidden');
        document.body.classList.remove('modal-open');
    };

    const openTaskAssignModal = () => {
        if (!taskAssignModal) {
            return;
        }
        taskAssignModal.removeAttribute('hidden');
        document.body.classList.add('modal-open');
    };

    const setServiceTypeValue = (serviceType, templateTitle, templateDescription) => {
        if (!(serviceSelect instanceof HTMLSelectElement)) {
            return;
        }
        const normalizedService = (serviceType || '').trim();
        if (normalizedService === '') {
            if (serviceSelect.options.length > 0) {
                serviceSelect.selectedIndex = 0;
            }
            return;
        }
        let option = Array.from(serviceSelect.options).find((item) => item.value === normalizedService);
        if (!option) {
            option = document.createElement('option');
            option.value = normalizedService;
            option.textContent = normalizedService;
            option.setAttribute('data-template-title', templateTitle || '');
            option.setAttribute('data-template-description', templateDescription || '');
            serviceSelect.appendChild(option);
        }
        serviceSelect.value = normalizedService;
    };

    const fillTaskAssignForm = (button) => {
        const bookingId = button.getAttribute('data-booking-id') || '';
        const serviceType = button.getAttribute('data-service-type') || '';
        const title = button.getAttribute('data-title') || '';
        const description = button.getAttribute('data-description') || '';
        const dueDate = button.getAttribute('data-due-date') || '';

        if (bookingInput) {
            bookingInput.value = bookingId;
        }
        if (taskAssignModalBooking) {
            taskAssignModalBooking.textContent = bookingId !== '' ? ('Buchung: #' + bookingId) : 'Buchung: -';
        }
        setServiceTypeValue(serviceType, title, description);

        if (titleInput) {
            titleInput.value = title;
        }
        if (descriptionInput) {
            descriptionInput.value = description;
        }
        if (dueDateInput) {
            dueDateInput.value = dueDate;
        }
        if (employeeSelect) {
            employeeSelect.value = '';
        }
    };

    const buildAssignmentWarning = (selectEl) => {
        if (!(selectEl instanceof HTMLSelectElement) || !selectEl.value) {
            return '';
        }
        const option = selectEl.selectedOptions[0];
        if (!option) {
            return '';
        }
        const active = parseInt(option.getAttribute('data-active') || '0', 10);
        const urgent = parseInt(option.getAttribute('data-urgent') || '0', 10);
        const warnings = [];
        if (active >= 8) {
            warnings.push('Mitarbeiter hat hohe Auslastung (' + active + ' offene Aufgaben).');
        }
        if (urgent >= 3) {
            warnings.push('Mitarbeiter hat bereits mehrere dringende Aufgaben (' + urgent + ').');
        }
        return warnings.join(' ');
    };

    if (serviceSelect) {
        serviceSelect.addEventListener('change', () => {
            applyTemplateFromService();
        });
    }

    document.querySelectorAll('[data-open-assign-modal]').forEach((button) => {
        button.addEventListener('click', () => {
            fillTaskAssignForm(button);
            applyTemplateFromService();
            openTaskAssignModal();
            if (employeeSelect) {
                employeeSelect.focus();
            }
        });
    });

    document.querySelectorAll('[data-close-assign-modal]').forEach((button) => {
        button.addEventListener('click', closeTaskAssignModal);
    });

    if (taskAssignModal) {
        taskAssignModal.addEventListener('click', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.hasAttribute('data-close-assign-modal')) {
                closeTaskAssignModal();
            }
        });
    }

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && taskAssignModal && !taskAssignModal.hasAttribute('hidden')) {
            closeTaskAssignModal();
        }
    });

    document.querySelectorAll('form.assignment-submit-check').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const selectEl = form.querySelector('select[data-employee-select]');
            if (!(selectEl instanceof HTMLSelectElement)) {
                return;
            }
            const warning = buildAssignmentWarning(selectEl);
            if (warning !== '' && !window.confirm(warning + ' Trotzdem zuweisen?')) {
                event.preventDefault();
            }
        });
    });
});
</script>
</body>
</html>
