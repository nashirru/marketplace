<?php
// File: checkout/checkout_process.php
// VERSI FINAL + VARIASI FIXED: Menghapus 'p.weight' yang menyebabkan error

// CRITICAL: Set error logging dulu sebelum header
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header('Content-Type: application/json');
ob_start();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('Checkout Fatal Error: ' . ($error['message'] ?? 'Unknown') . ' in ' . ($error['file'] ?? '-') . ':' . ($error['line'] ?? '-'));
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Fatal checkout error: ' . ($error['message'] ?? 'Unknown error'),
            'redirect_to_cart' => false
        ]);
    }
});

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
    // ============================================================
    // 1. AMBIL DATA CART DENGAN VARIASI (REPLACE LOGIKA LAMA)
    // ============================================================
    // PERBAIKAN: Menghapus 'p.weight' dari query SELECT di bawah ini
    $stmt_cart = $conn->prepare("
        SELECT c.*, 
               p.name, p.price AS base_price, p.stock AS base_stock, p.purchase_limit, p.stock_cycle_id,
               pv.name AS variation_name, pv.price AS variation_price, pv.stock AS variation_stock
        FROM cart c
        JOIN products p ON c.product_id = p.id
        LEFT JOIN product_variations pv ON c.variation_id = pv.id
        WHERE c.user_id = ?
    ");
    $stmt_cart->bind_param("i", $user_id);
    $stmt_cart->execute();
    $cart_items_data = $stmt_cart->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_cart->close();
    
    if (empty($cart_items_data)) {
        throw new Exception("Keranjang Anda kosong.", 1);
    }

    $total_harga = 0;
    $midtrans_items = [];
    $items_to_insert = [];
    
    // Kita tetap ambil ID produk untuk locking row parent (untuk cek purchase limit)
    $product_ids_in_cart = array_unique(array_column($cart_items_data, 'product_id'));
    
    if(empty($product_ids_in_cart)) {
        throw new Exception("Keranjang tidak valid. Gagal mendapatkan ID produk.");
    }

    // ============================================================
    // 2. LOCK PARENT PRODUCTS (PENTING UNTUK PURCHASE LIMIT)
    // ============================================================
    $placeholders = implode(',', array_fill(0, count($product_ids_in_cart), '?'));
    $types = str_repeat('i', count($product_ids_in_cart));
    
    // Select FOR UPDATE pada produk utama untuk mencegah race condition pada purchase limit
    $stmt_prod_check = $conn->prepare("SELECT id, name, purchase_limit, stock_cycle_id FROM products WHERE id IN ($placeholders) FOR UPDATE");
    $stmt_prod_check->bind_param($types, ...$product_ids_in_cart);
    $stmt_prod_check->execute();
    $latest_products_result = $stmt_prod_check->get_result();
    $latest_products_meta = []; // Hanya simpan meta data (limit, cycle)
    while($row = $latest_products_result->fetch_assoc()) {
        $latest_products_meta[$row['id']] = $row;
    }
    $stmt_prod_check->close();
    
    // Siapkan Prepared Statement untuk Atomic Update (Produk & Variasi)
    $stmt_update_prod = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $stmt_update_var  = $conn->prepare("UPDATE product_variations SET stock = stock - ? WHERE id = ? AND stock >= ?");

    // ============================================================
    // 3. LOOP ITEM & VALIDASI
    // ============================================================
    foreach ($cart_items_data as $item) {
        $product_id = $item['product_id'];
        $variation_id = !empty($item['variation_id']) ? $item['variation_id'] : null;
        $quantity = $item['quantity'];
        
        // Tentukan data yang dipakai (Variasi atau Produk Utama)
        $is_variation = ($variation_id !== null);
        
        $current_name = $item['name'];
        if ($is_variation) {
            $current_name .= " (" . $item['variation_name'] . ")";
            $current_price = floatval($item['variation_price']);
            $current_stock = intval($item['variation_stock']); // Stok dari query cart awal
        } else {
            $current_price = floatval($item['base_price']);
            $current_stock = intval($item['base_stock']); // Stok dari query cart awal
        }

        // Cek Stok Awal (Optimistic Check - User Friendly Error)
        // Pengecekan real ada di Atomic Update nanti
        if ($quantity > $current_stock) {
             throw new Exception("Stok '" . htmlspecialchars($current_name) . "' tidak cukup (sisa: {$current_stock}).", 1);
        }

        // Cek Purchase Limit (Pada Parent Product)
        if (isset($latest_products_meta[$product_id])) {
            $parent_meta = $latest_products_meta[$product_id];
            if ($parent_meta['purchase_limit'] > 0) {
                $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $parent_meta['stock_cycle_id']);
                $pending_count = get_user_pending_purchase_count($conn, $user_id, $product_id, $parent_meta['stock_cycle_id']);
                $total_will_purchase = $already_bought + $pending_count + $quantity;
                
                if ($total_will_purchase > $parent_meta['purchase_limit']) {
                     throw new Exception("Melebihi batas beli ({$parent_meta['purchase_limit']}) untuk '" . htmlspecialchars($item['name']) . "'.", 1);
                }
            }
        } else {
             throw new Exception("Produk #{$product_id} tidak ditemukan di database.", 1);
        }

        $subtotal_item = $current_price * $quantity;
        $total_harga += $subtotal_item;

        // Siapkan Item Midtrans
        // Gunakan ID unik gabungan jika variasi: "ID-VAR_ID"
        $midtrans_id = $product_id . ($is_variation ? '-' . $variation_id : '');
        $midtrans_name = substr($current_name, 0, 50); // Midtrans limit 50 char

        $midtrans_items[] = [
            'id' => strval($midtrans_id),
            'price' => intval(round($current_price)),
            'quantity' => intval($quantity),
            'name' => $midtrans_name
        ];
        
        $items_to_insert[] = [
            'product_id' => $product_id,
            'variation_id' => $variation_id, // Simpan ID variasi
            'quantity' => $quantity, 
            'price' => $current_price
        ];

        // ============================================================
        // 4. ATOMIC UPDATE STOCK (BENTENG PERTAHANAN)
        // ============================================================
        if ($is_variation) {
            // Update tabel VARIATION
            $stmt_update_var->bind_param("iii", $quantity, $variation_id, $quantity);
            if (!$stmt_update_var->execute()) {
                 throw new Exception("Gagal update stok variasi (DB Error).");
            }
            if ($stmt_update_var->affected_rows === 0) {
                 throw new Exception("Stok variasi '" . htmlspecialchars($current_name) . "' habis terjual saat proses checkout.", 1);
            }
        } else {
            // Update tabel PRODUCTS
            $stmt_update_prod->bind_param("iii", $quantity, $product_id, $quantity);
            if (!$stmt_update_prod->execute()) {
                 throw new Exception("Gagal update stok produk (DB Error).");
            }
            if ($stmt_update_prod->affected_rows === 0) {
                 throw new Exception("Stok '" . htmlspecialchars($current_name) . "' habis terjual saat proses checkout.", 1);
            }
        }
    }
    
    // Tutup statement update
    $stmt_update_prod->close();
    $stmt_update_var->close();


    // ============================================================
    // 5. PROSES ALAMAT (LOGIKA EXISTING KAMU)
    // ============================================================
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

        // Logic simpan alamat baru
        if ($is_default_new_address == 1) {
            // Reset default lama jika ada
            $conn->query("UPDATE user_addresses SET is_default = 0 WHERE user_id = $user_id");
        }
        
        $saved_address_id = save_user_address($conn, $user_id, $address_data); 
        if (!$saved_address_id) {
            throw new Exception("Gagal menyimpan alamat baru.");
        }
        $user_address_id_for_order = $saved_address_id;
    }

    // ============================================================
    // 6. BUAT ORDER & ITEM
    // ============================================================
    $order_number = generate_order_number($conn);
    $status = 'waiting_payment';
    $order_hash = md5($order_number . time() . rand()); // Hash unik tambahan
    $customer_note = sanitize_input($_POST['customer_note'] ?? '');
    if (strlen($customer_note) > 500) {
        $customer_note = substr($customer_note, 0, 500);
    }

    // Tambahkan order_hash ke INSERT (jika tabel orders kamu punya kolom order_hash)
    // Jika tidak punya, hapus 'order_hash' dari query di bawah ini
    $has_customer_note_column = false;
    $check_customer_note_column = $conn->query("SHOW COLUMNS FROM orders LIKE 'customer_note'");
    if ($check_customer_note_column && $check_customer_note_column->num_rows > 0) {
        $has_customer_note_column = true;
    }

    if ($has_customer_note_column) {
        $stmt_order = $conn->prepare("
            INSERT INTO orders (user_id, order_number, total, status, user_address_id,
            full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, order_hash, customer_note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_order->bind_param("isdsissssssssss",
            $user_id, $order_number, $total_harga, $status, $user_address_id_for_order,
            $address_data['full_name'], $address_data['phone_number'], $address_data['province'],
            $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'],
            $address_data['address_line_1'], $address_data['address_line_2'], $order_hash, $customer_note
        );
    } else {
        $stmt_order = $conn->prepare("
            INSERT INTO orders (user_id, order_number, total, status, user_address_id,
            full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, order_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_order->bind_param("isdsisssssssss",
            $user_id, $order_number, $total_harga, $status, $user_address_id_for_order,
            $address_data['full_name'], $address_data['phone_number'], $address_data['province'],
            $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'],
            $address_data['address_line_1'], $address_data['address_line_2'], $order_hash
        );
    }

    if (!$stmt_order->execute()) {
         throw new Exception("Gagal membuat pesanan: " . $stmt_order->error);
    }
    $order_id = $stmt_order->insert_id;
    $stmt_order->close();

    // Insert Items dengan Variation ID
    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, variation_id, quantity, price) VALUES (?, ?, ?, ?, ?)");
    foreach ($items_to_insert as $item) {
        // variation_id bisa null, pastikan bind_param handle null dengan benar (tapi s di bind_param akan ubah null jadi empty string/0 di beberapa driver, lebih aman i dan kirim null)
        $var_id_val = $item['variation_id'];
        
        $stmt_items->bind_param("iiiid", $order_id, $item['product_id'], $var_id_val, $item['quantity'], $item['price']);
        if(!$stmt_items->execute()) {
             throw new Exception("Gagal menyimpan item pesanan: " . $stmt_items->error);
        }
    }
    $stmt_items->close();

    
    // ============================================================
    // 7. MIDTRANS & FINALISASI
    // ============================================================
    $user_data_db = get_user_by_id($conn, $user_id); // Ganti nama variable agar tidak konflik
    $attempt_order_number = $order_number . '-T' . time(); 

    // Build payload
    $transaction_params = [
        'transaction_details' => [
            'order_id' => $attempt_order_number, 
            'gross_amount' => intval(round($total_harga))
        ],
        'customer_details' => [
            'first_name' => substr($address_data['full_name'], 0, 50), 
            'email' => $user_data_db['email'] ?? 'noreply@warokkite.com', 
            'phone' => $address_data['phone_number']
        ],
        'item_details' => $midtrans_items
    ];
    
    try {
        $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);
    } catch (\Exception $midtrans_error) {
        throw new Exception("Payment gateway error: " . $midtrans_error->getMessage());
    }

    // Simpan attempt
    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

    clear_cart($conn, $user_id);
    $conn->commit();
    
    $response = [
        'success' => true,
        'snap_token' => $snapToken,
        'db_order_id' => $order_id,
        'attempt_order_number' => $attempt_order_number
    ];
    
    echo json_encode($response);

} catch (Exception $e) {
    $conn->rollback();

    $error_code = $e->getCode();
    $error_message = $e->getMessage();
    $http_status = ($error_code === 1) ? 400 : 500; 

    http_response_code($http_status);
    echo json_encode([
        'success' => false,
        'message' => $error_message,
        'redirect_to_cart' => ($error_code === 1)
    ]);
} catch (Throwable $t) {
    $conn->rollback();
    error_log('Checkout Throwable Error: ' . $t->getMessage() . ' in ' . $t->getFile() . ':' . $t->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Checkout error: ' . $t->getMessage(),
        'redirect_to_cart' => false
    ]);
}
?>
