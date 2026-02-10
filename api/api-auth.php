<?php
/**
 * AUTHENTICATION CONTROLLER API (Full Logic & Secure)
 * =================================================================================
 * File: api/api-auth.php
 * Dependencies: api_helper.php
 * * DESCRIPTION:
 * Menangani login, logout, dan cek sesi. 
 * Updated: Menambahkan session_write_close() untuk memastikan data tersimpan 
 * sebelum JSON dikirim ke client (Mencegah Race Condition).
 * =================================================================================
 */

// Load Helper untuk koneksi DB dan setting sesi
// Helper akan otomatis mengatur session_start dengan parameter yang benar
require_once 'api_helper.php';

// Tentukan Action (mendukung GET dan POST)
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Routing Sederhana
switch ($action) {
    case 'login':
        auth_login($conn);
        break;
        
    case 'logout':
        auth_logout();
        break;
        
    case 'check_session':
        auth_check_session();
        break;
        
    default:
        send_response(false, 'Invalid Action Parameter', [], 400);
}

/**
 * LOGIC: HANDLING LOGIN
 */
function auth_login($conn) {
    // 1. Validasi Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_response(false, 'Method Not Allowed. Use POST.', [], 405);
    }

    // 2. Ambil Input (Support JSON Body & Form-Data)
    // Ini penting karena fetch JS biasanya kirim JSON body
    $input_data = json_decode(file_get_contents('php://input'), true);
    if (!$input_data) {
        $input_data = $_POST;
    }

    // 3. Sanitasi Input
    $email = isset($input_data['email']) ? api_sanitize($input_data['email']) : '';
    $password = isset($input_data['password']) ? $input_data['password'] : ''; // Password jangan disanitasi berlebihan

    // 4. Validasi Dasar
    if (empty($email) || empty($password)) {
        send_response(false, 'Harap isi Email dan Password.', [], 400);
    }

    if (!$conn) {
        send_response(false, 'Database Connection Failure.', [], 500);
    }

    // 5. Query Database (Prepared Statement Anti-SQL Injection)
    // Mengambil ID, Nama, Password Hash, dan Role
    $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ? LIMIT 1");
    
    if (!$stmt) {
        // Jika prepare gagal (misal tabel tidak ada)
        send_response(false, 'Database Query Error: ' . $conn->error, [], 500);
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // 6. Verifikasi User
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verifikasi Hash Password (Bcrypt/Argon2)
        if (password_verify($password, $user['password'])) {
            
            // Cek Role (Hanya Admin yang boleh lewat sini)
            if ($user['role'] !== 'admin') {
                send_response(false, 'Akses Ditolak: Akun Anda bukan Administrator.', [], 403);
            }

            // 7. SESSION FIXATION PREVENTION (PENTING)
            // Regenerasi ID sesi setiap kali login sukses untuk mencegah pembajakan sesi
            session_regenerate_id(true);

            // 8. Set Variabel Sesi
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

            // 9. FORCE WRITE SESSION (CRITICAL FOR AJAX)
            // Memaksa PHP menulis sesi ke disk/redis SEBELUM script berakhir.
            // Ini mencegah race condition di mana JSON diterima klien tapi sesi belum tersimpan.
            session_write_close();

            // 10. Kirim Respon Sukses
            // Sertakan Session ID dalam response body untuk debugging (atau fallback method)
            send_response(true, 'Login Berhasil! Mengarahkan...', [
                'redirect' => 'dashboard_v2.php',
                'user' => [
                    'id' => $user['id'],
                    'name' => $user['name'],
                    'role' => $user['role']
                ],
                'session_debug' => [
                    'id' => session_id(),
                    'cookie_params' => session_get_cookie_params()
                ]
            ]);

        } else {
            // Delay sedikit untuk mencegah Brute Force timing attack
            usleep(200000); // 0.2 detik
            send_response(false, 'Password salah. Silakan coba lagi.', [], 401);
        }
    } else {
        // Email tidak ditemukan
        usleep(200000); 
        send_response(false, 'Email tidak terdaftar.', [], 404);
    }
    
    $stmt->close();
}

/**
 * LOGIC: HANDLING LOGOUT
 */
function auth_logout() {
    // 1. Kosongkan array sesi
    $_SESSION = array();

    // 2. Hapus Cookie Sesi di Browser
    // Logic penghapusan cookie harus sama dengan logic pembuatannya (Domain & Path)
    // Kita gunakan logic dari api_helper session_get_cookie_params() yang sudah diset di awal
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // 3. Hancurkan Sesi di Server
    session_destroy();

    send_response(true, 'Logout Berhasil.', ['redirect' => 'login_v2.php']);
}

/**
 * LOGIC: CHECK SESSION STATUS
 * Berguna untuk pengecekan via AJAX saat user di dashboard
 */
function auth_check_session() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
        send_response(true, 'Session Active', [
            'user' => $_SESSION['user_name'],
            'expires_in' => (ini_get('session.gc_maxlifetime') / 3600) . ' hours'
        ]);
    } else {
        send_response(false, 'Session Expired or Invalid', [], 401);
    }
}
?>