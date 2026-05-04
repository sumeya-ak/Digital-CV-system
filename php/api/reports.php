<?php
/**
 * Reports API Endpoint
 * Updated for new schema: user_id, cv_id, examiner_evaluations, qr_codes (scan_count),
 * audit_logs (entity_type, entity_id), cv_statistics/pending_approvals views
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
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$user = requireRole(['manager']);
$type = $_GET['type'] ?? '';

switch ($type) {
    case 'cv_stats':
        getCVStats();
        break;

    case 'qr_usage':
        getQRUsageStats();
        break;

    case 'student_activity':
        getStudentActivity();
        break;

    case 'approval_rate':
        getApprovalRate();
        break;

    case 'audit':
        getAuditLogs();
        break;

    case 'dashboard':
        getDashboardSummary();
        break;

    case 'pending_approvals':
        getPendingApprovals();
        break;

    default:
        jsonResponse(['error' => 'Invalid report type. Use: cv_stats, qr_usage, student_activity, approval_rate, audit, dashboard, pending_approvals'], 400);
}

function getCVStats() {
    $pdo = getDBConnection();

    // Use the cv_statistics view
    $stmt = $pdo->query("SELECT * FROM cv_statistics");
    $stats = [];
    foreach ($stmt->fetchAll() as $row) {
        $stats[$row['status']] = [
            'count' => (int)$row['count'],
            'unique_students' => (int)$row['unique_students']
        ];
    }

    $stmt = $pdo->query("SELECT COUNT(*) as total FROM cvs");
    $total = (int)$stmt->fetch()['total'];

    jsonResponse([
        'total' => $total,
        'draft' => $stats['draft']['count'] ?? 0,
        'submitted' => $stats['submitted']['count'] ?? 0,
        'under_review' => $stats['under_review']['count'] ?? 0,
        'approved' => $stats['approved']['count'] ?? 0,
        'rejected' => $stats['rejected']['count'] ?? 0,
        'by_status' => $stats
    ]);
}

function getQRUsageStats() {
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

function getStudentActivity() {
    $pdo = getDBConnection();

    $stmt = $pdo->query("
        SELECT u.user_id, u.first_name, u.last_name, u.email,
               sp.institution, sp.student_id_number, sp.graduation_year,
               COUNT(c.cv_id) as cv_count,
               SUM(CASE WHEN c.status = 'approved' THEN 1 ELSE 0 END) as approved_count,
               SUM(CASE WHEN c.status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
               SUM(CASE WHEN c.status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
               SUM(CASE WHEN c.status = 'draft' THEN 1 ELSE 0 END) as draft_count
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN student_profiles sp ON u.user_id = sp.user_id
        LEFT JOIN cvs c ON c.user_id = u.user_id
        WHERE r.role_name = 'student'
        GROUP BY u.user_id
        ORDER BY cv_count DESC
    ");
    $students = $stmt->fetchAll();

    jsonResponse(['students' => $students]);
}

function getApprovalRate() {
    $pdo = getDBConnection();

    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_submitted,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
            SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review
        FROM cvs
        WHERE status != 'draft'
    ");
    $data = $stmt->fetch();

    $total = (int)$data['total_submitted'];
    $approved = (int)$data['approved'];
    $rate = $total > 0 ? round(($approved / $total) * 100, 1) : 0;

    jsonResponse([
        'total_submitted' => $total,
        'approved' => $approved,
        'rejected' => (int)$data['rejected'],
        'submitted' => (int)$data['submitted'],
        'under_review' => (int)$data['under_review'],
        'approval_rate' => $rate
    ]);
}

function getAuditLogs() {
    $pdo = getDBConnection();

    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $sql = "
        SELECT a.log_id, a.user_id, a.action, a.entity_type, a.entity_id,
               a.old_values, a.new_values, a.ip_address, a.created_at,
               u.email as user_email, u.first_name, u.last_name, r.role_name as user_role
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN roles r ON u.role_id = r.role_id
    ";
    $params = [];

    // Optional filters
    $where = [];
    if (isset($_GET['action']) && $_GET['action']) {
        $where[] = "a.action = ?";
        $params[] = $_GET['action'];
    }
    if (isset($_GET['entity_type']) && $_GET['entity_type']) {
        $where[] = "a.entity_type = ?";
        $params[] = $_GET['entity_type'];
    }
    if (isset($_GET['user_id']) && $_GET['user_id']) {
        $where[] = "a.user_id = ?";
        $params[] = $_GET['user_id'];
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($sql);
    $execParams = array_merge($params, [$limit, $offset]);
    $stmt->execute($execParams);
    $logs = $stmt->fetchAll();

    $countSql = "SELECT COUNT(*) as total FROM audit_logs";
    if (!empty($where)) {
        $countSql .= " WHERE " . implode(' AND ', $where);
    }
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetch()['total'];

    jsonResponse([
        'logs' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function getDashboardSummary() {
    $pdo = getDBConnection();

    // Users count by role
    $stmt = $pdo->query("
        SELECT r.role_name, COUNT(*) as count
        FROM users u
        JOIN roles r ON u.role_id = r.role_id
        GROUP BY r.role_name
    ");
    $usersByRole = [];
    foreach ($stmt->fetchAll() as $row) {
        $usersByRole[$row['role_name']] = (int)$row['count'];
    }

    // CV stats
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM cvs GROUP BY status");
    $cvsByStatus = [];
    foreach ($stmt->fetchAll() as $row) {
        $cvsByStatus[$row['status']] = (int)$row['count'];
    }

    // QR stats
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM qr_codes WHERE is_active = 1");
    $qrActive = (int)$stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT COALESCE(SUM(scan_count), 0) as total FROM qr_codes");
    $qrScans = (int)$stmt->fetch()['total'];

    // Evaluations
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM examiner_evaluations");
    $evalTotal = (int)$stmt->fetch()['total'];

    // Avg evaluation score
    $stmt = $pdo->query("SELECT COALESCE(AVG(overall_score), 0) as avg FROM examiner_evaluations");
    $avgScore = round((float)$stmt->fetch()['avg'], 2);

    jsonResponse([
        'users_by_role' => $usersByRole,
        'cvs_by_status' => $cvsByStatus,
        'active_qr_codes' => $qrActive,
        'total_qr_scans' => $qrScans,
        'evaluations' => $evalTotal,
        'avg_evaluation_score' => $avgScore
    ]);
}

function getPendingApprovals() {
    $pdo = getDBConnection();

    // Use the pending_approvals view
    $stmt = $pdo->query("SELECT * FROM pending_approvals ORDER BY submitted_at ASC");
    $pending = $stmt->fetchAll();

    jsonResponse(['pending_approvals' => $pending]);
}
