<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/document_quiz.php';
require_once '../config/notifications.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') { header('Location: ../auth/login.php'); exit(); }
$material_id = (int)($_GET['material_id'] ?? $_POST['material_id'] ?? 0);
$stmt = $pdo->prepare('SELECT m.*, c.title AS course_title FROM materials m JOIN courses c ON c.course_id = m.course_id WHERE m.material_id = ? AND c.instructor_id = ?');
$stmt->execute([$material_id, $_SESSION['user_id']]); $material = $stmt->fetch();
if (!$material || !in_array($material['type'], ['pdf', 'notes', 'docx'], true)) { http_response_code(404); exit('Only PDF, DOCX, and notes materials can generate quizzes.'); }
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $count = min(20, max(1, (int)($_POST['question_count'] ?? 5)));
    $path = dirname(__DIR__) . '/uploads/materials/' . basename($material['file_path']);
    $questions = is_file($path) ? build_quiz_from_document(extract_document_text($path, $material['type']), $count) : [];
    if (!$questions) { $message = 'No usable text was found. For PDF files, install pdftotext or Python pypdf on the server.'; }
    else {
        $title = trim($_POST['title'] ?? '') ?: 'Auto Quiz: ' . $material['file_path'];
        try {
            $pdo->beginTransaction();
            $quiz_stmt = $pdo->prepare('INSERT INTO quizzes (course_id, source_material_id, title, duration_minutes, total_marks) VALUES (?, ?, ?, ?, ?)');
            $quiz_stmt->execute([$material['course_id'], $material_id, $title, max(5, count($questions) * 2), count($questions)]);
            $quiz_id = $pdo->lastInsertId();
            foreach ($questions as $question) {
                $q = $pdo->prepare('INSERT INTO questions (quiz_id, question_text) VALUES (?, ?)'); $q->execute([$quiz_id, $question['text']]); $question_id = $pdo->lastInsertId();
                foreach ($question['options'] as $index => $option) { $o = $pdo->prepare('INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)'); $o->execute([$question_id, $option, $index === $question['correct'] ? 1 : 0]); }
            }
            $pdo->commit();
            notify_enrolled_students($pdo, (int)$material['course_id'], 'quiz', 'New quiz available', $title . ' was generated from course material.');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $message = 'Quiz could not be saved. Database schema may need the latest migration.';
        }
        if (!$message) { header('Location: dashboard.php?generated=1'); exit(); }
    }
}
?>
<!DOCTYPE html><html><head><title>Generate Quiz - EduQuiz</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="container mt-5" style="max-width: 600px;"><a href="material_manage.php?course_id=<?= (int)$material['course_id']; ?>" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Materials</a><div class="card shadow-sm p-4"><h4>Generate Quiz from Document</h4><p class="text-muted">Course: <?= htmlspecialchars($material['course_title']); ?><br>Source: <?= htmlspecialchars($material['file_path']); ?></p><?php if ($message): ?><div class="alert alert-warning"><?= htmlspecialchars($message); ?></div><?php endif; ?><form method="POST"><?= csrf_field(); ?><input type="hidden" name="material_id" value="<?= $material_id; ?>"><label class="form-label">Quiz title</label><input name="title" class="form-control mb-3" value="Auto Quiz from <?= htmlspecialchars($material['file_path']); ?>" required><label class="form-label">Maximum questions</label><input type="number" name="question_count" class="form-control mb-3" min="1" max="20" value="5" required><button class="btn btn-primary w-100">Generate Quiz</button></form></div></div></body></html>