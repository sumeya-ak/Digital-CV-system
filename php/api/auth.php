<?php
/**
 * Authentication API Endpoint
 * POST /php/api/auth.php?action=login|register|logout|me
 */

// Configure session before starting
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

session_start();

// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'register':
            $data = getInputData();
            validateRequired($data, ['email', 'password', 'first_name', 'last_name']);

            // Handle token-based registration for supervisor/examiner
            $token = $data['token'] ?? '';
            if ($token) {
                // Validate token and get role
                $inviteData = validateInvitationToken($token);
                if (isset($inviteData['error'])) {
                    jsonResponse($inviteData, 400);
                }
                $data['role'] = $inviteData['role'];
                $data['account_status'] = 'approved';
            } else {
                // Public registration - validate role
                if (!isset($data['role'])) {
                    $data['role'] = 'student'; // Default role
                }
                $validPublicRoles = ['student', 'recruiter'];
                if (!in_array($data['role'], $validPublicRoles)) {
                    jsonResponse(['error' => 'Invalid role. Only student and recruiter can register publicly. For supervisor/examiner, you need an invitation.'], 400);
                }
                // HR accounts start as pending
                $data['account_status'] = ($data['role'] === 'recruiter') ? 'pending' : 'approved';
            }

            $result = registerUser($data);
            if (isset($result['error'])) {
                jsonResponse($result, 400);
            }

            // If token was used, mark it as used
            if ($token) {
                useInvitationToken($token);
            }

            jsonResponse($result, 201);
            break;

        case 'login':
            $data = getInputData();
            validateRequired($data, ['email', 'password']);
            $result = loginUser($data['email'], $data['password']);
            if (isset($result['error'])) {
                jsonResponse($result, 401);
            }
            jsonResponse($result);
            break;

        case 'logout':
            $result = logoutUser();
            jsonResponse($result);
            break;

        case 'me':
            $user = getAuthUser();
            if (!$user) {
                jsonResponse(['error' => 'Not authenticated'], 401);
            }
            jsonResponse(['user' => $user]);
            break;

        default:
            jsonResponse(['error' => 'Invalid action. Use: register, login, logout, me'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}

function validateInvitationToken($token) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT invitation_id, email, role, status, expires_at
        FROM invitations
        WHERE token = ?
    ");
    $stmt->execute([$token]);
    $invitation = $stmt->fetch();

    if (!$invitation) {
        return ['error' => 'Invalid invitation token'];
    }

    if ($invitation['status'] === 'used') {
        return ['error' => 'Invitation has already been used'];
    }

    if ($invitation['status'] === 'expired') {
        return ['error' => 'Invitation has expired'];
    }

    if (strtotime($invitation['expires_at']) < time()) {
        $stmt = $pdo->prepare("UPDATE invitations SET status = 'expired' WHERE invitation_id = ?");
        $stmt->execute([$invitation['invitation_id']]);
        return ['error' => 'Invitation has expired'];
    }

    return ['role' => $invitation['role'], 'email' => $invitation['email']];
}

function useInvitationToken($token) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        UPDATE invitations
        SET status = 'used', used_at = NOW()
        WHERE token = ?
    ");
    $stmt->execute([$token]);
}
