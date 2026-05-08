<?php
// File: config/config.php

if (!function_exists('app_load_dotenv')) {
    function app_load_dotenv($path) {
        if (!is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) {
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }
            $name = trim(substr($trimmed, 0, $eqPos));
            $value = trim(substr($trimmed, $eqPos + 1));
            if ($name === '') {
                continue;
            }
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
            if (!array_key_exists($name, $_SERVER)) {
                $_SERVER[$name] = $value;
            }
            if (getenv($name) === false) {
                putenv($name . '=' . $value);
            }
        }
    }
}

$projectRoot = dirname(__DIR__);
app_load_dotenv($projectRoot . '/.env');
app_load_dotenv($projectRoot . '/.env.local');

// =================================================================
// GLOBAL ERROR HANDLER — Mencegah HTTP 500 mentah & expose stack trace
// =================================================================
ini_set('display_errors', 0);     // WAJIB: jangan tampilkan error ke browser
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

$_warok_log_dir = $projectRoot . '/logs';
if (!is_dir($_warok_log_dir)) @mkdir($_warok_log_dir, 0755, true);
ini_set('error_log', $_warok_log_dir . '/php_error.log');
error_reporting(E_ALL);

// Rotasi log otomatis jika > 10MB
$_warok_log_file = $_warok_log_dir . '/php_error.log';
if (file_exists($_warok_log_file) && filesize($_warok_log_file) > 10 * 1024 * 1024) {
    rename($_warok_log_file, $_warok_log_file . '.' . date('Ymd_His'));
}
unset($_warok_log_dir, $_warok_log_file);

// Handler untuk runtime error (E_WARNING, E_NOTICE, dsb)
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // Hanya log, jangan tampilkan ke user
    if (!(error_reporting() & $errno)) return false;
    error_log(sprintf('[%s] errno=%d %s in %s:%d', date('Y-m-d H:i:s'), $errno, $errstr, $errfile, $errline));
    // Untuk error fatal yang ditangkap di sini, hentikan eksekusi dengan halaman bersih
    if (in_array($errno, [E_USER_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo '<!DOCTYPE html><html><head><title>Terjadi Kesalahan</title></head><body style="font-family:sans-serif;text-align:center;padding:80px"><h2>&#9888; Terjadi kesalahan sementara</h2><p>Silakan coba beberapa saat lagi.</p></body></html>';
        exit(1);
    }
    return true; // biarkan PHP handle error non-fatal lainnya
});

// Handler untuk fatal error (E_ERROR, E_PARSE, E_CORE_ERROR) via shutdown
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log(sprintf(
            '[FATAL %s] %s in %s:%d',
            date('Y-m-d H:i:s'), $error['message'], $error['file'], $error['line']
        ));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        // Flush semua output buffer agar tidak ada output parsial
        while (ob_get_level() > 0) ob_end_clean();
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Gangguan Sementara</title><style>body{font-family:sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fff;border-radius:12px;padding:48px 40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px}h2{color:#1a1a1a;font-size:1.4rem;margin:0 0 12px}p{color:#6b7280;margin:0 0 24px}a{display:inline-block;padding:10px 24px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}</style></head><body><div class="box"><div style="font-size:2.5rem">&#9888;&#65039;</div><h2>Terjadi Gangguan Sementara</h2><p>Kami sedang memperbaiki masalah ini. Silakan kembali beberapa saat lagi.</p><a href="/">Kembali ke Beranda</a></div></body></html>';
    }
});
// =================================================================
// AKHIR GLOBAL ERROR HANDLER
// =================================================================

// --- Pengaturan Aplikasi ---
// Prioritas:
// 1) APP_BASE_URL dari environment (.env / server env)
// 2) Auto-detect dari request aktif
$envBaseUrl = getenv('APP_BASE_URL');
if (!defined('BASE_URL')) {
    if (is_string($envBaseUrl) && trim($envBaseUrl) !== '') {
        define('BASE_URL', rtrim(trim($envBaseUrl), '/'));
    } else {
        $protocol = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ) ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $projectBase = '';
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
        $projectRootNormalized = rtrim(str_replace('\\', '/', $projectRoot), '/');

        if ($docRoot !== '' && stripos($projectRootNormalized, $docRoot) === 0) {
            $projectBase = substr($projectRootNormalized, strlen($docRoot));
        }

        if ($projectBase === '' || $projectBase === false) {
            $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '/';
            $projectBase = dirname(dirname($scriptPath));
        }

        $projectBase = rtrim(str_replace('\\', '/', (string)$projectBase), '/');
        define('BASE_URL', $protocol . $host . ($projectBase === '' ? '' : $projectBase));
    }
}

// --- Pengaturan Koneksi Database ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'publi');

// --- Buat Koneksi (Persistent Connection: prefix 'p:' agar PHP reuse koneksi, ---
// --- mengurangi overhead buka koneksi baru tiap request saat high traffic)    ---
$conn = new mysqli('p:' . DB_HOST, DB_USER, DB_PASS, DB_NAME);

// --- Cek Koneksi (Graceful — tidak expose detail error ke browser) ---
if ($conn->connect_error) {
    // Log detail error ke file, BUKAN ke browser
    error_log(sprintf(
        '[DB-CONNECT-ERROR %s] errno=%d %s',
        date('Y-m-d H:i:s'), $conn->connect_errno, $conn->connect_error
    ));
    // Tampilkan halaman maintenance yang bersih ke user
    http_response_code(503);
    header('Retry-After: 60');
    header('Content-Type: text/html; charset=UTF-8');
    while (ob_get_level() > 0) ob_end_clean();
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><title>Gangguan Sementara - Warok Kite</title><style>body{font-family:sans-serif;background:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.box{background:#fff;border-radius:12px;padding:48px 40px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:420px}h2{color:#1a1a1a;font-size:1.4rem;margin:0 0 12px}p{color:#6b7280;margin:0 0 24px}a{display:inline-block;padding:10px 24px;background:#dc2626;color:#fff;border-radius:8px;text-decoration:none;font-weight:600}</style></head><body><div class="box"><div style="font-size:2.5rem">&#128683;</div><h2>Sedang Ada Gangguan</h2><p>Layanan kami sedang tidak dapat diakses sementara waktu. Kami sedang bekerja untuk memulihkannya.</p><a href="javascript:location.reload()">Coba Lagi</a></div></body></html>';
    exit;
}

// --- Atur Karakter Set ---
$conn->set_charset("utf8mb4");

// --- Reset koneksi persistent agar tidak ada state sisa dari request sebelumnya ---
mysqli_report(MYSQLI_REPORT_OFF); // Matikan exception mode sementara untuk ping
if (!$conn->ping()) {
    // Koneksi persistent mati, coba reconnect sekali
    $conn->close();
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        error_log('[DB-RECONNECT-FAIL] ' . $conn->connect_error);
        http_response_code(503);
        exit('Service Unavailable');
    }
    $conn->set_charset('utf8mb4');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Kembalikan mode strict
// Rollback transaksi yang mungkin tidak ditutup di request sebelumnya (penting untuk persistent conn)
if ($conn->in_transaction) {
    $conn->rollback();
}

// --- Mulai Session ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
