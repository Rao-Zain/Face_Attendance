<?php

session_start();

require __DIR__ . '/config/helpers.php';

$page = $_GET['page'] ?? (is_logged_in() ? 'dashboard' : 'login');

$routes = [
    'login' => __DIR__ . '/modules/auth/login.php',
    'register' => __DIR__ . '/modules/auth/register.php',
    'logout' => __DIR__ . '/modules/auth/logout.php',
    'dashboard' => __DIR__ . '/modules/dashboard.php',
    'students' => __DIR__ . '/modules/students/index.php',
    'attendance' => __DIR__ . '/modules/attendance/index.php',
    'reports' => __DIR__ . '/modules/reports/index.php',
    'notifications' => __DIR__ . '/modules/notifications/index.php',
];

if (!isset($routes[$page])) {
    http_response_code(404);
    exit('Page not found.');
}

ob_start();
require $routes[$page];
$content = ob_get_clean();

if (!isset($skipLayout) || $skipLayout !== true) {
    render_layout($pageTitle ?? 'FaceTrack Attendance', $content, $page);
}

