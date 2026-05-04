<?php
/**
 * QR Code API Endpoint
 * Updated for new schema: qr_id, cv_id (INT), unique_token, access_url, scan_count, is_active, expires_at
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
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate':
        $user = requireRole(['supervisor', 'manager']);
        generateQR();
        break;

    case 'get':
        $user = requireRole(['student', 'supervisor', 'recruiter', 'manager']);
        $cvId = $_GET['cv_id'] ?? '';
        if (!$cvId) jsonResponse(['error' => 'CV ID required'], 400);
        getQRCode($cvId, $user);
        break;

    case 'get_by_token':
        $token = $_GET['token'] ?? '';
        if (!$token) jsonResponse(['error' => 'Token required'], 400);
        getQRByToken($token);
        break;

    case 'verify':
        $user = requireRole(['manager']);
        $cvId = $_GET['cv_id'] ?? '';
        if (!$cvId) jsonResponse(['error' => 'CV ID required'], 400);
        verifyQRCode($cvId);
        break;

    case 'stats':
        $user = requireRole(['manager']);
        getQRStats();
        break;

    case 'log_access':
        logQRAccess();
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: generate, get, get_by_token, verify, stats, log_access'], 400);
}

function generateQR() {
    $data = getInputData();
    $cvId = $data['cv_id'] ?? '';

    if (!$cvId) jsonResponse(['error' => 'CV ID required'], 400);

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT cv_id, status, title FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if ($cv['status'] !== 'approved') jsonResponse(['error' => 'CV must be approved first'], 400);

    // Check if QR already exists
    $stmt = $pdo->prepare("SELECT qr_id FROM qr_codes WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'QR code already exists for this CV'], 400);
    }

    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $accessUrl = $baseUrl . '/public-cv.html?id=' . $cvId;
    $uniqueToken = generateUniqueToken();
    $expiryDays = (int)getSystemSetting('qr_code_expiry_days', '365');

    $stmt = $pdo->prepare("
        INSERT INTO qr_codes (cv_id, qr_code_data, unique_token, access_url, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
    ");
    $stmt->execute([$cvId, $accessUrl, $uniqueToken, $accessUrl, $expiryDays]);

    logAction($_SESSION['user_id'], 'QR_GENERATED', 'qr_code', $cvId);

    jsonResponse([
        'success' => true,
        'cv_id' => $cvId,
        'unique_token' => $uniqueToken,
        'access_url' => $accessUrl,
        'expires_at' => date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"))
    ]);
}

function getQRCode($cvId, $user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT q.*, c.title, c.status
        FROM qr_codes q
        JOIN cvs c ON q.cv_id = c.cv_id
        WHERE q.cv_id = ?
    ");
    $stmt->execute([$cvId]);
    $qr = $stmt->fetch();

    if (!$qr) {
        jsonResponse(['error' => 'QR code not found'], 404);
    }

    if ($user['role_name'] === 'student') {
        $stmt = $pdo->prepare("SELECT user_id FROM cvs WHERE cv_id = ?");
        $stmt->execute([$cvId]);
        $cv = $stmt->fetch();
        if ($cv['user_id'] != $user['user_id']) {
            jsonResponse(['error' => 'Access denied'], 403);
        }
    }

    jsonResponse(['qr' => $qr]);
}

function getQRByToken($token) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT q.*, c.title, c.status, c.personal_summary,
               u.first_name, u.last_name, u.email as student_email
        FROM qr_codes q
        JOIN cvs c ON q.cv_id = c.cv_id
        JOIN users u ON c.user_id = u.user_id
        WHERE q.unique_token = ? AND q.is_active = 1
    ");
    $stmt->execute([$token]);
    $qr = $stmt->fetch();

    if (!$qr) {
        jsonResponse(['error' => 'Invalid or expired QR code'], 404);
    }

    // Check expiry
    if ($qr['expires_at'] && strtotime($qr['expires_at']) < time()) {
        jsonResponse(['error' => 'QR code has expired'], 410);
    }

    jsonResponse(['qr' => $qr, 'cv_id' => $qr['cv_id']]);
}

function verifyQRCode($cvId) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT q.*, c.title, c.status, u.first_name, u.last_name, u.email
        FROM qr_codes q
        JOIN cvs c ON q.cv_id = c.cv_id
        JOIN users u ON c.user_id = u.user_id
        WHERE q.cv_id = ?
    ");
    $stmt->execute([$cvId]);
    $qr = $stmt->fetch();

    if (!$qr) {
        jsonResponse(['valid' => false, 'error' => 'QR code not found'], 404);
    }

    logAction($_SESSION['user_id'], 'QR_VERIFIED', 'qr_code', $cvId);

    jsonResponse([
        'valid' => true,
        'cv_id' => $cvId,
        'cv_title' => $qr['title'],
        'student_name' => $qr['first_name'] . ' ' . $qr['last_name'],
        'status' => $qr['status'],
        'scan_count' => $qr['scan_count'],
        'is_active' => $qr['is_active'],
        'unique_token' => $qr['unique_token'],
        'expires_at' => $qr['expires_at'],
        'created_at' => $qr['created_at']
    ]);
}

function getQRStats() {
    $pdo = getDBConnection();

    // Use the qr_usage_statistics view
    $stmt = $pdo->query("SELECT * FROM qr_usage_statistics ORDER BY scan_count DESC LIMIT 50");
    $qrStats = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM qr_codes WHERE is_active = 1");
    $activeQR = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COALESCE(SUM(scan_count), 0) as total FROM qr_codes");
    $totalScans = (int)$stmt->fetch()['total'];

    jsonResponse([
        'active_qr_codes' => $activeQR,
        'total_scans' => $totalScans,
        'qr_statistics' => $qrStats
    ]);
}

function logQRAccess() {
    $data = getInputData();
    $qrId = $data['qr_id'] ?? null;
    $accessType = $data['access_type'] ?? 'scan';

    if (!$qrId) jsonResponse(['error' => 'QR ID required'], 400);

    $pdo = getDBConnection();

    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO qr_access_logs (qr_id, accessed_by, ip_address, user_agent, access_type)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$qrId, $userId, $ip, $ua, $accessType]);

    // Update scan count and last_scanned_at
    $pdo->prepare("UPDATE qr_codes SET scan_count = scan_count + 1, last_scanned_at = NOW() WHERE qr_id = ?")->execute([$qrId]);

    jsonResponse(['success' => true]);
}
