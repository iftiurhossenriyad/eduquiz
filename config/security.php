<?php
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function verify_csrf_token(): void
{
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $submitted_token)) {
        http_response_code(419);
        exit('Invalid request token. Please go back and try again.');
    }
}
