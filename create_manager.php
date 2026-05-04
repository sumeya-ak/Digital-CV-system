<?php
// Create manager account script
// Run this once to create a manager account

require_once 'php/includes/db.php';
require_once 'php/includes/functions.php';

$pdo = getDBConnection();

// Check if manager role exists
$stmt = $pdo->prepare("SELECT role_id FROM roles WHERE role_name = 'manager'");
$stmt->execute();
$managerRole = $stmt->fetch();

if (!$managerRole) {
    // Create manager role if it doesn't exist
    $stmt = $pdo->prepare("INSERT INTO roles (role_name, description) VALUES ('manager', 'System Manager with full access')");
    $stmt->execute();
    $managerRoleId = $pdo->lastInsertId();
    echo "Created manager role<br>";
} else {
    $managerRoleId = $managerRole['role_id'];
    echo "Manager role already exists<br>";
}

// Create manager account
$email = 'manager@cvsystem.com';
$password = 'demo1234';
$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

// Check if manager account already exists
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    echo "Manager account already exists for: $email<br>";
} else {
    $stmt = $pdo->prepare("
        INSERT INTO users (email, password_hash, first_name, last_name, role_id, account_status)
        VALUES (?, ?, ?, ?, ?, 'approved')
    ");
    $stmt->execute([
        $email,
        $hashedPassword,
        'System',
        'Manager',
        $managerRoleId
    ]);
    
    echo "Manager account created successfully!<br>";
    echo "Email: $email<br>";
    echo "Password: $password<br>";
    echo "You can now log in at: login.html<br>";
}
?>
