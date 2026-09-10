<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') { header('Location: ../auth/login.php'); exit(); }
$course_id = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$access = $pdo->prepare("SELECT c.title FROM courses c JOIN enrollments e ON e.course_id = c.course_id WHERE c.course_id = ? AND e.student_id = ? AND e.status = 'approved' AND c.status = 'approved' AND c.visibility <> 'hidden'");
$access->execute([$course_id, $_SESSION['user_id']]);
$course = $access->fetch();
if (!$course) { http_response_code(403); exit('You are not enrolled in this course.'); }
$upload_dir = dirname(__DIR__) . '/uploads/tasks';
if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $assignment_id = (int)($_POST['assignment_id'] ?? 0);
    $file = $_FILES['task_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024) { $message = 'Choose a file up to 10 MB.'; }
    else {
        $assignment = $pdo->prepare('SELECT assignment_id FROM assignments WHERE assignment_id = ? AND course_id = ?');
        $assignment->execute([$assignment_id, $course_id]);
        $valid_extension = in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx', 'txt'], true);
        if (!$assignment->fetch() || !$valid_extension) { $message = 'Invalid task or file type.'; }
        else {
            $name = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $name)) {
                $stmt = $pdo->prepare('INSERT INTO assignment_submissions (assignment_id, student_id, file_path) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), submitted_at = CURRENT_TIMESTAMP');
                $stmt->execute([$assignment_id, $_SESSION['user_id'], $name]);
                $message = 'Task submitted successfully.';
            }
        }
    }
}
$stmt = $pdo->prepare('SELECT a.*, s.file_path, s.submitted_at FROM assignments a LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id AND s.student_id = ? WHERE a.course_id = ? ORDER BY a.due_at IS NULL, a.due_at');
$stmt->execute([$_SESSION['user_id'], $course_id]); $assignments = $stmt->fetchAll();
?>
<!DOCTYPE html><html><head><title>Tasks - EduQuiz</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container mt-4" style="max-width: 850px;"><a href="course_view.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Courses</a><h4>Tasks: <?= htmlspecialchars($course['title']); ?></h4><?php if ($message): ?><div class="alert alert-info"><?= htmlspecialchars($message); ?></div><?php endif; ?>
<?php foreach ($assignments as $assignment): ?><div class="card shadow-sm mb-3"><div class="card-body"><div class="d-flex justify-content-between"><h5><?= htmlspecialchars($assignment['title']); ?></h5><small>Due: <?= $assignment['due_at'] ? htmlspecialchars($assignment['due_at']) : 'No deadline'; ?></small></div><p><?= nl2br(htmlspecialchars($assignment['instructions'])); ?></p><form method="POST" enctype="multipart/form-data" class="row g-2 align-items-end"><input type="hidden" name="course_id" value="<?= $course_id; ?>"><input type="hidden" name="assignment_id" value="<?= (int)$assignment['assignment_id']; ?>"><?= csrf_field(); ?><div class="col-md-8"><label class="form-label">Upload PDF, DOC, DOCX or TXT</label><input type="file" name="task_file" class="form-control" required accept=".pdf,.doc,.docx,.txt"></div><div class="col-md-4"><button class="btn btn-success w-100">Submit Task</button></div></form><?php if ($assignment['submitted_at']): ?><small class="text-success">Submitted: <?= htmlspecialchars($assignment['submitted_at']); ?></small><?php endif; ?></div></div><?php endforeach; ?><?php if (!$assignments): ?><p class="text-muted">No tasks for this course yet.</p><?php endif; ?></div></body></html>