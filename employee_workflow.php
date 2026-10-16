<?php
declare(strict_types=1);

function workflowDataDir(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function workflowNormalize(string $value): string {
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }
    return strtolower($value);
}

function workflowReadJsonArray(string $path, array $fallback): array {
    if (!is_file($path)) {
        return $fallback;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $fallback;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $fallback;
    }

    return $decoded;
}

function workflowWriteJsonArray(string $path, array $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function workflowServiceTypesPath(): string {
    return workflowDataDir() . '/service_types.json';
}

function workflowDefaultServiceTypes(): array {
    return [
        'Solaranlagen Montage',
        'Gartenarbeiten',
        'Entruempelung',
        'Umzugshilfe',
        'Haushaltsaufloesung',
        'Elektrik Service',
        'Wartung und Support',
    ];
}

function workflowSanitizeServiceTypes(array $types): array {
    $seen = [];
    $clean = [];

    foreach ($types as $type) {
        $label = trim((string)$type);
        if ($label === '') {
            continue;
        }
        if (strlen($label) > 80) {
            $label = substr($label, 0, 80);
        }

        $norm = workflowNormalize($label);
        if (isset($seen[$norm])) {
            continue;
        }

        $seen[$norm] = true;
        $clean[] = $label;
    }

    return array_values($clean);
}

function workflowReadServiceTypes(): array {
    $path = workflowServiceTypesPath();
    $types = workflowReadJsonArray($path, []);
    if (!is_array($types)) {
        $types = [];
    }

    $types = workflowSanitizeServiceTypes($types);
    if (empty($types)) {
        $types = workflowDefaultServiceTypes();
        workflowWriteJsonArray($path, $types);
    }

    return $types;
}

function workflowWriteServiceTypes(array $types): bool {
    $clean = workflowSanitizeServiceTypes($types);
    if (empty($clean)) {
        return false;
    }

    return workflowWriteJsonArray(workflowServiceTypesPath(), $clean);
}

function workflowAddServiceType(string $label): bool {
    $label = trim($label);
    if ($label === '') {
        return false;
    }

    $types = workflowReadServiceTypes();
    $norm = workflowNormalize($label);
    foreach ($types as $existing) {
        if (workflowNormalize((string)$existing) === $norm) {
            return true;
        }
    }

    $types[] = $label;
    return workflowWriteServiceTypes($types);
}

function workflowDeleteServiceType(string $label): bool {
    $label = trim($label);
    if ($label === '') {
        return false;
    }

    $types = workflowReadServiceTypes();
    $norm = workflowNormalize($label);
    $filtered = array_values(array_filter(
        $types,
        static fn($item): bool => workflowNormalize((string)$item) !== $norm
    ));

    if (count($filtered) === count($types)) {
        return false;
    }

    if (empty($filtered)) {
        return false;
    }

    return workflowWriteServiceTypes($filtered);
}

function workflowTaskStatusLabels(): array {
    return [
        'assigned' => 'Zugewiesen',
        'accepted' => 'Angenommen',
        'in_progress' => 'In Arbeit',
        'done' => 'Erledigt',
        'blocked' => 'Blockiert',
        'canceled' => 'Abgebrochen',
    ];
}

function workflowTaskPriorityLabels(): array {
    return [
        'normal' => 'Normal',
        'high' => 'Hoch',
        'urgent' => 'Dringend',
    ];
}

function workflowEmployeeTasksPath(): string {
    return workflowDataDir() . '/employee_tasks.json';
}

function workflowReadEmployeeTasks(): array {
    $path = workflowEmployeeTasksPath();
    $raw = workflowReadJsonArray($path, []);
    if (!is_array($raw)) {
        return [];
    }

    $statuses = workflowTaskStatusLabels();
    $priorities = workflowTaskPriorityLabels();
    $tasks = [];

    foreach ($raw as $task) {
        if (!is_array($task)) {
            continue;
        }

        $status = (string)($task['status'] ?? 'assigned');
        if ($status === 'new') {
            $status = 'assigned';
        }
        if (!isset($statuses[$status])) {
            $status = 'assigned';
        }

        $priority = (string)($task['priority'] ?? 'normal');
        if (!isset($priorities[$priority])) {
            $priority = 'normal';
        }

        $tasks[] = [
            'id' => (int)($task['id'] ?? 0),
            'employee_id' => (int)($task['employee_id'] ?? 0),
            'employee_name' => (string)($task['employee_name'] ?? ''),
            'employee_role' => (string)($task['employee_role'] ?? ''),
            'service_type' => (string)($task['service_type'] ?? 'Allgemein'),
            'booking_id' => isset($task['booking_id']) && (int)$task['booking_id'] > 0 ? (int)$task['booking_id'] : null,
            'title' => (string)($task['title'] ?? ''),
            'description' => (string)($task['description'] ?? ''),
            'priority' => $priority,
            'status' => $status,
            'due_date' => (string)($task['due_date'] ?? ''),
            'created_by' => (string)($task['created_by'] ?? 'admin'),
            'created_at' => (string)($task['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => (string)($task['updated_at'] ?? date('Y-m-d H:i:s')),
            'completed_at' => (string)($task['completed_at'] ?? ''),
            'employee_seen' => array_key_exists('employee_seen', $task) ? (bool)$task['employee_seen'] : true,
            'employee_seen_at' => (string)($task['employee_seen_at'] ?? ''),
        ];
    }

    usort($tasks, static function (array $a, array $b): int {
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return $tasks;
}

function workflowWriteEmployeeTasks(array $tasks): bool {
    return workflowWriteJsonArray(workflowEmployeeTasksPath(), array_values($tasks));
}

function workflowNextEmployeeTaskId(array $tasks): int {
    $maxId = 0;
    foreach ($tasks as $task) {
        $id = (int)(is_array($task) ? ($task['id'] ?? 0) : 0);
        if ($id > $maxId) {
            $maxId = $id;
        }
    }
    return $maxId + 1;
}

function workflowIsValidDate(string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    return checkdate($m, $d, $y);
}

function workflowCreateEmployeeTask(array $payload): ?array {
    $employeeId = (int)($payload['employee_id'] ?? 0);
    $employeeName = trim((string)($payload['employee_name'] ?? ''));
    $employeeRole = trim((string)($payload['employee_role'] ?? ''));
    $serviceType = trim((string)($payload['service_type'] ?? 'Allgemein'));
    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $dueDate = trim((string)($payload['due_date'] ?? ''));
    $priority = (string)($payload['priority'] ?? 'normal');
    $createdBy = trim((string)($payload['created_by'] ?? 'admin'));
    $bookingId = (int)($payload['booking_id'] ?? 0);

    if ($employeeId <= 0 || $title === '') {
        return null;
    }

    $priorities = workflowTaskPriorityLabels();
    if (!isset($priorities[$priority])) {
        $priority = 'normal';
    }

    if ($serviceType === '') {
        $serviceType = 'Allgemein';
    }

    if ($dueDate !== '' && !workflowIsValidDate($dueDate)) {
        $dueDate = '';
    }

    $tasks = workflowReadEmployeeTasks();
    $taskId = workflowNextEmployeeTaskId($tasks);
    $now = date('Y-m-d H:i:s');

    $task = [
        'id' => $taskId,
        'employee_id' => $employeeId,
        'employee_name' => $employeeName,
        'employee_role' => $employeeRole,
        'service_type' => $serviceType,
        'booking_id' => $bookingId > 0 ? $bookingId : null,
        'title' => $title,
        'description' => $description,
        'priority' => $priority,
        'status' => 'assigned',
        'due_date' => $dueDate,
        'created_by' => $createdBy !== '' ? $createdBy : 'admin',
        'created_at' => $now,
        'updated_at' => $now,
        'completed_at' => '',
        'employee_seen' => false,
        'employee_seen_at' => '',
    ];

    $tasks[] = $task;
    if (!workflowWriteEmployeeTasks($tasks)) {
        return null;
    }

    return $task;
}

function workflowUpdateEmployeeTaskStatus(int $taskId, string $status): bool {
    if ($taskId <= 0) {
        return false;
    }

    $statuses = workflowTaskStatusLabels();
    if (!isset($statuses[$status])) {
        return false;
    }

    $tasks = workflowReadEmployeeTasks();
    $changed = false;

    foreach ($tasks as &$task) {
        if (!is_array($task) || (int)($task['id'] ?? 0) !== $taskId) {
            continue;
        }

        $task['status'] = $status;
        $task['updated_at'] = date('Y-m-d H:i:s');
        if ($status === 'done') {
            $task['completed_at'] = date('Y-m-d H:i:s');
        } else {
            $task['completed_at'] = '';
        }
        $changed = true;
        break;
    }
    unset($task);

    if (!$changed) {
        return false;
    }

    return workflowWriteEmployeeTasks($tasks);
}

function workflowAcknowledgeEmployeeTask(int $taskId, int $employeeId = 0): bool {
    if ($taskId <= 0) {
        return false;
    }

    $tasks = workflowReadEmployeeTasks();
    $changed = false;
    $found = false;
    $now = date('Y-m-d H:i:s');

    foreach ($tasks as &$task) {
        if (!is_array($task) || (int)($task['id'] ?? 0) !== $taskId) {
            continue;
        }
        if ($employeeId > 0 && (int)($task['employee_id'] ?? 0) !== $employeeId) {
            continue;
        }

        $found = true;
        if (!((bool)($task['employee_seen'] ?? false))) {
            $task['employee_seen'] = true;
            $task['employee_seen_at'] = $now;
            $task['updated_at'] = $now;
            $changed = true;
        }
        break;
    }
    unset($task);

    if (!$found) {
        return false;
    }
    if (!$changed) {
        return true;
    }

    return workflowWriteEmployeeTasks($tasks);
}

function workflowFindEmployeeTaskById(int $taskId): ?array {
    if ($taskId <= 0) {
        return null;
    }

    $tasks = workflowReadEmployeeTasks();
    foreach ($tasks as $task) {
        if (!is_array($task) || (int)($task['id'] ?? 0) !== $taskId) {
            continue;
        }
        return $task;
    }

    return null;
}

function workflowReassignEmployeeTask(
    int $taskId,
    int $employeeId,
    string $employeeName,
    string $employeeRole
): bool {
    if ($taskId <= 0 || $employeeId <= 0) {
        return false;
    }

    $employeeName = trim($employeeName);
    $employeeRole = trim($employeeRole);

    $tasks = workflowReadEmployeeTasks();
    $changed = false;
    $now = date('Y-m-d H:i:s');

    foreach ($tasks as &$task) {
        if (!is_array($task) || (int)($task['id'] ?? 0) !== $taskId) {
            continue;
        }

        if ((string)($task['status'] ?? '') === 'done') {
            unset($task);
            return false;
        }

        $task['employee_id'] = $employeeId;
        $task['employee_name'] = $employeeName;
        $task['employee_role'] = $employeeRole;
        $task['status'] = 'assigned';
        $task['completed_at'] = '';
        $task['employee_seen'] = false;
        $task['employee_seen_at'] = '';
        $task['updated_at'] = $now;
        $changed = true;
        break;
    }
    unset($task);

    if (!$changed) {
        return false;
    }

    return workflowWriteEmployeeTasks($tasks);
}

function workflowDeleteEmployeeTask(int $taskId): bool {
    if ($taskId <= 0) {
        return false;
    }

    $tasks = workflowReadEmployeeTasks();
    $before = count($tasks);
    $tasks = array_values(array_filter(
        $tasks,
        static fn($task): bool => (int)(is_array($task) ? ($task['id'] ?? 0) : 0) !== $taskId
    ));

    if (count($tasks) === $before) {
        return false;
    }

    return workflowWriteEmployeeTasks($tasks);
}

function workflowFilterEmployeeTasks(array $tasks, int $employeeId = 0, string $status = 'all', string $search = ''): array {
    $filtered = array_values(array_filter($tasks, static function (array $task) use ($employeeId, $status, $search): bool {
        if ($employeeId > 0 && (int)($task['employee_id'] ?? 0) !== $employeeId) {
            return false;
        }

        if ($status !== 'all' && (string)($task['status'] ?? '') !== $status) {
            return false;
        }

        if ($search !== '') {
            $needle = workflowNormalize($search);
            $haystack = workflowNormalize(
                (string)($task['id'] ?? '') . ' '
                . (string)($task['employee_name'] ?? '') . ' '
                . (string)($task['service_type'] ?? '') . ' '
                . (string)($task['title'] ?? '') . ' '
                . (string)($task['description'] ?? '') . ' '
                . (string)($task['booking_id'] ?? '')
            );
            if (strpos($haystack, $needle) === false) {
                return false;
            }
        }

        return true;
    }));

    usort($filtered, static function (array $a, array $b): int {
        $aDue = (string)($a['due_date'] ?? '');
        $bDue = (string)($b['due_date'] ?? '');
        if ($aDue !== '' && $bDue !== '' && $aDue !== $bDue) {
            return strcmp($aDue, $bDue);
        }
        if ($aDue !== '' && $bDue === '') {
            return -1;
        }
        if ($aDue === '' && $bDue !== '') {
            return 1;
        }
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });

    return $filtered;
}

function workflowEmployeeTaskStats(array $tasks): array {
    $stats = [];

    foreach ($tasks as $task) {
        if (!is_array($task)) {
            continue;
        }

        $employeeId = (int)($task['employee_id'] ?? 0);
        if ($employeeId <= 0) {
            continue;
        }

        if (!isset($stats[$employeeId])) {
            $stats[$employeeId] = [
                'total' => 0,
                'done' => 0,
                'not_done' => 0,
            ];
        }

        $stats[$employeeId]['total']++;
        if ((string)($task['status'] ?? '') === 'done') {
            $stats[$employeeId]['done']++;
        } else {
            $stats[$employeeId]['not_done']++;
        }
    }

    return $stats;
}

function workflowBookingServiceMapPath(): string {
    return workflowDataDir() . '/booking_services.json';
}

function workflowReadBookingServiceMap(): array {
    $raw = workflowReadJsonArray(workflowBookingServiceMapPath(), []);
    if (!is_array($raw)) {
        return [];
    }

    $map = [];
    foreach ($raw as $bookingId => $serviceType) {
        $id = (int)$bookingId;
        $label = trim((string)$serviceType);
        if ($id <= 0 || $label === '') {
            continue;
        }
        $map[(string)$id] = $label;
    }
    return $map;
}

function workflowWriteBookingServiceMap(array $map): bool {
    $clean = [];
    foreach ($map as $bookingId => $serviceType) {
        $id = (int)$bookingId;
        $label = trim((string)$serviceType);
        if ($id <= 0 || $label === '') {
            continue;
        }
        $clean[(string)$id] = $label;
    }

    return workflowWriteJsonArray(workflowBookingServiceMapPath(), $clean);
}

function workflowSetBookingServiceType(int $bookingId, string $serviceType): bool {
    if ($bookingId <= 0) {
        return false;
    }
    $serviceType = trim($serviceType);
    if ($serviceType === '') {
        return false;
    }

    $map = workflowReadBookingServiceMap();
    $map[(string)$bookingId] = $serviceType;
    return workflowWriteBookingServiceMap($map);
}

function workflowGetBookingServiceType(int $bookingId): string {
    if ($bookingId <= 0) {
        return '';
    }
    $map = workflowReadBookingServiceMap();
    return (string)($map[(string)$bookingId] ?? '');
}

function workflowDeleteBookingServiceType(int $bookingId): bool {
    if ($bookingId <= 0) {
        return false;
    }

    $map = workflowReadBookingServiceMap();
    $key = (string)$bookingId;
    if (!isset($map[$key])) {
        return true;
    }

    unset($map[$key]);
    return workflowWriteBookingServiceMap($map);
}
