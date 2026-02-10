<?php
// File: checkout/get_snap_token.php
// VERSI IQ 180: FIXED SQL ERROR
// Deskripsi: Menangani request token Snap Midtrans.
// 
// PERBAIKAN PENTING:
// 1. MENGHAPUS query "UPDATE orders SET midtrans_order_id..." yang menyebabkan error "Unknown column".
//    Data history pembayaran sudah dicatat dengan benar di tabel 'payment_attempts'.
// 2. Menambahkan validasi items yang lebih ketat.

// Matikan display error agar tidak merusak format JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 

header('Content-Type: application/json');

try {
    // Load dependensi
    require_once '../config/config.php';
    require_once '../sistem/sistem.php';
    require_once '../midtrans/config_midtrans.php';

    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }

    // Validasi Login
    if (!isset($_SESSION['user_id'])) {
        throw new Exception("Sesi habis. Silakan login kembali.");
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Metode request tidak valid.");
    }

    $order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    if ($order_id <= 0) {
        throw new Exception("ID Pesanan tidak valid.");
    }

    // Ambil Data Pesanan
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $_SESSION['user_id']);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        throw new Exception("Pesanan tidak ditemukan atau bukan milik Anda.");
    }

    if ($order['status'] !== 'waiting_payment') {
        throw new Exception("Pesanan ini tidak dalam status menunggu pembayaran.");
    }

    // Ambil Item Pesanan (Manual Query untuk bypassing sistem lama)
    // Menggunakan LEFT JOIN ke product_variations untuk mendapatkan nama variasi
    $order_items = [];
    $sql_items = "
        SELECT oi.id, oi.price, oi.quantity, 
               p.name as product_name, 
               pv.name as variation_name
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_variations pv ON oi.variation_id = pv.id
        WHERE oi.order_id = ?
    ";

    $stmt_items = $conn->prepare($sql_items);
    if (!$stmt_items) {
        throw new Exception("Gagal menyiapkan query item: " . $conn->error);
    }
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    
    while ($row = $result_items->fetch_assoc()) {
        $order_items[] = $row;
    }
    $stmt_items->close();

    if (empty($order_items)) {
        throw new Exception("Item pesanan tidak ditemukan di database.");
    }

    // Format Item untuk Midtrans
    $midtrans_items = [];
    foreach ($order_items as $item) {
        $item_name = $item['product_name'];
        if (!empty($item['variation_name'])) {
            $item_name .= " (" . $item['variation_name'] . ")";
        }

        $midtrans_items[] = [
            'id'       => $item['id'],
            'price'    => (int)$item['price'],
            'quantity' => (int)$item['quantity'],
            'name'     => substr($item_name, 0, 50) // Midtrans limit 50 chars
        ];
    }

    // Hitung Selisih Total (Ongkir/Diskon)
    $items_total = 0;
    foreach ($midtrans_items as $itm) {
        $items_total += ($itm['price'] * $itm['quantity']);
    }

    $diff = (int)$order['total'] - $items_total;
    if ($diff > 0) {
        $midtrans_items[] = [
            'id'       => 'SHIPPING',
            'price'    => $diff,
            'quantity' => 1,
            'name'     => 'Biaya Pengiriman'
        ];
    }

    // Generate ID Order Unik untuk Midtrans (Format: ORDERID-TIMESTAMP)
    // Ini penting agar user bisa mencoba bayar ulang jika sebelumnya gagal/closed
    $attempt_order_number = $order['order_number'] . '-' . time();

    // Parameter Transaksi Midtrans
    $transaction_params = [
        'transaction_details' => [
            'order_id'     => $attempt_order_number,
            'gross_amount' => (int)$order['total'],
        ],
        'customer_details' => [
            'first_name' => substr($order['full_name'], 0, 50),
            'email'      => $_SESSION['user_email'] ?? 'customer@warokkite.com',
            'phone'      => $order['phone_number'],
            'billing_address' => [
                'first_name' => substr($order['full_name'], 0, 50),
                'address'    => substr($order['address_line_1'], 0, 200),
                'city'       => $order['city'],
                'postal_code'=> $order['postal_code'],
                'country_code'=> 'IDN'
            ],
            'shipping_address' => [
                'first_name' => substr($order['full_name'], 0, 50),
                'address'    => substr($order['address_line_1'], 0, 200),
                'city'       => $order['city'],
                'postal_code'=> $order['postal_code'],
                'country_code'=> 'IDN'
            ]
        ],
        'item_details' => $midtrans_items,
        // Redirect URL setelah selesai di Snap
        // Kita arahkan kembali ke profile tab orders
        'callbacks' => [
            'finish' => BASE_URL . '/profile/profile.php?tab=orders'
        ]
    ];

    // Request Token ke Midtrans
    try {
        $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);
    } catch (\Exception $midtrans_error) {
        throw new Exception("Gagal menghubungi Midtrans: " . $midtrans_error->getMessage());
    }

    // Simpan Attempt ke Database
    // Tabel 'payment_attempts' menyimpan history snap_token dan attempt_order_number
    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    if ($stmt_attempt) {
        $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
        $stmt_attempt->execute();
        $stmt_attempt->close();
    }
    
    // [FIXED] MENGHAPUS BLOCK QUERY UPDATE 'midtrans_order_id' YANG MENYEBABKAN ERROR
    // Kolom 'midtrans_order_id' tidak ada di tabel 'orders'.
    // Data attempt sudah aman tersimpan di tabel 'payment_attempts'.

    // Return JSON Success
    echo json_encode([
        'success' => true,
        'snap_token' => $snapToken,
        'db_order_id' => $order_id,
        'order_number' => $order['order_number']
    ]);

} catch (Exception $e) {
    // Return JSON Error
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>