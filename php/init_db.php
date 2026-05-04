<?php
/**
 * Database Initialization Script
 * Run this once to seed demo data after importing database.sql
 * Access via: http://localhost/cv/php/init_db.php
 *
 * IMPORTANT: Import database.sql first to create tables, views, and seed data:
 *   mysql -u root -p < database.sql
 *   OR run the SQL in phpMyAdmin / MySQL Workbench
 */

require_once __DIR__ . '/includes/db.php';

try {
    $pdo = getDBConnection();

    // Check if roles exist (indicates database.sql has been imported)
    $stmt = $pdo->query("SELECT COUNT(*) FROM roles");
    $roleCount = (int)$stmt->fetchColumn();

    if ($roleCount === 0) {
        echo "<h2>Database tables not found!</h2>";
        echo "<p>Please import <code>database.sql</code> first to create all tables, views, and seed data:</p>";
        echo "<pre>mysql -u root -p &lt; database.sql</pre>";
        echo "<p>Or import it via phpMyAdmin / MySQL Workbench.</p>";
        echo "<hr><p><a href='../login.html'>Go to Login Page</a></p>";
        exit;
    }

    echo "<h2>Database is ready!</h2>";
    echo "<p>Tables, views, and seed data (roles, permissions, system settings) are in place.</p>";

    // Check if demo users exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $userCount = (int)$stmt->fetchColumn();

    if ($userCount === 0) {
        // Create demo users with role_id FK and profile tables
        $demoUsers = [
            [
                'email' => 'student@demo.com', 'password' => 'demo1234',
                'first_name' => 'Abebe', 'last_name' => 'Kebede', 'role' => 'student',
                'student_id_number' => 'STD/2024/001', 'institution' => 'Addis Ababa University',
                'department' => 'Computer Science', 'graduation_year' => 2026,
                'degree_program' => 'MSc Computer Science'
            ],
            [
                'email' => 'supervisor@demo.com', 'password' => 'demo1234',
                'first_name' => 'Dr. Tigist', 'last_name' => 'Haile', 'role' => 'supervisor',
                'employee_id' => 'SUP/001', 'department' => 'Computer Science',
                'specialization' => 'Software Engineering'
            ],
            [
                'email' => 'examiner@demo.com', 'password' => 'demo1234',
                'first_name' => 'Prof. Dawit', 'last_name' => 'Assefa', 'role' => 'examiner',
                'employee_id' => 'EXM/001', 'department' => 'Information Systems',
                'specialization' => 'Database Systems'
            ],
            [
                'email' => 'recruiter@demo.com', 'password' => 'demo1234',
                'first_name' => 'Sara', 'last_name' => 'Mohammed', 'role' => 'recruiter',
                'job_title' => 'HR Manager', 'employee_id' => 'REC/001'
            ],
            [
                'email' => 'manager@demo.com', 'password' => 'demo1234',
                'first_name' => 'Admin', 'last_name' => 'User', 'role' => 'manager'
            ],
        ];

        require_once __DIR__ . '/includes/auth.php';

        foreach ($demoUsers as $uData) {
            $result = registerUser($uData);
            if (isset($result['error'])) {
                echo "<p style='color:red;'>Error creating {$uData['email']}: {$result['error']}</p>";
            } else {
                echo "<p style='color:green;'>Created: {$uData['email']} ({$uData['role']})</p>";
            }
        }

        echo "<h3>Demo Accounts (password: demo1234)</h3><ul>";
        foreach ($demoUsers as $u) {
            echo "<li><strong>{$u['email']}</strong> — {$u['role']}</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>Demo users already exist. Skipping seed data.</p>";
    }

    // Show current stats
    $stmt = $pdo->query("SELECT r.role_name, COUNT(u.user_id) as count FROM roles r LEFT JOIN users u ON r.role_id = u.role_id GROUP BY r.role_name");
    echo "<h3>Current Users by Role</h3><ul>";
    foreach ($stmt->fetchAll() as $row) {
        echo "<li>{$row['role_name']}: {$row['count']}</li>";
    }
    echo "</ul>";

    echo "<hr><p><a href='../login.html'>Go to Login Page</a> | <a href='../index.html'>Go to Home Page</a></p>";

} catch (Exception $e) {
    echo "<h2>Error:</h2><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Make sure you have created the database 'digital_cv_system' in MySQL first:</p>";
    echo "<pre>CREATE DATABASE digital_cv_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</pre>";
    echo "<p>Then import database.sql:</p>";
    echo "<pre>mysql -u root -p digital_cv_system &lt; database.sql</pre>";
}
