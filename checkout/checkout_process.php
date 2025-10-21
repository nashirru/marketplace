<?php
// File: checkout/checkout_process.php
// Versi Final dengan Redirect yang Benar

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

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
    $address_data = [
        'full_name'      => sanitize_input($_POST['full_name'] ?? ''),
        'phone_number'   => sanitize_input($_POST['phone_number'] ?? ''),
        'province'       => sanitize_input($_POST['province'] ?? ''),
        'city'           => sanitize_input($_POST['city'] ?? ''),
        'subdistrict'    => sanitize_input($_POST['subdistrict'] ?? ''),
        'postal_code'    => sanitize_input($_POST['postal_code'] ?? ''),
        'address_line_1' => sanitize_input($_POST['address_line_1'] ?? ''),
        'address_line_2' => sanitize_input($_POST['address_line_2'] ?? null)
    ];
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $existing_address_id = (int)($_POST['existing_address'] ?? 0);
    $user_address_id = 0;

    if (empty($address_data['full_name']) || empty($address_data['phone_number']) || empty($address_data['address_line_1'])) {
        throw new Exception("Harap isi semua kolom alamat yang wajib diisi.");
    }
    
    if ($existing_address_id > 0) {
        $user_address_id = $existing_address_id;
    } else {
        if ($is_default) { 
            $conn->query("UPDATE user_addresses SET is_default = 0 WHERE user_id = $user_id"); 
        }
        $stmt_addr = $conn->prepare("INSERT INTO user_addresses (user_id, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_addr->bind_param("issssssssi", $user_id, $address_data['full_name'], $address_data['phone_number'], $address_data['province'], $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'], $address_data['address_line_1'], $address_data['address_line_2'], $is_default);
        $stmt_addr->execute();
        $user_address_id = $stmt_addr->insert_id;
        $stmt_addr->close();
    }

    $cart_items_data = get_cart_items($conn, $user_id);
    if (empty($cart_items_data['items'])) { 
        throw new Exception("Keranjang Anda kosong."); 
    }
    $total_harga = $cart_items_data['subtotal'];
    
    $order_number = 'WK-' . time() . '-' . $user_id;
    $order_hash = generate_order_hash();

    $stmt_order = $conn->prepare("INSERT INTO orders (user_id, order_number, order_hash, total, status, user_address_id, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, created_at) VALUES (?, ?, ?, ?, 'waiting_payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt_order->bind_param("issdissssssss", $user_id, $order_number, $order_hash, $total_harga, $user_address_id, $address_data['full_name'], $address_data['phone_number'], $address_data['province'], $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'], $address_data['address_line_1'], $address_data['address_line_2']);
    $stmt_order->execute();
    $order_id = $stmt_order->insert_id;
    $stmt_order->close();

    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    $midtrans_items = [];
    foreach ($cart_items_data['items'] as $item) {
        $stmt_items->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        $stmt_items->execute();
        $midtrans_items[] = [
            'id' => $item['product_id'], 
            'price' => (int)$item['price'], 
            'quantity' => (int)$item['quantity'], 
            'name' => $item['name']
        ];
    }
    $stmt_items->close();
    
    $stmt_user = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_result = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();
    
    $attempt_order_number = $order_number . '-1';
    
    $transaction_params = [
        'transaction_details' => [
            'order_id' => $attempt_order_number, 
            'gross_amount' => (int)$total_harga
        ], 
        'customer_details' => [
            'first_name' => $address_data['full_name'], 
            'email' => $user_result['email'], 
            'phone' => $address_data['phone_number']
        ], 
        'item_details' => $midtrans_items
    ];
    
    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);
    
    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

    clear_cart($conn, $user_id);

    $conn->commit();
    echo json_encode([
        'success' => true,
        'snap_token' => $snapToken,
        'order_id' => $attempt_order_number,
        'db_order_id' => $order_id
    ]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit;
}