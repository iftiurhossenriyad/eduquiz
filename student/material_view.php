<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../auth/login.php');
    exit();
}

$material_id = (int)($_GET['material_id'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT m.file_path, m.type
     FROM materials m
     JOIN enrollments e ON e.course_id = m.course_id
     JOIN courses c ON c.course_id = m.course_id
    WHERE m.material_id = ? AND e.student_id = ? AND e.status = 'approved' AND c.status = 'approved' AND c.visibility <> 'hidden'"
);
$stmt->execute([$material_id, $_SESSION['user_id']]);
$material = $stmt->fetch();

if (!$material) {
    http_response_code(404);
    exit('Material not found or access denied.');
}

$file_path = dirname(__DIR__) . '/uploads/materials/' . basename($material['file_path']);
if (!is_file($file_path)) {
    http_response_code(404);
    exit('Material file is unavailable.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file_path);
$allowed_mimes = ['application/pdf', 'video/mp4', 'video/webm', 'text/plain'];
if (!in_array($mime, $allowed_mimes, true)) {
    http_response_code(415);
    exit('Unsupported material type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file_path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
readfile($file_path);
