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
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Mendekripsi string URL-safe kembali menjadi ID integer.
 * @param string $data String terenkripsi dari URL.
 * @return int|false ID asli atau false jika gagal.
 */
function decode_id($data) {
    $data = strtr($data, '-_', '+/');
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
 * ✅ NOTIFIKASI BARU: Desain lebih clean dengan background putih.
 */
function flash_message()
{
    if (isset($_SESSION['flashdata'])) {
        $type    = $_SESSION['flashdata']['type'];
        $message = $_SESSION['flashdata']['message'];
        
        $icon_class = 'fa-info-circle';
        $color_class = 'text-blue-500';
        $border_color = 'border-blue-500';

        if ($type === 'success') {
            $icon_class = 'fa-check-circle';
            $color_class = 'text-green-500';
            $border_color = 'border-green-500';
        } elseif ($type === 'error') {
            $icon_class = 'fa-times-circle';
            $color_class = 'text-red-500';
            $border_color = 'border-red-500';
        }

        echo "
        <div id='flashdata' class='fixed top-5 right-5 z-50 p-4 rounded-lg shadow-lg bg-white {$border_color} border-l-4 flex items-center animate-fade-in-out'>
            <i class='fas {$icon_class} {$color_class} text-2xl mr-3'></i>
            <div>
                <p class='font-semibold text-gray-800'>" . ucfirst($type) . "</p>
                <p class='text-sm text-gray-600'>" . htmlspecialchars($message) . "</p>
            </div>
        </div>
        <script>setTimeout(() => { const el = document.getElementById('flashdata'); if (el) { el.style.opacity = '0'; el.style.transform = 'translateX(100%)'; } setTimeout(() => el ? el.remove() : null, 500); }, 4000);</script>
        <style>
            #flashdata { transition: opacity 0.5s, transform 0.5s; }
            @keyframes fade-in-out { 
                0% { opacity: 0; transform: translateX(100%); } 
                10% { opacity: 1; transform: translateX(0); }
                90% { opacity: 1; transform: translateX(0); }
                100% { opacity: 0; transform: translateX(100%); } 
            } 
            .animate-fade-in-out { animation: fade-in-out 4.5s ease-in-out; }
        </style>";
        
        unset($_SESSION['flashdata']);
    }
}


/**
 * Mengambil flash message (untuk digunakan di variabel).
 */
function get_flashdata($type = null)
{
    if ($type === null) {
        return $_SESSION['flashdata'] ?? null;
    }

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
    $date_part = date('ymd');
    $day_doubled = date('d') * 2;
    
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
        $stmt_stock = $conn->prepare("
            UPDATE products p
            JOIN order_items oi ON p.id = oi.product_id
            SET p.stock = p.stock + oi.quantity
            WHERE oi.order_id = ?
        ");
        
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

// FUNGSI UNTUK LOGIKA LIMIT PEMBELIAN
/**
 * Menghitung jumlah produk yang sudah pernah dibeli oleh user yang terhitung limit.
 */
function get_user_purchase_count($conn, $user_id, $product_id) {
    if ($user_id <= 0 || $product_id <= 0) {
        return 0;
    }

    $stmt_reset = $conn->prepare("SELECT last_stock_reset, purchase_limit FROM products WHERE id = ?");
    $stmt_reset->bind_param("i", $product_id);
    $stmt_reset->execute();
    $result_reset = $stmt_reset->get_result();
    $product_data = $result_reset->fetch_assoc();
    $stmt_reset->close();

    if (empty($product_data) || (int)$product_data['purchase_limit'] <= 0) {
        return 0;
    }
    
    $last_reset_time = $product_data['last_stock_reset'] ?? '1970-01-01 00:00:00';
    
    $stmt = $conn->prepare("
        SELECT SUM(oi.quantity) as total_bought
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.user_id = ? 
          AND oi.product_id = ? 
          AND o.created_at >= ? 
          AND (o.status = 'completed' OR o.status = 'shipped' OR o.status = 'processed' OR o.status = 'belum_dicetak')
    ");
    $stmt->bind_param("iis", $user_id, $product_id, $last_reset_time);
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

/**
 * Mengambil batas pembelian produk.
 */
function get_product_limit($conn, $product_id) {
    $stmt = $conn->prepare("SELECT purchase_limit FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return ($row) ? (int)$row['purchase_limit'] : 0; 
}


// --- FUNGSI UNTUK ADMIN PESANAN (DIPERBAIKI) ---

/**
 * [FIXED] Mengambil data pesanan beserta item-itemnya dengan filter dinamis.
 * @param mysqli $conn Koneksi database.
 * @param array $options Opsi filter (status, search, limit, page).
 * @return array Hasil data pesanan dan total record.
 */
function get_orders_with_items_by_status($conn, $options) {
    $status_filter = $options['status'] ?? 'semua';
    $search_query = $options['search'] ?? '';
    $limit = (int)($options['limit'] ?? 10);
    $current_page = max(1, (int)($options['page'] ?? 1)); // Pastikan minimal 1
    $offset = max(0, ($current_page - 1) * $limit); // Pastikan tidak negatif
    
    // Validasi limit
    if ($limit <= 0) $limit = 10;

    // Build WHERE conditions
    $where_conditions = [];
    $where_clause = "";
    
    // Filter Status
    if ($status_filter !== 'semua') {
        $where_conditions[] = "o.status = '" . $conn->real_escape_string($status_filter) . "'";
    }

    // Filter Pencarian
    if (!empty($search_query)) {
        $search_term = $conn->real_escape_string($search_query);
        $where_conditions[] = "(o.order_number LIKE '%{$search_term}%' OR u.name LIKE '%{$search_term}%' OR o.phone_number LIKE '%{$search_term}%')";
    }

    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // =======================================================
    // 1. Hitung Total Records
    // =======================================================
    $total_query = "SELECT COUNT(o.id) as total FROM orders o LEFT JOIN users u ON o.user_id = u.id" . $where_clause;
    $result_total = $conn->query($total_query);
    $total_records = 0;
    
    if ($result_total) {
        $row_total = $result_total->fetch_assoc();
        $total_records = (int)$row_total['total'];
    }

    // =======================================================
    // 2. Ambil Data Pesanan Utama (dengan limit/offset)
    // =======================================================
    $orders = [];
    $sql_orders = "
        SELECT o.*, u.name as user_name, u.email as user_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        {$where_clause}
        ORDER BY o.created_at DESC 
        LIMIT {$limit} OFFSET {$offset}
    ";
    
    $result_orders = $conn->query($sql_orders);
    
    if ($result_orders) {
        $order_ids = [];
        while ($row = $result_orders->fetch_assoc()) {
            $row['user_name'] = $row['user_name'] ?? 'User Dihapus';
            $row['user_email'] = $row['user_email'] ?? 'N/A';
            
            $orders[$row['id']] = $row;
            $orders[$row['id']]['items'] = [];
            $order_ids[] = (int)$row['id'];
        }
        
        // =======================================================
        // 3. Ambil Item-item untuk Pesanan yang Ditampilkan
        // =======================================================
        if (!empty($order_ids)) {
            $order_ids_str = implode(',', $order_ids);
            
            $sql_items = "
                SELECT oi.*, p.name as product_name, p.image as product_image 
                FROM order_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.order_id IN ({$order_ids_str})
            ";
            
            $result_items = $conn->query($sql_items);
            
            if ($result_items) {
                while ($item = $result_items->fetch_assoc()) {
                    if (isset($orders[$item['order_id']])) {
                        $orders[$item['order_id']]['items'][] = $item;
                    }
                }
            }
        }
    }

    return [
        'orders' => array_values($orders),
        'total' => $total_records
    ];
}