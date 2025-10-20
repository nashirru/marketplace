<?php
// File: checkout/checkout_process.php
// File ini HANYA untuk memproses data dari form checkout.

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

check_login();

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Jika file diakses langsung tanpa POST, tendang ke keranjang
    redirect('/cart/cart.php');
}

$conn->begin_transaction();
try {
    // 1. VALIDASI DAN PROSES DATA ALAMAT
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

    if (empty($address_data['full_name']) || empty($address_data['phone_number']) || empty($address_data['province']) || empty($address_data['city']) || empty($address_data['address_line_1'])) {
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

    // 2. AMBIL ITEM KERANJANG DAN HITUNG TOTAL
    $cart_items_data = get_cart_items($conn, $user_id);
    if (empty($cart_items_data['items'])) {
        throw new Exception("Keranjang Anda kosong. Proses tidak dapat dilanjutkan.");
    }
    $total_harga = $cart_items_data['subtotal'];
    
    // 3. BUAT ORDER DI DATABASE
    $order_number = 'WK-' . time() . '-' . $user_id;
    $stmt_order = $conn->prepare("INSERT INTO orders (user_id, order_number, total, status, user_address_id, created_at, updated_at) VALUES (?, ?, ?, 'waiting_payment', ?, NOW(), NOW())");
    $stmt_order->bind_param("isdi", $user_id, $order_number, $total_harga, $user_address_id);
    $stmt_order->execute();
    $order_id = $stmt_order->insert_id;
    $stmt_order->close();

    // 4. SIMPAN ORDER ITEMS & SIAPKAN UNTUK MIDTRANS
    $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    $midtrans_items = [];
    foreach ($cart_items_data['items'] as $item) {
        $stmt_items->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
        $stmt_items->execute();
        
        $midtrans_items[] = [
            'id'       => $item['product_id'],
            'price'    => (int)$item['price'],
            'quantity' => (int)$item['quantity'],
            'name'     => $item['name']
        ];
    }
    $stmt_items->close();
    
    // 5. BUAT SNAP TOKEN
    // Ambil data user dari DB untuk Midtrans
    $stmt_user = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user_result = $stmt_user->get_result()->fetch_assoc();
    $stmt_user->close();
    
    $transaction_params = [
        'transaction_details' => [
            'order_id' => $order_number,
            'gross_amount' => (int)$total_harga,
        ],
        'customer_details' => [
            'first_name' => $address_data['full_name'],
            'email'      => $user_result['email'],
            'phone'      => $address_data['phone_number'],
        ],
        'item_details' => $midtrans_items,
    ];

    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);

    // 6. SIMPAN SNAP TOKEN KE DATABASE
    $stmt_update_token = $conn->prepare("UPDATE orders SET snap_token = ? WHERE id = ?");
    $stmt_update_token->bind_param("si", $snapToken, $order_id);
    $stmt_update_token->execute();
    $stmt_update_token->close();

    // 7. HAPUS KERANJANG
    clear_cart($conn, $user_id);

    // 8. COMMIT TRANSAKSI
    $conn->commit();

    // 9. SIMPAN SNAP TOKEN KE SESSION & REDIRECT KE HALAMAN PEMBAYARAN
    $_SESSION['snap_token'] = $snapToken;
    redirect('payment.php');

} catch (Exception $e) {
    $conn->rollback();
    set_flashdata('error', 'Gagal memproses pesanan: ' . $e->getMessage());
    // Redirect kembali ke halaman form checkout
    redirect('checkout.php');
}