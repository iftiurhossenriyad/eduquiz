<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$course_id = $_GET['course_id'] ?? null;
$stmt = $pdo->prepare("SELECT q.* FROM quizzes q JOIN enrollments e ON e.course_id = q.course_id JOIN courses c ON c.course_id = q.course_id WHERE q.course_id = ? AND e.student_id = ? AND e.status = 'approved' AND c.status = 'approved' AND c.visibility <> 'hidden'");
$stmt->execute([$course_id, $_SESSION['user_id']]);
$quizzes = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Take Quiz - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4" style="max-width: 600px;">
    <a href="course_view.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Courses</a>
    <h4>Available Quizzes</h4>

    <?php foreach ($quizzes as $quiz): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1"><?= htmlspecialchars($quiz['title']); ?></h5>
                    <small class="text-muted">Duration: <?= $quiz['duration_minutes']; ?> mins</small>
                </div>
                <a href="quiz_attempt.php?quiz_id=<?= $quiz['quiz_id']; ?>" class="btn btn-success btn-sm">Start Quiz</a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

</body>
</html>