<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/notifications.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'instructor') {
    header("Location: ../auth/login.php");
    exit();
}

$course_id = $_GET['course_id'] ?? null;
if (!$course_id) {
    header("Location: dashboard.php");
    exit();
}

$course_stmt = $pdo->prepare("SELECT course_id FROM courses WHERE course_id = ? AND instructor_id = ?");
$course_stmt->execute([(int)$course_id, $_SESSION['user_id']]);
if (!$course_stmt->fetch()) {
    header("Location: dashboard.php");
    exit();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $quiz_title = trim($_POST['quiz_title'] ?? '');
    $duration = (int)($_POST['duration'] ?? 0);
    $question_count = (int)($_POST['question_count'] ?? 0);
    $questions = $_POST['questions'] ?? [];

    $valid_questions = [];
    foreach ($questions as $question) {
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

    if ($quiz_title === '' || $duration < 1 || $question_count < 1 || count($valid_questions) !== $question_count) {
        $message = "Please select the question count and complete all questions before saving.";
    } else {
    
    // Create Quiz
    $stmt = $pdo->prepare("INSERT INTO quizzes (course_id, title, duration_minutes, total_marks) VALUES (?, ?, ?, ?)");
    $stmt->execute([$course_id, $quiz_title, $duration, count($valid_questions)]);
    $quiz_id = $pdo->lastInsertId();

    // Insert Questions and Options
    foreach ($valid_questions as $q) {
        $stmt_q = $pdo->prepare("INSERT INTO questions (quiz_id, question_text) VALUES (?, ?)");
        $stmt_q->execute([$quiz_id, $q['text']]);
        $question_id = $pdo->lastInsertId();

        foreach ($q['options'] as $index => $option_text) {
            $is_correct = ($index == $q['correct']) ? 1 : 0;
            $stmt_o = $pdo->prepare("INSERT INTO options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
            $stmt_o->execute([$question_id, $option_text, $is_correct]);
        }
    }

    notify_enrolled_students($pdo, (int)$course_id, 'quiz', 'New quiz available', $quiz_title . ' was added to this course.');

    $message = "Quiz created successfully!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Quiz - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-4 mb-5" style="max-width: 700px;">
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm mb-3">&larr; Back to Dashboard</a>
    
    <div class="card shadow p-4">
        <h4 class="mb-3">Create MCQ Quiz</h4>
        
        <?php if ($message): ?>
            <div class="alert <?= strpos($message, 'successfully') !== false ? 'alert-success' : 'alert-danger'; ?>"><?= htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <?= csrf_field(); ?>
            <div class="mb-3">
                <label>Quiz Title</label>
                <input type="text" name="quiz_title" class="form-control" required placeholder="e.g. Midterm Quiz">
            </div>
            <div class="mb-3">
                <label>Duration (Minutes)</label>
                <input type="number" name="duration" class="form-control" required value="10">
            </div>

            <div class="mb-3">
                <label for="question-count">Number of Questions</label>
                <select id="question-count" name="question_count" class="form-select" required>
                    <?php for ($count = 1; $count <= 50; $count++): ?>
                        <option value="<?= $count; ?>"><?= $count; ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div id="questions-container"></div>

            <div id="question-navigation" class="d-flex gap-2 mt-3">
                <button type="button" id="previous-question" class="btn btn-outline-secondary">Previous</button>
                <button type="button" id="next-question" class="btn btn-outline-primary flex-grow-1">Next Question</button>
            </div>

            <button type="submit" id="save-quiz" class="btn btn-success w-100 mt-3">Save Quiz</button>
        </form>
    </div>
</div>

<script>
    const questionCount = document.getElementById('question-count');
    const questionsContainer = document.getElementById('questions-container');
    const previousQuestion = document.getElementById('previous-question');
    const nextQuestion = document.getElementById('next-question');
    const saveQuiz = document.getElementById('save-quiz');
    let currentQuestionIndex = 0;

    function createQuestion(questionIndex) {
        const question = document.createElement('section');
        question.className = 'border rounded p-3 mb-3';

        let options = '';
        for (let optionIndex = 0; optionIndex < 4; optionIndex += 1) {
            options += `
                <div class="col-6">
                    <input type="text" name="questions[${questionIndex}][options][${optionIndex}]" class="form-control" placeholder="Option ${optionIndex + 1}" required>
                </div>`;
        }

        question.innerHTML = `
            <h5>Question ${questionIndex + 1}</h5>
            <div class="mb-3">
                <label>Question Text</label>
                <input type="text" name="questions[${questionIndex}][text]" class="form-control" required placeholder="Write the question">
            </div>
            <div class="row g-2 mb-3">${options}</div>
            <label for="correct-${questionIndex}">Correct Option</label>
            <select id="correct-${questionIndex}" name="questions[${questionIndex}][correct]" class="form-select">
                <option value="0">Option 1</option>
                <option value="1">Option 2</option>
                <option value="2">Option 3</option>
                <option value="3">Option 4</option>
            </select>`;

        questionsContainer.appendChild(question);
    }

    function updateQuestionView() {
        const questionSections = questionsContainer.querySelectorAll('section');
        questionSections.forEach((section, index) => {
            section.classList.toggle('d-none', index !== currentQuestionIndex);
        });

        previousQuestion.disabled = currentQuestionIndex === 0;
        const targetQuestionCount = Number(questionCount.value);
        const allQuestionsCreated = questionSections.length >= targetQuestionCount;
        nextQuestion.disabled = allQuestionsCreated && currentQuestionIndex === questionSections.length - 1;
        nextQuestion.textContent = allQuestionsCreated ? 'All Questions Added' : 'Next Question';
        saveQuiz.disabled = questionSections.length !== targetQuestionCount;
    }

    function currentQuestionIsValid() {
        const currentSection = questionsContainer.querySelectorAll('section')[currentQuestionIndex];
        if (!currentSection) {
            return false;
        }

        return Array.from(currentSection.querySelectorAll('input, select')).every(field => field.reportValidity());
    }

    previousQuestion.addEventListener('click', () => {
        if (currentQuestionIndex > 0) {
            currentQuestionIndex -= 1;
            updateQuestionView();
        }
    });
    nextQuestion.addEventListener('click', () => {
        if (!currentQuestionIsValid()) {
            return;
        }

        const totalQuestions = questionsContainer.querySelectorAll('section').length;
        const targetQuestionCount = Number(questionCount.value);
        if (currentQuestionIndex === totalQuestions - 1 && totalQuestions < targetQuestionCount) {
            createQuestion(totalQuestions);
        }
        if (currentQuestionIndex < questionsContainer.querySelectorAll('section').length - 1) {
            currentQuestionIndex += 1;
        }
        updateQuestionView();
    });
    questionCount.addEventListener('change', () => {
        questionsContainer.replaceChildren();
        currentQuestionIndex = 0;
        createQuestion(0);
        updateQuestionView();
    });
    createQuestion(0);
    updateQuestionView();
</script>

</body>
</html>