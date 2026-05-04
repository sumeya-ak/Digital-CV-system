<?php
/**
 * CV CRUD API Endpoint
 * Updated for new database schema: cv_id (INT), user_id, status enum, proper FK references
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
$id = $_GET['id'] ?? '';

switch ($action) {
    case 'list':
        $user = requireRole(['student', 'supervisor', 'examiner', 'recruiter', 'manager']);
        listCVs($user);
        break;

    case 'get':
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        // Allow public access for approved CVs (QR code access)
        $user = getAuthUser();
        if (!$user) {
            // Public access - only allow approved CVs
            getPublicCV($id);
        } else {
            getCV($id, $user);
        }
        break;

    case 'create':
        $user = requireRole(['student']);
        createCV($user);
        break;

    case 'update':
        $user = requireRole(['student']);
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        updateCV($id, $user);
        break;

    case 'submit':
        $user = requireRole(['student']);
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        submitCV($id, $user);
        break;

    case 'approve':
        $user = requireRole(['supervisor', 'manager']);
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        approveCV($id, $user);
        break;

    case 'reject':
        $user = requireRole(['supervisor', 'manager']);
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        rejectCV($id, $user);
        break;

    case 'delete':
        $user = requireRole(['student', 'manager']);
        if (!$id) jsonResponse(['error' => 'CV ID required'], 400);
        deleteCV($id, $user);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

function listCVs($user) {
    $pdo = getDBConnection();

    $sql = "
        SELECT c.cv_id, c.title, c.status, c.version, c.created_at, c.updated_at, c.submitted_at,
               u.first_name, u.last_name, u.email as student_email,
               sp.institution
        FROM cvs c
        JOIN users u ON c.user_id = u.user_id
        LEFT JOIN student_profiles sp ON u.user_id = sp.user_id
    ";
    $params = [];

    if ($user['role_name'] === 'student') {
        $sql .= " WHERE c.user_id = ?";
        $params[] = $user['user_id'];
    } elseif ($user['role_name'] === 'supervisor') {
        $sql .= " WHERE c.status IN ('submitted', 'under_review', 'approved', 'rejected')";
    } elseif ($user['role_name'] === 'examiner') {
        $sql .= " WHERE c.status = 'approved'";
    } elseif ($user['role_name'] === 'recruiter') {
        $sql .= " WHERE c.status = 'approved'";
    }

    if (isset($_GET['status']) && $_GET['status']) {
        $sql .= ($params ? " AND" : " WHERE") . " c.status = ?";
        $params[] = $_GET['status'];
    }

    $sql .= " ORDER BY c.updated_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cvs = $stmt->fetchAll();

    // Add student_name convenience field
    foreach ($cvs as &$cv) {
        $cv['student_name'] = $cv['first_name'] . ' ' . $cv['last_name'];
        $cv['id'] = $cv['cv_id'];
    }

    jsonResponse(['cvs' => $cvs]);
}

function getPublicCV($cvId) {
    $cv = getFullCV($cvId);
    if (!$cv) {
        jsonResponse(['error' => 'CV not found'], 404);
    }

    // Only allow access to approved CVs
    if ($cv['status'] !== 'approved') {
        jsonResponse(['error' => 'CV not available for public viewing'], 403);
    }

    jsonResponse(['cv' => $cv]);
}

function getCV($cvId, $user) {
    $cv = getFullCV($cvId);
    if (!$cv) {
        jsonResponse(['error' => 'CV not found'], 404);
    }

    if ($user['role_name'] === 'student' && $cv['user_id'] != $user['user_id']) {
        if ($cv['status'] !== 'approved') {
            jsonResponse(['error' => 'Access denied'], 403);
        }
    }

    // Log recruiter access
    if ($user['role_name'] === 'recruiter') {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT qr_id FROM qr_codes WHERE cv_id = ?");
        $stmt->execute([$cvId]);
        $qr = $stmt->fetch();
        if ($qr) {
            $stmt = $pdo->prepare("
                INSERT INTO qr_access_logs (qr_id, accessed_by, ip_address, user_agent, access_type)
                VALUES (?, ?, ?, ?, 'direct_link')
            ");
            $stmt->execute([$qr['qr_id'], $user['user_id'], $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null]);

            $pdo->prepare("UPDATE qr_codes SET scan_count = scan_count + 1, last_scanned_at = NOW() WHERE cv_id = ?")->execute([$cvId]);
        }
        logAction($user['user_id'], 'CV_VIEWED_RECRUITER', 'cv', $cvId);
    }

    jsonResponse(['cv' => $cv]);
}

function createCV($user) {
    $data = getInputData();
    validateRequired($data, ['title']);

    $pdo = getDBConnection();
    $pdo->beginTransaction();

    try {
        // Insert CV
        $stmt = $pdo->prepare("
            INSERT INTO cvs (user_id, title, personal_summary, status)
            VALUES (?, ?, ?, 'draft')
        ");
        $stmt->execute([
            $user['user_id'],
            $data['title'],
            $data['personal_summary'] ?? $data['summary'] ?? null
        ]);
        $cvId = $pdo->lastInsertId();

        // Insert education
        if (!empty($data['education'])) {
            $stmt = $pdo->prepare("
                INSERT INTO cv_education (cv_id, institution_name, degree, field_of_study, start_date, end_date, is_current, gpa, description, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $order = 0;
            foreach ($data['education'] as $ed) {
                $stmt->execute([
                    $cvId,
                    $ed['institution_name'] ?? $ed['institution'] ?? '',
                    $ed['degree'] ?? '',
                    $ed['field_of_study'] ?? null,
                    $ed['start_date'] ?? $ed['startDate'] ?? null,
                    $ed['end_date'] ?? $ed['endDate'] ?? null,
                    !empty($ed['is_current']) ? 1 : 0,
                    $ed['gpa'] ?? null,
                    $ed['description'] ?? null,
                    $order++
                ]);
            }
        }

        // Insert experience
        if (!empty($data['experience'])) {
            $stmt = $pdo->prepare("
                INSERT INTO cv_experience (cv_id, company_name, job_title, location, start_date, end_date, is_current, description, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $order = 0;
            foreach ($data['experience'] as $ex) {
                $stmt->execute([
                    $cvId,
                    $ex['company_name'] ?? $ex['company'] ?? '',
                    $ex['job_title'] ?? $ex['title'] ?? '',
                    $ex['location'] ?? null,
                    $ex['start_date'] ?? $ex['startDate'] ?? null,
                    $ex['end_date'] ?? $ex['endDate'] ?? null,
                    !empty($ex['is_current']) ? 1 : 0,
                    $ex['description'] ?? null,
                    $order++
                ]);
            }
        }

        // Insert skills
        if (!empty($data['skills'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_skills (cv_id, skill_name, proficiency_level, category) VALUES (?, ?, ?, ?)");
            foreach ($data['skills'] as $skill) {
                if (is_string($skill) && trim($skill)) {
                    $stmt->execute([$cvId, trim($skill), 'intermediate', null]);
                } elseif (is_array($skill) && !empty($skill['skill_name'])) {
                    $stmt->execute([$cvId, $skill['skill_name'], $skill['proficiency_level'] ?? 'intermediate', $skill['category'] ?? null]);
                }
            }
        }

        // Insert projects
        if (!empty($data['projects'])) {
            $stmt = $pdo->prepare("
                INSERT INTO cv_projects (cv_id, project_name, description, technologies, project_url, start_date, end_date, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $order = 0;
            foreach ($data['projects'] as $proj) {
                $stmt->execute([
                    $cvId,
                    $proj['project_name'] ?? '',
                    $proj['description'] ?? null,
                    $proj['technologies'] ?? null,
                    $proj['project_url'] ?? null,
                    $proj['start_date'] ?? null,
                    $proj['end_date'] ?? null,
                    $order++
                ]);
            }
        }

        // Insert certifications
        if (!empty($data['certifications'])) {
            $stmt = $pdo->prepare("
                INSERT INTO cv_certifications (cv_id, certification_name, issuing_organization, issue_date, expiry_date, credential_id, credential_url)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($data['certifications'] as $cert) {
                if (is_string($cert) && trim($cert)) {
                    $stmt->execute([$cvId, trim($cert), null, null, null, null, null]);
                } elseif (is_array($cert)) {
                    $stmt->execute([
                        $cvId,
                        $cert['certification_name'] ?? $cert['name'] ?? '',
                        $cert['issuing_organization'] ?? null,
                        $cert['issue_date'] ?? null,
                        $cert['expiry_date'] ?? null,
                        $cert['credential_id'] ?? null,
                        $cert['credential_url'] ?? null
                    ]);
                }
            }
        }

        $pdo->commit();
        logAction($user['user_id'], 'CV_CREATED', 'cv', $cvId, null, ['title' => $data['title']]);

        // Notify supervisors about new CV (if submitted)
        jsonResponse(['success' => true, 'cv_id' => $cvId], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to create CV: ' . $e->getMessage()], 500);
    }
}

function updateCV($cvId, $user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT user_id, status FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if ($cv['user_id'] != $user['user_id']) jsonResponse(['error' => 'Access denied'], 403);
    if (!in_array($cv['status'], ['draft', 'rejected'])) {
        jsonResponse(['error' => 'Cannot edit a submitted/approved CV'], 400);
    }

    $data = getInputData();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            UPDATE cvs SET title = ?, personal_summary = ?, updated_at = NOW()
            WHERE cv_id = ?
        ");
        $stmt->execute([
            $data['title'] ?? null,
            $data['personal_summary'] ?? $data['summary'] ?? null,
            $cvId
        ]);

        // Delete and re-insert related data
        $pdo->prepare("DELETE FROM cv_education WHERE cv_id = ?")->execute([$cvId]);
        $pdo->prepare("DELETE FROM cv_experience WHERE cv_id = ?")->execute([$cvId]);
        $pdo->prepare("DELETE FROM cv_skills WHERE cv_id = ?")->execute([$cvId]);
        $pdo->prepare("DELETE FROM cv_projects WHERE cv_id = ?")->execute([$cvId]);
        $pdo->prepare("DELETE FROM cv_certifications WHERE cv_id = ?")->execute([$cvId]);

        // Re-insert education
        if (!empty($data['education'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_education (cv_id, institution_name, degree, field_of_study, start_date, end_date, is_current, gpa, description, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            foreach ($data['education'] as $ed) {
                $stmt->execute([$cvId, $ed['institution_name'] ?? $ed['institution'] ?? '', $ed['degree'] ?? '', $ed['field_of_study'] ?? null, $ed['start_date'] ?? $ed['startDate'] ?? null, $ed['end_date'] ?? $ed['endDate'] ?? null, !empty($ed['is_current']) ? 1 : 0, $ed['gpa'] ?? null, $ed['description'] ?? null, $order++]);
            }
        }

        if (!empty($data['experience'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_experience (cv_id, company_name, job_title, location, start_date, end_date, is_current, description, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            foreach ($data['experience'] as $ex) {
                $stmt->execute([$cvId, $ex['company_name'] ?? $ex['company'] ?? '', $ex['job_title'] ?? $ex['title'] ?? '', $ex['location'] ?? null, $ex['start_date'] ?? $ex['startDate'] ?? null, $ex['end_date'] ?? $ex['endDate'] ?? null, !empty($ex['is_current']) ? 1 : 0, $ex['description'] ?? null, $order++]);
            }
        }

        if (!empty($data['skills'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_skills (cv_id, skill_name, proficiency_level, category) VALUES (?, ?, ?, ?)");
            foreach ($data['skills'] as $skill) {
                if (is_string($skill) && trim($skill)) {
                    $stmt->execute([$cvId, trim($skill), 'intermediate', null]);
                } elseif (is_array($skill) && !empty($skill['skill_name'])) {
                    $stmt->execute([$cvId, $skill['skill_name'], $skill['proficiency_level'] ?? 'intermediate', $skill['category'] ?? null]);
                }
            }
        }

        if (!empty($data['projects'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_projects (cv_id, project_name, description, technologies, project_url, start_date, end_date, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $order = 0;
            foreach ($data['projects'] as $proj) {
                $stmt->execute([$cvId, $proj['project_name'] ?? '', $proj['description'] ?? null, $proj['technologies'] ?? null, $proj['project_url'] ?? null, $proj['start_date'] ?? null, $proj['end_date'] ?? null, $order++]);
            }
        }

        if (!empty($data['certifications'])) {
            $stmt = $pdo->prepare("INSERT INTO cv_certifications (cv_id, certification_name, issuing_organization, issue_date, expiry_date, credential_id, credential_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['certifications'] as $cert) {
                if (is_string($cert) && trim($cert)) {
                    $stmt->execute([$cvId, trim($cert), null, null, null, null, null]);
                } elseif (is_array($cert)) {
                    $stmt->execute([$cvId, $cert['certification_name'] ?? $cert['name'] ?? '', $cert['issuing_organization'] ?? null, $cert['issue_date'] ?? null, $cert['expiry_date'] ?? null, $cert['credential_id'] ?? null, $cert['credential_url'] ?? null]);
                }
            }
        }

        $pdo->commit();
        logAction($user['user_id'], 'CV_UPDATED', 'cv', $cvId);

        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(['error' => 'Failed to update CV'], 500);
    }
}

function submitCV($cvId, $user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT user_id, status FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if ($cv['user_id'] != $user['user_id']) jsonResponse(['error' => 'Access denied'], 403);
    if (!in_array($cv['status'], ['draft', 'rejected'])) {
        jsonResponse(['error' => 'CV already submitted'], 400);
    }

    $oldStatus = $cv['status'];
    $stmt = $pdo->prepare("UPDATE cvs SET status = 'submitted', submitted_at = NOW(), updated_at = NOW() WHERE cv_id = ?");
    $stmt->execute([$cvId]);

    recordApprovalHistory($cvId, $oldStatus, 'submitted', $user['user_id']);
    logAction($user['user_id'], 'CV_SUBMITTED', 'cv', $cvId);

    // Notify supervisors
    $stmt = $pdo->query("SELECT user_id FROM users u JOIN roles r ON u.role_id = r.role_id WHERE r.role_name = 'supervisor' AND u.is_active = 1");
    $supervisors = $stmt->fetchAll();
    foreach ($supervisors as $sup) {
        $cvTitle = $cv['title'] ?? '';
        createNotification($sup['user_id'], 'New CV Submitted', "A new CV '$cvTitle' has been submitted for review.", 'approval', $cvId, 'info');
    }

    jsonResponse(['success' => true]);
}

function approveCV($cvId, $user) {
    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT cv_id, status, user_id, title FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if (!in_array($cv['status'], ['submitted', 'under_review'])) {
        jsonResponse(['error' => 'CV is not in a reviewable state'], 400);
    }

    $oldStatus = $cv['status'];
    $stmt = $pdo->prepare("
        UPDATE cvs SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?, review_comments = NULL, updated_at = NOW()
        WHERE cv_id = ?
    ");
    $stmt->execute([$user['user_id'], $cvId]);

    // Record approval history
    recordApprovalHistory($cvId, $oldStatus, 'approved', $user['user_id']);

    // Generate QR code entry
    $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $accessUrl = $baseUrl . '/public-cv.html?id=' . $cvId;
    $uniqueToken = generateUniqueToken();
    $expiryDays = (int)getSystemSetting('qr_code_expiry_days', '365');

    $stmt = $pdo->prepare("
        INSERT INTO qr_codes (cv_id, qr_code_data, unique_token, access_url, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
        ON DUPLICATE KEY UPDATE is_active = TRUE, access_url = VALUES(access_url)
    ");
    $stmt->execute([$cvId, $accessUrl, $uniqueToken, $accessUrl, $expiryDays]);

    logAction($user['user_id'], 'CV_APPROVED', 'cv', $cvId);

    // Notify student
    createNotification($cv['user_id'], 'CV Approved!', "Your CV has been approved. A QR code has been generated.", 'approval', $cvId, 'success');

    jsonResponse(['success' => true]);
}

function rejectCV($cvId, $user) {
    $data = getInputData();
    $comment = $data['comment'] ?? $data['review_comments'] ?? '';

    $pdo = getDBConnection();

    $stmt = $pdo->prepare("SELECT cv_id, status, user_id, title FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);
    $cv = $stmt->fetch();

    if (!$cv) jsonResponse(['error' => 'CV not found'], 404);
    if (!in_array($cv['status'], ['submitted', 'under_review'])) {
        jsonResponse(['error' => 'CV is not in a reviewable state'], 400);
    }

    $oldStatus = $cv['status'];
    $stmt = $pdo->prepare("
        UPDATE cvs SET status = 'rejected', reviewed_at = NOW(), reviewed_by = ?, review_comments = ?, updated_at = NOW()
        WHERE cv_id = ?
    ");
    $stmt->execute([$user['user_id'], $comment, $cvId]);

    recordApprovalHistory($cvId, $oldStatus, 'rejected', $user['user_id'], $comment);
    logAction($user['user_id'], 'CV_REJECTED', 'cv', $cvId, null, ['comment' => $comment]);

    // Notify student
    createNotification($cv['user_id'], 'CV Rejected', "Your CV was rejected. Comments: " . ($comment ?: 'No comments provided'), 'approval', $cvId, 'error');

    jsonResponse(['success' => true]);
}

function deleteCV($cvId, $user) {
    $pdo = getDBConnection();

    if ($user['role_name'] === 'student') {
        $stmt = $pdo->prepare("SELECT user_id FROM cvs WHERE cv_id = ?");
        $stmt->execute([$cvId]);
        $cv = $stmt->fetch();
        if (!$cv || $cv['user_id'] != $user['user_id']) {
            jsonResponse(['error' => 'Access denied'], 403);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM cvs WHERE cv_id = ?");
    $stmt->execute([$cvId]);

    logAction($user['user_id'], 'CV_DELETED', 'cv', $cvId);
    jsonResponse(['success' => true]);
}
