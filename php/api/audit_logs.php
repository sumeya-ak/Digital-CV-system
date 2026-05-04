<?php
/**
 * Audit Logs API Endpoint
 * GET /php/api/audit_logs.php?action=list
 * DELETE /php/api/audit_logs.php?action=clear
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
header('Access-Control-Allow-Methods: GET, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $user = requireRole(['manager']);
        listAuditLogs();
        break;

    case 'clear':
        $user = requireRole(['manager']);
        clearAuditLogs();
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: list, clear'], 400);
}

function listAuditLogs() {
    $pdo = getDBConnection();

    // Check if audit_logs table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'audit_logs'");
    $stmt->execute();
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        // Return empty array if table doesn't exist
        jsonResponse(['logs' => []]);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT al.*, u.first_name, u.last_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();

    jsonResponse(['logs' => $logs]);
}

function clearAuditLogs() {
    $pdo = getDBConnection();

    // Check if audit_logs table exists
    $stmt = $pdo->prepare("SHOW TABLES LIKE 'audit_logs'");
    $stmt->execute();
    $tableExists = $stmt->fetch();

    if (!$tableExists) {
        jsonResponse(['success' => true, 'message' => 'No audit logs to clear']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM audit_logs");
    $stmt->execute();

    jsonResponse(['success' => true, 'message' => 'Audit logs cleared']);
}
?>
