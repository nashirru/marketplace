<?php
// File: sistem/sistem.php
// PERBAIKAN: Menambahkan 1 fungsi baru: get_order_number_by_id()

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// =========================================================
// FUNGSI ENKRIPSI & DEKRIPSI (URL-SAFE VERSION)
// =========================================================
define('ENCRYPTION_KEY', 'W4r0kK1t3-!@#$');

function encode_id($id) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($id, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    $data = $encrypted . '::' . $iv;
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

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


function set_flashdata($type, $message)
{
    $_SESSION['flashdata'] = ['type' => $type, 'message' => $message];
}

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


function check_login()
{
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        set_flashdata('error', 'Anda harus login terlebih dahulu.');
        redirect('/login/login.php');
    }
}

function check_admin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        set_flashdata('error', 'Akses dilarang. Hanya untuk Admin.');
        redirect('/login/login.php');
    }
}


function redirect($url)
{
    if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
        header("Location: " . $url);
    } else {
        header("Location: " . BASE_URL . $url);
    }
    exit;
}


function sanitize_input($data)
{
    return htmlspecialchars(stripslashes(trim($data)));
}

function format_rupiah($number)
{
    if (!is_numeric($number)) return 'Rp 0';
    return 'Rp ' . number_format($number, 0, ',', '.');
}


$settings_cache = [];
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

function get_setting($conn, $key)
{
    global $settings_cache;
    if (empty($settings_cache)) {
        load_settings($conn);
    }
    return $settings_cache[$key] ?? null;
}

function merge_session_cart_to_db($conn, $user_id) {
    if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $product_id => $item) {
            $quantity = $item['quantity'];

            // Gunakan ON DUPLICATE KEY UPDATE untuk efisiensi
            $stmt = $conn->prepare("
                INSERT INTO cart (user_id, product_id, quantity) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
            ");
            $stmt->bind_param("iii", $user_id, $product_id, $quantity);
            $stmt->execute();
            $stmt->close();
        }
        unset($_SESSION['cart']);
    }
}

function get_cart_items($conn, $user_id) {
    $cart_items = get_cart_items_for_display($conn, $user_id);
    $subtotal = 0;
    $items_with_subtotal = [];

    foreach ($cart_items as $item) {
        $item_subtotal = $item['price'] * $item['quantity'];
        $subtotal += $item_subtotal;
        $item['subtotal'] = $item_subtotal;
        $items_with_subtotal[] = $item;
    }

    $default_address = null;
    if ($user_id > 0) {
        $default_address = get_default_user_address($conn, $user_id);
    }
    
    return [
        'items' => $items_with_subtotal,
        'subtotal' => $subtotal,
        'default_address' => $default_address
    ];
}


function get_cart_items_for_display($conn, $user_id) {
    $cart_items = [];
    if ($user_id > 0) {
        $stmt = $conn->prepare("SELECT c.quantity, p.id as product_id, p.name, p.price, p.image, p.stock, p.purchase_limit, p.stock_cycle_id FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
        $stmt->bind_param("i", $user_id);
    } else {
        if (empty($_SESSION['cart'])) return [];
        $product_ids = array_keys($_SESSION['cart']);
        $placeholders = implode(',', array_fill(0, count($product_ids), '?'));
        $stmt = $conn->prepare("SELECT id as product_id, name, price, image, stock, purchase_limit, stock_cycle_id FROM products WHERE id IN ($placeholders)");
        $stmt->bind_param(str_repeat('i', count($product_ids)), ...$product_ids);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user_id > 0) {
        while ($row = $result->fetch_assoc()) $cart_items[] = $row;
    } else {
        $products_data = array_column($result->fetch_all(MYSQLI_ASSOC), null, 'product_id');
        foreach ($_SESSION['cart'] as $pid => $item) {
            if (isset($products_data[$pid])) {
                $cart_items[] = array_merge($products_data[$pid], ['quantity' => $item['quantity']]);
            }
        }
    }
    $stmt->close();
    return $cart_items;
}

function get_cart_items_for_calculation($conn, $user_id) {
    $cart_data = [];
    if ($user_id > 0) {
        $stmt = $conn->prepare("SELECT c.quantity, p.price, p.id as product_id, p.name FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $cart_data[] = $row;
        $stmt->close();
    } else {
        if (!empty($_SESSION['cart'])) {
            $pids = array_keys($_SESSION['cart']);
            if (!empty($pids)) {
                $placeholders = implode(',', array_fill(0, count($pids), '?'));
                $stmt = $conn->prepare("SELECT id, price, name FROM products WHERE id IN ($placeholders)");
                $stmt->bind_param(str_repeat('i', count($pids)), ...$pids);
                $stmt->execute();
                $products_db = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                
                $prices = array_column($products_db, 'price', 'id');
                $names = array_column($products_db, 'name', 'id');

                foreach ($_SESSION['cart'] as $pid => $item) {
                    if (isset($prices[$pid])) {
                        $cart_data[] = [
                            'quantity' => $item['quantity'], 
                            'price' => $prices[$pid],
                            'product_id' => $pid, 
                            'name' => $names[$pid] 
                        ];
                    }
                }
            }
        }
    }
    return $cart_data;
}

function clear_cart($conn, $user_id) {
    if ($user_id > 0) {
        $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->close();
    } else {
        unset($_SESSION['cart']);
    }
}

function get_payment_methods($conn) {
    $methods = [];
    $result = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1");
    if ($result) {
        while ($row = $result->fetch_assoc()) $methods[] = $row;
    }
    return $methods;
}

function get_default_user_address($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $address = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$address) {
        return get_first_user_address($conn, $user_id);
    }
    return $address;
}

function generate_order_number($conn) {
    $date_part = date('ymd');
    $stmt = $conn->prepare("SELECT COUNT(id) as total_today FROM orders WHERE created_at >= CURDATE()");
    $stmt->execute();
    $sequence = ($stmt->get_result()->fetch_assoc()['total_today'] ?? 0) + 1;
    return "WK" . $date_part . str_pad($sequence, 4, '0', STR_PAD_LEFT);
}


function generate_order_hash() {
    return md5(uniqid(rand(), true));
}

function get_first_user_address($conn, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $address = $stmt->get_result()->fetch_assoc();
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

function get_user_address_by_id($conn, $address_id, $user_id) {
    $stmt = $conn->prepare("SELECT * FROM user_addresses WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $address_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $address = $result->fetch_assoc();
    $stmt->close();
    return $address;
}

function save_user_address($conn, $user_id, $address_data) {
    if (isset($address_data['is_default']) && $address_data['is_default'] == 1) {
        $stmt_reset = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ?");
        $stmt_reset->bind_param("i", $user_id);
        $stmt_reset->execute();
        $stmt_reset->close();
    } else {
        $address_data['is_default'] = 0; 
    }

    $stmt_insert = $conn->prepare("
        INSERT INTO user_addresses 
        (user_id, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, is_default) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_insert->bind_param("issssssssi", 
        $user_id, 
        $address_data['full_name'], $address_data['phone_number'], 
        $address_data['province'], $address_data['city'], $address_data['subdistrict'], 
        $address_data['postal_code'], $address_data['address_line_1'], 
        $address_data['address_line_2'], $address_data['is_default']
    );

    if ($stmt_insert->execute()) {
        $new_id = $conn->insert_id;
        $stmt_insert->close();
        return $new_id;
    } else {
        $stmt_insert->close();
        return false;
    }
}

function set_default_address($conn, $user_id, $address_id) {
    $conn->query("UPDATE user_addresses SET is_default = 0 WHERE user_id = $user_id");
    $stmt_on = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ? AND user_id = ?");
    $stmt_on->bind_param("ii", $address_id, $user_id);
    $stmt_on->execute();
    $stmt_on->close();
}


function cancel_overdue_orders($conn) {
    $stmt_find = $conn->prepare("SELECT id FROM orders WHERE status = 'waiting_payment' AND created_at < NOW() - INTERVAL 1 DAY");
    $stmt_find->execute();
    $result = $stmt_find->get_result();
    $order_ids = array_column($result->fetch_all(MYSQLI_ASSOC), 'id');
    if (empty($order_ids)) return;

    $conn->begin_transaction();
    try {
        $stmt_stock = $conn->prepare("UPDATE products p JOIN order_items oi ON p.id = oi.product_id SET p.stock = p.stock + oi.quantity WHERE oi.order_id = ?");
        $stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
        foreach ($order_ids as $order_id) {
            $stmt_stock->bind_param("i", $order_id);
            $stmt_stock->execute();
            $stmt_cancel->bind_param("i", $order_id);
            $stmt_cancel->execute();
        }
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Gagal membatalkan pesanan: " . $e->getMessage());
    }
}

// --- FUNGSI LOGIKA LIMIT PEMBELIAN ---
function get_user_purchase_count($conn, $user_id, $product_id, $stock_cycle_id) {
    if (!$user_id || !$product_id) return 0;
    
    $stmt = $conn->prepare("
        SELECT quantity_purchased 
        FROM user_purchase_records 
        WHERE user_id = ? AND product_id = ? AND stock_cycle_id = ?
    ");
    $stmt->bind_param("iii", $user_id, $product_id, $stock_cycle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return (int)($row['quantity_purchased'] ?? 0);
}

function get_user_pending_purchase_count($conn, $user_id, $product_id, $stock_cycle_id) {
    if (!$user_id || !$product_id) return 0;

    $stmt = $conn->prepare("
        SELECT SUM(oi.quantity) as pending_quantity 
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        WHERE o.user_id = ? 
          AND oi.product_id = ?
          AND o.status = 'waiting_payment'
          AND p.stock_cycle_id = ?
    ");
    $stmt->bind_param("iii", $user_id, $product_id, $stock_cycle_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int)($row['pending_quantity'] ?? 0);
}


function get_quantity_in_cart($conn, $user_id, $product_id) {
    if ($user_id > 0) {
        $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['quantity'] ?? 0);
    } else {
        return (int)($_SESSION['cart'][$product_id]['quantity'] ?? 0);
    }
}

function get_product_limit($conn, $product_id) {
    $stmt = $conn->prepare("SELECT purchase_limit FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return ($row) ? (int)$row['purchase_limit'] : 0; 
}


// =================================================================
// --- PERBAIKAN FUNGSI get_orders_with_items_by_status ---
// Fungsi ini di-refactor untuk:
// 1. Menerima filter $start_date dan $end_date dari $options.
// 2. Menggunakan Prepared Statements untuk keamanan (mencegah SQL Injection).
// =================================================================
function get_orders_with_items_by_status($conn, $options) {
    // Ambil semua opsi
    $status_filter = $options['status'] ?? 'semua';
    $search_query = $options['search'] ?? '';
    $limit = (int)($options['limit'] ?? 10);
    $current_page = max(1, (int)($options['p'] ?? 1));
    $offset = max(0, ($current_page - 1) * $limit);
    
    // Ambil opsi tanggal BARU
    $start_date = $options['start_date'] ?? '';
    $end_date = $options['end_date'] ?? '';
    
    if ($limit <= 0) $limit = 10;

    // Persiapan untuk Prepared Statement
    $where_conditions = [];
    $params = [];
    $types = "";
    
    // 1. Filter Status
    if ($status_filter !== 'semua') {
        $where_conditions[] = "o.status = ?";
        $params[] = $status_filter;
        $types .= "s";
    }

    // 2. Filter Pencarian
    if (!empty($search_query)) {
        $search_term = "%" . $search_query . "%";
        $where_conditions[] = "(o.order_number LIKE ? OR u.name LIKE ? OR o.phone_number LIKE ?)";
        // Tambahkan 3x karena ada 3 placeholder (?)
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= "sss";
    }

    // 3. Filter Tanggal (INI PERBAIKANNYA)
    if (!empty($start_date) && !empty($end_date)) {
        // Gunakan DATE() untuk membandingkan tanggalnya saja, mengabaikan jam
        $where_conditions[] = "DATE(o.created_at) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
        $types .= "ss";
    }

    // Gabungkan semua kondisi WHERE
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // --- Query Total (Menggunakan Prepared Statement) ---
    $total_query = "SELECT COUNT(o.id) as total FROM orders o LEFT JOIN users u ON o.user_id = u.id" . $where_clause;
    
    $stmt_total = $conn->prepare($total_query);
    if (!empty($params)) {
        $stmt_total->bind_param($types, ...$params);
    }
    $stmt_total->execute();
    $total_records = (int)$stmt_total->get_result()->fetch_assoc()['total'];
    $stmt_total->close();


    // --- Query Data Pesanan (Menggunakan Prepared Statement) ---
    $orders = [];
    $sql_orders = "
        SELECT o.*, u.name as user_name, u.email as user_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        {$where_clause}
        ORDER BY o.created_at DESC 
        LIMIT ? OFFSET ?
    ";
    
    // Tambahkan parameter LIMIT dan OFFSET ke $params dan $types
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt_orders = $conn->prepare($sql_orders);
    $stmt_orders->bind_param($types, ...$params);
    $stmt_orders->execute();
    $result_orders = $stmt_orders->get_result();
    
    if ($result_orders) {
        $order_ids = [];
        while ($row = $result_orders->fetch_assoc()) {
            $row['user_name'] = $row['user_name'] ?? 'User Dihapus';
            $row['user_email'] = $row['user_email'] ?? 'N/A';
            
            $orders[$row['id']] = $row;
            $orders[$row['id']]['items'] = [];
            $order_ids[] = (int)$row['id'];
        }
        $stmt_orders->close();
        
        if (!empty($order_ids)) {
            $order_ids_str = implode(',', $order_ids);
            
            // Query untuk item bisa tetap menggunakan query biasa karena $order_ids_str aman (sudah di-cast ke int)
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
    } else {
         $stmt_orders->close();
    }

    return [
        'orders' => array_values($orders),
        'total' => $total_records
    ];
}
// =================================================================
// --- AKHIR PERBAIKAN ---
// =================================================================

// --- FUNGSI-FUNGSI DARI FILE ASLI ANDA ---
function create_notification($conn, $user_id, $message) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}

function get_all_products($conn) {
    $products = [];
    $sql = "SELECT p.*, c.name as category_name 
            FROM products p 
            JOIN categories c ON p.category_id = c.id 
            ORDER BY p.created_at DESC";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
    }
    return $products;
}

function get_product_by_id($conn, $id) {
    $product = null;
    $stmt = $conn->prepare("SELECT p.*, c.name as category_name 
                            FROM products p 
                            JOIN categories c ON p.category_id = c.id 
                            WHERE p.id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows == 1) {
        $product = $result->fetch_assoc();
    }
    $stmt->close();
    return $product;
}

function get_all_categories($conn) {
    $categories = [];
    $sql = "SELECT * FROM categories ORDER BY name ASC";
    $result = $conn->query($sql);
    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
    }
    return $categories;
}

function get_order_by_hash($conn, $hash) {
    $stmt = $conn->prepare("SELECT * FROM orders WHERE order_hash = ?");
    $stmt->bind_param("s", $hash);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();
    return $order;
}

// ============================================================
// [FUNGSI BARU] Ditambahkan untuk polling
// ============================================================
function get_order_number_by_id($conn, $order_id) {
    $stmt = $conn->prepare("SELECT order_number FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['order_number'] ?? null;
}

function get_order_items_with_details($conn, $order_id) {
    $items = [];
    // [PERBAIKAN] Menambahkan p.id as product_id
    $stmt = $conn->prepare("
        SELECT oi.quantity, oi.price, p.name, p.image, p.id as product_id 
        FROM order_items oi 
        JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function get_payment_method_by_id($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM payment_methods WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $method = $result->fetch_assoc();
    $stmt->close();
    return $method;
}

function get_user_by_id($conn, $id) {
    $stmt = $conn->prepare("SELECT id, name, email, role, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

function get_all_users($conn) {
    $users = [];
    $result = $conn->query("SELECT id, name, email, role, created_at FROM users ORDER BY created_at DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    return $users;
}

function get_dashboard_stats($conn) {
    $stats = [];
    $stats['total_revenue'] = $conn->query("SELECT SUM(total) as revenue FROM orders WHERE status = 'completed'")->fetch_assoc()['revenue'] ?? 0;
    $stats['new_orders'] = $conn->query("SELECT COUNT(id) as count FROM orders WHERE status IN ('waiting_payment', 'waiting_approval')")->fetch_assoc()['count'] ?? 0;
    $stats['total_customers'] = $conn->query("SELECT COUNT(id) as count FROM users WHERE role = 'user'")->fetch_assoc()['count'] ?? 0;
    $stats['low_stock_products'] = $conn->query("SELECT COUNT(id) as count FROM products WHERE stock < 10")->fetch_assoc()['count'] ?? 0;
    return $stats;
}

function get_latest_orders($conn, $limit = 5) {
    $orders = [];
    $result = $conn->query("SELECT o.id, o.order_number, o.total, o.status, u.name as user_name FROM orders o JOIN users u ON o.user_id = u.id ORDER BY o.created_at DESC LIMIT $limit");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
    }
    return $orders;
}

function get_admin_status_class($status) {
    $classes = [
        'completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800',
        'processed' => 'bg-purple-100 text-purple-800', 'belum_dicetak' => 'bg-cyan-100 text-cyan-800',
        'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}
?>