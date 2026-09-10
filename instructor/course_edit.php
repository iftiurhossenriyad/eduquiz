<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php');
    exit();
}

$course_id = (int)($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$course_stmt = $pdo->prepare('SELECT course_id, title, description, status, visibility FROM courses WHERE course_id = ? AND instructor_id = ?');
$course_stmt->execute([$course_id, $_SESSION['user_id']]);
$course = $course_stmt->fetch();

if (!$course) {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$message_type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    if (isset($_POST['delete_course'])) {
        $material_stmt = $pdo->prepare('SELECT file_path FROM materials WHERE course_id = ?');
        $material_stmt->execute([$course_id]);
        $material_files = $material_stmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $delete_stmt = $pdo->prepare('DELETE FROM courses WHERE course_id = ? AND instructor_id = ?');
            $delete_stmt->execute([$course_id, $_SESSION['user_id']]);
            $pdo->commit();

            $upload_dir = dirname(__DIR__) . '/uploads/materials';
            foreach ($material_files as $file_path) {
                $stored_file = $upload_dir . '/' . basename($file_path);
                if (is_file($stored_file)) {
                    unlink($stored_file);
                }
            }
            header('Location: dashboard.php?deleted=1');
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Course could not be deleted.';
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $visibility = $_POST['visibility'] ?? 'public';

        if ($title === '' || $description === '' || !in_array($visibility, ['public', 'enrolled', 'hidden'], true)) {
            $message = 'Course title and description are required.';
        } else {
            $update_stmt = $pdo->prepare("UPDATE courses SET title = ?, description = ?, visibility = ?, status = 'pending' WHERE course_id = ? AND instructor_id = ?");
            $update_stmt->execute([$title, $description, $visibility, $course_id, $_SESSION['user_id']]);
            header('Location: dashboard.php?updated=1');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Course - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 600px;">
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a>
    <div class="card shadow p-4">
        <h4 class="mb-3">Edit Course</h4>
        <?php if ($message): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <form method="POST">
            <?= csrf_field(); ?>
            <input type="hidden" name="course_id" value="<?= $course_id; ?>">
            <div class="mb-3">
                <label for="course-title" class="form-label">Course Title</label>
                <input id="course-title" type="text" name="title" class="form-control" required value="<?= htmlspecialchars($course['title']); ?>">
            </div>
            <div class="mb-3">
                <label for="course-description" class="form-label">Course Description</label>
                <textarea id="course-description" name="description" class="form-control" rows="5" required><?= htmlspecialchars($course['description'] ?? ''); ?></textarea>
            </div>
            <div class="mb-3">
                <label for="course-visibility" class="form-label">Student visibility</label>
                <select id="course-visibility" name="visibility" class="form-select" required>
                    <option value="public" <?= ($course['visibility'] ?? 'public') === 'public' ? 'selected' : ''; ?>>Everyone</option>
                    <option value="enrolled" <?= ($course['visibility'] ?? '') === 'enrolled' ? 'selected' : ''; ?>>Approved enrolled students only</option>
                    <option value="hidden" <?= ($course['visibility'] ?? '') === 'hidden' ? 'selected' : ''; ?>>Hidden from students</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Save Changes</button>
        </form>
        <hr>
        <form method="POST" onsubmit="return confirm('Delete this course and all of its quizzes, enrollments, results, and materials?');">
            <?= csrf_field(); ?>
            <input type="hidden" name="course_id" value="<?= $course_id; ?>">
            <button type="submit" name="delete_course" class="btn btn-outline-danger w-100">Delete Course Permanently</button>
        </form>
    </div>
</div>
</body>
</html>
