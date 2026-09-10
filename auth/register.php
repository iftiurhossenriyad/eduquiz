<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'student';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['student', 'instructor'], true)) {
        $message = 'Please provide valid details. Password must be at least 8 characters.';
    } else {

    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $status = 'pending';

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)");
    
    try {
        if ($stmt->execute([$name, $email, $password_hash, $role, $status])) {
            $message = "Registration Successful! Wait for Admin Approval.";
        }
    } catch (PDOException $e) {
        $message = "Error: Email might already exist!";
    }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>EduQuiz - Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width: 450px;">
    <div class="card shadow p-4">
        <h3 class="text-center mb-3">Register for EduQuiz</h3>
        <?php if ($message): ?>
            <div class="alert alert-info"><?= $message; ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <?= csrf_field(); ?>
            <div class="mb-3">
                <label>Full Name</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>
            <div class="mb-3">
                <label>Password</label>
                <div class="input-group">
                    <input id="register-password" type="password" name="password" class="form-control" required>
                    <button type="button" class="btn btn-outline-secondary" id="toggle-register-password" aria-label="Show password">Show</button>
                </div>
            </div>
            <div class="mb-3">
                <label>Role</label>
                <select name="role" class="form-select">
                    <option value="student">Student</option>
                    <option value="instructor">Instructor</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary w-100">Register</button>
        </form>
    </div>
</div>

<script>
    const registerPassword = document.getElementById('register-password');
    const toggleRegisterPassword = document.getElementById('toggle-register-password');

    toggleRegisterPassword.addEventListener('click', () => {
        const isHidden = registerPassword.type === 'password';
        registerPassword.type = isHidden ? 'text' : 'password';
        toggleRegisterPassword.textContent = isHidden ? 'Hide' : 'Show';
        toggleRegisterPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
</script>
</body>
</html>