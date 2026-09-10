<?php
session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Read stored password column dynamically
                $db_pass = $user['password_hash'] ?? $user['password'] ?? $user['pass'] ?? '';

                // Password Match Verification
                if (password_verify($password, $db_pass)) {
                    
                    $role = strtolower(trim($user['role'] ?? 'student'));
                    $status = strtolower(trim($user['status'] ?? 'approved'));

                    if ($status !== 'approved') {
                        $error = $status === 'pending'
                            ? 'Your account is waiting for admin approval.'
                            : 'Your account is not approved for login.';
                    } else {
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user['user_id'] ?? $user['id'];
                        $_SESSION['name'] = $user['name'] ?? $user['username'] ?? 'User';
                        $_SESSION['role'] = $role;

                        // Redirect according to user role
                        if ($role === 'student') {
                            header("Location: ../student/dashboard.php");
                            exit();
                        } elseif ($role === 'teacher' || $role === 'instructor') {
                            header("Location: ../instructor/dashboard.php");
                            exit();
                        } elseif ($role === 'admin') {
                            header("Location: ../admin/dashboard.php");
                            exit();
                        } else {
                            $error = "Invalid user role!";
                        }
                    }
                } else {
                    $error = "Password incorrect! Please check your password.";
                }
            } else {
                $error = "Email not found! Please register first.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter both email and password.";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>EduQuiz - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center vh-100">

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow p-4">
                <h3 class="text-center mb-4">Login to EduQuiz</h3>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <?= csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <div class="input-group">
                            <input id="login-password" type="password" name="password" class="form-control" required>
                            <button type="button" class="btn btn-outline-secondary" id="toggle-login-password" aria-label="Show password">Show</button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100">Login</button>
                </form>

                <div class="text-center mt-3">
                    <small><a href="forgot_password.php">Forgot password?</a></small><br>
                    <small>Don't have an account? <a href="register.php">Register</a></small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const loginPassword = document.getElementById('login-password');
    const toggleLoginPassword = document.getElementById('toggle-login-password');

    toggleLoginPassword.addEventListener('click', () => {
        const isHidden = loginPassword.type === 'password';
        loginPassword.type = isHidden ? 'text' : 'password';
        toggleLoginPassword.textContent = isHidden ? 'Hide' : 'Show';
        toggleLoginPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
</script>

</body>
</html>