
ALTER TABLE users 
ADD COLUMN account_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved' AFTER email_verified,
ADD INDEX idx_account_status (account_status);


CREATE TABLE IF NOT EXISTS invitations (
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
