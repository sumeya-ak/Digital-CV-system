<?php
/**
 * Users Management API Endpoint
 * Updated for new schema: user_id, password_hash, role_id, is_active, profile tables
 */

// Configure session before starting
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

session_start();

// Disable error display
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';
$userId = $_GET['id'] ?? '';

switch ($action) {
    case 'list':
        $user = requireRole(['manager']);
        listUsers();
        break;

    case 'get':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        getUser($userId);
        break;

    case 'create':
        $user = requireRole(['manager']);
        createUser();
        break;

    case 'update':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        updateUser($userId);
        break;

    case 'deactivate':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        toggleUserActive($userId, false);
        break;

    case 'activate':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        toggleUserActive($userId, true);
        break;

    case 'delete':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        deleteUser($userId);
        break;

    case 'approve':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        approveUser($userId);
        break;

    case 'reject':
        $user = requireRole(['manager']);
        if (!$userId) jsonResponse(['error' => 'User ID required'], 400);
        rejectUser($userId);
        break;

    case 'profile':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        updateProfile($user);
        break;

    case 'notifications':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        getNotifications($user);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: list, get, create, update, deactivate, activate, delete, profile, notifications'], 400);
}

function listUsers() {
    $pdo = getDBConnection();

    // Check if account_status column exists
    $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE 'account_status'");
    $stmt->execute();
    $columnExists = $stmt->fetch();
    
    if ($columnExists) {
        $sql = "
            SELECT u.user_id, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.email_verified,
                   u.account_status, u.created_at, r.role_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE 1=1
        ";
    } else {
        $sql = "
            SELECT u.user_id, u.email, u.first_name, u.last_name, u.phone, u.is_active, u.email_verified,
                   u.created_at, r.role_name
            FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE 1=1
        ";
    }
    $params = [];

    if (isset($_GET['role']) && $_GET['role']) {
        $sql .= " AND r.role_name = ?";
        $params[] = $_GET['role'];
    }

    $sql .= " ORDER BY u.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();

    // Add profile info
    foreach ($users as &$u) {
        $u['id'] = $u['user_id'];
        $u['role'] = $u['role_name'];
        if ($u['role_name'] === 'student') {
            $stmt = $pdo->prepare("SELECT institution, student_id_number FROM student_profiles WHERE user_id = ?");
            $stmt->execute([$u['user_id']]);
            $profile = $stmt->fetch();
            $u['institution'] = $profile['institution'] ?? null;
            $u['student_id_number'] = $profile['student_id_number'] ?? null;
        }
    }

    jsonResponse(['users' => $users]);
}

function getUser($id) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT u.user_id, u.email, u.first_name, u.last_name, u.phone, u.profile_picture,
               u.is_active, u.email_verified, u.last_login, u.created_at, r.role_name
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        WHERE u.user_id = ?
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    if (!$user) jsonResponse(['error' => 'User not found'], 404);

    $user['id'] = $user['user_id'];
    $user['role'] = $user['role_name'];

    jsonResponse(['user' => $user]);
}

function createUser() {
    $data = getInputData();
    validateRequired($data, ['email', 'password', 'first_name', 'last_name', 'role']);

    $result = registerUser($data);
    if (isset($result['error'])) {
        jsonResponse($result, 400);
    }

    jsonResponse($result, 201);
}

function updateUser($id) {
    $data = getInputData();
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'User not found'], 404);

    $fields = [];
    $params = [];

    if (isset($data['first_name'])) { $fields[] = "first_name = ?"; $params[] = $data['first_name']; }
    if (isset($data['last_name'])) { $fields[] = "last_name = ?"; $params[] = $data['last_name']; }
    if (isset($data['phone'])) { $fields[] = "phone = ?"; $params[] = $data['phone']; }
    if (isset($data['role'])) {
        $roleId = getRoleId($data['role']);
        if ($roleId) { $fields[] = "role_id = ?"; $params[] = $roleId; }
    }
    if (isset($data['password'])) { $fields[] = "password_hash = ?"; $params[] = password_hash($data['password'], PASSWORD_BCRYPT); }

    if (empty($fields)) jsonResponse(['error' => 'No fields to update'], 400);

    $params[] = $id;
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    logAction($_SESSION['user_id'], 'USER_UPDATED', 'user', $id);

    jsonResponse(['success' => true]);
}

function toggleUserActive($id, $active) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'User not found'], 404);

    if ($id == $_SESSION['user_id']) {
        jsonResponse(['error' => 'Cannot modify your own account status'], 400);
    }

    $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
    $stmt->execute([$active, $id]);

    $actionName = $active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
    logAction($_SESSION['user_id'], $actionName, 'user', $id);

    jsonResponse(['success' => true, 'is_active' => $active]);
}

function deleteUser($id) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'User not found'], 404);

    if ($id == $_SESSION['user_id']) {
        jsonResponse(['error' => 'Cannot delete your own account'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
    $stmt->execute([$id]);

    logAction($_SESSION['user_id'], 'USER_DELETED', 'user', $id);

    jsonResponse(['success' => true]);
}

function updateProfile($user) {
    $data = getInputData();
    $pdo = getDBConnection();

    $fields = [];
    $params = [];

    if (isset($data['first_name'])) { $fields[] = "first_name = ?"; $params[] = $data['first_name']; }
    if (isset($data['last_name'])) { $fields[] = "last_name = ?"; $params[] = $data['last_name']; }
    if (isset($data['phone'])) { $fields[] = "phone = ?"; $params[] = $data['phone']; }

    if (!empty($fields)) {
        $params[] = $user['user_id'];
        $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    // Update role-specific profile
    if ($user['role_name'] === 'student') {
        $sFields = []; $sParams = [];
        if (isset($data['institution'])) { $sFields[] = "institution = ?"; $sParams[] = $data['institution']; }
        if (isset($data['department'])) { $sFields[] = "department = ?"; $sParams[] = $data['department']; }
        if (isset($data['graduation_year'])) { $sFields[] = "graduation_year = ?"; $sParams[] = $data['graduation_year']; }
        if (isset($data['degree_program'])) { $sFields[] = "degree_program = ?"; $sParams[] = $data['degree_program']; }
        if (isset($data['linkedin_url'])) { $sFields[] = "linkedin_url = ?"; $sParams[] = $data['linkedin_url']; }
        if (isset($data['portfolio_url'])) { $sFields[] = "portfolio_url = ?"; $sParams[] = $data['portfolio_url']; }
        if (isset($data['summary'])) { $sFields[] = "summary = ?"; $sParams[] = $data['summary']; }

        if (!empty($sFields)) {
            $sParams[] = $user['user_id'];
            $sql = "UPDATE student_profiles SET " . implode(', ', $sFields) . " WHERE user_id = ?";
            $pdo->prepare($sql)->execute($sParams);
        }
    }

    logAction($user['user_id'], 'PROFILE_UPDATED', 'user', $user['user_id']);

    jsonResponse(['success' => true]);
}

function getNotifications($user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT notification_id, title, message, type, related_entity_type, related_entity_id,
               is_read, created_at, read_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user['user_id']]);
    $notifications = $stmt->fetchAll();

    // Mark as read if requested
    if (isset($_GET['mark_read']) && $_GET['mark_read'] === 'true') {
        $pdo->prepare("UPDATE notifications SET is_read = TRUE, read_at = NOW() WHERE user_id = ? AND is_read = FALSE")->execute([$user['user_id']]);
    }

    jsonResponse(['notifications' => $notifications]);
}

function approveUser($userId) {
    $pdo = getDBConnection();

    // Get user info
    $stmt = $pdo->prepare("SELECT user_id, email, role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    // Only HR/recruiter accounts need approval
    if (!in_array($user['role_name'], ['hr', 'recruiter'])) {
        jsonResponse(['error' => 'Only HR/Recruiter accounts can be approved'], 400);
    }

    // Update account status
    $stmt = $pdo->prepare("UPDATE users SET account_status = 'approved' WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Create notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_entity_type, related_entity_id)
        VALUES (?, 'Account Approved', 'Your account has been approved. You now have full access to the system.', 'success', 'user', ?)
    ");
    $stmt->execute([$userId, $userId]);

    logAction($_SESSION['user_id'], 'USER_APPROVED', 'user', $userId);

    jsonResponse(['success' => true, 'message' => 'User approved successfully']);
}

function rejectUser($userId) {
    $pdo = getDBConnection();

    // Get user info
    $stmt = $pdo->prepare("SELECT user_id, email, role_name FROM users u JOIN roles r ON u.role_id = r.role_id WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    // Only HR/recruiter accounts can be rejected
    if (!in_array($user['role_name'], ['hr', 'recruiter'])) {
        jsonResponse(['error' => 'Only HR/Recruiter accounts can be rejected'], 400);
    }

    // Update account status
    $stmt = $pdo->prepare("UPDATE users SET account_status = 'rejected' WHERE user_id = ?");
    $stmt->execute([$userId]);

    // Create notification
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_entity_type, related_entity_id)
        VALUES (?, 'Account Rejected', 'Your account has been rejected. Please contact support for more information.', 'error', 'user', ?)
    ");
    $stmt->execute([$userId, $userId]);

    logAction($_SESSION['user_id'], 'USER_REJECTED', 'user', $userId);

    jsonResponse(['success' => true, 'message' => 'User rejected successfully']);
}
