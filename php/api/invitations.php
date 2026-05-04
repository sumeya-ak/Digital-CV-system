<?php
/**
 * Invitations API Endpoint
 * For secure role-based registration (supervisor, examiner)
 * POST /php/api/invitations.php?action=create
 * GET /php/api/invitations.php?action=list
 * GET /php/api/invitations.php?action=validate&token=TOKEN
 * POST /php/api/invitations.php?action=use&token=TOKEN
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
header('Access-Control-Allow-Methods: GET, POST, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create':
        $user = requireRole(['manager']);
        createInvitation($user);
        break;

    case 'list':
        $user = requireRole(['manager']);
        listInvitations();
        break;

    case 'validate':
        validateInvitation();
        break;

    case 'use':
        useInvitation();
        break;

    case 'delete':
        $user = requireRole(['manager']);
        $id = $_GET['id'] ?? '';
        if (!$id) jsonResponse(['error' => 'Invitation ID required'], 400);
        deleteInvitation($id);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: create, list, validate, use, delete'], 400);
}

function createInvitation($user) {
    $data = getInputData();
    validateRequired($data, ['email', 'role']);

    // Validate role
    $validRoles = ['supervisor', 'examiner'];
    if (!in_array($data['role'], $validRoles)) {
        jsonResponse(['error' => 'Invalid role. Only supervisor and examiner can be invited'], 400);
    }

    $pdo = getDBConnection();

    // Check if invitation already exists for this email
    $stmt = $pdo->prepare("SELECT invitation_id FROM invitations WHERE email = ? AND status = 'pending'");
    $stmt->execute([$data['email']]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Pending invitation already exists for this email'], 400);
    }

    // Generate secure token
    $token = bin2hex(random_bytes(32));

    // Set expiration (7 days from now)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    // Create invitation
    $stmt = $pdo->prepare("
        INSERT INTO invitations (email, role, token, expires_at, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$data['email'], $data['role'], $token, $expiresAt, $user['user_id']]);

    $invitationId = $pdo->lastInsertId();

    // Generate registration link
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $registerLink = "$protocol://$host/register.html?token=" . $token;

    jsonResponse([
        'invitation_id' => $invitationId,
        'email' => $data['email'],
        'role' => $data['role'],
        'token' => $token,
        'register_link' => $registerLink,
        'expires_at' => $expiresAt
    ], 201);
}

function listInvitations() {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT i.*, u.first_name, u.last_name
        FROM invitations i
        LEFT JOIN users u ON i.created_by = u.user_id
        ORDER BY i.created_at DESC
    ");
    $stmt->execute();
    $invitations = $stmt->fetchAll();

    jsonResponse(['invitations' => $invitations]);
}

function validateInvitation() {
    $token = $_GET['token'] ?? '';
    if (!$token) {
        jsonResponse(['error' => 'Token required'], 400);
    }

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT invitation_id, email, role, status, expires_at
        FROM invitations
        WHERE token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch();

    if (!$invitation) {
        jsonResponse(['error' => 'Invalid invitation token'], 404);
    }

    if ($invitation['status'] === 'used') {
        jsonResponse(['error' => 'Invitation has already been used'], 400);
    }

    if ($invitation['status'] === 'expired') {
        jsonResponse(['error' => 'Invitation has expired'], 400);
    }

    if (strtotime($invitation['expires_at']) < time()) {
        // Mark as expired
        $stmt = $pdo->prepare("UPDATE invitations SET status = 'expired' WHERE invitation_id = ?");
        $stmt->execute([$invitation['invitation_id']]);
        jsonResponse(['error' => 'Invitation has expired'], 400);
    }

    jsonResponse([
        'valid' => true,
        'email' => $invitation['email'],
        'role' => $invitation['role']
    ]);
}

function useInvitation() {
    $data = getInputData();
    validateRequired($data, ['token']);

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT invitation_id, email, role, status, expires_at
        FROM invitations
        WHERE token = ?
    ");
    $stmt->execute([$data['token']]);
    $invitation = $stmt->fetch();

    if (!$invitation) {
        jsonResponse(['error' => 'Invalid invitation token'], 404);
    }

    if ($invitation['status'] !== 'pending') {
        jsonResponse(['error' => 'Invitation is not valid'], 400);
    }

    if (strtotime($invitation['expires_at']) < time()) {
        $stmt = $pdo->prepare("UPDATE invitations SET status = 'expired' WHERE invitation_id = ?");
        $stmt->execute([$invitation['invitation_id']]);
        jsonResponse(['error' => 'Invitation has expired'], 400);
    }

    // Mark as used
    $stmt = $pdo->prepare("
        UPDATE invitations
        SET status = 'used', used_at = NOW()
        WHERE invitation_id = ?
    ");
    $stmt->execute([$invitation['invitation_id']]);

    jsonResponse([
        'success' => true,
        'email' => $invitation['email'],
        'role' => $invitation['role']
    ]);
}

function deleteInvitation($id) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("DELETE FROM invitations WHERE invitation_id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(['error' => 'Invitation not found'], 404);
    }

    jsonResponse(['success' => true, 'message' => 'Invitation deleted']);
}
