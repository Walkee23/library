<?php
session_start();

require_once __DIR__ . '/config.php'; 

if (isset($_SESSION['user_id'])) {
    
    $role = $_SESSION['role'] ?? '';
    $redirect_url = BASE_URL;

    switch ($role) {
        case 'Student':
            $redirect_url .= '/views/student.php'; 
            break;
        case 'Teacher':
            $redirect_url .= '/views/teacher.php'; 
            break;
        case 'Librarian':
            $redirect_url .= '/views/librarian.php'; 
            break;
        case 'Staff':
            $redirect_url .= '/views/staff.php'; 
            break;
        default:
            // If the role is valid but doesn't match above, default to a general page or login
            $redirect_url .= '/views/login.php'; 
            break;
    }
    
    header("Location: " . $redirect_url);
    exit();
    
} else {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}