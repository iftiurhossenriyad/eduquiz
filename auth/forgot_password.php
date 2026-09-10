<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$message = '';
$message_type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $message = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?)');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            $message = 'No account was found with this email address.';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
            $update->execute([$password_hash, $user['user_id']]);
            $message = 'Password updated successfully. You can now log in.';
            $message_type = 'success';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Reset Password - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow p-4">
                <h3 class="text-center mb-4">Reset Password</h3>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type; ?>"><?= htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-group">
                            <input id="new-password" type="password" name="password" class="form-control" minlength="6" required>
                            <button type="button" class="btn btn-outline-secondary" data-toggle-password="new-password">Show</button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Confirm Password</label>
                        <div class="input-group">
                            <input id="confirm-password" type="password" name="confirm_password" class="form-control" minlength="6" required>
                            <button type="button" class="btn btn-outline-secondary" data-toggle-password="confirm-password">Show</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Update Password</button>
                </form>

                <div class="text-center mt-3">
                    <a href="login.php">Back to Login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('[data-toggle-password]').forEach(button => {
        button.addEventListener('click', () => {
            const passwordField = document.getElementById(button.dataset.togglePassword);
            const isHidden = passwordField.type === 'password';
            passwordField.type = isHidden ? 'text' : 'password';
            button.textContent = isHidden ? 'Hide' : 'Show';
        });
    });
</script>
</body>
</html>