<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/notifications.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header('Location: ../auth/login.php');
    exit();
}

$quiz_id = (int)($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);
$quiz_stmt = $pdo->prepare(
    'SELECT q.* FROM quizzes q JOIN courses c ON q.course_id = c.course_id WHERE q.quiz_id = ? AND c.instructor_id = ?'
);
$quiz_stmt->execute([$quiz_id, $_SESSION['user_id']]);
$quiz = $quiz_stmt->fetch();

if (!$quiz) {
    header('Location: dashboard.php');
    exit();
}

$questions_stmt = $pdo->prepare('SELECT * FROM questions WHERE quiz_id = ? ORDER BY question_id');
$questions_stmt->execute([$quiz_id]);
$questions = $questions_stmt->fetchAll();

foreach ($questions as &$question) {
    $options_stmt = $pdo->prepare('SELECT * FROM options WHERE question_id = ? ORDER BY option_id');
    $options_stmt->execute([$question['question_id']]);
    $question['options'] = $options_stmt->fetchAll();
}
unset($question);

$message = '';
$message_type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $quiz_title = trim($_POST['quiz_title'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);
    $posted_questions = $_POST['questions'] ?? [];
    $valid_questions = [];

    foreach ($posted_questions as $question) {
        $question_text = trim($question['text'] ?? '');
        $options = array_map('trim', $question['options'] ?? []);
        $correct_option = filter_var($question['correct'] ?? null, FILTER_VALIDATE_INT);

        if ($question_text === '' || count($options) !== 4 || in_array('', $options, true) || $correct_option === false || $correct_option < 0 || $correct_option > 3) {
            $valid_questions = [];
            break;
        }

        $valid_questions[] = [
            'text' => $question_text,
            'options' => $options,
            'correct' => $correct_option,
        ];
    }

    if ($quiz_title === '' || $duration < 1 || count($valid_questions) === 0) {
        $message = 'Please provide a title, a valid duration, and complete all questions.';
    } else {
        try {
            $pdo->beginTransaction();

            $update_quiz = $pdo->prepare('UPDATE quizzes SET title = ?, duration_minutes = ?, total_marks = ? WHERE quiz_id = ?');
            $update_quiz->execute([$quiz_title, $duration, count($valid_questions), $quiz_id]);

            $old_questions_stmt = $pdo->prepare('SELECT question_id FROM questions WHERE quiz_id = ?');
            $old_questions_stmt->execute([$quiz_id]);
            $old_question_ids = $old_questions_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($old_question_ids) > 0) {
                $placeholders = implode(',', array_fill(0, count($old_question_ids), '?'));
                $delete_options = $pdo->prepare("DELETE FROM options WHERE question_id IN ($placeholders)");
                $delete_options->execute($old_question_ids);
            }

            $delete_questions = $pdo->prepare('DELETE FROM questions WHERE quiz_id = ?');
            $delete_questions->execute([$quiz_id]);

            foreach ($valid_questions as $question) {
                $insert_question = $pdo->prepare('INSERT INTO questions (quiz_id, question_text) VALUES (?, ?)');
                $insert_question->execute([$quiz_id, $question['text']]);
                $question_id = $pdo->lastInsertId();

                foreach ($question['options'] as $option_index => $option_text) {
                    $insert_option = $pdo->prepare('INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)');
                    $insert_option->execute([$question_id, $option_text, $option_index === $question['correct'] ? 1 : 0]);
                }
            }

            $pdo->commit();
            notify_enrolled_students($pdo, (int)$quiz['course_id'], 'quiz_update', 'Quiz updated', $quiz_title . ' was updated by the instructor.');
            header('Location: dashboard.php?updated=1');
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Quiz update failed. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Quiz - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-4 mb-5" style="max-width: 700px;">
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a>

    <div class="card shadow p-4">
        <h4 class="mb-3">Edit Quiz</h4>

        <?php if ($message): ?>
            <div class="alert alert-<?= $message_type; ?>"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field(); ?>
            <input type="hidden" name="quiz_id" value="<?= $quiz_id; ?>">

            <div class="mb-3">
                <label for="quiz-title">Quiz Title</label>
                <input id="quiz-title" type="text" name="quiz_title" class="form-control" required value="<?= htmlspecialchars($quiz['title']); ?>">
            </div>

            <div class="mb-3">
                <label for="duration">Duration (Minutes)</label>
                <input id="duration" type="number" name="duration" class="form-control" min="1" required value="<?= (int)$quiz['duration_minutes']; ?>">
            </div>

            <div class="mb-3">
                <label for="question-count">Number of Questions</label>
                <select id="question-count" class="form-select" required>
                    <?php for ($count = 1; $count <= 50; $count++): ?>
                        <option value="<?= $count; ?>" <?= $count === count($questions) ? 'selected' : ''; ?>><?= $count; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div id="questions-container"></div>
            <div class="d-flex gap-2 mt-3">
                <button type="button" id="previous-question" class="btn btn-outline-secondary">Previous</button>
                <button type="button" id="next-question" class="btn btn-outline-primary flex-grow-1">Next Question</button>
            </div>
            <button type="submit" class="btn btn-primary w-100 mt-3">Update Quiz</button>
        </form>
    </div>
</div>

<script>
    const existingQuestions = <?= json_encode(array_map(function ($question) {
        return [
            'text' => $question['question_text'],
            'options' => array_map(function ($option) {
                return $option['option_text'];
            }, $question['options']),
            'correct' => (int)array_search(1, array_map('intval', array_column($question['options'], 'is_correct')), true),
        ];
    }, $questions), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const questionCount = document.getElementById('question-count');
    const questionsContainer = document.getElementById('questions-container');
    const previousQuestion = document.getElementById('previous-question');
    const nextQuestion = document.getElementById('next-question');
    let currentQuestionIndex = 0;

    function renderQuestions() {
        const count = Number(questionCount.value);
        questionsContainer.replaceChildren();

        for (let questionIndex = 0; questionIndex < count; questionIndex += 1) {
            const savedQuestion = existingQuestions[questionIndex] || { text: '', options: ['', '', '', ''], correct: 0 };
            const question = document.createElement('section');
            question.className = 'border rounded p-3 mb-3';

            let options = '';
            for (let optionIndex = 0; optionIndex < 4; optionIndex += 1) {
                const optionValue = savedQuestion.options[optionIndex] || '';
                options += `
                    <div class="col-6">
                        <input type="text" name="questions[${questionIndex}][options][${optionIndex}]" class="form-control" placeholder="Option ${optionIndex + 1}" required value="${escapeHtml(optionValue)}">
                    </div>`;
            }

            question.innerHTML = `
                <h5>Question ${questionIndex + 1}</h5>
                <div class="mb-3">
                    <label>Question Text</label>
                    <input type="text" name="questions[${questionIndex}][text]" class="form-control" required placeholder="Write the question" value="${escapeHtml(savedQuestion.text)}">
                </div>
                <div class="row g-2 mb-3">${options}</div>
                <label for="correct-${questionIndex}">Correct Option</label>
                <select id="correct-${questionIndex}" name="questions[${questionIndex}][correct]" class="form-select">
                    <option value="0" ${savedQuestion.correct === 0 ? 'selected' : ''}>Option 1</option>
                    <option value="1" ${savedQuestion.correct === 1 ? 'selected' : ''}>Option 2</option>
                    <option value="2" ${savedQuestion.correct === 2 ? 'selected' : ''}>Option 3</option>
                    <option value="3" ${savedQuestion.correct === 3 ? 'selected' : ''}>Option 4</option>
                </select>`;

            questionsContainer.appendChild(question);
        }

        updateQuestionView();
    }

    function updateQuestionView() {
        const questionSections = questionsContainer.querySelectorAll('section');
        questionSections.forEach((section, index) => {
            section.classList.toggle('d-none', index !== currentQuestionIndex);
        });

        previousQuestion.disabled = currentQuestionIndex === 0;
        nextQuestion.disabled = questionSections.length === 0 || currentQuestionIndex === questionSections.length - 1;
    }

    function currentQuestionIsValid() {
        const currentSection = questionsContainer.querySelectorAll('section')[currentQuestionIndex];
        return currentSection && Array.from(currentSection.querySelectorAll('input, select')).every(field => field.reportValidity());
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, character => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#039;',
            '"': '&quot;'
        }[character]));
    }

    questionCount.addEventListener('change', () => {
        currentQuestionIndex = 0;
        renderQuestions();
    });
    previousQuestion.addEventListener('click', () => {
        if (currentQuestionIndex > 0) {
            currentQuestionIndex -= 1;
            updateQuestionView();
        }
    });
    nextQuestion.addEventListener('click', () => {
        if (currentQuestionIsValid() && currentQuestionIndex < questionsContainer.querySelectorAll('section').length - 1) {
            currentQuestionIndex += 1;
            updateQuestionView();
        }
    });
    renderQuestions();
</script>
</body>
</html>
