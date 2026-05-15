<?php
// ============================================
// CONFIG.PHP — Railway Cloud Ready
// Reads DB credentials from environment vars
// ============================================

// Railway automatically sets these environment variables
// when you add a MySQL plugin to your project
define('DB_HOST', getenv('MYSQLHOST')     ?: getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('MYSQLUSER')     ?: getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'eschool_voting');
define('DB_PORT', getenv('MYSQLPORT')     ?: 3306);

// ---- SECURE SESSION SETTINGS ----
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime', 1800);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- SECURITY HEADERS ----
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ---- DATABASE CONNECTION ----
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['error' => 'Database connection failed: ' . $conn->connect_error]));
}
$conn->set_charset('utf8mb4');

// ---- HELPERS ----
function sanitize($conn, $value) {
    return $conn->real_escape_string(trim(strip_tags($value)));
}

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function generateReceipt() {
    return 'VR-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function checkRateLimit($conn, $ip) {
    $ip     = $conn->real_escape_string($ip);
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $result = $conn->query("SELECT COUNT(*) as cnt FROM login_attempts WHERE ip_address='$ip' AND attempted_at > '$window'");
    $row    = $result->fetch_assoc();
    return $row['cnt'] < 10;
}

function logAttempt($conn, $ip, $student_id = null) {
    $ip  = $conn->real_escape_string($ip);
    $sid = $student_id ? "'".$conn->real_escape_string($student_id)."'" : 'NULL';
    $conn->query("INSERT INTO login_attempts (ip_address, student_id) VALUES ('$ip', $sid)");
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
}

function logAudit($conn, $action, $desc, $by = 'System') {
    $action = $conn->real_escape_string($action);
    $desc   = $conn->real_escape_string($desc);
    $by     = $conn->real_escape_string($by);
    $ip     = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
    $conn->query("INSERT INTO audit_log (action, description, performed_by, ip_address) VALUES ('$action','$desc','$by','$ip')");
}

function getCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
?>
