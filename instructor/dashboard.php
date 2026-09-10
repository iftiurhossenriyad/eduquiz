<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['teacher', 'instructor'], true)) {
    header("Location: ../auth/login.php");
    exit();
}

$instructor_id = $_SESSION['user_id'];

// Handle Approval / Rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['enrollment_id'])) {
    verify_csrf_token();
    $status = $_POST['action'] === 'approve' ? 'approved' : 'rejected';
    $stmt = $pdo->prepare("UPDATE enrollments e JOIN courses c ON e.course_id = c.course_id SET e.status = ? WHERE e.enrollment_id = ? AND c.instructor_id = ?");
    $stmt->execute([$status, (int)$_POST['enrollment_id'], $instructor_id]);
    header("Location: dashboard.php");
    exit();
}

// Fetch Pending Enrollment Requests
$requests = $pdo->prepare("
    SELECT e.enrollment_id, u.name as student_name, u.email, c.title AS course_name 
    FROM enrollments e 
    JOIN users u ON e.student_id = u.user_id 
    JOIN courses c ON e.course_id = c.course_id 
    WHERE e.status = 'pending' AND c.instructor_id = ?
");
$requests->execute([$instructor_id]);
$pending_requests = $requests->fetchAll();

// Fetch all students enrolled in the instructor's courses
$enrollments = $pdo->prepare("\n    SELECT e.enrollment_id, e.course_id, u.name AS student_name, u.email, c.title AS course_name, e.status\n    FROM enrollments e\n    JOIN users u ON e.student_id = u.user_id\n    JOIN courses c ON e.course_id = c.course_id\n    WHERE c.instructor_id = ?\n    ORDER BY c.title, u.name\n");
$enrollments->execute([$instructor_id]);
$course_enrollments = $enrollments->fetchAll();

// Fetch Quizzes Created by Instructor
$quizzes = $pdo->prepare("SELECT q.*, c.title AS course_name FROM quizzes q JOIN courses c ON q.course_id = c.course_id WHERE c.instructor_id = ?");
$quizzes->execute([$instructor_id]);
$teacher_quizzes = $quizzes->fetchAll();

// Fetch courses owned by this instructor
$courses_stmt = $pdo->prepare("SELECT course_id, title, description, status FROM courses WHERE instructor_id = ? ORDER BY course_id DESC");
$courses_stmt->execute([$instructor_id]);
$instructor_courses = $courses_stmt->fetchAll();

// Fetch Student Quiz Results
$results = $pdo->prepare("
    SELECT u.name as student_name, q.title as quiz_title, r.score 
    FROM results r 
    JOIN users u ON r.student_id = u.user_id
    JOIN quizzes q ON r.quiz_id = q.quiz_id 
    JOIN courses c ON q.course_id = c.course_id 
        WHERE c.instructor_id = ?
            AND NOT EXISTS (
                    SELECT 1
                    FROM results earlier
                    WHERE earlier.student_id = r.student_id
                        AND earlier.quiz_id = r.quiz_id
                        AND earlier.result_id < r.result_id
            )
    ORDER BY r.result_id DESC
");
$results->execute([$instructor_id]);
$student_results = $results->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Instructor Dashboard - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/eduquiz.css" rel="stylesheet">
</head>
<body class="bg-light eduquiz-shell">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
            <a class="navbar-brand" href="#">EduQuiz Instructor Panel</a>
        <div>
            <span class="text-white me-3">Welcome, <?= htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">

    <div class="dashboard-intro">
        <div>
            <div class="eyebrow">Instructor command center</div>
            <h1>Shape the next lesson.</h1>
            <p>Publish course work, guide your students, and watch understanding take form.</p>
        </div>
        <a href="course_create.php" class="btn btn-success">+ Create New Course</a>
    </div>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">Quiz updated successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success">Course deleted successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['generated'])): ?>
        <div class="alert alert-success">Quiz generated from the document. Review it from My Quizzes before sharing.</div>
    <?php endif; ?>

    <!-- Instructor Courses -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white"><strong>My Courses</strong></div>
        <div class="list-group list-group-flush">
            <?php if (!$instructor_courses): ?>
                <div class="list-group-item text-muted">No courses created yet.</div>
            <?php else: ?>
                <?php foreach ($instructor_courses as $course): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-3">
                        <div>
                            <strong><?= htmlspecialchars($course['title']); ?></strong>
                            <br><small class="text-muted">Status: <?= htmlspecialchars(ucfirst($course['status'])); ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="course_edit.php?course_id=<?= (int)$course['course_id']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                            <a href="material_manage.php?course_id=<?= (int)$course['course_id']; ?>" class="btn btn-outline-secondary btn-sm">Materials</a>
                            <a href="task_manage.php?course_id=<?= (int)$course['course_id']; ?>" class="btn btn-outline-success btn-sm">Tasks</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending Approvals -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark"><strong>Pending Course Enrollment Requests</strong></div>
        <div class="card-body">
            <?php if (count($pending_requests) > 0): ?>
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Course</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pending_requests as $req): ?>
                            <tr>
                                <td><?= htmlspecialchars($req['student_name']); ?></td>
                                <td><?= htmlspecialchars($req['email']); ?></td>
                                <td><?= htmlspecialchars($req['course_name']); ?></td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field(); ?><input type="hidden" name="enrollment_id" value="<?= (int)$req['enrollment_id']; ?>"><input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn btn-success btn-sm">Approve</button>
                                    </form>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field(); ?><input type="hidden" name="enrollment_id" value="<?= (int)$req['enrollment_id']; ?>"><input type="hidden" name="action" value="reject">
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">No pending requests found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Enrolled Students -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white"><strong>Students Enrolled in My Courses</strong></div>
        <div class="card-body">
            <?php if (count($course_enrollments) > 0): ?>
                <?php
                $enrollment_courses = [];
                foreach ($course_enrollments as $enrollment) {
                    $enrollment_courses[$enrollment['course_id']] = $enrollment['course_name'];
                }
                asort($enrollment_courses);
                ?>
                <div class="mb-3">
                    <label for="enrollment-course-filter" class="form-label">Select Course</label>
                    <select id="enrollment-course-filter" class="form-select">
                        <option value="all">All Courses</option>
                        <?php foreach ($enrollment_courses as $course_id => $course_name): ?>
                            <option value="<?= (int)$course_id; ?>"><?= htmlspecialchars($course_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0" id="enrollment-table">
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Email</th>
                                <th>Course</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($course_enrollments as $enrollment): ?>
                                <?php $status_class = $enrollment['status'] === 'approved' ? 'success' : ($enrollment['status'] === 'pending' ? 'warning' : 'danger'); ?>
                                <tr data-course-id="<?= (int)$enrollment['course_id']; ?>">
                                    <td><?= htmlspecialchars($enrollment['student_name']); ?></td>
                                    <td><?= htmlspecialchars($enrollment['email']); ?></td>
                                    <td><?= htmlspecialchars($enrollment['course_name']); ?></td>
                                    <td><span class="badge bg-<?= $status_class; ?>"><?= htmlspecialchars(ucfirst($enrollment['status'])); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p id="no-filtered-enrollments" class="text-muted mb-0 mt-3 d-none">No students found for this course.</p>
            <?php else: ?>
                <p class="text-muted mb-0">No students have enrolled in your courses yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Instructor Quizzes -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <span><strong>My Quizzes</strong></span>
            <a href="course_create.php" class="btn btn-light btn-sm">+ Create New Course</a>
        </div>
        <div class="card-body">
            <?php if (count($instructor_courses) > 0): ?>
                <div class="mb-3">
                    <label for="quiz-course" class="form-label">Create quiz for</label>
                    <div class="input-group">
                        <select id="quiz-course" class="form-select">
                            <?php foreach ($instructor_courses as $course): ?>
                                <option value="<?= (int)$course['course_id']; ?>"><?= htmlspecialchars($course['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline-primary" onclick="window.location.href='quiz_create.php?course_id=' + document.getElementById('quiz-course').value">Create Quiz</button>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='material_manage.php?course_id=' + document.getElementById('quiz-course').value">Manage Materials</button>
                        <button type="button" class="btn btn-outline-success" onclick="window.location.href='task_manage.php?course_id=' + document.getElementById('quiz-course').value">Manage Tasks</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (count($teacher_quizzes) > 0): ?>
                <ul class="list-group">
                    <?php foreach ($teacher_quizzes as $quiz): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($quiz['title']); ?></strong>
                                <br><small class="text-muted">Course: <?= htmlspecialchars($quiz['course_name']); ?></small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-secondary"><?= $quiz['duration_minutes']; ?> mins</span>
                                <a href="quiz_edit.php?quiz_id=<?= (int)$quiz['quiz_id']; ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted mb-0">No quizzes created yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Student Marks -->
    <div class="card shadow-sm">
        <div class="card-header bg-info text-white"><strong>Student Performance & Marks</strong></div>
        <div class="card-body">
            <?php if (count($student_results) > 0): ?>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Quiz Title</th>
                            <th>Obtained Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($student_results as $res): ?>
                            <tr>
                                <td><?= htmlspecialchars($res['student_name']); ?></td>
                                <td><?= htmlspecialchars($res['quiz_title']); ?></td>
                                <td><span class="badge bg-success"><?= $res['score']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="text-muted mb-0">No quiz submissions recorded yet.</p>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    const enrollmentFilter = document.getElementById('enrollment-course-filter');
    const enrollmentRows = document.querySelectorAll('#enrollment-table tbody tr');
    const noFilteredEnrollments = document.getElementById('no-filtered-enrollments');

    if (enrollmentFilter) {
        enrollmentFilter.addEventListener('change', () => {
            const selectedCourse = enrollmentFilter.value;
            let visibleRows = 0;

            enrollmentRows.forEach(row => {
                const isVisible = selectedCourse === 'all' || row.dataset.courseId === selectedCourse;
                row.classList.toggle('d-none', !isVisible);
                if (isVisible) {
                    visibleRows += 1;
                }
            });

            noFilteredEnrollments.classList.toggle('d-none', visibleRows > 0);
        });
    }
</script>

</body>
</html>