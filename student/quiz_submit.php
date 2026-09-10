<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$score = 0;
$total_questions = 0;
$time_expired = false;
$answer_review = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_SESSION['quiz_submission'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
    }
    $student_id = $_SESSION['user_id'];
    $submission = $_SESSION['quiz_submission'] ?? [];
    unset($_SESSION['quiz_submission']);
    $quiz_id = $submission['quiz_id'] ?? ($_POST['quiz_id'] ?? ($_GET['quiz_id'] ?? null));
    $timer_key = 'quiz_timer_' . (int)$quiz_id;
    $answer_key = 'quiz_answers_' . (int)$quiz_id;
    $is_timeout_request = isset($_POST['timeout']);
    $timer_started_at = (int)($submission['timer_started_at'] ?? ($_SESSION[$timer_key] ?? 0));
    $time_expired = !empty($submission['time_expired']);
    $user_answers = $submission['answers'] ?? ($_POST['answers'] ?? ($is_timeout_request ? ($_SESSION[$answer_key] ?? []) : []));

    if ($is_timeout_request && $timer_started_at === 0) {
        http_response_code(403);
        exit('This quiz attempt has not started.');
    }

    unset($_SESSION[$timer_key], $_SESSION[$answer_key]);

    $quiz_stmt = $pdo->prepare("SELECT q.quiz_id FROM quizzes q JOIN enrollments e ON e.course_id = q.course_id JOIN courses c ON c.course_id = q.course_id WHERE q.quiz_id = ? AND e.student_id = ? AND e.status = 'approved' AND c.status = 'approved' AND c.visibility <> 'hidden'");
    $quiz_stmt->execute([$quiz_id, $student_id]);
    if (!$quiz_stmt->fetch()) {
        http_response_code(403);
        exit('You are not allowed to submit this quiz.');
    }

    $duration_stmt = $pdo->prepare('SELECT duration_minutes FROM quizzes WHERE quiz_id = ?');
    $duration_stmt->execute([$quiz_id]);
    $duration_minutes = (int)$duration_stmt->fetchColumn();
    if ($timer_started_at > 0 && time() > $timer_started_at + ($duration_minutes * 60) && !$time_expired) {
        http_response_code(403);
        exit('Quiz time has expired.');
    }

    if ($is_timeout_request) {
        $time_expired = true;
    }

    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM questions WHERE quiz_id = ?");
    $count_stmt->execute([$quiz_id]);
    $total_questions = (int)$count_stmt->fetchColumn();

    $questions_stmt = $pdo->prepare('SELECT question_id, question_text FROM questions WHERE quiz_id = ? ORDER BY question_id');
    $questions_stmt->execute([$quiz_id]);
    $quiz_questions = $questions_stmt->fetchAll();

    foreach ($quiz_questions as $question) {
        $options_stmt = $pdo->prepare('SELECT option_id, option_text, is_correct FROM options WHERE question_id = ? ORDER BY option_id');
        $options_stmt->execute([$question['question_id']]);
        $options = $options_stmt->fetchAll();
        $selected_option_id = isset($user_answers[$question['question_id']]) ? (int)$user_answers[$question['question_id']] : null;
        $selected_option = null;
        $correct_option = null;

        foreach ($options as $option) {
            if ((int)$option['option_id'] === $selected_option_id) {
                $selected_option = $option;
            }
            if ((int)$option['is_correct'] === 1) {
                $correct_option = $option;
            }
        }

        $is_correct = $selected_option !== null && (int)$selected_option['is_correct'] === 1;
        if ($is_correct) {
            $score++;
        }

        $answer_review[] = [
            'question_text' => $question['question_text'],
            'selected_text' => $selected_option['option_text'] ?? 'Not answered',
            'correct_text' => $correct_option['option_text'] ?? 'No correct answer set',
            'is_correct' => $is_correct,
            'is_answered' => $selected_option !== null,
        ];
    }

    if ($quiz_id) {
        $columns_stmt = $pdo->query("DESCRIBE results");
        $existing_columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
        $user_col = in_array('student_id', $existing_columns) ? 'student_id' : 'user_id';

        $elapsed_seconds = $timer_started_at > 0
            ? min(max(0, time() - $timer_started_at), max(1, $duration_minutes * 60))
            : null;

        $latest_result_stmt = $pdo->prepare("SELECT result_id FROM results WHERE $user_col = ? AND quiz_id = ? ORDER BY result_id DESC LIMIT 1");
        $latest_result_stmt->execute([$student_id, $quiz_id]);
        $latest_result_id = $latest_result_stmt->fetchColumn();

        if ($latest_result_id) {
            $update_values = [$score];
            $update_parts = ['score = ?'];
            if (in_array('total_questions', $existing_columns, true)) {
                $update_parts[] = 'total_questions = ?';
                $update_values[] = $total_questions;
            }
            if (in_array('elapsed_seconds', $existing_columns, true)) {
                $update_parts[] = 'elapsed_seconds = ?';
                $update_values[] = $elapsed_seconds;
            }
            if (in_array('submitted_at', $existing_columns, true)) {
                $update_parts[] = 'submitted_at = CURRENT_TIMESTAMP';
            }
            $update_values[] = $latest_result_id;
            $stmt_res = $pdo->prepare('UPDATE results SET ' . implode(', ', $update_parts) . ' WHERE result_id = ?');
            $stmt_res->execute($update_values);
        } elseif (in_array('total_questions', $existing_columns, true)) {
            $sql = "INSERT INTO results ($user_col, quiz_id, score, total_questions, elapsed_seconds) VALUES (?, ?, ?, ?, ?)";
            $stmt_res = $pdo->prepare($sql);
            $stmt_res->execute([$student_id, $quiz_id, $score, $total_questions, $elapsed_seconds]);
        } else {
            $sql = "INSERT INTO results ($user_col, quiz_id, score, elapsed_seconds) VALUES (?, ?, ?, ?)";
            $stmt_res = $pdo->prepare($sql);
            $stmt_res->execute([$student_id, $quiz_id, $score, $elapsed_seconds]);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Quiz Result - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5" style="max-width: 500px;">
    <div class="card shadow p-4 text-center">
        <h2 class="text-success mb-3"><?= $time_expired ? 'Time Finished!' : 'Quiz Completed!'; ?></h2>
        <h4>Your Score</h4>
        <h1 class="display-3 text-primary my-3"><?= $score; ?> / <?= $total_questions; ?></h1>

        <?php if (!empty($answer_review)): ?>
            <div class="text-start mt-4">
                <h4 class="mb-3">Answer Review</h4>
                <?php foreach ($answer_review as $index => $review): ?>
                    <?php $status_class = $review['is_correct'] ? 'success' : ($review['is_answered'] ? 'danger' : 'warning'); ?>
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between gap-2">
                            <strong>Q<?= $index + 1; ?>. <?= htmlspecialchars($review['question_text']); ?></strong>
                            <span class="badge bg-<?= $status_class; ?>">
                                <?= $review['is_correct'] ? 'Correct' : ($review['is_answered'] ? 'Wrong' : 'Not answered'); ?>
                            </span>
                        </div>
                        <div class="mt-2 small">
                            <div>Your answer: <strong><?= htmlspecialchars($review['selected_text']); ?></strong></div>
                            <?php if (!$review['is_correct']): ?>
                                <div class="text-success">Correct answer: <strong><?= htmlspecialchars($review['correct_text']); ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <div class="d-flex gap-2 mt-3">
            <?php if (!empty($quiz_id)): ?>
                <a href="quiz_attempt.php?quiz_id=<?= (int)$quiz_id; ?>" class="btn btn-success flex-grow-1">Retake Quiz</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-primary flex-grow-1">Back to Dashboard</a>
        </div>
    </div>
</div>

</body>
</html>