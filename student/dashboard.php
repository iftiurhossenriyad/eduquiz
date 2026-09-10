<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$student_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_notifications_read'])) {
    verify_csrf_token();
    $mark_read = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE student_id = ?');
    $mark_read->execute([$student_id]);
    header('Location: dashboard.php');
    exit();
}

$notifications_stmt = $pdo->prepare("SELECT n.*, c.title AS course_title FROM notifications n JOIN courses c ON c.course_id = n.course_id WHERE n.student_id = ? AND c.visibility <> 'hidden' ORDER BY n.created_at DESC LIMIT 20");
$notifications_stmt->execute([$student_id]);
$notifications = $notifications_stmt->fetchAll();
$unread_notifications = 0;
foreach ($notifications as $notification) {
    $unread_notifications += (int)$notification['is_read'] === 0 ? 1 : 0;
}

$stmt = $pdo->prepare("SELECT r.*, q.title FROM results r JOIN quizzes q ON r.quiz_id = q.quiz_id WHERE r.student_id = ? AND NOT EXISTS (SELECT 1 FROM results earlier WHERE earlier.student_id = r.student_id AND earlier.quiz_id = r.quiz_id AND earlier.result_id < r.result_id) ORDER BY r.result_id DESC");
$stmt->execute([$student_id]);
$results = $stmt->fetchAll();
$trend_stmt = $pdo->prepare("SELECT q.title, r.score, r.total_questions, r.submitted_at FROM results r JOIN quizzes q ON r.quiz_id = q.quiz_id WHERE r.student_id = ? ORDER BY r.submitted_at ASC, r.result_id ASC");
$trend_stmt->execute([$student_id]);
$trend_rows = $trend_stmt->fetchAll();
$progress = [];
foreach ($trend_rows as $trend_row) {
    $key = $trend_row['title'];
    $total = max(1, (int)$trend_row['total_questions']);
    $percent = round(((int)$trend_row['score'] / $total) * 100);
    if (!isset($progress[$key])) {
        $progress[$key] = ['first' => $percent, 'latest' => $percent, 'attempts' => 0];
    }
    $progress[$key]['latest'] = $percent;
    $progress[$key]['attempts']++;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/eduquiz.css" rel="stylesheet">
</head>
<body class="bg-light eduquiz-shell">

<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container">
        <a class="navbar-brand font-weight-bold" href="dashboard.php">EduQuiz Student Panel</a>
        <div class="d-flex">
            <span class="navbar-text text-white me-3">Welcome, <?= htmlspecialchars($_SESSION['name']); ?></span>
            <a href="../auth/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-12 mb-4">
            <div class="dashboard-intro">
                <div>
                    <div class="eyebrow">Student workspace</div>
                    <h1>Keep your learning moving.</h1>
                    <p>Pick up a quiz, check your next task, and see how your scores are changing.</p>
                </div>
                <a href="course_view.php" class="btn btn-success">Browse Courses & Quizzes &rarr;</a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12 mb-3">
            <div class="card accent-panel">
                <div class="card-body">
                    <div class="section-title mt-0">
                        <h2>Notifications <span class="badge bg-danger"><?= $unread_notifications; ?> unread</span></h2>
                        <?php if ($unread_notifications > 0): ?><form method="POST"><?= csrf_field(); ?><button name="mark_notifications_read" class="btn btn-outline-secondary btn-sm">Mark all as read</button></form><?php endif; ?>
                    </div>
                    <?php if ($notifications): ?>
                        <div class="list-group">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="list-group-item <?= (int)$notification['is_read'] === 0 ? 'notification-unread' : ''; ?>">
                                    <div class="d-flex justify-content-between gap-2"><strong><?= htmlspecialchars($notification['title']); ?></strong><small><?= htmlspecialchars($notification['created_at']); ?></small></div>
                                    <div><?= htmlspecialchars($notification['message']); ?></div>
                                    <small class="text-muted">Course: <?= htmlspecialchars($notification['course_title']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?><p class="text-muted mb-0">No notifications yet.</p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-12 mb-3">
            <div class="card">
                <div class="card-body">
                    <div class="section-title mt-0"><h2>Improvement Tracking</h2></div>
                    <?php if ($progress): ?>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Quiz</th><th>First Score</th><th>Latest Score</th><th>Change</th><th>Attempts</th></tr></thead><tbody>
                        <?php foreach ($progress as $quiz_title => $trend): ?>
                            <?php $change = $trend['latest'] - $trend['first']; ?>
                            <tr><td><?= htmlspecialchars($quiz_title); ?></td><td><?= $trend['first']; ?>%</td><td><?= $trend['latest']; ?>%</td><td><span class="badge bg-<?= $change >= 0 ? 'success' : 'danger'; ?>"><?= $change >= 0 ? '+' : ''; ?><?= $change; ?>%</span></td><td><?= $trend['attempts']; ?></td></tr>
                        <?php endforeach; ?></tbody></table></div>
                    <?php else: ?><p class="text-muted mb-0">Complete a quiz to start tracking improvement.</p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-12">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="section-title mt-0"><h2>Recent Quiz Results</h2></div>
                    <?php if (!empty($results) && count($results) > 0): ?>
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Quiz Title</th>
                                    <th>Score</th>
                                    <th>Total Questions</th>
                                    <th>Completion Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($results as $res): ?>
                                    <?php
                                    $t_q = (isset($res['total_questions']) && $res['total_questions'] > 0) ? $res['total_questions'] : 0;
                                    if ($t_q == 0) {
                                        $stmt_q_cnt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
                                        $stmt_q_cnt->execute([$res['quiz_id']]);
                                        $t_q = $stmt_q_cnt->fetchColumn();
                                    }
                                    $elapsed_seconds = isset($res['elapsed_seconds']) ? (int)$res['elapsed_seconds'] : null;
                                    $time_label = $elapsed_seconds === null
                                        ? 'Not recorded'
                                        : floor($elapsed_seconds / 60) . 'm ' . ($elapsed_seconds % 60) . 's';
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($res['title']); ?></td>
                                        <td><span class="badge bg-success"><?= $res['score']; ?></span></td>
                                        <td><?= $t_q; ?></td>
                                        <td><?= htmlspecialchars($time_label); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="card-text text-muted">No quiz attempts found yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>