<?php
// ===============================================================================================
// File: api/api-settings.php
// Status: FULL RESTORE + FIX
// Deskripsi: Endpoint untuk mengelola Pengaturan Toko dan Mode Maintenance
// ===============================================================================================

// 1. INCLUDE CORE HELPER (WAJIB: Menangani CORS, Session, DB, dan Error Handling)
require_once 'api_helper.php';

// 2. VALIDASI KEAMANAN
// Memastikan user yang akses adalah admin
api_check_admin();

// 3. SETUP ENVIRONMENT
// Deteksi BASE_URL (Backup logic jika config gagal load atau tidak mendefinisikannya)
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
    $baseDir = dirname($scriptDir);
    $baseDir = str_replace('\\', '/', $baseDir);
    if ($baseDir == '/' || $baseDir == '.') $baseDir = '';
    define('BASE_URL', $protocol . "://" . $host . $baseDir);
}

// 4. HELPER FUNCTIONS SPECIFIC TO SETTINGS

// Ambil Setting dari DB
function api_get_setting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['setting_value'];
    }
    return $default;
}

// Simpan Setting ke DB
function api_save_setting($conn, $key, $value) {
    // Menggunakan INSERT ON DUPLICATE KEY UPDATE agar aman
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
    $stmt->close();
}

// Handle Upload Logo
function api_handle_logo_upload($file) {
    // Path folder upload (Naik satu level dari api/ ke assets/)
    $target_dir = __DIR__ . '/../assets/images/settings/';
    
    // Buat folder jika belum ada
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $allowed_types = ['jpg', 'jpeg', 'png', 'webp'];
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validasi Ekstensi
    if (!in_array($file_ext, $allowed_types)) {
        throw new Exception("Tipe file tidak diizinkan. Gunakan JPG, PNG, atau WEBP.");
    }

    // Validasi Ukuran (Max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception("Ukuran file terlalu besar (Maksimal 2MB).");
    }

    // Validasi Validitas Gambar
    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        throw new Exception("File bukan gambar yang valid.");
    }

    // Generate Nama File Unik
    $new_filename = 'logo_' . time() . '_' . uniqid() . '.' . $file_ext;
    $target_file = $target_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        return $new_filename;
    } else {
        throw new Exception("Gagal mengupload file ke server. Cek permission folder assets.");
    }
}

// 5. ROUTER & CONTROLLER
$request_method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? ($_GET['action'] ?? '');

try {
    
    // --- GET REQUEST: FETCH DATA ---
    if ($request_method === 'GET') {
        
        $keys_to_fetch = [
            'store_name', 'store_description', 'store_address',
            'store_phone', 'store_email', 'store_facebook', 'store_tiktok',
            'store_logo', 'maintenance_mode', 'maintenance_message'
        ];

        $settings_data = [];
        foreach ($keys_to_fetch as $key) {
            $settings_data[$key] = api_get_setting($conn, $key);
        }

        // Construct Logo URL
        if (!empty($settings_data['store_logo'])) {
            // Cek file fisik
            if (file_exists(__DIR__ . '/../assets/images/settings/' . $settings_data['store_logo'])) {
                $settings_data['store_logo_url'] = BASE_URL . '/assets/images/settings/' . $settings_data['store_logo'];
            } else {
                 $settings_data['store_logo_url'] = BASE_URL . '/assets/images/settings/default_logo.png';
            }
        } else {
            $settings_data['store_logo_url'] = BASE_URL . '/assets/images/settings/default_logo.png';
        }

        send_response(true, "Data pengaturan berhasil dimuat.", $settings_data);
    }

    // --- POST REQUEST: UPDATE DATA ---
    if ($request_method === 'POST') {
        
        $response_data = [];

        // CASE 1: UPDATE GENERAL SETTINGS
        if ($action === 'update_general') {
            $fields = [
                'store_name', 'store_description', 'store_address',
                'store_phone', 'store_email', 'store_facebook', 'store_tiktok'
            ];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $val = trim($_POST[$field]);
                    api_save_setting($conn, $field, $val);
                }
            }

            // Handle Logo Upload jika ada
            if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] === UPLOAD_ERR_OK) {
                // Hapus logo lama (Opsional, good practice)
                $old_logo = api_get_setting($conn, 'store_logo');
                
                // Upload Baru
                $new_logo_name = api_handle_logo_upload($_FILES['store_logo']);
                api_save_setting($conn, 'store_logo', $new_logo_name);

                // Cleanup Lama
                if ($old_logo && $old_logo !== 'default_logo.png') {
                    $old_file_path = __DIR__ . '/../assets/images/settings/' . $old_logo;
                    if (file_exists($old_file_path)) @unlink($old_file_path);
                }
                
                $response_data['logo_updated'] = true;
                $response_data['new_logo_url'] = BASE_URL . '/assets/images/settings/' . $new_logo_name;
            }

            send_response(true, 'Pengaturan toko berhasil diperbarui.', $response_data);

        } 
        // CASE 2: UPDATE MAINTENANCE MODE
        elseif ($action === 'update_maintenance') {
            $mode = $_POST['maintenance_mode'] ?? 'off';
            $message = $_POST['maintenance_message'] ?? '';

            if (!in_array($mode, ['on', 'off'])) {
                throw new Exception("Nilai maintenance mode tidak valid (Gunakan 'on' atau 'off').");
            }

            api_save_setting($conn, 'maintenance_mode', $mode);
            api_save_setting($conn, 'maintenance_message', $message);

            $status_text = ($mode === 'on') ? 'AKTIF' : 'NON-AKTIF';
            send_response(true, "Mode Maintenance berhasil diubah menjadi: $status_text");

        } else {
            throw new Exception("Action POST tidak valid: $action");
        }
    }

} catch (Exception $e) {
    send_response(false, 'Gagal: ' . $e->getMessage(), [], 500);
}
?>