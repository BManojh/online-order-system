
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'order_processing_system');

// Create database connection using PDO
try {
    $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Helper: Redirect to another page
function redirect($url) {
    header("Location: $url");
    exit();
}

// Helper: Display notification using session
function showNotification($message, $type = 'success') {
    $_SESSION['notification'] = [
        'message' => $message,
        'type' => $type
    ];
}

// Helper: Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper: Check if user is admin
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Handle user login (replace this logic with real DB check)
function loginUser($username, $password) {
    global $db;

    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        return true;
    }

    return false;
}

// Get all records from a table
function getAllRecords($db, $table, $orderBy = 'id') {
    try {
        $stmt = $db->query("SELECT * FROM `$table` ORDER BY `$orderBy`");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        showNotification("Error fetching $table: " . $e->getMessage(), 'error');
        return [];
    }
}

// Get single record by ID
function getRecordById($db, $table, $id) {
    try {
        $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        showNotification("Error fetching record from $table: " . $e->getMessage(), 'error');
        return null;
    }
}
?>