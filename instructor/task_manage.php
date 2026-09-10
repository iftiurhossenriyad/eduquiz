<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/notifications.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php');
    exit();
}

$course_id = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$course_stmt = $pdo->prepare('SELECT course_id, title FROM courses WHERE course_id = ? AND instructor_id = ?');
$course_stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $course_stmt->fetch();
if (!$course) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assignment'])) {
    verify_csrf_token();
    $title = trim($_POST['title'] ?? '');
    $instructions = trim($_POST['instructions'] ?? '');
    $due_at = trim($_POST['due_at'] ?? '') ?: null;
    if ($title === '' || $instructions === '') {
        $message = 'Title and instructions are required.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO assignments (course_id, title, instructions, due_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$course_id, $title, $instructions, $due_at]);
        notify_enrolled_students($pdo, $course_id, 'task', 'New task available', $title . ' was added to ' . $course['title'] . '.');
        $message = 'Task created successfully.';
    }
}

$assignments_stmt = $pdo->prepare('SELECT a.*, COUNT(s.submission_id) AS submission_count FROM assignments a LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id WHERE a.course_id = ? GROUP BY a.assignment_id ORDER BY a.created_at DESC');
$assignments_stmt->execute([$course_id]);
$assignments = $assignments_stmt->fetchAll();
?>
<!DOCTYPE html>
<html><head><title>Tasks - EduQuiz</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container mt-4" style="max-width: 850px;">
<a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a>
<div class="card shadow-sm mb-4"><div class="card-body"><h4>Create Task: <?= htmlspecialchars($course['title']); ?></h4>
<?php if ($message): ?><div class="alert alert-info"><?= htmlspecialchars($message); ?></div><?php endif; ?>
<form method="POST"><input type="hidden" name="course_id" value="<?= $course_id; ?>"><?= csrf_field(); ?>
<input type="text" name="title" class="form-control mb-2" placeholder="Task title" required>
<textarea name="instructions" class="form-control mb-2" rows="4" placeholder="Task instructions" required></textarea>
<label class="form-label">Due date (optional)</label><input type="datetime-local" name="due_at" class="form-control mb-3">
<button name="create_assignment" class="btn btn-primary">Create Task</button></form></div></div>
<div class="card shadow-sm"><div class="card-header"><strong>Created Tasks</strong></div><div class="list-group list-group-flush">
<?php foreach ($assignments as $assignment): ?><div class="list-group-item"><div class="d-flex justify-content-between"><strong><?= htmlspecialchars($assignment['title']); ?></strong><span class="badge bg-secondary"><?= (int)$assignment['submission_count']; ?> submissions</span></div><p class="mb-1 mt-2"><?= nl2br(htmlspecialchars($assignment['instructions'])); ?></p><small class="text-muted">Due: <?= $assignment['due_at'] ? htmlspecialchars($assignment['due_at']) : 'No deadline'; ?></small></div><?php endforeach; ?>
<?php if (!$assignments): ?><div class="list-group-item text-muted">No tasks created yet.</div><?php endif; ?></div></div></div></body></html>