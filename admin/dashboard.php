<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

// Admin access validation
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();
    $action = $_POST['action'];

    if (isset($_POST['user_id'])) {
        $user_id = (int)$_POST['user_id'];
        if (in_array($action, ['approve', 'reject', 'activate'], true)) {
            $status = $action === 'approve' || $action === 'activate' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role <> 'admin'");
            $stmt->execute([$status, $user_id]);
        } elseif ($action === 'delete_user' && $user_id !== (int)$_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ? AND role <> 'admin'");
            $stmt->execute([$user_id]);
        }
    } elseif (isset($_POST['course_id'])) {
        $course_id = (int)$_POST['course_id'];
        if (in_array($action, ['approve', 'reject'], true)) {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $pdo->prepare('UPDATE courses SET status = ? WHERE course_id = ?');
            $stmt->execute([$status, $course_id]);
        } elseif ($action === 'delete_course') {
            $stmt = $pdo->prepare('DELETE FROM courses WHERE course_id = ?');
            $stmt->execute([$course_id]);
        }
    } elseif (isset($_POST['quiz_id']) && $action === 'delete_quiz') {
        $stmt = $pdo->prepare('DELETE FROM quizzes WHERE quiz_id = ?');
        $stmt->execute([(int)$_POST['quiz_id']]);
    } elseif (isset($_POST['assignment_id']) && $action === 'delete_assignment') {
        $stmt = $pdo->prepare('DELETE FROM assignments WHERE assignment_id = ?');
        $stmt->execute([(int)$_POST['assignment_id']]);
    } elseif (isset($_POST['material_id']) && $action === 'delete_material') {
        $material_id = (int)$_POST['material_id'];
        $stmt = $pdo->prepare('SELECT file_path FROM materials WHERE material_id = ?');
        $stmt->execute([$material_id]);
        $material = $stmt->fetch();
        $stmt = $pdo->prepare('DELETE FROM materials WHERE material_id = ?');
        $stmt->execute([$material_id]);
        if ($material) {
            $file_path = dirname(__DIR__) . '/uploads/materials/' . basename($material['file_path']);
            if (is_file($file_path)) { unlink($file_path); }
        }
    }

    header('Location: dashboard.php');
    exit();
}

// Fetch all pending non-admin users
$stmt = $pdo->query("SELECT * FROM users WHERE role <> 'admin' AND status = 'pending' ORDER BY created_at ASC");
$pending_users = $stmt->fetchAll();

$pending_courses_stmt = $pdo->query("SELECT c.course_id, c.title, c.description, c.created_at, u.name AS instructor_name FROM courses c JOIN users u ON u.user_id = c.instructor_id WHERE c.status = 'pending' ORDER BY c.created_at ASC");
$pending_courses = $pending_courses_stmt->fetchAll();

$stats = [
    'users' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'admin'")->fetchColumn(),
    'students' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn(),
    'instructors' => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'instructor'")->fetchColumn(),
    'courses' => (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    'quizzes' => (int)$pdo->query("SELECT COUNT(*) FROM quizzes")->fetchColumn(),
    'enrollments' => (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE status = 'approved'")->fetchColumn(),
];

$user_search = trim($_GET['user_search'] ?? '');
$user_role = $_GET['user_role'] ?? 'all';
$user_status = $_GET['user_status'] ?? 'all';
$user_sql = "SELECT user_id, name, email, role, status, created_at FROM users WHERE 1=1";
$user_params = [];
if ($user_search !== '') {
    $user_sql .= " AND (name LIKE ? OR email LIKE ?)";
    $user_params[] = "%$user_search%";
    $user_params[] = "%$user_search%";
}
if (in_array($user_role, ['student', 'instructor', 'admin'], true)) {
    $user_sql .= " AND role = ?";
    $user_params[] = $user_role;
}
if (in_array($user_status, ['pending', 'approved', 'rejected'], true)) {
    $user_sql .= " AND status = ?";
    $user_params[] = $user_status;
}
$user_sql .= " ORDER BY created_at DESC";
$users_stmt = $pdo->prepare($user_sql);
$users_stmt->execute($user_params);
$all_users = $users_stmt->fetchAll();

$course_stmt = $pdo->query("\n    SELECT c.course_id, c.title, c.status, u.name AS instructor_name,\n           (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.course_id AND e.status = 'approved') AS enrolled_students,\n           (SELECT COUNT(*) FROM quizzes q WHERE q.course_id = c.course_id) AS quiz_count\n    FROM courses c\n    JOIN users u ON u.user_id = c.instructor_id\n    ORDER BY c.created_at DESC\n");
$course_overview = $course_stmt->fetchAll();

$quizzes_stmt = $pdo->query("SELECT q.quiz_id, q.title, q.course_id, c.title AS course_title FROM quizzes q JOIN courses c ON c.course_id = q.course_id ORDER BY q.quiz_id DESC");
$all_quizzes = $quizzes_stmt->fetchAll();
$assignments_stmt = $pdo->query("SELECT a.assignment_id, a.title, a.course_id, c.title AS course_title, a.due_at, a.created_at, COUNT(s.submission_id) AS submissions FROM assignments a JOIN courses c ON c.course_id = a.course_id LEFT JOIN assignment_submissions s ON s.assignment_id = a.assignment_id GROUP BY a.assignment_id, a.title, a.course_id, c.title, a.due_at, a.created_at ORDER BY a.created_at DESC");
$all_assignments = $assignments_stmt->fetchAll();
$materials_stmt = $pdo->query("SELECT m.material_id, m.type, m.file_path, m.course_id, c.title AS course_title, m.uploaded_at FROM materials m JOIN courses c ON c.course_id = m.course_id ORDER BY m.uploaded_at DESC");
$all_materials = $materials_stmt->fetchAll();
$result_search = trim($_GET['result_search'] ?? '');
$results_sql = "SELECT u.name AS student_name, u.email AS student_email, q.title AS quiz_title, c.title AS course_title, r.score, r.total_questions, r.submitted_at FROM results r JOIN users u ON u.user_id = r.student_id JOIN quizzes q ON q.quiz_id = r.quiz_id JOIN courses c ON c.course_id = q.course_id WHERE 1=1";
$result_params = [];
if ($result_search !== '') {
    $results_sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR q.title LIKE ? OR c.title LIKE ?)';
    $result_pattern = "%{$result_search}%";
    $result_params = [$result_pattern, $result_pattern, $result_pattern, $result_pattern];
}
$results_sql .= ' ORDER BY r.submitted_at DESC, r.result_id DESC';
$results_stmt = $pdo->prepare($results_sql);
$results_stmt->execute($result_params);
$recent_results = $results_stmt->fetchAll();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/eduquiz.css" rel="stylesheet">
</head>
<body class="bg-light eduquiz-shell">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand" href="#">EduQuiz Admin Panel</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">Admin: <?= htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="dashboard-intro">
        <div>
            <div class="eyebrow">Platform control room</div>
            <h1>Keep EduQuiz in balance.</h1>
            <p>Review people, courses, learning activity, and the health of the platform.</p>
        </div>
        <span class="badge bg-warning text-dark px-3 py-2">Administrator access</span>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach ([
            ['label' => 'Total Users', 'value' => $stats['users'], 'class' => 'primary'],
            ['label' => 'Students', 'value' => $stats['students'], 'class' => 'success'],
            ['label' => 'Instructors', 'value' => $stats['instructors'], 'class' => 'info'],
            ['label' => 'Courses', 'value' => $stats['courses'], 'class' => 'warning'],
            ['label' => 'Quizzes', 'value' => $stats['quizzes'], 'class' => 'dark'],
            ['label' => 'Approved Enrollments', 'value' => $stats['enrollments'], 'class' => 'secondary'],
        ] as $stat): ?>
            <div class="col-6 col-md-4 col-lg-2">
                <div class="card metric-card border-<?= $stat['class']; ?> h-100">
                    <div class="card-body">
                        <div class="text-muted small"><?= $stat['label']; ?></div>
                        <div class="fs-3 fw-bold"><?= $stat['value']; ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="section-title"><h2>Needs attention</h2><span class="eyebrow">Approvals queue</span></div>
    
    <div class="card shadow-sm p-3 mb-4">
        <?php if (count($pending_users) > 0): ?>
            <table class="table table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Registered Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_users as $user): ?>
                        <tr>
                            <td><?= htmlspecialchars($user['name']); ?></td>
                            <td><?= htmlspecialchars($user['email']); ?></td>
                            <td><?= htmlspecialchars(ucfirst($user['role'])); ?></td>
                            <td><?= htmlspecialchars($user['created_at'] ?? ''); ?></td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <?= csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int)$user['user_id']; ?>"><input type="hidden" name="action" value="approve">
                                    <button class="btn btn-success btn-sm me-1" type="submit">Approve</button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <?= csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int)$user['user_id']; ?>"><input type="hidden" name="action" value="reject">
                                    <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="text-muted mb-0">No pending user approvals found.</p>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-warning text-dark"><strong>Pending Course Approvals</strong></div>
        <div class="card-body">
            <?php if ($pending_courses): ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>Course</th><th>Instructor</th><th>Description</th><th>Action</th></tr></thead>
                        <tbody>
                            <?php foreach ($pending_courses as $course): ?>
                                <tr>
                                    <td><?= htmlspecialchars($course['title']); ?></td>
                                    <td><?= htmlspecialchars($course['instructor_name']); ?></td>
                                    <td><?= htmlspecialchars($course['description'] ?? ''); ?></td>
                                    <td class="text-nowrap">
                                        <form method="POST" class="d-inline">
                                            <?= csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int)$course['course_id']; ?>"><input type="hidden" name="action" value="approve">
                                            <button class="btn btn-success btn-sm" type="submit">Approve</button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <?= csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int)$course['course_id']; ?>"><input type="hidden" name="action" value="reject">
                                            <button class="btn btn-danger btn-sm" type="submit">Reject</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No pending course approvals found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><strong>All Users</strong></div>
        <div class="card-body">
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-5"><input type="search" name="user_search" class="form-control" placeholder="Search name or email" value="<?= htmlspecialchars($user_search); ?>"></div>
                <div class="col-md-3"><select name="user_role" class="form-select"><option value="all">All Roles</option><option value="student" <?= $user_role === 'student' ? 'selected' : ''; ?>>Student</option><option value="instructor" <?= $user_role === 'instructor' ? 'selected' : ''; ?>>Instructor</option><option value="admin" <?= $user_role === 'admin' ? 'selected' : ''; ?>>Admin</option></select></div>
                <div class="col-md-3"><select name="user_status" class="form-select"><option value="all">All Statuses</option><option value="pending" <?= $user_status === 'pending' ? 'selected' : ''; ?>>Pending</option><option value="approved" <?= $user_status === 'approved' ? 'selected' : ''; ?>>Approved</option><option value="rejected" <?= $user_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option></select></div>
                <div class="col-md-1"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_users as $user): ?>
                            <?php $user_status_class = $user['status'] === 'approved' ? 'success' : ($user['status'] === 'pending' ? 'warning' : 'danger'); ?>
                            <tr>
                                <td><?= htmlspecialchars($user['name']); ?></td>
                                <td><?= htmlspecialchars($user['email']); ?></td>
                                <td><?= htmlspecialchars(ucfirst($user['role'])); ?></td>
                                <td><span class="badge bg-<?= $user_status_class; ?>"><?= htmlspecialchars(ucfirst($user['status'])); ?></span></td>
                                <td><?= htmlspecialchars($user['created_at']); ?></td>
                                <td class="text-nowrap">
                                    <?php if ($user['role'] !== 'admin'): ?>
                                        <form method="POST" class="d-inline"><?= csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int)$user['user_id']; ?>"><input type="hidden" name="action" value="<?= $user['status'] === 'approved' ? 'reject' : 'activate'; ?>"><button class="btn btn-outline-<?= $user['status'] === 'approved' ? 'warning' : 'success'; ?> btn-sm"><?= $user['status'] === 'approved' ? 'Suspend' : 'Activate'; ?></button></form>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user and related records?');"><?= csrf_field(); ?><input type="hidden" name="user_id" value="<?= (int)$user['user_id']; ?>"><input type="hidden" name="action" value="delete_user"><button class="btn btn-outline-danger btn-sm">Delete</button></form>
                                    <?php else: ?><span class="text-muted small">Protected</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white"><strong>Course Overview</strong></div>
        <div class="card-body">
            <?php if ($course_overview): ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle mb-0">
                        <thead><tr><th>Course</th><th>Instructor</th><th>Status</th><th>Students</th><th>Quizzes</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($course_overview as $course): ?>
                                <tr>
                                    <td><?= htmlspecialchars($course['title']); ?></td>
                                    <td><?= htmlspecialchars($course['instructor_name']); ?></td>
                                    <td><?= htmlspecialchars(ucfirst($course['status'])); ?></td>
                                    <td><?= (int)$course['enrolled_students']; ?></td>
                                    <td><?= (int)$course['quiz_count']; ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($course['status'] !== 'approved'): ?><form method="POST" class="d-inline"><?= csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int)$course['course_id']; ?>"><input type="hidden" name="action" value="approve"><button class="btn btn-outline-success btn-sm">Approve</button></form><?php endif; ?>
                                        <?php if ($course['status'] === 'approved'): ?><form method="POST" class="d-inline"><?= csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int)$course['course_id']; ?>"><input type="hidden" name="action" value="reject"><button class="btn btn-outline-warning btn-sm">Reject</button></form><?php endif; ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this course and all related content?');"><?= csrf_field(); ?><input type="hidden" name="course_id" value="<?= (int)$course['course_id']; ?>"><input type="hidden" name="action" value="delete_course"><button class="btn btn-outline-danger btn-sm">Delete</button></form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">No courses found.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white"><strong>Quiz Management</strong></div>
        <div class="list-group list-group-flush">
            <?php foreach ($all_quizzes as $quiz): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-3"><div><strong><?= htmlspecialchars($quiz['title']); ?></strong><br><small class="text-muted">Course: <?= htmlspecialchars($quiz['course_title']); ?></small></div><form method="POST" onsubmit="return confirm('Delete this quiz and its questions/results?');"><?= csrf_field(); ?><input type="hidden" name="quiz_id" value="<?= (int)$quiz['quiz_id']; ?>"><input type="hidden" name="action" value="delete_quiz"><button class="btn btn-outline-danger btn-sm">Delete Quiz</button></form></div>
            <?php endforeach; ?>
            <?php if (!$all_quizzes): ?><div class="list-group-item text-muted">No quizzes found.</div><?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white"><strong>Task Management</strong></div>
        <div class="list-group list-group-flush">
            <?php foreach ($all_assignments as $assignment): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-3"><div><strong><?= htmlspecialchars($assignment['title']); ?></strong><br><small class="text-muted">Course: <?= htmlspecialchars($assignment['course_title']); ?> · <?= (int)$assignment['submissions']; ?> submissions</small></div><form method="POST" onsubmit="return confirm('Delete this task and submissions?');"><?= csrf_field(); ?><input type="hidden" name="assignment_id" value="<?= (int)$assignment['assignment_id']; ?>"><input type="hidden" name="action" value="delete_assignment"><button class="btn btn-outline-danger btn-sm">Delete Task</button></form></div>
            <?php endforeach; ?>
            <?php if (!$all_assignments): ?><div class="list-group-item text-muted">No tasks found.</div><?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white"><strong>Material Management</strong></div>
        <div class="list-group list-group-flush">
            <?php foreach ($all_materials as $material): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-3"><div><strong><?= htmlspecialchars(strtoupper($material['type'])); ?></strong> · <?= htmlspecialchars($material['file_path']); ?><br><small class="text-muted">Course: <?= htmlspecialchars($material['course_title']); ?></small></div><form method="POST" onsubmit="return confirm('Delete this material?');"><?= csrf_field(); ?><input type="hidden" name="material_id" value="<?= (int)$material['material_id']; ?>"><input type="hidden" name="action" value="delete_material"><button class="btn btn-outline-danger btn-sm">Delete Material</button></form></div>
            <?php endforeach; ?>
            <?php if (!$all_materials): ?><div class="list-group-item text-muted">No materials found.</div><?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm mb-5">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center gap-3"><strong>Recent Quiz Results</strong><span class="badge bg-light text-dark"><?= count($recent_results); ?> results</span></div>
        <div class="card-body border-bottom">
            <form method="GET" class="row g-2">
                <div class="col-md-10"><input type="search" name="result_search" class="form-control" value="<?= htmlspecialchars($result_search); ?>" placeholder="Search student, email, quiz or course"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Search Results</button></div>
            </form>
        </div>
        <div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead><tr><th>Student</th><th>Quiz</th><th>Score</th><th>Submitted</th></tr></thead><tbody>
        <?php foreach ($recent_results as $result): ?><tr><td><?= htmlspecialchars($result['student_name']); ?></td><td><?= htmlspecialchars($result['quiz_title']); ?></td><td><?= (int)$result['score']; ?> / <?= (int)$result['total_questions']; ?></td><td><?= htmlspecialchars($result['submitted_at']); ?></td></tr><?php endforeach; ?>
        <?php if (!$recent_results): ?><tr><td colspan="4" class="text-muted">No quiz results found.</td></tr><?php endif; ?></tbody></table></div>
    </div>
</div>

</body>
</html>