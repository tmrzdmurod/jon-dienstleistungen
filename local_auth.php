<?php
declare(strict_types=1);

function localAuthPath(): string {
    $dir = __DIR__ . '/tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir . '/local_auth.json';
}

function localAuthNormalize(string $value): string {
    $value = trim($value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value);
    }
    return strtolower($value);
}

function localAuthRead(): array {
    $path = localAuthPath();
    $empty = [
        'clients' => [],
        'employees' => [],
    ];

    if (!is_file($path)) {
        return $empty;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $empty;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return $empty;
    }

    if (!isset($data['clients']) || !is_array($data['clients'])) {
        $data['clients'] = [];
    }
    if (!isset($data['employees']) || !is_array($data['employees'])) {
        $data['employees'] = [];
    }

    return $data;
}

function localAuthWrite(array $data): bool {
    if (!isset($data['clients']) || !is_array($data['clients'])) {
        $data['clients'] = [];
    }
    if (!isset($data['employees']) || !is_array($data['employees'])) {
        $data['employees'] = [];
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return @file_put_contents(localAuthPath(), $json, LOCK_EX) !== false;
}

function localAuthSetClientPassword(int $userId, string $username, string $email, string $plainPassword): bool {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        return false;
    }

    $data = localAuthRead();
    $usernameNorm = localAuthNormalize($username);
    $emailNorm = localAuthNormalize($email);
    $updated = false;

    foreach ($data['clients'] as &$client) {
        if (!is_array($client)) {
            continue;
        }
        $existingUserId = (int)($client['user_id'] ?? 0);
        $existingUserNorm = localAuthNormalize((string)($client['username'] ?? ''));
        $existingEmailNorm = localAuthNormalize((string)($client['email'] ?? ''));
        if (($userId > 0 && $existingUserId === $userId) || $existingUserNorm === $usernameNorm || $existingEmailNorm === $emailNorm) {
            $client['user_id'] = $userId;
            $client['username'] = $username;
            $client['email'] = $email;
            $client['username_norm'] = $usernameNorm;
            $client['email_norm'] = $emailNorm;
            $client['password_hash'] = $hash;
            $client['updated_at'] = date('Y-m-d H:i:s');
            $updated = true;
            break;
        }
    }
    unset($client);

    if (!$updated) {
        $data['clients'][] = [
            'user_id' => $userId,
            'username' => $username,
            'email' => $email,
            'username_norm' => $usernameNorm,
            'email_norm' => $emailNorm,
            'password_hash' => $hash,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    return localAuthWrite($data);
}

function localAuthVerifyClient(string $login, string $plainPassword): ?array {
    $data = localAuthRead();
    $needle = localAuthNormalize($login);

    foreach ($data['clients'] as $client) {
        if (!is_array($client)) {
            continue;
        }
        $usernameNorm = (string)($client['username_norm'] ?? localAuthNormalize((string)($client['username'] ?? '')));
        $emailNorm = (string)($client['email_norm'] ?? localAuthNormalize((string)($client['email'] ?? '')));
        if ($needle !== $usernameNorm && $needle !== $emailNorm) {
            continue;
        }

        $hash = (string)($client['password_hash'] ?? '');
        if ($hash === '' || !password_verify($plainPassword, $hash)) {
            continue;
        }

        return [
            'user_id' => (int)($client['user_id'] ?? 0),
            'username' => (string)($client['username'] ?? ''),
            'email' => (string)($client['email'] ?? ''),
        ];
    }

    return null;
}

function localAuthRemoveClient(int $userId = 0, string $username = '', string $email = ''): bool {
    $usernameNorm = localAuthNormalize($username);
    $emailNorm = localAuthNormalize($email);
    if ($userId <= 0 && $usernameNorm === '' && $emailNorm === '') {
        return false;
    }

    $data = localAuthRead();
    $before = count($data['clients']);
    $data['clients'] = array_values(array_filter(
        $data['clients'],
        static function ($client) use ($userId, $usernameNorm, $emailNorm): bool {
            if (!is_array($client)) {
                return false;
            }

            $existingUserId = (int)($client['user_id'] ?? 0);
            $existingUsernameNorm = (string)($client['username_norm'] ?? localAuthNormalize((string)($client['username'] ?? '')));
            $existingEmailNorm = (string)($client['email_norm'] ?? localAuthNormalize((string)($client['email'] ?? '')));

            $matchById = $userId > 0 && $existingUserId === $userId;
            $matchByUsername = $usernameNorm !== '' && $existingUsernameNorm === $usernameNorm;
            $matchByEmail = $emailNorm !== '' && $existingEmailNorm === $emailNorm;

            return !($matchById || $matchByUsername || $matchByEmail);
        }
    ));

    if (count($data['clients']) === $before) {
        return false;
    }

    return localAuthWrite($data);
}

function localAuthSetEmployeePassword(
    int $employeeId,
    string $fullName,
    string $role,
    string $email,
    string $phone,
    string $status,
    string $profileData,
    string $plainPassword,
    bool $mustChangePassword = false
): bool {
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        return false;
    }

    $data = localAuthRead();
    $emailNorm = localAuthNormalize($email);
    $updated = false;

    foreach ($data['employees'] as &$employee) {
        if (!is_array($employee)) {
            continue;
        }
        $existingId = (int)($employee['employee_id'] ?? 0);
        $existingEmailNorm = localAuthNormalize((string)($employee['email'] ?? ''));
        if ($existingId === $employeeId || ($emailNorm !== '' && $existingEmailNorm === $emailNorm)) {
            $employee['employee_id'] = $employeeId;
            $employee['full_name'] = $fullName;
            $employee['role'] = $role;
            $employee['email'] = $email;
            $employee['email_norm'] = $emailNorm;
            $employee['phone'] = $phone;
            $employee['status'] = $status;
            $employee['profile_data'] = $profileData;
            $employee['password_hash'] = $hash;
            $employee['must_change_password'] = $mustChangePassword ? 1 : 0;
            $employee['updated_at'] = date('Y-m-d H:i:s');
            $updated = true;
            break;
        }
    }
    unset($employee);

    if (!$updated) {
        $data['employees'][] = [
            'employee_id' => $employeeId,
            'full_name' => $fullName,
            'role' => $role,
            'email' => $email,
            'email_norm' => $emailNorm,
            'phone' => $phone,
            'status' => $status,
            'profile_data' => $profileData,
            'password_hash' => $hash,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }

    return localAuthWrite($data);
}

function localAuthGetEmployeeById(int $employeeId): ?array {
    if ($employeeId <= 0) {
        return null;
    }

    $data = localAuthRead();
    foreach ($data['employees'] as $employee) {
        if (!is_array($employee)) {
            continue;
        }
        if ((int)($employee['employee_id'] ?? 0) !== $employeeId) {
            continue;
        }
        return $employee;
    }

    return null;
}

function localAuthVerifyEmployee(string $login, string $plainPassword): ?array {
    $data = localAuthRead();
    $needle = localAuthNormalize($login);

    foreach ($data['employees'] as $employee) {
        if (!is_array($employee)) {
            continue;
        }

        $fullNameNorm = localAuthNormalize((string)($employee['full_name'] ?? ''));
        $emailNorm = (string)($employee['email_norm'] ?? localAuthNormalize((string)($employee['email'] ?? '')));
        if ($needle !== $emailNorm && $needle !== $fullNameNorm) {
            continue;
        }

        $hash = (string)($employee['password_hash'] ?? '');
        if ($hash === '' || !password_verify($plainPassword, $hash)) {
            continue;
        }

        $status = (string)($employee['status'] ?? 'inactive');
        if ($status !== 'active') {
            continue;
        }

        return [
            'employee_id' => (int)($employee['employee_id'] ?? 0),
            'full_name' => (string)($employee['full_name'] ?? ''),
            'role' => (string)($employee['role'] ?? ''),
            'email' => (string)($employee['email'] ?? ''),
            'phone' => (string)($employee['phone'] ?? ''),
            'status' => $status,
            'profile_data' => (string)($employee['profile_data'] ?? '{}'),
            'must_change_password' => ((int)($employee['must_change_password'] ?? 0)) === 1,
        ];
    }

    return null;
}

function localAuthUpdateEmployeeStatus(int $employeeId, string $status): bool {
    if ($employeeId <= 0) {
        return false;
    }

    $data = localAuthRead();
    $changed = false;

    foreach ($data['employees'] as &$employee) {
        if (!is_array($employee)) {
            continue;
        }
        if ((int)($employee['employee_id'] ?? 0) !== $employeeId) {
            continue;
        }
        $employee['status'] = $status;
        $employee['updated_at'] = date('Y-m-d H:i:s');
        $changed = true;
        break;
    }
    unset($employee);

    if (!$changed) {
        return false;
    }

    return localAuthWrite($data);
}

function localAuthRemoveEmployee(int $employeeId): bool {
    if ($employeeId <= 0) {
        return false;
    }

    $data = localAuthRead();
    $before = count($data['employees']);
    $data['employees'] = array_values(array_filter(
        $data['employees'],
        static fn($employee): bool => (int)(is_array($employee) ? ($employee['employee_id'] ?? 0) : 0) !== $employeeId
    ));

    if (count($data['employees']) === $before) {
        return false;
    }

    return localAuthWrite($data);
}

function localAuthChangeEmployeePassword(int $employeeId, string $currentPassword, string $newPassword): bool {
    if ($employeeId <= 0) {
        return false;
    }

    $data = localAuthRead();
    $changed = false;

    foreach ($data['employees'] as &$employee) {
        if (!is_array($employee)) {
            continue;
        }
        if ((int)($employee['employee_id'] ?? 0) !== $employeeId) {
            continue;
        }

        $hash = (string)($employee['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            return false;
        }

        $employee['password_hash'] = $newHash;
        $employee['must_change_password'] = 0;
        $employee['updated_at'] = date('Y-m-d H:i:s');
        $changed = true;
        break;
    }
    unset($employee);

    if (!$changed) {
        return false;
    }

    return localAuthWrite($data);
}
