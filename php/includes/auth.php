<?php
/**
 * Authentication Functions
 * Uses new schema: user_id, password_hash, role_id (FK to roles table)
 */

require_once __DIR__ . '/db.php';

/**
 * Register a new user
 */
function registerUser($data) {
    $pdo = getDBConnection();

    // Check if email exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmt->execute([$data['email']]);
    if ($stmt->fetch()) {
        return ['error' => 'Email already registered'];
    }

    $roleId = getRoleId($data['role']);
    if (!$roleId) {
        return ['error' => 'Invalid role: ' . $data['role']];
    }

    $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (email, password_hash, first_name, last_name, phone, role_id, account_status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['email'],
            $hashedPassword,
            $data['first_name'],
            $data['last_name'],
            $data['phone'] ?? null,
            $roleId,
            $data['account_status'] ?? 'approved'
        ]);
        $userId = $pdo->lastInsertId();

        // Create role-specific profile
        if ($data['role'] === 'student') {
            $stmt = $pdo->prepare("
                INSERT INTO student_profiles (user_id, student_id_number, institution, department, graduation_year, degree_program, linkedin_url, portfolio_url, summary)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $data['student_id_number'] ?? 'STD' . str_pad($userId, 6, '0', STR_PAD_LEFT),
                $data['institution'] ?? '',
                $data['department'] ?? null,
                $data['graduation_year'] ?? null,
                $data['degree_program'] ?? null,
                $data['linkedin_url'] ?? null,
                $data['portfolio_url'] ?? null,
                $data['summary'] ?? null
            ]);
        } elseif ($data['role'] === 'supervisor') {
            $stmt = $pdo->prepare("
                INSERT INTO supervisor_profiles (user_id, employee_id, department, specialization)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $data['employee_id'] ?? 'SUP' . str_pad($userId, 6, '0', STR_PAD_LEFT),
                $data['department'] ?? null,
                $data['specialization'] ?? null
            ]);
        } elseif ($data['role'] === 'recruiter') {
            $stmt = $pdo->prepare("
                INSERT INTO recruiter_profiles (user_id, job_title, employee_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $data['job_title'] ?? null,
                $data['employee_id'] ?? 'REC' . str_pad($userId, 6, '0', STR_PAD_LEFT)
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['error' => 'Registration failed: ' . $e->getMessage()];
    }

    logAction($userId, 'USER_REGISTERED', 'user', $userId, null, [
        'email' => $data['email'],
        'role' => $data['role']
    ]);

    // Create welcome notification
    createNotification($userId, 'Welcome!', 'Your account has been created successfully.', 'system', null);

    return [
        'success' => true,
        'user' => [
            'user_id' => $userId,
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'role' => $data['role'],
            'institution' => $data['institution'] ?? null
        ]
    ];
}

/**
 * Login user
 */
function loginUser($email, $password) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.password_hash, u.first_name, u.last_name, u.phone,
               u.role_id, u.is_active, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return ['error' => 'Invalid email or password'];
    }

    if (!$user['is_active']) {
        return ['error' => 'Account is deactivated. Contact administrator.'];
    }

    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['user_role'] = $user['role_name'];
    $_SESSION['user_email'] = $user['email'];

    // Update last_login
    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
    $stmt->execute([$user['user_id']]);

    logAction($user['user_id'], 'USER_LOGIN', 'user', $user['user_id']);

    return [
        'success' => true,
        'user' => [
            'user_id' => $user['user_id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role_name'],
            'phone' => $user['phone']
        ]
    ];
}

/**
 * Logout user
 */
function logoutUser() {
    if (isset($_SESSION['user_id'])) {
        logAction($_SESSION['user_id'], 'USER_LOGOUT', 'user', $_SESSION['user_id']);
    }
    session_destroy();
    return ['success' => true];
}

/**
 * Get current authenticated user with role_name
 */
function getAuthUser() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name, u.phone, u.profile_picture,
               u.role_id, u.is_active, u.email_verified, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user) {
        $user['id'] = $user['user_id']; // compatibility alias
        $user['role'] = $user['role_name']; // compatibility alias
    }

    return $user;
}

/**
 * Check if user has required role (by role_name)
 * Also checks account_status for HR/recruiter users
 */
function requireRole($roles) {
    $user = getAuthUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    if (!in_array($user['role_name'], (array)$roles)) {
        http_response_code(403);
        echo json_encode(['error' => 'Insufficient permissions']);
        exit;
    }

    // Check account_status for HR/recruiter users
    if (in_array($user['role_name'], ['hr', 'recruiter'])) {
        // Since account_status field might not exist, check if user exists and is active
        $stmt = getDBConnection()->prepare("SELECT account_status FROM users WHERE user_id = ?");
        $stmt->execute([$user['user_id']]);
        $result = $stmt->fetch();
        
        // If account_status field doesn't exist, assume approved for existing users
        $accountStatus = $result ? $result['account_status'] : 'approved';
        
        if ($accountStatus !== 'approved') {
            http_response_code(403);
            echo json_encode(['error' => 'Your account is pending approval. Please wait for admin approval.']);
            exit;
        }
    }

    return $user;
}

/**
 * Log audit action (new schema: entity_type, entity_id, old_values, new_values)
 */
function logAction($userId, $action, $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
    $pdo = getDBConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $action,
        $entityType,
        $entityId,
        $oldValues ? json_encode($oldValues) : null,
        $newValues ? json_encode($newValues) : null,
        $ip,
        $ua
    ]);
}

/**
 * Create notification
 */
function createNotification($userId, $title, $message, $entityType = 'system', $entityId = null, $type = 'info') {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_entity_type, related_entity_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$userId, $title, $message, $type, $entityType, $entityId]);
}
