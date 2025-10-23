<?php
// File: checkout/checkout_process_v2.php
// Ini adalah file BARU untuk bypass server cache (OPcache)
// Kode di dalamnya sudah benar dengan (string) cast.

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// ... (kode session_start dan validasi user_id) ...
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
    exit;
}

$conn->begin_transaction();
try {
    // ... (kode get_cart_items_data, validasi keranjang kosong) ...
    $cart_items_data = get_cart_items_for_calculation($conn, $user_id);
    if (empty($cart_items_data)) {
        throw new Exception("Keranjang Anda kosong.", 1);
    }

    $total_harga = 0;
    $midtrans_items = [];
    $items_to_insert = [];
    
    $product_ids_in_cart = array_column($cart_items_data, 'product_id');
    if(empty($product_ids_in_cart)) {
        throw new Exception("Keranjang tidak valid. Gagal mendapatkan ID produk.");
    }
    // ... (kode validasi stok, limit, dll) ...
    $placeholders = implode(',', array_fill(0, count($product_ids_in_cart), '?'));
    $types = str_repeat('i', count($product_ids_in_cart));
    $stmt_prod_check = $conn->prepare("SELECT id, name, price, stock, purchase_limit, stock_cycle_id FROM products WHERE id IN ($placeholders) FOR UPDATE");
    $stmt_prod_check->bind_param($types, ...$product_ids_in_cart);
    $stmt_prod_check->execute();
    $latest_products_result = $stmt_prod_check->get_result();
    $latest_products = [];
    while($row = $latest_products_result->fetch_assoc()) {
        $latest_products[$row['id']] = $row;
    }
    $stmt_prod_check->close();
    
    $cart_quantities = array_column($cart_items_data, 'quantity', 'product_id');

    foreach ($product_ids_in_cart as $product_id) {
        $quantity_in_cart = $cart_quantities[$product_id];

        if (!isset($latest_products[$product_id])) {
            throw new Exception("Salah satu produk di keranjang tidak tersedia.", 1);
        }
        $product = $latest_products[$product_id];

        if ($quantity_in_cart > $product['stock']) {
             throw new Exception("Stok '" . htmlspecialchars($product['name']) . "' tidak cukup (sisa: {$product['stock']}).", 1);
        }

        if ($product['purchase_limit'] > 0) {
            $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
            $pending_count = get_user_pending_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
            $total_will_purchase = $already_bought + $pending_count + $quantity_in_cart;
            
            if ($total_will_purchase > $product['purchase_limit']) {
                 throw new Exception("Melebihi batas beli ({$product['purchase_limit']}) untuk '" . htmlspecialchars($product['name']) . "'.", 1);
            }
        }

        $subtotal_item = $product['price'] * $quantity_in_cart;
        $total_harga += $subtotal_item;

        // ============================================================
        // INI ADALAH PERBAIKANNYA
        // ============================================================
        $midtrans_items[] = [
            'id' => (string)$product_id, // WAJIB (string)
            'price' => (int)$product['price'], 
            'quantity' => (int)$quantity_in_cart, 
            'name' => $product['name']
        ];
        
        $items_to_insert[] = ['product_id' => $product_id, 'quantity' => $quantity_in_cart, 'price' => $product['price']];

        $new_stock = $product['stock'] - $quantity_in_cart;
        $stmt_update_stock = $conn->prepare("UPDATE products SET stock = ? WHERE id = ?");
        $stmt_update_stock->bind_param("ii", $new_stock, $product_id);
        if (!$stmt_update_stock->execute() || $stmt_update_stock->affected_rows === 0) {
             throw new Exception("Gagal update stok '" . htmlspecialchars($product['name']) . "'.");
        }
        $stmt_update_stock->close();
    }
    // ... (kode proses alamat) ...
    $existing_address_id = (int)($_POST['existing_address'] ?? 0);
    $user_address_id_for_order = null;
    $address_data = [];

    if ($existing_address_id > 0) {
        $fetched_address = get_user_address_by_id($conn, $existing_address_id, $user_id);
        if (!$fetched_address) {
            throw new Exception("Alamat yang dipilih tidak valid.");
        }
        $address_data = $fetched_address;
        $user_address_id_for_order = $existing_address_id;
    } else {
        $is_default_new_address = isset($_POST['is_default']) ? 1 : 0;
        $address_data = [
            'full_name' => sanitize_input($_POST['full_name'] ?? ''),
            'phone_number' => sanitize_input($_POST['phone_number'] ?? ''),
            'province' => sanitize_input($_POST['province'] ?? ''),
            'city' => sanitize_input($_POST['city'] ?? ''),
            'subdistrict' => sanitize_input($_POST['subdistrict'] ?? ''),
            'postal_code' => sanitize_input($_POST['postal_code'] ?? ''),
            'address_line_1' => sanitize_input($_POST['address_line_1'] ?? ''),
            'address_line_2' => sanitize_input($_POST['address_line_2'] ?? ''),
            'is_default' => $is_default_new_address,
        ];

        if (empty($address_data['full_name']) || empty($address_data['phone_number']) || empty($address_data['province']) || empty($address_data['city']) || empty($address_data['address_line_1'])) {
            throw new Exception("Harap isi semua field alamat baru yang wajib.");
        }

        if ($is_default_new_address == 1) {
            $saved_address_id = save_user_address($conn, $user_id, $address_data); 
            if (!$saved_address_id) {
                throw new Exception("Gagal menyimpan alamat baru sebagai alamat utama.");
            }
            $user_address_id_for_order = $saved_address_id;
        }
    }
    // ... (kode buat order, masukkan order items) ...
    $order_number = generate_order_number($conn);
    $status = 'waiting_payment';

    $stmt_order = $conn->prepare("
        INSERT INTO orders (user_id, order_number, total, status, user_address_id,
        full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt_order->bind_param("isdsissssssss",
        $user_id, $order_number, $total_harga, $status, $user_address_id_for_order,
        $address_data['full_name'], $address_data['phone_number'], $address_data['province'],
        $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'],
        $address_data['address_line_1'], $address_data['address_line_2']
    );

    if (!$stmt_order->execute()) {
         throw new Exception("Gagal membuat pesanan: " . $stmt_order->error);
    }
    $order_id = $stmt_order->insert_id;
    $stmt_order->close();

    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    foreach ($items_to_insert as $item) {
        $stmt_items->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        if(!$stmt_items->execute()) {
             throw new Exception("Gagal menyimpan item pesanan: " . $stmt_items->error);
        }
    }
    $stmt_items->close();
    
    // -- Persiapan Midtrans --
    $user_data = get_user_by_id($conn, $user_id);
    $attempt_order_number = $order_number . '-T' . time(); 

    $transaction_params = [
        'transaction_details' => ['order_id' => $attempt_order_number, 'gross_amount' => (int)$total_harga],
        'customer_details' => ['first_name' => $address_data['full_name'], 'email' => $user_data['email'], 'phone' => $address_data['phone_number']],
        'item_details' => $midtrans_items
    ];
    
    // ============================================================
    // BARIS DEBUG TAMBAHAN
    // ============================================================
    // Ini akan mencatat payload ke log error PHP Anda (misal: logs/midtrans_error.log)
    error_log("[DEBUG] PAYLOAD CHECKOUT DESKTOP (v2): " . json_encode($transaction_params));
    // ============================================================

    // Dapatkan Snap Token
    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);

    // ... (kode simpan attempt, clear cart, commit, dan echo) ...
    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

    clear_cart($conn, $user_id);

    $conn->commit();
    echo json_encode([
        'success' => true,
        'snap_token' => $snapToken,
        'db_order_id' => $order_id
    ]);

} catch (Exception $e) {
    // ... (kode catch exception) ...
    $conn->rollback();

    $error_code = $e->getCode();
    $error_message = $e->getMessage();
    $http_status = ($error_code === 1) ? 400 : 500; 

    error_log("Checkout Gagal (User: $user_id): " . $error_message);

    http_response_code($http_status);
    echo json_encode([
        'success' => false,
        'message' => $error_message,
        'redirect_to_cart' => ($error_code === 1)
    ]);
}
?>