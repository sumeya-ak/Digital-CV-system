<?php
/**
 * Shared utility functions
 * Updated for new database schema
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Send JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get input data from request
 */
function getInputData() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        return json_decode(file_get_contents('php://input'), true);
    }
    return $_POST;
}

/**
 * Validate required fields
 */
function validateRequired($data, $fields) {
    $missing = [];
    foreach ($fields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        jsonResponse(['error' => 'Missing required fields: ' . implode(', ', $missing)], 400);
    }
    return true;
}

/**
 * Upload file handler — uses documents table with file_size, mime_type, document_type
 */
function uploadFile($fileInput, $cvId, $targetDir = null) {
    if ($targetDir === null) {
        $targetDir = __DIR__ . '/../../assets/uploads/';
    }

    if (!isset($_FILES[$fileInput])) {
        return [];
    }

    // Normalize for single/multi file
    $fileData = $_FILES[$fileInput];
    if (!is_array($fileData['name'])) {
        $fileData = [
            'name'     => [$fileData['name']],
            'type'     => [$fileData['type']],
            'tmp_name' => [$fileData['tmp_name']],
            'error'    => [$fileData['error']],
            'size'     => [$fileData['size']]
        ];
    }

    $maxSizeMB = (int)getSystemSetting('max_file_upload_size_mb', '10');
    $maxSize = $maxSizeMB * 1024 * 1024;
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png',
                     'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    $pdo = getDBConnection();
    $files = [];

    for ($i = 0; $i < count($fileData['name']); $i++) {
        if ($fileData['error'][$i] !== UPLOAD_ERR_OK) continue;
        if ($fileData['size'][$i] > $maxSize) continue;
        if (!in_array($fileData['type'][$i], $allowedTypes)) continue;

        $fileName = uniqid() . '_' . basename($fileData['name'][$i]);
        $targetPath = $targetDir . $fileName;

        if (move_uploaded_file($fileData['tmp_name'][$i], $targetPath)) {
            $docType = $data['document_type'] ?? 'other';
            $stmt = $pdo->prepare("
                INSERT INTO documents (cv_id, file_name, original_name, file_path, file_size, mime_type, document_type)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $cvId,
                $fileName,
                $fileData['name'][$i],
                'assets/uploads/' . $fileName,
                $fileData['size'][$i],
                $fileData['type'][$i],
                $docType
            ]);
            $files[] = [
                'document_id' => $pdo->lastInsertId(),
                'original_name' => $fileData['name'][$i],
                'file_path' => 'assets/uploads/' . $fileName
            ];
        }
    }

    return $files;
}

/**
 * Get full CV with all related data using new schema
 */
function getFullCV($cvId) {
    $pdo = getDBConnection();

    // Get CV with user info and student profile
    $stmt = $pdo->prepare("
        SELECT c.*, u.first_name, u.last_name, u.email as student_email, u.phone as user_phone,
               sp.institution, sp.student_id_number, sp.department, sp.graduation_year,
               sp.degree_program, sp.linkedin_url, sp.portfolio_url
        FROM cvs c
        JOIN users u ON c.user_id = u.user_id
        LEFT JOIN student_profiles sp ON u.user_id = sp.user_id
        WHERE c.cv_id = ?
    ");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) return null;

    // Get education
    $stmt = $pdo->prepare("SELECT * FROM cv_education WHERE cv_id = ? ORDER BY display_order, education_id");
    $stmt->execute([$cvId]);
    $cv['education'] = $stmt->fetchAll();

    // Get experience
    $stmt = $pdo->prepare("SELECT * FROM cv_experience WHERE cv_id = ? ORDER BY display_order, experience_id");
    $stmt->execute([$cvId]);
    $cv['experience'] = $stmt->fetchAll();

    // Get skills
    $stmt = $pdo->prepare("SELECT skill_id, skill_name, proficiency_level, category FROM cv_skills WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv['skills'] = $stmt->fetchAll();
    $cv['skill_names'] = array_column($cv['skills'], 'skill_name');

    // Get projects
    $stmt = $pdo->prepare("SELECT * FROM cv_projects WHERE cv_id = ? ORDER BY display_order, project_id");
    $stmt->execute([$cvId]);
    $cv['projects'] = $stmt->fetchAll();

    // Get certifications
    $stmt = $pdo->prepare("SELECT * FROM cv_certifications WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv['certifications'] = $stmt->fetchAll();
    $cv['certification_names'] = array_column($cv['certifications'], 'certification_name');

    // Get documents
    $stmt = $pdo->prepare("SELECT document_id, file_name, original_name, file_path, document_type FROM documents WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv['documents'] = $stmt->fetchAll();

    // Get evaluation if exists
    $stmt = $pdo->prepare("
        SELECT e.*, u.first_name as examiner_first, u.last_name as examiner_last
        FROM examiner_evaluations e
        JOIN users u ON e.examiner_id = u.user_id
        WHERE e.cv_id = ?
    ");
    $stmt->execute([$cvId]);
    $cv['evaluation'] = $stmt->fetch() ?: null;

    // Get QR code if exists
    $stmt = $pdo->prepare("SELECT * FROM qr_codes WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv['qr_code'] = $stmt->fetch() ?: null;

    // Convenience fields
    $cv['student_name'] = $cv['first_name'] . ' ' . $cv['last_name'];
    $cv['id'] = $cv['cv_id']; // compatibility alias

    return $cv;
}

/**
 * Record CV approval history
 */
function recordApprovalHistory($cvId, $statusFrom, $statusTo, $changedBy, $comments = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO cv_approval_history (cv_id, status_from, status_to, changed_by, comments)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$cvId, $statusFrom, $statusTo, $changedBy, $comments]);
}

/**
 * Generate unique token for QR code
 */
function generateUniqueToken() {
    return bin2hex(random_bytes(16));
}
