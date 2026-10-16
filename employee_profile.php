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

require_once 'local_auth.php';
require_once 'employee_workflow.php';
require_once __DIR__ . '/partials/layout.php';

$employeeAuth = authRequire('employee');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$employeeId = (int)($_SESSION['employee_id'] ?? 0);
$employeeName = (string)($_SESSION['employee_name'] ?? 'Mitarbeiter');
$employeeEmail = (string)($_SESSION['employee_email'] ?? '');
$employeeRole = (string)($_SESSION['employee_role'] ?? 'operations');
$employeeStatus = (string)($_SESSION['employee_status'] ?? 'inactive');
$profileDataRaw = (string)($_SESSION['employee_profile_data'] ?? '{}');
$mustChangePassword = !empty($_SESSION['employee_must_change_password']);

$taskStatusLabels = workflowTaskStatusLabels();
$taskPriorityLabels = workflowTaskPriorityLabels();

$message = '';
$messageType = '';
$openPasswordModal = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Ungültiger CSRF-Token.';
        $messageType = 'error';
    } elseif ($action === 'change_password') {
        $openPasswordModal = !$mustChangePassword;
        $currentPassword = trim((string)($_POST['current_password'] ?? ''));
        $newPassword = trim((string)($_POST['new_password'] ?? ''));
        $confirmPassword = trim((string)($_POST['confirm_password'] ?? ''));

        if ($newPassword === '' || strlen($newPassword) < 6) {
            $message = 'Neues Passwort muss mindestens 6 Zeichen lang sein.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'Neue Passwörter stimmen nicht überein.';
            $messageType = 'error';
        } elseif (!localAuthChangeEmployeePassword($employeeId, $currentPassword, $newPassword)) {
            $message = 'Passwort konnte nicht geändert werden. Prüfen Sie Ihr aktuelles Passwort.';
            $messageType = 'error';
        } else {
            $mustChangePassword = false;
            $openPasswordModal = false;
            $message = 'Passwort wurde erfolgreich geändert.';
            $messageType = 'success';
        }
    } elseif ($action === 'task_set_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');

        if ($taskId <= 0 || !isset($taskStatusLabels[$newStatus])) {
            $message = 'Ungültige Task-Daten.';
            $messageType = 'error';
        } else {
            $allTasks = workflowReadEmployeeTasks();
            $taskBelongsToEmployee = false;
            foreach ($allTasks as $task) {
                if ((int)($task['id'] ?? 0) === $taskId && (int)($task['employee_id'] ?? 0) === $employeeId) {
                    $taskBelongsToEmployee = true;
                    break;
                }
            }

            if (!$taskBelongsToEmployee) {
                $message = 'Task wurde nicht gefunden oder gehört nicht zu Ihrem Profil.';
                $messageType = 'error';
            } elseif (workflowUpdateEmployeeTaskStatus($taskId, $newStatus)) {
                $message = 'Task-Status aktualisiert.';
                $messageType = 'success';
            } else {
                $message = 'Task-Status konnte nicht aktualisiert werden.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'task_acknowledge') {
        $taskId = (int)($_POST['task_id'] ?? 0);

        if ($taskId <= 0) {
            $message = 'Ungültige Task-Daten.';
            $messageType = 'error';
        } elseif (workflowAcknowledgeEmployeeTask($taskId, $employeeId)) {
            $message = 'Neue Aufgabe bestätigt.';
            $messageType = 'success';
        } else {
            $message = 'Aufgabe konnte nicht bestätigt werden.';
            $messageType = 'error';
        }
    }
}

$stored = localAuthGetEmployeeById($employeeId);
if (!is_array($stored)) {
    // Konto wurde gelöscht: ein noch gültiges Token darf nicht weiter Zugang geben.
    authLogout('employee');
    header('Location: login.php');
    exit;
}
if (is_array($stored)) {
    $employeeName = (string)($stored['full_name'] ?? $employeeName);
    $employeeEmail = (string)($stored['email'] ?? $employeeEmail);
    $employeeRole = (string)($stored['role'] ?? $employeeRole);
    $employeeStatus = (string)($stored['status'] ?? $employeeStatus);
    $profileDataRaw = (string)($stored['profile_data'] ?? $profileDataRaw);
    $mustChangePassword = ((int)($stored['must_change_password'] ?? ($mustChangePassword ? 1 : 0))) === 1;

    // Geänderte Stammdaten gehören ins Token, sonst zeigt der nächste Aufruf alte Werte.
    $freshAuth = [
        'employee_auth' => true,
        'employee_id' => $employeeId,
        'employee_name' => $employeeName,
        'employee_email' => $employeeEmail,
        'employee_role' => $employeeRole,
        'employee_status' => $employeeStatus,
        'employee_must_change_password' => $mustChangePassword,
    ];
    if ($freshAuth != $employeeAuth) {
        authIssue('employee', $freshAuth, authState()['employee']['auth_time'] ?? null);
    }
}

if ($employeeStatus !== 'active') {
    authLogout('employee');
    header('Location: login.php');
    exit;
}

$profileData = json_decode($profileDataRaw, true);
if (!is_array($profileData)) {
    $profileData = [];
}

$responsibilities = isset($profileData['responsibilities']) && is_array($profileData['responsibilities'])
    ? $profileData['responsibilities']
    : [];
$checklist = isset($profileData['checklist']) && is_array($profileData['checklist'])
    ? $profileData['checklist']
    : [];
$permissions = isset($profileData['permissions']) && is_array($profileData['permissions'])
    ? $profileData['permissions']
    : [];
$kpiTargets = isset($profileData['kpi_targets']) && is_array($profileData['kpi_targets'])
    ? $profileData['kpi_targets']
    : [];

$employeeTasks = workflowFilterEmployeeTasks(workflowReadEmployeeTasks(), $employeeId, 'all', '');
$newTaskAlerts = array_values(array_filter(
    $employeeTasks,
    static fn(array $task): bool => !((bool)($task['employee_seen'] ?? true))
));

function e(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$roleLabels = [
    'project_manager' => 'Projektmanager',
    'installer' => 'Solarmodul-Montage',
    'electrician' => 'Elektriker',
    'support' => 'Service und Support',
    'operations' => 'Operations',
];
$employeeStatusLabel = $employeeStatus === 'active' ? 'Aktiv' : 'Inaktiv';
$roleLabel = $roleLabels[$employeeRole] ?? $employeeRole;

// Technische Schlüssel (z. B. tasks_completed_per_week) lesbar machen.
$readable = static fn(string $key): string => ucfirst(str_replace('_', ' ', $key));

$tasksDone = count(array_filter($employeeTasks, static fn(array $task): bool => (string)($task['status'] ?? '') === 'done'));
$tasksClosed = count(array_filter($employeeTasks, static fn(array $task): bool => in_array((string)($task['status'] ?? ''), ['done', 'canceled'], true)));
$tasksOpen = count($employeeTasks) - $tasksClosed;
$initials = '';
foreach (preg_split('/\s+/', trim($employeeName)) ?: [] as $namePart) {
    $initials .= function_exists('mb_substr') ? mb_strtoupper(mb_substr($namePart, 0, 1)) : strtoupper(substr($namePart, 0, 1));
}
$initials = function_exists('mb_substr') ? mb_substr($initials, 0, 2) : substr($initials, 0, 2);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mitarbeiterprofil – Jon Dienstleistungen</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="img/jd1.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="styles/site.css?v=20261013b">
    <link rel="stylesheet" href="styles/employee_profile.css?v=20261013c">
</head>
<body class="staff-body">
<header class="staff-top">
    <div class="wrap staff-top__inner">
        <a class="brand brand--light" href="employee_profile.php">
            <?= siteBrandMark() ?>
            <span class="brand__text"><strong>JON</strong><span>Mitarbeiter</span></span>
        </a>
        <div class="head-actions">
            <?php if (!$mustChangePassword): ?>
                <button type="button" class="icon-btn" id="openPasswordModal" aria-label="Passwort ändern" title="Passwort ändern">
                    <i class="fas fa-key" aria-hidden="true"></i> <span>Passwort</span>
                </button>
            <?php endif; ?>
            <a href="logout.php?scope=employee" class="logout"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> <span>Ausloggen</span></a>
        </div>
    </div>
</header>

<main class="wrap shell">
    <section class="head">
        <span class="staff-avatar" aria-hidden="true"><?= e($initials !== '' ? $initials : 'M') ?></span>
        <div class="head__text">
            <h1>Mitarbeiterprofil</h1>
            <p>Willkommen, <?= e($employeeName) ?>.</p>
            <ul class="head__meta">
                <li><i class="fas fa-briefcase" aria-hidden="true"></i> <?= e($roleLabel) ?></li>
                <li><i class="fas fa-envelope" aria-hidden="true"></i> <?= e($employeeEmail) ?></li>
                <li><span class="badge <?= $employeeStatus === 'active' ? 'active' : 'inactive' ?>"><?= e($employeeStatusLabel) ?></span></li>
            </ul>
        </div>
        <dl class="head__stats">
            <div><dt>Offen</dt><dd><?= $tasksOpen ?></dd></div>
            <div><dt>Erledigt</dt><dd><?= $tasksDone ?></dd></div>
            <div><dt>Gesamt</dt><dd><?= count($employeeTasks) ?></dd></div>
        </dl>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message <?= $messageType === 'success' ? 'success' : 'error' ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($newTaskAlerts)): ?>
        <div class="task-alerts">
            <?php foreach ($newTaskAlerts as $taskAlert): ?>
                <?php
                $taskAlertId = (int)($taskAlert['id'] ?? 0);
                $taskAlertDue = (string)($taskAlert['due_date'] ?? '');
                ?>
                <form method="POST" class="task-alert">
                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                    <input type="hidden" name="action" value="task_acknowledge">
                    <input type="hidden" name="task_id" value="<?= $taskAlertId ?>">
                    <i class="fas fa-bell" aria-hidden="true"></i>
                    <div>
                        <p class="task-alert-title">Neue Aufgabe: <?= e((string)($taskAlert['title'] ?? '')) ?></p>
                        <p class="task-alert-meta">
                            Service: <?= e((string)($taskAlert['service_type'] ?? '-')) ?>
                            <?php if ($taskAlertDue !== ''): ?>
                                | Fällig bis: <?= e($taskAlertDue) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <button type="submit" class="btn btn--dark btn--sm">Verstanden</button>
                </form>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($mustChangePassword): ?>
        <div class="warn"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Bitte ändern Sie Ihr Startpasswort.</div>
        <div class="card card-spaced">
            <h2>Passwort ändern</h2>
            <form method="POST" class="pass-form">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <label>Aktuelles Passwort
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label>Neues Passwort
                    <input type="password" name="new_password" autocomplete="new-password" required>
                </label>
                <label>Neues Passwort erneut
                    <input type="password" name="confirm_password" autocomplete="new-password" required>
                </label>
                <button type="submit" class="btn btn--primary">Ändern</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="grid">
        <section class="card card-full">
            <h2><i class="fas fa-list-check" aria-hidden="true"></i> Meine Aufgaben</h2>
            <div class="task-table-wrap">
                <table class="task-table">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Titel</th>
                        <th>Buchung</th>
                        <th>Fällig</th>
                        <th>Priorität</th>
                        <th>Status</th>
                        <th>Erledigt</th>
                        <th>Aktion</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($employeeTasks)): ?>
                        <tr><td colspan="9" class="empty-row">Keine Aufgaben zugewiesen.</td></tr>
                    <?php else: ?>
                        <?php foreach ($employeeTasks as $task): ?>
                            <?php
                            $taskId = (int)($task['id'] ?? 0);
                            $taskStatus = (string)($task['status'] ?? 'assigned');
                            $taskPriority = (string)($task['priority'] ?? 'normal');
                            $taskDone = $taskStatus === 'done';
                            $taskBadgeClass = match ($taskStatus) {
                                'done' => 'task-done',
                                'accepted' => 'task-accepted',
                                'in_progress' => 'task-in-progress',
                                'blocked' => 'task-blocked',
                                'canceled' => 'task-canceled',
                                'new' => 'task-assigned',
                                default => 'task-assigned',
                            };
                            ?>
                            <tr>
                                <td data-label="ID"><?= $taskId ?></td>
                                <td data-label="Service"><?= e((string)($task['service_type'] ?? '')) ?></td>
                                <td data-label="Titel">
                                    <strong><?= e((string)($task['title'] ?? '')) ?></strong>
                                    <?php if ((string)($task['description'] ?? '') !== ''): ?>
                                        <div class="task-description"><?= e((string)$task['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Buchung"><?= (int)($task['booking_id'] ?? 0) > 0 ? '#' . (int)$task['booking_id'] : '-' ?></td>
                                <td data-label="Fällig"><?= (string)($task['due_date'] ?? '') !== '' ? e((string)$task['due_date']) : '-' ?></td>
                                <td data-label="Priorität"><?= e((string)($taskPriorityLabels[$taskPriority] ?? $taskPriority)) ?></td>
                                <td data-label="Status"><span class="task-badge <?= e($taskBadgeClass) ?>"><?= e((string)($taskStatusLabels[$taskStatus] ?? $taskStatus)) ?></span></td>
                                <td data-label="Erledigt">
                                    <span class="task-badge <?= $taskDone ? 'task-complete-yes' : 'task-complete-no' ?>">
                                        <?= $taskDone ? 'Ja' : 'Nein' ?>
                                    </span>
                                </td>
                                <td data-label="Aktion">
                                    <form method="POST" class="task-status-form">
                                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                        <input type="hidden" name="action" value="task_set_status">
                                        <input type="hidden" name="task_id" value="<?= $taskId ?>">
                                        <select name="status" required>
                                            <?php foreach ($taskStatusLabels as $statusKey => $statusLabel): ?>
                                                <option value="<?= e($statusKey) ?>" <?= $taskStatus === $statusKey ? 'selected' : '' ?>><?= e((string)$statusLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn--primary btn--sm">Speichern</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2><i class="fas fa-user" aria-hidden="true"></i> Profil</h2>
            <div class="meta">
                <div class="meta-item"><small>Name</small><?= e($employeeName) ?></div>
                <div class="meta-item"><small>Rolle</small><?= e($roleLabel) ?></div>
                <div class="meta-item"><small>E-Mail</small><?= e($employeeEmail) ?></div>
                <div class="meta-item"><small>Status</small><span class="badge <?= $employeeStatus === 'active' ? 'active' : 'inactive' ?>"><?= e($employeeStatusLabel) ?></span></div>
            </div>
        </section>

        <section class="card">
            <h2><i class="fas fa-bullseye" aria-hidden="true"></i> KPI Ziele</h2>
            <?php if (empty($kpiTargets)): ?>
                <p class="card-empty">Keine KPI Ziele definiert.</p>
            <?php else: ?>
                <table class="kpi-table">
                    <tbody>
                    <?php foreach ($kpiTargets as $key => $value): ?>
                        <tr>
                            <td><?= e($readable((string)$key)) ?></td>
                            <td><?= e((string)$value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2><i class="fas fa-clipboard-list" aria-hidden="true"></i> Aufgabenbereiche</h2>
            <?php if (empty($responsibilities)): ?>
                <p class="card-empty">Keine Daten.</p>
            <?php else: ?>
                <ul class="tick-list">
                    <?php foreach ($responsibilities as $item): ?>
                        <li><?= e((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2><i class="fas fa-square-check" aria-hidden="true"></i> Checkliste</h2>
            <?php if (empty($checklist)): ?>
                <p class="card-empty">Keine Daten.</p>
            <?php else: ?>
                <ul class="tick-list tick-list--check">
                    <?php foreach ($checklist as $item): ?>
                        <li><?= e((string)$item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card card-full">
            <h2><i class="fas fa-shield-halved" aria-hidden="true"></i> Rechte</h2>
            <?php if (empty($permissions)): ?>
                <p class="card-empty">Keine Rechte eingetragen.</p>
            <?php else: ?>
                <ul class="chip-list">
                    <?php foreach ($permissions as $perm): ?>
                        <li><?= e($readable((string)$perm)) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php if (!$mustChangePassword): ?>
<div id="passwordModal" class="password-modal <?= $openPasswordModal ? 'open' : '' ?>" aria-hidden="<?= $openPasswordModal ? 'false' : 'true' ?>">
    <div class="password-modal-content">
        <div class="password-modal-header">
            <h3>Passwort ändern</h3>
            <button type="button" class="password-close" id="closePasswordModal" aria-label="Schließen">×</button>
        </div>
        <form method="POST" class="modal-pass-form">
            <input type="hidden" name="action" value="change_password">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <label>Aktuelles Passwort
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label>Neues Passwort
                <input type="password" name="new_password" autocomplete="new-password" required>
            </label>
            <label>Neues Passwort erneut
                <input type="password" name="confirm_password" autocomplete="new-password" required>
            </label>
            <button type="submit" class="btn btn--primary btn--block">Ändern</button>
        </form>
    </div>
</div>
<?php endif; ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.message').forEach((msg) => {
            setTimeout(() => {
                msg.style.transition = 'opacity 0.4s ease';
                msg.style.opacity = '0';
                setTimeout(() => msg.remove(), 450);
            }, 10000);
        });

        const openBtn = document.getElementById('openPasswordModal');
        const closeBtn = document.getElementById('closePasswordModal');
        const modal = document.getElementById('passwordModal');

        if (!modal) {
            return;
        }

        const openModal = () => {
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
        };

        const closeModal = () => {
            modal.classList.remove('open');
            modal.setAttribute('aria-hidden', 'true');
        };

        if (openBtn) {
            openBtn.addEventListener('click', openModal);
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', closeModal);
        }

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
    });
</script>
</body>
</html>
