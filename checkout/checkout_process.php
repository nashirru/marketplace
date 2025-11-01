<?php
// File: checkout/checkout_process.php
// VERSI FINAL: Siap produksi (log debug dihapus)

// CRITICAL: Set error logging dulu sebelum header
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// $debug_log = __DIR__ . '/../logs/checkout_debug.log';
// ini_set('error_log', $debug_log);
//
// function debug_log($message) {
//     global $debug_log;
//     $timestamp = date("Y-m-d H:i:s");
//     file_put_contents($debug_log, "[$timestamp] $message\n", FILE_APPEND);
// }
//
// debug_log("========== CHECKOUT START (v2-atomic) ==========");

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

// debug_log("Files loaded successfully");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    // debug_log("ABORT: User not logged in");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

$user_id = $_SESSION['user_id'];
// debug_log("User ID: $user_id");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // debug_log("ABORT: Invalid request method");
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode request tidak valid.']);
    exit;
}

$conn->begin_transaction();
// debug_log("Transaction started");

try {
    $cart_items_data = get_cart_items_for_calculation($conn, $user_id);
    // debug_log("Cart items count: " . count($cart_items_data));
    
    if (empty($cart_items_data)) {
        throw new Exception("Keranjang Anda kosong.", 1);
    }

    $total_harga = 0;
    $midtrans_items = [];
    $items_to_insert = [];
    
    $product_ids_in_cart = array_column($cart_items_data, 'product_id');
    // debug_log("Product IDs: " . implode(',', $product_ids_in_cart));
    
    if(empty($product_ids_in_cart)) {
        throw new Exception("Keranjang tidak valid. Gagal mendapatkan ID produk.");
    }

    // PENTING: Kita tetap lock untuk memastikan Harga dan Purchase Limit tidak berubah
    // saat proses checkout
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
    // debug_log("Products locked and fetched");
    
    $cart_quantities = array_column($cart_items_data, 'quantity', 'product_id');

    foreach ($product_ids_in_cart as $product_id) {
        $quantity_in_cart = $cart_quantities[$product_id];

        if (!isset($latest_products[$product_id])) {
            throw new Exception("Salah satu produk di keranjang tidak tersedia.", 1);
        }
        $product = $latest_products[$product_id];

        // Cek stok (dari data yang sudah di-lock)
        // Ini adalah 'optimistic check' pertama
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

        // CRITICAL FIX: Validasi tipe data sebelum masuk array
        $product_price = floatval($product['price']);
        $subtotal_item = $product_price * $quantity_in_cart;
        $total_harga += $subtotal_item;

        // Validasi dan sanitasi nama produk
        $product_name = trim($product['name']);
        if (empty($product_name)) {
            $product_name = "Produk #" . $product_id;
        }
        $product_name = substr($product_name, 0, 50);

    // debug_log("Processing product $product_id: $product_name, Price: $product_price, Qty: $quantity_in_cart");

    // SUPER CRITICAL: Pastikan tipe data 100% benar
        $midtrans_items[] = [
            'id' => strval($product_id),  // Force string conversion
            'price' => intval(round($product_price)), // Force integer
            'quantity' => intval($quantity_in_cart), // Force integer
            'name' => $product_name
        ];
        
        $items_to_insert[] = [
            'product_id' => $product_id, 
            'quantity' => $quantity_in_cart, 
            'price' => $product_price
        ];

        // ============================================================
        // [PERBAIKAN BUG STOK] - ATOMIC UPDATE
        // ============================================================
        // Alih-alih menghitung $new_stock di PHP, kita lakukan
        // pengecekan dan pengurangan stok langsung di database.
        // Ini adalah benteng terakhir melawan race condition.
        
        $stmt_update_stock = $conn->prepare(
            "UPDATE products SET stock = stock - ? 
             WHERE id = ? AND stock >= ?"
        );
        // Kurangi stok (param 1) HANYA JIKA stok saat ini (AND stock >=)
        // lebih besar atau sama dengan jumlah yg dibeli (param 3)
        $stmt_update_stock->bind_param("iii", $quantity_in_cart, $product_id, $quantity_in_cart);
        
        if (!$stmt_update_stock->execute()) {
             // Error query
             throw new Exception("Gagal update stok (DB Error) untuk '" . htmlspecialchars($product['name']) . "'.");
        }
        
        // INI ADALAH KUNCI PERBAIKAN:
        if ($stmt_update_stock->affected_rows === 0) {
            // Jika affected_rows = 0, itu berarti query SUKSES dijalankan
            // TAPI kondisi `AND stock >= ?` GAGAL.
            // Artinya, stok sudah habis dibeli orang lain di antara
            // `SELECT FOR UPDATE` dan `UPDATE` ini.
             throw new Exception("Stok '" . htmlspecialchars($product['name']) . "' habis terjual saat proses checkout.", 1);
        }
        $stmt_update_stock->close();
    // debug_log("Atomic update success for Product ID: $product_id");
    // ============================================================
        // AKHIR PERBAIKAN
        // ============================================================
    }

    // debug_log("Total amount calculated: " . $total_harga);
    // debug_log("Midtrans items prepared: " . count($midtrans_items));

    // Proses alamat
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

        // ============================================================
        // [PERBAIKAN SYNTAX ERROR]
        // Logika ini tidak sengaja terhapus saat pembersihan log
        // Kita kembalikan blok 'if' untuk menyimpan alamat baru
        // dan '}' penutup untuk 'else'
        // ============================================================
        if ($is_default_new_address == 1) {
            $saved_address_id = save_user_address($conn, $user_id, $address_data); 
            if (!$saved_address_id) {
                throw new Exception("Gagal menyimpan alamat baru sebagai alamat utama.");
            }
            $user_address_id_for_order = $saved_address_id;
        }
    } // <-- Ini adalah '}' penutup 'else' yang HILANG

    // debug_log("Address processed: " . $address_data['full_name']);

    // Buat order
    $order_number = generate_order_number($conn);
    $status = 'waiting_payment';

    // debug_log("Generated order number: $order_number");

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

    // debug_log("Order created with ID: $order_id");

    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    foreach ($items_to_insert as $item) {
        $stmt_items->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        if(!$stmt_items->execute()) {
             throw new Exception("Gagal menyimpan item pesanan: " . $stmt_items->error);
        }
    }
    $stmt_items->close();

    // debug_log("Order items inserted");
    
    // Persiapan Midtrans
    $user_data = get_user_by_id($conn, $user_id);
    $attempt_order_number = $order_number . '-T' . time(); 

    // debug_log("Attempt order number: $attempt_order_number");

    // CRITICAL: Validasi ulang sebelum kirim ke Midtrans
    $validated_items = [];
    foreach ($midtrans_items as $idx => $item) {
        if (!isset($item['id']) || !isset($item['price']) || !isset($item['quantity']) || !isset($item['name'])) {
            // debug_log("ERROR: Invalid item at index $idx: " . json_encode($item));
            throw new Exception("Item tidak valid di keranjang");
        }
        
        // Double check tipe data
        $validated_items[] = [
            'id' => (string)$item['id'],
            'price' => (int)$item['price'],
            'quantity' => (int)$item['quantity'],
            'name' => (string)$item['name']
        ];
    }

    // Build payload
    $transaction_params = [
        'transaction_details' => [
            'order_id' => $attempt_order_number, 
            'gross_amount' => intval(round($total_harga))
        ],
        'customer_details' => [
            'first_name' => substr($address_data['full_name'], 0, 50), 
            'email' => $user_data['email'] ?? 'noreply@warokkite.com', 
            'phone' => $address_data['phone_number']
        ],
        'item_details' => $validated_items
    ];
    
    // debug_log("=== MIDTRANS PAYLOAD ===");
    // debug_log(json_encode($transaction_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    // debug_log("========================");

    // Call Midtrans API
    // debug_log("Calling Midtrans API...");
    
    try {
        $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);
        // debug_log("Snap token received: " . substr($snapToken, 0, 20) . "...");
    } catch (\Exception $midtrans_error) {
        // debug_log("MIDTRANS API ERROR: " . $midtrans_error->getMessage());
        // debug_log("Midtrans trace: " . $midtrans_error->getTraceAsString());
        throw new Exception("Payment gateway error: " . $midtrans_error->getMessage());
    }

    // Simpan attempt
    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

    // debug_log("Payment attempt saved");

    clear_cart($conn, $user_id);
    // debug_log("Cart cleared");

    $conn->commit();
    // debug_log("Transaction committed");
    
    $response = [
        'success' => true,
        'snap_token' => $snapToken,
        'db_order_id' => $order_id,
        'attempt_order_number' => $attempt_order_number
    ];
    
    // debug_log("Response: " . json_encode($response));
    // debug_log("========== CHECKOUT SUCCESS ==========\n");
    
    echo json_encode($response);

} catch (Exception $e) {
    $conn->rollback();

    $error_code = $e->getCode();
    $error_message = $e->getMessage();
    $http_status = ($error_code === 1) ? 400 : 500; 

    // debug_log("ERROR: " . $error_message);
    // debug_log("Stack trace: " . $e->getTraceAsString());
    // debug_log("========== CHECKOUT FAILED ==========\n");

    http_response_code($http_status);
    echo json_encode([
        'success' => false,
        'message' => $error_message,
        'redirect_to_cart' => ($error_code === 1)
    ]);
}
?>