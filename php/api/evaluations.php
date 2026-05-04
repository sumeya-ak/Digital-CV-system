<?php
/**
 * Evaluations API Endpoint
 * Updated for new schema: examiner_evaluations table, scores 1-10, user_id FK
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
    case 'list':
        $user = requireRole(['examiner', 'manager']);
        listEvaluations($user);
        break;

    case 'get':
        $user = requireRole(['examiner', 'manager', 'student', 'recruiter']);
        $cvId = $_GET['cv_id'] ?? '';
        if (!$cvId) jsonResponse(['error' => 'CV ID required'], 400);
        getEvaluation($cvId, $user);
        break;

    case 'create':
        $user = requireRole(['examiner']);
        createEvaluation($user);
        break;

    default:
        jsonResponse(['error' => 'Invalid action. Use: list, get, create'], 400);
}

function listEvaluations($user) {
    $pdo = getDBConnection();

    $sql = "
        SELECT e.evaluation_id, e.cv_id, e.examiner_id, e.overall_score,
               e.content_quality_score, e.presentation_score, e.completeness_score,
               e.comments, e.evaluated_at,
               c.title as cv_title, u.first_name as student_first, u.last_name as student_last
        FROM examiner_evaluations e
        JOIN cvs c ON e.cv_id = c.cv_id
        JOIN users u ON c.user_id = u.user_id
    ";
    $params = [];

    if ($user['role_name'] === 'examiner') {
        $sql .= " WHERE e.examiner_id = ?";
        $params[] = $user['user_id'];
    }

    $sql .= " ORDER BY e.evaluated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll();

    jsonResponse(['evaluations' => $evaluations]);
}

function getEvaluation($cvId, $user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("
        SELECT e.*, u.first_name as examiner_first, u.last_name as examiner_last
        FROM examiner_evaluations e
        JOIN users u ON e.examiner_id = u.user_id
        WHERE e.cv_id = ?
    ");
    $stmt->execute([$cvId]);
    $evaluation = $stmt->fetch();

    if (!$evaluation) {
        jsonResponse(['evaluation' => null]);
    }

    jsonResponse(['evaluation' => $evaluation]);
}

function createEvaluation($user) {
    $data = getInputData();
    validateRequired($data, ['cv_id', 'content_quality_score', 'presentation_score', 'completeness_score']);

    $cvId = $data['cv_id'];
    $contentScore = (int)$data['content_quality_score'];
    $presentationScore = (int)$data['presentation_score'];
    $completenessScore = (int)$data['completeness_score'];

    if ($contentScore < 1 || $contentScore > 10 || $presentationScore < 1 || $presentationScore > 10 || $completenessScore < 1 || $completenessScore > 10) {
        jsonResponse(['error' => 'Scores must be between 1 and 10'], 400);
    }

    $overallScore = round(($contentScore + $presentationScore + $completenessScore) / 3, 2);

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT status, user_id FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if ($cv['status'] !== 'approved') jsonResponse(['error' => 'CV must be approved first'], 400);

    $stmt = $pdo->prepare("SELECT evaluation_id FROM examiner_evaluations WHERE cv_id = ? AND examiner_id = ?");
    $stmt->execute([$cvId, $user['user_id']]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'You have already evaluated this CV'], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO examiner_evaluations (cv_id, examiner_id, overall_score, content_quality_score, presentation_score, completeness_score, comments)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$cvId, $user['user_id'], $overallScore, $contentScore, $presentationScore, $completenessScore, $data['comments'] ?? null]);

    logAction($user['user_id'], 'CV_EVALUATED', 'examiner_evaluation', $cvId, null, ['overall_score' => $overallScore]);

    // Notify student
    createNotification($cv['user_id'], 'CV Evaluated', "Your CV has been evaluated. Overall score: $overallScore/10", 'cv', $cvId, 'info');

    jsonResponse(['success' => true, 'overall_score' => $overallScore], 201);
}
