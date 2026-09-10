<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $instructor_id = $_SESSION['user_id'];

    $stmt = $pdo->prepare("INSERT INTO courses (instructor_id, title, description, status) VALUES (?, ?, ?, 'pending')");
    if ($title !== '' && $description !== '' && $stmt->execute([$instructor_id, $title, $description])) {
        header("Location: dashboard.php");
        exit();
    } else {
        $message = "Failed to create course.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Course - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 500px;">
    <div class="card shadow p-4">
        <h4 class="mb-3">Create New Course</h4>
        <?php if ($message): ?>
            <div class="alert alert-danger"><?= $message; ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <?= csrf_field(); ?>
            <div class="mb-3">
                <label>Course Title</label>
                <input type="text" name="title" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Course Description</label>
                <textarea name="description" class="form-control" rows="4" required></textarea>
            </div>
            <button type="submit" class="btn btn-success w-100">Submit for Approval</button>
            <a href="dashboard.php" class="btn btn-link w-100 mt-2 text-secondary text-center">Cancel</a>
        </form>
    </div>
</div>
</body>
</html>