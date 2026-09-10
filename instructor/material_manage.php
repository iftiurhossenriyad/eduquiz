<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

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

$upload_dir = dirname(__DIR__) . '/uploads/materials';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

$message = '';
$message_type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    if (isset($_POST['delete_material'])) {
        $material_id = (int)$_POST['material_id'];
        $material_stmt = $pdo->prepare('SELECT file_path FROM materials WHERE material_id = ? AND course_id = ?');
        $material_stmt->execute([$material_id, $course_id]);
        $material = $material_stmt->fetch();

        if ($material) {
            $delete_stmt = $pdo->prepare('DELETE FROM materials WHERE material_id = ? AND course_id = ?');
            $delete_stmt->execute([$material_id, $course_id]);
            $stored_file = $upload_dir . '/' . basename($material['file_path']);
            if (is_file($stored_file)) {
                unlink($stored_file);
            }
            $message = 'Material deleted successfully.';
            $message_type = 'success';
        }
    } elseif (isset($_FILES['material_file'])) {
        $type = $_POST['type'] ?? '';
        $allowed_types = ['pdf', 'video', 'notes', 'docx'];
        $file = $_FILES['material_file'];
        $allowed_extensions = [
            'pdf' => ['pdf'],
            'video' => ['mp4', 'webm'],
            'notes' => ['txt'],
                    'docx' => ['docx'],
        ];
        $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));

        if (!in_array($type, $allowed_types, true) || $file['error'] !== UPLOAD_ERR_OK) {
            $message = 'Please select a valid material type and file.';
        } elseif ($file['size'] > 20 * 1024 * 1024) {
            $message = 'Material files must be 20 MB or smaller.';
        } elseif (!in_array($extension, $allowed_extensions[$type], true)) {
            $message = 'The selected file type does not match the material type.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $allowed_mimes = [
                'pdf' => ['application/pdf'],
                'video' => ['video/mp4', 'video/webm'],
                'notes' => ['text/plain'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            ];

            if (!in_array($mime, $allowed_mimes[$type], true)) {
                $message = 'The uploaded file content is not valid for the selected type.';
            } else {
                $stored_name = bin2hex(random_bytes(16)) . '.' . $extension;
                if (move_uploaded_file($file['tmp_name'], $upload_dir . '/' . $stored_name)) {
                    $insert_stmt = $pdo->prepare('INSERT INTO materials (course_id, type, file_path) VALUES (?, ?, ?)');
                    $insert_stmt->execute([$course_id, $type, $stored_name]);
                    $message = 'Material uploaded successfully.';
                    $message_type = 'success';
                } else {
                    $message = 'The material could not be saved.';
                }
            }
        }
    }
}

$materials_stmt = $pdo->prepare('SELECT material_id, type, file_path, uploaded_at FROM materials WHERE course_id = ? ORDER BY uploaded_at DESC');
$materials_stmt->execute([$course_id]);
$materials = $materials_stmt->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Course Materials - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4 mb-5" style="max-width: 800px;">
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h4>Materials: <?= htmlspecialchars($course['title']); ?></h4>
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type; ?>"><?= htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field(); ?>
                <input type="hidden" name="course_id" value="<?= $course_id; ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label for="material-type" class="form-label">Type</label>
                        <select id="material-type" name="type" class="form-select" required>
                            <option value="pdf">PDF</option>
                            <option value="video">Video</option>
                            <option value="notes">Notes</option>
                            <option value="docx">DOCX</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="material-file" class="form-label">File</label>
                        <input id="material-file" type="file" name="material_file" class="form-control" required accept=".pdf,.docx,.mp4,.webm,.txt">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">Upload Material</button>
                    </div>
                </div>
                <small class="text-muted">PDF, DOCX, MP4/WebM video, or TXT notes; maximum 20 MB.</small>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header"><strong>Uploaded Materials</strong></div>
        <div class="list-group list-group-flush">
            <?php if (!$materials): ?>
                <div class="list-group-item text-muted">No materials uploaded yet.</div>
            <?php else: ?>
                <?php foreach ($materials as $material): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <strong><?= htmlspecialchars(ucfirst($material['type'])); ?></strong>
                            <br><small class="text-muted"><?= htmlspecialchars($material['file_path']); ?> · <?= htmlspecialchars($material['uploaded_at']); ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-primary btn-sm" href="../uploads/materials/<?= rawurlencode($material['file_path']); ?>" target="_blank" rel="noopener">Open</a>
                            <?php if (in_array($material['type'], ['pdf', 'notes', 'docx'], true)): ?>
                                <a class="btn btn-outline-success btn-sm" href="generate_quiz.php?material_id=<?= (int)$material['material_id']; ?>">Generate Quiz</a>
                            <?php endif; ?>
                            <form method="POST">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="course_id" value="<?= $course_id; ?>">
                                <input type="hidden" name="material_id" value="<?= (int)$material['material_id']; ?>">
                                <button type="submit" name="delete_material" class="btn btn-outline-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
