<?php
// File: sistem/sistem.php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =========================================================
// FUNGSI ENKRIPSI & DEKRIPSI (URL-SAFE VERSION)
// =========================================================
define('ENCRYPTION_KEY', 'W4r0kK1t3-!@#$');

/**
 * Mengenkripsi ID menjadi string yang aman untuk URL (URL-safe).
 * @param int $id ID integer.
 * @return string ID yang sudah dienkripsi dan aman untuk URL.
 */
function encode_id($id) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($id, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    $data = $encrypted . '::' . $iv;
    // Mengganti karakter + dan / agar aman di URL dan menghapus padding =
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Mendekripsi string URL-safe kembali menjadi ID integer.
 * @param string $data String terenkripsi dari URL.
 * @return int|false ID asli atau false jika gagal.
 */
function decode_id($data) {
    // Mengembalikan karakter - dan _ menjadi + dan /
    $data = strtr($data, '-_', '+/');
    // Menambahkan kembali padding = yang mungkin hilang
    $data = base64_decode($data . str_repeat('=', (4 - strlen($data) % 4) % 4));
    
    if ($data === false) {
        return false;
    }

    list($encrypted_data, $iv) = array_pad(explode('::', $data, 2), 2, null);
    if (!$encrypted_data || !$iv) return false;
    
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}


/**
 * Mengatur flash message (pesan sekali tampil).
 */
function set_flashdata($type, $message)
{
    $_SESSION['flashdata'] = ['type' => $type, 'message' => $message];
}

/**
 * Menampilkan flash message.
 */
function flash_message()
{
    if (isset($_SESSION['flashdata'])) {
        $type    = $_SESSION['flashdata']['type'];
        $message = $_SESSION['flashdata']['message'];
        
        $color_class = 'bg-blue-500'; // Default
        if ($type === 'success') $color_class = 'bg-green-500';
        elseif ($type === 'error') $color_class = 'bg-red-500';

        echo "<div id='flashdata' class='fixed top-5 right-5 z-50 p-4 rounded-md shadow-lg text-white {$color_class} animate-fade-in-out'><p>" . htmlspecialchars($message) . "</p></div>
        <script>setTimeout(() => { const el = document.getElementById('flashdata'); if (el) el.style.opacity = '0'; setTimeout(() => el ? el.remove() : null, 500); }, 3000);</script>
        <style>@keyframes fade-in-out { 0%,100% { opacity:0; } 10%,90% { opacity:1; } } .animate-fade-in-out { animation: fade-in-out 3.5s; }</style>";
        
        unset($_SESSION['flashdata']);
    }
}

/**
 * Mengambil flash message (untuk digunakan di variabel).
 */
function get_flashdata($type)
{
    if (isset($_SESSION['flashdata']) && $_SESSION['flashdata']['type'] === $type) {
        $message = $_SESSION['flashdata']['message'];
        unset($_SESSION['flashdata']);
        return $message;
    }
    return null;
}


/**
 * Memeriksa login dan menyimpan URL tujuan.
 */
function check_login()
{
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        set_flashdata('error', 'Anda harus login terlebih dahulu.');
        redirect('/login/login.php');
    }
}

/**
 * Memeriksa apakah user adalah admin.
 */
function check_admin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        set_flashdata('error', 'Akses dilarang. Hanya untuk Admin.');
        redirect('/login/login.php');
    }
}


/**
 * Redirect ke URL yang ditentukan.
 */
function redirect($url)
{
    // Cek apakah URL sudah absolut
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        header("Location: " . $url);
    } else {
        header("Location: " . BASE_URL . $url);
    }
    exit;
}


/**
 * Membersihkan input.
 */
function sanitize_input($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

/**
 * Format angka menjadi Rupiah.
 */
function format_rupiah($number)
{
    if (!is_numeric($number)) return 'Rp 0';
    return 'Rp ' . number_format($number, 0, ',', '.');
}


$settings_cache = [];
/**
 * Memuat semua pengaturan.
 */
function load_settings($conn)
{
    global $settings_cache;
    if (empty($settings_cache)) {
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings_cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
}

/**
 * Mengambil nilai pengaturan dari cache.
 */
function get_setting($conn, $key)
{
    global $settings_cache;
    if (empty($settings_cache)) {
        load_settings($conn);
    }
    return $settings_cache[$key] ?? null;
}

/**
 * Menggabungkan keranjang session ke database setelah login.
 */
function merge_session_cart_to_db($conn, $user_id) {
    if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $product_id => $item) {
            $quantity = $item['quantity'];

            $stmt_check = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt_check->bind_param("ii", $user_id, $product_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();

            if ($result_check->num_rows > 0) {
                $existing_item = $result_check->fetch_assoc();
                $new_quantity = $existing_item['quantity'] + $quantity;
                
                $stmt_update = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt_update->bind_param("iii", $new_quantity, $user_id, $product_id);
                $stmt_update->execute();
                $stmt_update->close();
            } else {
                $stmt_insert = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt_insert->bind_param("iii", $user_id, $product_id, $quantity);
                $stmt_insert->execute();
                $stmt_insert->close();
            }
            $stmt_check->close();
        }
        unset($_SESSION['cart']);
    }
}

// --- FUNGSI-FUNGSI UNTUK CHECKOUT & PROFIL ---

function get_cart_items($conn, $user_id) {
    $items = [];
    $stmt = $conn->prepare("
        SELECT p.id as product_id, p.name, p.price, p.image, c.quantity
        FROM cart c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function get_payment_methods($conn) {
    $methods = [];
    $result = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $methods[] = $row;
        }
    }
    return $methods;
}

function get_default_user_address($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? AND is_default = 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $address = $result->fetch_assoc();
    $stmt->close();
    return $address;
}

function generate_order_number($conn) {
    $date_part = date('ymd'); // Tahun (2 digit), bulan, tanggal
    $day_doubled = date('d') * 2;
    
    // Hitung jumlah pesanan pada hari ini untuk mendapatkan nomor urut
    $today_start = date('Y-m-d 00:00:00');
    $today_end = date('Y-m-d 23:59:59');
    
    $stmt = $conn->prepare("SELECT COUNT(id) as total_today FROM orders WHERE created_at BETWEEN ? AND ?");
    $stmt->bind_param("ss", $today_start, $today_end);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $sequence = ($row['total_today'] ?? 0) + 1;
    
    return "WK" . $date_part . $day_doubled . $sequence;
}


function generate_order_hash() {
    return md5(uniqid(rand(), true));
}

function get_first_user_address($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $address = $result->fetch_assoc();
    $stmt->close();
    return $address;
}

function get_user_addresses($conn, $user_id) {
    $addresses = [];
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $addresses[] = $row;
    }
    $stmt->close();
    return $addresses;
}

function cancel_overdue_orders($conn) {
    $interval = "24 HOUR";
    $stmt_find = $conn->prepare("
        SELECT id FROM orders 
        WHERE status = 'waiting_payment' 
        AND created_at < NOW() - INTERVAL $interval
    ");
    $stmt_find->execute();
    $result = $stmt_find->get_result();
    
    $order_ids_to_cancel = [];
    while ($row = $result->fetch_assoc()) {
        $order_ids_to_cancel[] = $row['id'];
    }
    $stmt_find->close();

    if (empty($order_ids_to_cancel)) {
        return; 
    }

    $conn->begin_transaction();
    try {
        // Kembalikan stok
        $stmt_stock = $conn->prepare("
            UPDATE products p
            JOIN order_items oi ON p.id = oi.product_id
            SET p.stock = p.stock + oi.quantity
            WHERE oi.order_id = ?
        ");
        
        // Update status pesanan
        $stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");

        foreach ($order_ids_to_cancel as $order_id) {
            $stmt_stock->bind_param("i", $order_id);
            $stmt_stock->execute();
            
            $stmt_cancel->bind_param("i", $order_id);
            $stmt_cancel->execute();
        }
        
        $stmt_stock->close();
        $stmt_cancel->close();
        
        $conn->commit();

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Failed to cancel overdue orders: " . $e->getMessage());
    }
}

// ✅ FUNGSI BARU DITAMBAHKAN
/**
 * Menghitung jumlah produk yang sudah pernah dibeli oleh user.
 * Hanya menghitung dari pesanan yang sudah 'selesai' (completed).
 */
function get_user_purchase_count($conn, $user_id, $product_id) {
    if ($user_id <= 0 || $product_id <= 0) {
        return 0;
    }
    
    $stmt = $conn->prepare("
        SELECT SUM(oi.quantity) as total_bought
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.user_id = ? 
          AND oi.product_id = ? 
          AND (o.status = 'completed' OR o.status = 'shipped' OR o.status = 'processed' OR o.status = 'belum_dicetak')
    ");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return (int)($row['total_bought'] ?? 0);
}

/**
 * Mendapatkan kuantitas produk yang ada di keranjang user.
 */
function get_quantity_in_cart($conn, $user_id, $product_id) {
    if ($user_id <= 0) return 0;

    $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt->bind_param("ii", $user_id, $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int)($row['quantity'] ?? 0);
}

?>