-- Database schema for Digital CV System
-- Import this directly into your existing database

CREATE TABLE roles (
    role_id INT PRIMARY KEY AUTO_INCREMENT,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE permissions (
    permission_id INT PRIMARY KEY AUTO_INCREMENT,
    permission_name VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255)
);

CREATE TABLE role_permissions (
    role_permission_id INT PRIMARY KEY AUTO_INCREMENT,
    role_id INT NOT NULL,
    permission_id INT NOT NULL,
    FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(permission_id) ON DELETE CASCADE,
    UNIQUE KEY unique_role_permission (role_id, permission_id)
);

CREATE TABLE users (
    user_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    profile_picture VARCHAR(500),
    role_id INT NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    email_verified BOOLEAN DEFAULT FALSE,
    account_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id),
    INDEX idx_email (email),
    INDEX idx_role (role_id),
    INDEX idx_account_status (account_status)
);

CREATE TABLE student_profiles (
    profile_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    student_id_number VARCHAR(50) NOT NULL,
    institution VARCHAR(255) NOT NULL,
    department VARCHAR(255),
    graduation_year INT,
    degree_program VARCHAR(255),
    linkedin_url VARCHAR(500),
    portfolio_url VARCHAR(500),
    summary TEXT,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_institution (institution),
    INDEX idx_graduation_year (graduation_year)
);

CREATE TABLE supervisor_profiles (
    profile_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    employee_id VARCHAR(50) NOT NULL,
    department VARCHAR(255),
    specialization VARCHAR(255),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE cvs (
    cv_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    status ENUM('draft', 'submitted', 'under_review', 'approved', 'rejected') DEFAULT 'draft',
    version INT DEFAULT 1,
    personal_summary TEXT,
    submitted_at TIMESTAMP NULL,
    reviewed_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    review_comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_user_cv (user_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

CREATE TABLE cv_education (
    education_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    institution_name VARCHAR(255) NOT NULL,
    degree VARCHAR(255) NOT NULL,
    field_of_study VARCHAR(255),
    start_date DATE NOT NULL,
    end_date DATE,
    is_current BOOLEAN DEFAULT FALSE,
    gpa DECIMAL(3,2),
    description TEXT,
    display_order INT DEFAULT 0,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_education (cv_id)
);

CREATE TABLE cv_experience (
    experience_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    job_title VARCHAR(255) NOT NULL,
    location VARCHAR(255),
    start_date DATE NOT NULL,
    end_date DATE,
    is_current BOOLEAN DEFAULT FALSE,
    description TEXT,
    display_order INT DEFAULT 0,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_experience (cv_id)
);

CREATE TABLE cv_skills (
    skill_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    skill_name VARCHAR(100) NOT NULL,
    proficiency_level ENUM('beginner', 'intermediate', 'advanced', 'expert') DEFAULT 'intermediate',
    category VARCHAR(50),
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_skills (cv_id),
    INDEX idx_skill_name (skill_name)
);

CREATE TABLE cv_projects (
    project_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    project_name VARCHAR(255) NOT NULL,
    description TEXT,
    technologies VARCHAR(500),
    project_url VARCHAR(500),
    start_date DATE,
    end_date DATE,
    display_order INT DEFAULT 0,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_projects (cv_id)
);

CREATE TABLE cv_certifications (
    certification_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    certification_name VARCHAR(255) NOT NULL,
    issuing_organization VARCHAR(255),
    issue_date DATE,
    expiry_date DATE,
    credential_id VARCHAR(255),
    credential_url VARCHAR(500),
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_certifications (cv_id)
);

CREATE TABLE documents (
    document_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(100),
    document_type ENUM('certificate', 'portfolio', 'transcript', 'reference', 'other') DEFAULT 'other',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_cv_documents (cv_id)
);

CREATE TABLE qr_codes (
    qr_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL UNIQUE,
    qr_code_data TEXT NOT NULL,
    qr_image_path VARCHAR(500),
    unique_token VARCHAR(255) NOT NULL UNIQUE,
    access_url VARCHAR(500) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    scan_count INT DEFAULT 0,
    last_scanned_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    INDEX idx_qr_token (unique_token),
    INDEX idx_qr_active (is_active)
);

CREATE TABLE qr_access_logs (
    log_id INT PRIMARY KEY AUTO_INCREMENT,
    qr_id INT NOT NULL,
    accessed_by INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    access_type ENUM('scan', 'direct_link', 'share') DEFAULT 'scan',
    FOREIGN KEY (qr_id) REFERENCES qr_codes(qr_id) ON DELETE CASCADE,
    FOREIGN KEY (accessed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_qr_access (qr_id),
    INDEX idx_accessed_at (accessed_at)
);

CREATE TABLE cv_approval_history (
    history_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    status_from ENUM('draft', 'submitted', 'under_review', 'approved', 'rejected'),
    status_to ENUM('draft', 'submitted', 'under_review', 'approved', 'rejected') NOT NULL,
    changed_by INT NOT NULL,
    comments TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_cv_history (cv_id)
);

CREATE TABLE examiner_evaluations (
    evaluation_id INT PRIMARY KEY AUTO_INCREMENT,
    cv_id INT NOT NULL,
    examiner_id INT NOT NULL,
    overall_score DECIMAL(4,2),
    content_quality_score INT CHECK (content_quality_score BETWEEN 1 AND 10),
    presentation_score INT CHECK (presentation_score BETWEEN 1 AND 10),
    completeness_score INT CHECK (completeness_score BETWEEN 1 AND 10),
    comments TEXT,
    evaluated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cv_id) REFERENCES cvs(cv_id) ON DELETE CASCADE,
    FOREIGN KEY (examiner_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE KEY unique_cv_examiner (cv_id, examiner_id)
);

CREATE TABLE notifications (
    notification_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    related_entity_type ENUM('cv', 'qr_code', 'approval', 'system') NOT NULL,
    related_entity_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    email_sent BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_user_notifications (user_id, is_read),
    INDEX idx_created_at (created_at)
);

CREATE TABLE audit_logs (
    log_id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
);

CREATE TABLE system_settings (
    setting_id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    description VARCHAR(255),
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE organizations (
    org_id INT PRIMARY KEY AUTO_INCREMENT,
    org_name VARCHAR(255) NOT NULL,
    org_type VARCHAR(100),
    industry VARCHAR(100),
    website VARCHAR(500),
    logo_path VARCHAR(500),
    is_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE recruiter_profiles (
    profile_id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    org_id INT,
    job_title VARCHAR(255),
    employee_id VARCHAR(50),
    is_verified BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (org_id) REFERENCES organizations(org_id) ON DELETE SET NULL
);

INSERT INTO roles (role_name, description) VALUES
('student', 'Graduate Student - Can create and manage CVs'),
('supervisor', 'Project Supervisor - Reviews and approves CVs'),
('examiner', 'Examiner - Evaluates CV quality'),
('recruiter', 'Company HR/Recruiter - Views CVs via QR code'),
('manager', 'System Manager - Oversees entire system');

INSERT INTO permissions (permission_name, description) VALUES
('create_cv', 'Create new CV'),
('edit_cv', 'Edit own CV'),
('delete_cv', 'Delete own CV'),
('submit_cv', 'Submit CV for approval'),
('view_all_cvs', 'View all CVs in system'),
('approve_cv', 'Approve or reject CVs'),
('evaluate_cv', 'Evaluate CV quality'),
('scan_qr', 'Scan QR codes and view CVs'),
('manage_users', 'Manage user accounts'),
('manage_roles', 'Assign roles and permissions'),
('view_reports', 'Generate and view reports'),
('system_admin', 'Full system administration access'),
('view_audit_logs', 'View system audit logs'),
('manage_settings', 'Manage system settings');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id 
FROM roles r, permissions p 
WHERE r.role_name = 'student' 
AND p.permission_name IN ('create_cv', 'edit_cv', 'delete_cv', 'submit_cv');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id 
FROM roles r, permissions p 
WHERE r.role_name = 'supervisor' 
AND p.permission_name IN ('view_all_cvs', 'approve_cv');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id 
FROM roles r, permissions p 
WHERE r.role_name = 'examiner' 
AND p.permission_name IN ('view_all_cvs', 'evaluate_cv');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id 
FROM roles r, permissions p 
WHERE r.role_name = 'recruiter' 
AND p.permission_name IN ('scan_qr');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id 
FROM roles r, permissions p 
WHERE r.role_name = 'manager';

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('max_cv_versions', '10', 'Maximum number of CV versions per student'),
('qr_code_expiry_days', '365', 'Number of days until QR code expires'),
('require_supervisor_approval', 'true', 'Whether CV requires supervisor approval before QR generation'),
('allow_public_cv_view', 'false', 'Allow public access to approved CVs'),
('max_file_upload_size_mb', '10', 'Maximum file upload size in MB'),
('allowed_file_types', 'pdf,jpg,jpeg,png,doc,docx', 'Comma-separated list of allowed file types');


-- Views removed for free hosting compatibility
-- Views are not supported on this hosting plan

CREATE TABLE invitations (
    invitation_id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'used', 'expired') DEFAULT 'pending',
    expires_at TIMESTAMP NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    used_at TIMESTAMP NULL,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_email (email),
    INDEX idx_status (status)
);
