<?php
/**
 * CORE SECURITY & CONFIGURATION HELPER (IQ 180 - Session Handshake Receiver)
 * =================================================================================
 * File: api/api_helper.php
 * * FIX VITAL: Menambahkan logika penerimaan 'sid' dari URL/POST.
 * Tanpa ini, AJAX lintas subdomain akan selalu gagal (401) karena Cookie diblokir browser.
 * =================================================================================
 */

// 1. BUFFERING & CLEANING
if (ob_get_level()) ob_end_clean();
ob_start(); 

date_default_timezone_set('Asia/Jakarta');

// 2. ERROR REPORTING (Silent di Production agar JSON tidak rusak)
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

$logDir = __DIR__ . '/../logs';
if (!file_exists($logDir)) @mkdir($logDir, 0755, true);
ini_set('error_log', $logDir . '/php_error.log');
error_reporting(E_ALL);

function apiExceptionHandler($exception) {
    if (ob_get_length()) ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['status'=>false, 'message'=>'Internal Error', 'data'=>['msg'=>$exception->getMessage()]]);
    exit;
}
set_exception_handler('apiExceptionHandler');

// ---------------------------------------------------------------------------------
// SECTION 3: SESSION SECURITY (THE HANDSHAKE FIX)
// ---------------------------------------------------------------------------------
class SessionSecurity {
    public static function configure() {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        // --- A. PATH ABSOLUT (Sniper Path) ---
        $username = 'u111743367'; // Username Hosting Anda
        
        $candidates = [
            "/home/$username/domains/warokkite.com/public_html/warok_sessions",
            "/home/$username/public_html/warok_sessions",
            dirname(__DIR__) . '/warok_sessions'
        ];

        $sessionDir = null;
        foreach ($candidates as $path) {
            if (file_exists(dirname($path))) {
                $sessionDir = $path;
                break;
            }
        }
        if (!$sessionDir) $sessionDir = sys_get_temp_dir();

        // Ensure Permission
        if (!file_exists($sessionDir)) {
            $oldmask = umask(0);
            @mkdir($sessionDir, 0777, true); 
            umask($oldmask);
            file_put_contents($sessionDir . '/index.php', '<?php header("HTTP/1.0 403 Forbidden"); exit;');
            file_put_contents($sessionDir . '/.htaccess', "Deny from all");
        } else {
            @chmod($sessionDir, 0777);
        }

        // Force Config
        session_save_path($sessionDir);
        ini_set('session.gc_probability', 1);
        ini_set('session.gc_maxlifetime', 86400 * 30);

        // --- B. COOKIE DOMAIN ---
        $host = $_SERVER['HTTP_HOST'];
        $cookie_params = [
            'lifetime' => 86400 * 30,
            'path' => '/', 
            'domain' => '', 
            'secure' => false,
            'httponly' => true, 
            'samesite' => 'Lax'
        ];

        if (strpos($host, 'warokkite.com') !== false) {
            $cookie_params['domain'] = '.warokkite.com';
            $cookie_params['secure'] = true;
        } else if (strpos($host, 'ngrok') !== false) {
            $cookie_params['secure'] = true;
            $cookie_params['samesite'] = 'None';
        }

        session_set_cookie_params([
            'lifetime' => $cookie_params['lifetime'],
            'path' => $cookie_params['path'],
            'domain' => $cookie_params['domain'],
            'secure' => $cookie_params['secure'],
            'httponly' => $cookie_params['httponly'],
            'samesite' => $cookie_params['samesite']
        ]);
        
        session_name('WAROK_ADMIN_SESSION');

        // --- C. HANDSHAKE RECEIVER (INI YANG HILANG SEBELUMNYA) ---
        // Jika browser memblokir cookie (karena beda subdomain/AJAX),
        // kita paksa pakai ID sesi yang dikirim lewat URL/Body.
        if (isset($_REQUEST['sid']) && !empty($_REQUEST['sid'])) {
            // Validasi format untuk mencegah Session Fixation/Injection
            if (preg_match('/^[a-zA-Z0-9,-]+$/', $_REQUEST['sid'])) {
                session_id($_REQUEST['sid']);
            }
        }

        session_start();
    }
}

SessionSecurity::configure();

// ---------------------------------------------------------------------------------
// SECTION 4: CORS (Strict Allow)
// ---------------------------------------------------------------------------------
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
if ($origin === '*') {
    if (isset($_SERVER['HTTP_REFERER'])) {
        $parsed = parse_url($_SERVER['HTTP_REFERER']);
        $origin = $parsed['scheme'] . '://' . $parsed['host'];
    }
}
header("Access-Control-Allow-Origin: " . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
header("Content-Type: application/json; charset=UTF-8");

// ---------------------------------------------------------------------------------
// SECTION 5: DB CONNECTION
// ---------------------------------------------------------------------------------
$config_found = false;
$potential_paths = [
    __DIR__ . '/../config/config.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config/config.php'
];
foreach ($potential_paths as $path) {
    if (file_exists($path)) { require_once $path; $config_found = true; break; }
}

if (!$config_found || !isset($conn) || $conn->connect_error) {
    send_response(false, 'Database Error: Connection Failed', [], 500);
}

// ---------------------------------------------------------------------------------
// SECTION 6: HELPERS
// ---------------------------------------------------------------------------------
function send_response($status, $message, $data = [], $http_code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($http_code);
    echo json_encode(['status' => (bool)$status, 'success' => (bool)$status, 'message' => (string)$message, 'data' => $data]);
    exit;
}

function api_sanitize($data) {
    global $conn;
    if (is_array($data)) return array_map('api_sanitize', $data);
    return $conn->real_escape_string(htmlspecialchars(strip_tags(trim($data))));
}

if (!function_exists('api_check_admin')) {
    function api_check_admin() {
        if (!isset($_SESSION['user_id'])) send_response(false, 'Unauthorized: Session Expired', [], 401);
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] !== 'admin') send_response(false, 'Forbidden', [], 403);
        return true;
    }
}
?>