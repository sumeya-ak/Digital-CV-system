<?php
/**
 * Notifications API Endpoint
 * GET/PUT /php/api/notifications.php?action=list|mark_read|mark_all_read
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
header('Access-Control-Allow-Methods: GET, PUT');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        listNotifications($user);
        break;

    case 'mark_read':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        markRead($user);
        break;

    case 'mark_all_read':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        markAllRead($user);
        break;

    case 'unread_count':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        getUnreadCount($user);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: list, mark_read, mark_all_read, unread_count'], 400);
}

function listNotifications($user) {
    $pdo = getDBConnection();
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT notification_id, title, message, type, related_entity_type, related_entity_id,
               is_read, created_at, read_at
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user['user_id'], $limit, $offset]);
    $notifications = $stmt->fetchAll();

    jsonResponse(['notifications' => $notifications]);
}

function markRead($user) {
    $data = getInputData();
    $notificationId = $data['notification_id'] ?? null;

    if (!$notificationId) jsonResponse(['error' => 'Notification ID required'], 400);

    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = TRUE, read_at = NOW()
        WHERE notification_id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $user['user_id']]);

    jsonResponse(['success' => true]);
}

function markAllRead($user) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        UPDATE notifications SET is_read = TRUE, read_at = NOW()
        WHERE user_id = ? AND is_read = FALSE
    ");
    $stmt->execute([$user['user_id']]);

    jsonResponse(['success' => true]);
}

function getUnreadCount($user) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->execute([$user['user_id']]);
    $count = (int)$stmt->fetch()['count'];

    jsonResponse(['unread_count' => $count]);
}
