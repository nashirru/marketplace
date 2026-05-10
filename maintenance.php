<?php
// File: maintenance.php
// Halaman ini akan ditampilkan saat mode maintenance aktif.
// UPDATE V3.3: Menambahkan Unified Session Config agar tidak merusak sesi Admin.

// --- BLOCK 1: UNIFIED SESSION CONFIGURATION ---
// Pastikan sesi di sini juga menggunakan aturan domain yang sama
if (session_status() == PHP_SESSION_NONE) {
    $host = $_SERVER['HTTP_HOST'];
    $cookie_params = [
        'lifetime' => 86400 * 30,
        'path' => '/',            
        'domain' => '',           
        'secure' => true,
        'httponly' => false,      
        'samesite' => 'Lax'       
    ];

    // Deteksi Environment
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $cookie_params['secure'] = false;
        $cookie_params['samesite'] = 'Lax';
        $cookie_params['domain'] = ''; 
    } else if (strpos($host, 'ngrok') !== false) {
        $cookie_params['domain'] = ''; 
        $cookie_params['secure'] = true;
        $cookie_params['samesite'] = 'None';
    } else {
        // Production: Force Wildcard Domain (.warokkite.com)
        if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $host, $regs)) {
            $cookie_params['domain'] = '.' . $regs['domain'];
        }
        $cookie_params['secure'] = isset($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443;
    }

    session_set_cookie_params([
        'lifetime' => $cookie_params['lifetime'],
        'path' => $cookie_params['path'],
        'domain' => $cookie_params['domain'],
        'secure' => $cookie_params['secure'],
        'httponly' => $cookie_params['httponly'],
        'samesite' => $cookie_params['samesite']
    ]);
    
    session_name('WAROK_MAIN_SESSION'); // Gunakan nama yang konsisten dengan index.php
    session_start();
}
// --- END SESSION CONFIG ---

// 1. Load Konfigurasi Database
require_once 'config/config.php'; 

// Fungsi Helper untuk cek status
function is_maintenance_active_check($conn) {
    if (!$conn) return false; // Safety check
    $key = 'maintenance_mode';
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        // Cek nilai 1, '1', 'true', 'on'
        $val = strtolower($row['setting_value'] ?? '');
        return ($val == '1' || $val == 'true' || $val == 'on');
    }
    return false;
}

// Definisikan BASE_URL dengan Support Ngrok/Proxy
if (!defined('BASE_URL')) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
             || $_SERVER['SERVER_PORT'] == 443
             || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https');
             
    $protocol = $https ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_path = str_replace('/maintenance.php', '', $_SERVER['SCRIPT_NAME']);
    $script_path = ($script_path === '/') ? '' : $script_path;
    define('BASE_URL', $protocol . $host . $script_path);
}

// 2. LOGIKA ANTI-LOOP
// Jika database bilang maintenance OFF, langsung kembalikan ke Beranda/Index.
if (!is_maintenance_active_check($conn)) {
    header("Location: " . BASE_URL . "/");
    exit;
}

// --- Mulai Tampilan Maintenance ---

// Ambil pesan dan path logo dari Session
$message = $_SESSION['maintenance_message'] ?? 'Situs sedang dalam perbaikan berkala untuk meningkatkan kualitas layanan. Mohon kembali lagi nanti.';
$logo_path = $_SESSION['maintenance_logo_path'] ?? null;

// Kirim header 503
header('HTTP/1.1 503 Service Unavailable');
header('Retry-After: 3600');
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Situs Sedang dalam Perbaikan</title>
    <?php
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'store_logo' LIMIT 1");
    if ($stmt) {
        $stmt->execute();
        $logo_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    $logo_name = $logo_row['setting_value'] ?? '';
    $favicon_path = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    ?>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon_path) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon_path) ?>">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen px-4">
    
    <div class="max-w-md w-full">
        <div class="bg-white p-8 sm:p-10 rounded-2xl shadow-xl text-center border border-gray-100">
            
            <div class="mb-8 flex justify-center">
                <?php
                if ($logo_path):
                    $default_logo_fallback = BASE_URL . '/assets/images/settings/default_logo.png'; 
                ?>
                    <img src="<?php echo htmlspecialchars($logo_path, ENT_QUOTES, 'UTF-8'); ?>" 
                         alt="Logo Toko" 
                         class="h-20 w-auto object-contain"
                         onerror="this.src='<?php echo htmlspecialchars($default_logo_fallback, ENT_QUOTES, 'UTF-8'); ?>'; this.onerror=null;">
                <?php else: ?>
                    <div class="h-24 w-24 bg-red-50 rounded-full flex items-center justify-center text-red-600">
                        <i class="fas fa-tools text-4xl"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mb-3 tracking-tight">
                Sedang Perbaikan
            </h1>
            
            <div class="h-1 w-16 bg-red-600 mx-auto rounded-full mb-6"></div>
            
            <p class="text-gray-600 leading-relaxed mb-8">
                <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
            </p>
            
            <p class="text-xs text-gray-400 mt-8">
                &copy; <?php echo date("Y"); ?> Warok Kite.
            </p>
            
        </div>
    </div>
</body>
</html>