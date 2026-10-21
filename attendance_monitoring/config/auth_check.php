<?php
// config/auth_check.php
require_once __DIR__ . '/database.php';

function is_logged_in() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function current_user() {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'             => $_SESSION['user_id'] ?? null,
        'identifier'     => $_SESSION['user_identifier'] ?? null,
        'name'           => $_SESSION['user_name'] ?? null,
        'email'          => $_SESSION['user_email'] ?? null,
        'role'           => $_SESSION['user_role'] ?? null,
        'course_section' => $_SESSION['course_section'] ?? null,
        'qr_token'       => $_SESSION['qr_token'] ?? null
    ];
}

function require_auth($allowed_roles = []) {
    if (!is_logged_in()) {
        header("Location: " . base_url('login.php'));
        exit;
    }

    if (!empty($allowed_roles)) {
        $current_role = $_SESSION['user_role'] ?? '';
        if (!in_array($current_role, (array)$allowed_roles)) {
            // Redirect based on actual role
            if ($current_role === 'student') {
                header("Location: " . base_url('student/dashboard.php'));
            } elseif ($current_role === 'professor') {
                header("Location: " . base_url('professor/dashboard.php'));
            } elseif ($current_role === 'admin') {
                header("Location: " . base_url('admin/dashboard.php'));
            } else {
                header("Location: " . base_url('login.php'));
            }
            exit;
        }
    }
}

// Generate unique secure QR token for student
function generate_qr_token($student_id) {
    return 'STU_QR_' . hash('sha256', $student_id . microtime(true) . bin2hex(random_bytes(8)));
}
