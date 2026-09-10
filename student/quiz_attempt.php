<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$quiz_id = $_GET['quiz_id'] ?? null;

// Fetch Quiz
$stmt = $pdo->prepare("SELECT q.* FROM quizzes q JOIN enrollments e ON e.course_id = q.course_id JOIN courses c ON c.course_id = q.course_id WHERE q.quiz_id = ? AND e.student_id = ? AND e.status = 'approved' AND c.status = 'approved' AND c.visibility <> 'hidden'");
$stmt->execute([$quiz_id, $_SESSION['user_id']]);
$quiz = $stmt->fetch();

if (!$quiz) {
    die("Quiz not found!");
}

$timer_key = 'quiz_timer_' . (int)$quiz_id;
$answer_key = 'quiz_answers_' . (int)$quiz_id;
$quiz_duration_seconds = max(1, (int)$quiz['duration_minutes']) * 60;

if (!isset($_SESSION[$timer_key])) {
    $_SESSION[$timer_key] = time();
    unset($_SESSION[$answer_key]);
}

$quiz_started_at = (int)$_SESSION[$timer_key];
$remaining_seconds = ($quiz_started_at + $quiz_duration_seconds) - time();

// Fetch Questions
$stmt_q = $pdo->prepare("SELECT * FROM questions WHERE quiz_id = ?");
$stmt_q->execute([$quiz_id]);
$questions = $stmt_q->fetchAll();

if (count($questions) === 0) {
    $current_index = 0;
} else {
    $current_index = max(0, min((int)($_GET['question'] ?? 0), count($questions) - 1));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($questions) > 0) {
    verify_csrf_token();
    if ($remaining_seconds <= 0) {
        $_SESSION['quiz_submission'] = [
            'quiz_id' => (int)$quiz_id,
            'answers' => $_SESSION[$answer_key] ?? [],
            'timer_started_at' => $quiz_started_at,
            'time_expired' => true,
        ];
        unset($_SESSION[$answer_key], $_SESSION[$timer_key]);
        header('Location: quiz_submit.php');
        exit();
    }

    $posted_question_id = (int)($_POST['question_id'] ?? 0);
    $posted_option_id = (int)($_POST['option_id'] ?? 0);

    foreach ($questions as $question_index => $question) {
        if ((int)$question['question_id'] === $posted_question_id) {
            $current_index = $question_index;
            break;
        }
    }

    $option_stmt = $pdo->prepare("SELECT option_id FROM options WHERE option_id = ? AND question_id = ?");
    $option_stmt->execute([$posted_option_id, $posted_question_id]);
    if (!$option_stmt->fetch()) {
        $error = 'Please select a valid answer.';
    } else {
        $_SESSION[$answer_key][$posted_question_id] = $posted_option_id;
        if ($current_index === count($questions) - 1) {
            $answers = $_SESSION[$answer_key];
            unset($_SESSION[$answer_key]);
            $_SESSION['quiz_submission'] = [
                'quiz_id' => (int)$quiz_id,
                'answers' => $answers,
                'timer_started_at' => $quiz_started_at,
            ];
            unset($_SESSION[$timer_key]);
            header('Location: quiz_submit.php');
            exit();
        }

        header('Location: quiz_attempt.php?quiz_id=' . (int)$quiz_id . '&question=' . ($current_index + 1));
        exit();
    }
}

$current_question = $questions[$current_index] ?? null;
$selected_option = $current_question
    ? ($_SESSION[$answer_key][$current_question['question_id']] ?? null)
    : null;
$options = [];
if ($current_question) {
    $stmt_o = $pdo->prepare("SELECT * FROM options WHERE question_id = ?");
    $stmt_o->execute([$current_question['question_id']]);
    $options = $stmt_o->fetchAll();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Attempt Quiz - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4 mb-5" style="max-width: 650px;">
    <div class="card shadow p-4">
        <h3 class="mb-1"><?= htmlspecialchars($quiz['title']); ?></h3>
        <div class="d-flex justify-content-between align-items-center">
            <p class="text-muted mb-0">Time Limit: <?= $quiz['duration_minutes']; ?> Minutes</p>
            <span id="quiz-timer" class="badge bg-danger fs-6" data-remaining="<?= max(0, $remaining_seconds); ?>">00:00</span>
        </div>
        <hr>

        <?php if (count($questions) > 0): ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="badge bg-secondary">Question <?= $current_index + 1; ?> of <?= count($questions); ?></span>
                <span class="text-muted small">Your answers are saved as you continue.</span>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <?= csrf_field(); ?>
                <input type="hidden" name="question_id" value="<?= (int)$current_question['question_id']; ?>">
                <div class="mb-4">
                    <h5>Q<?= $current_index + 1; ?>. <?= htmlspecialchars($current_question['question_text']); ?></h5>
                    <?php foreach ($options as $opt): ?>
                        <div class="form-check my-2">
                            <input class="form-check-input" type="radio" name="option_id" value="<?= (int)$opt['option_id']; ?>" required <?= (int)$selected_option === (int)$opt['option_id'] ? 'checked' : ''; ?>>
                            <label class="form-check-label">
                                <?= htmlspecialchars($opt['option_text']); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <?= $current_index === count($questions) - 1 ? 'Submit Quiz' : 'Next Question'; ?>
                </button>
            </form>
            <form id="timeout-form" method="POST" action="quiz_submit.php" class="d-none">
                <?= csrf_field(); ?>
                <input type="hidden" name="timeout" value="1">
                <input type="hidden" name="quiz_id" value="<?= (int)$quiz_id; ?>">
            </form>
        <?php else: ?>
            <div class="alert alert-warning text-center">
                This quiz does not have any questions yet!
            </div>
            <a href="course_view.php" class="btn btn-outline-secondary w-100">Go Back</a>
        <?php endif; ?>
    </div>
</div>

<script>
    const quizTimer = document.getElementById('quiz-timer');
    let remainingSeconds = Number(quizTimer.dataset.remaining);

    function updateTimer() {
        const minutes = Math.floor(remainingSeconds / 60).toString().padStart(2, '0');
        const seconds = (remainingSeconds % 60).toString().padStart(2, '0');
        quizTimer.textContent = `${minutes}:${seconds}`;

        if (remainingSeconds <= 0) {
            document.getElementById('timeout-form').submit();
            return;
        }

        remainingSeconds -= 1;
        window.setTimeout(updateTimer, 1000);
    }

    updateTimer();
</script>

</body>
</html>