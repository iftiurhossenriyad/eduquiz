<?php
$local_config = __DIR__ . '/db.local.php';
$local = is_file($local_config) ? require $local_config : [];

$host = $local['host'] ?? getenv('DB_HOST') ?: 'localhost';
$db   = $local['name'] ?? getenv('DB_NAME') ?: 'eduquiz_db';
$user = $local['user'] ?? getenv('DB_USER') ?: 'root';
$pass = $local['pass'] ?? getenv('DB_PASS') ?: '';
$charset = $local['charset'] ?? getenv('DB_CHARSET') ?: 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Database connection failed: " . $e->getMessage());
}
?>