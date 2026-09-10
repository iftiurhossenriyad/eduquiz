<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$admin_check = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1");
if ($admin_check->fetch()) {
    header('Location: login.php');
    exit();
}

$message = '';
$message_type = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please provide a valid name and email address.';
    } elseif (strlen($password) < 8) {
        $message = 'Admin password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
    } else {
        try {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'admin', 'approved')");
            $stmt->execute([$name, $email, $password_hash]);
            $message = 'Admin account created. You can now log in.';
            $message_type = 'success';
        } catch (PDOException $e) {
            $message = 'This email may already be registered.';
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Setup - EduQuiz</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow p-4">
                <h3 class="text-center mb-3">Create First Admin</h3>
                <p class="text-muted small">This setup is available only while no admin account exists.</p>

                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type; ?>"><?= htmlspecialchars($message); ?></div>
                <?php endif; ?>

                <?php if ($message_type !== 'success'): ?>
                    <form method="POST" action="">
                        <?= csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Admin Email</label>
                            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control" minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-dark w-100">Create Admin Account</button>
                    </form>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary w-100">Go to Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
