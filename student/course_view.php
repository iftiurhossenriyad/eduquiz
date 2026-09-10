<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$message = "";

// Handle Enroll Action
if (isset($_POST['enroll'])) {
    verify_csrf_token();
    $course_id = (int)($_POST['course_id'] ?? 0);
    
    // Check existing enrollment
    $chk = $pdo->prepare("SELECT * FROM enrollments WHERE student_id = ? AND course_id = ?");
    $chk->execute([$student_id, $course_id]);
    if ($chk->rowCount() == 0) {
        try {
            $ins = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, status) VALUES (?, ?, 'pending')");
            $ins->execute([$student_id, $course_id]);
            $message = "Enrollment request sent! Waiting for instructor approval.";
        } catch (PDOException $e) {
            $message = "This enrollment request already exists.";
        }
    }
}

// Fetch Courses and Enrollment Status
$stmt = $pdo->prepare("
    SELECT c.*, e.status as enroll_status 
    FROM courses c 
    LEFT JOIN enrollments e ON c.course_id = e.course_id AND e.student_id = ?
        WHERE c.status = 'approved'
            AND (c.visibility = 'public' OR (c.visibility = 'enrolled' AND e.status = 'approved'))
");
$stmt->execute([$student_id]);
$courses = $stmt->fetchAll();

$people_stmt = $pdo->prepare("SELECT c.course_id, instructor.name AS instructor_name, instructor.email AS instructor_email, classmates.user_id AS classmate_id, classmates.name AS classmate_name, classmates.email AS classmate_email FROM courses c JOIN users instructor ON instructor.user_id = c.instructor_id LEFT JOIN enrollments classmates_enrollment ON classmates_enrollment.course_id = c.course_id AND classmates_enrollment.status = 'approved' LEFT JOIN users classmates ON classmates.user_id = classmates_enrollment.student_id AND classmates.role = 'student' WHERE c.status = 'approved' AND c.visibility IN ('public', 'enrolled') ORDER BY c.course_id, classmates.name");
$people_stmt->execute();
$course_people = [];
foreach ($people_stmt->fetchAll() as $person) {
    $course_id = (int)$person['course_id'];
    if (!isset($course_people[$course_id])) {
        $course_people[$course_id] = [
            'instructor_name' => $person['instructor_name'],
            'instructor_email' => $person['instructor_email'],
            'classmates' => [],
        ];
    }
    if ($person['classmate_id'] !== null && (int)$person['classmate_id'] !== $student_id) {
        $course_people[$course_id]['classmates'][] = [
            'name' => $person['classmate_name'],
            'email' => $person['classmate_email'],
        ];
    }
}

$materials_stmt = $pdo->prepare('SELECT material_id, course_id, type, file_path, uploaded_at FROM materials WHERE course_id IN (SELECT course_id FROM enrollments WHERE student_id = ? AND status = \'approved\') ORDER BY uploaded_at DESC');
$materials_stmt->execute([$student_id]);
$course_materials = [];
foreach ($materials_stmt->fetchAll() as $material) {
    $course_materials[(int)$material['course_id']][] = $material;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Available Courses - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/eduquiz.css" rel="stylesheet">
</head>
<body class="bg-light eduquiz-shell">

<div class="container mt-4">
    <div class="dashboard-intro">
        <div>
            <div class="eyebrow">Your learning library</div>
            <h1>Find your next course.</h1>
            <p>Explore approved courses, meet your cohort, and jump into the next activity.</p>
        </div>
        <a href="dashboard.php" class="btn btn-secondary btn-sm">&larr; Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= $message; ?></div>
    <?php endif; ?>

    <div class="row">
        <?php foreach ($courses as $c): ?>
            <div class="col-md-4 mb-3">
                <div class="card course-tile h-100 p-3">
                        <h5><?= htmlspecialchars($c['title']); ?></h5>
                    <p class="text-muted"><?= htmlspecialchars($c['description'] ?? ''); ?></p>

                    <?php if ($c['enroll_status'] === 'approved' && isset($course_people[(int)$c['course_id']])): ?>
                        <div class="border rounded p-2 mb-3 small">
                            <strong>Instructor</strong>
                            <div><?= htmlspecialchars($course_people[(int)$c['course_id']]['instructor_name']); ?></div>
                            <a href="mailto:<?= htmlspecialchars($course_people[(int)$c['course_id']]['instructor_email']); ?>" class="text-muted"><?= htmlspecialchars($course_people[(int)$c['course_id']]['instructor_email']); ?></a>
                            <hr class="my-2">
                            <strong>Classmates (<?= count($course_people[(int)$c['course_id']]['classmates']); ?>)</strong>
                            <?php if ($course_people[(int)$c['course_id']]['classmates']): ?>
                                <ul class="ps-3 mb-0 mt-1">
                                    <?php foreach ($course_people[(int)$c['course_id']]['classmates'] as $classmate): ?>
                                        <li><?= htmlspecialchars($classmate['name']); ?> <a href="mailto:<?= htmlspecialchars($classmate['email']); ?>" class="text-muted">(email)</a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="text-muted mt-1">No other classmates yet.</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($c['enroll_status'] === 'approved' && !empty($course_materials[(int)$c['course_id']])): ?>
                        <div class="mb-3">
                            <strong>Course Materials</strong>
                            <ul class="small ps-3 mb-0">
                                <?php foreach ($course_materials[(int)$c['course_id']] as $material): ?>
                                    <li><a href="material_view.php?material_id=<?= (int)$material['material_id']; ?>" target="_blank" rel="noopener"><?= htmlspecialchars(ucfirst($material['type'])); ?> material</a></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-auto">
                        <?php if (!$c['enroll_status']): ?>
                            <form method="POST">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="course_id" value="<?= $c['course_id']; ?>">
                                <button type="submit" name="enroll" class="btn btn-primary w-100">Enroll Course</button>
                            </form>
                        <?php elseif ($c['enroll_status'] === 'pending'): ?>
                            <button class="btn btn-warning w-100" disabled>Pending Approval...</button>
                        <?php elseif ($c['enroll_status'] === 'approved'): ?>
                            <div class="d-grid gap-2">
                                <a href="quiz_take.php?course_id=<?= $c['course_id']; ?>" class="btn btn-success">View Quizzes &rarr;</a>
                                <a href="tasks.php?course_id=<?= $c['course_id']; ?>" class="btn btn-outline-primary">View Tasks</a>
                            </div>
                        <?php else: ?>
                            <button class="btn btn-danger w-100" disabled>Request Rejected</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>