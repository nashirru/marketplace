<?php
// File: checkout/get_snap_token.php
// Deskripsi: Menangani request token Snap Midtrans untuk pembayaran ulang dari halaman profil.

// Matikan display error agar tidak merusak format JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

/**
 * Generate ID unik untuk Midtrans attempt.
 * Menggunakan microtime + random suffix untuk menghindari duplicate entry saat traffic tinggi.
 *
 * @param string $order_number Nomor order (contoh: WK2605090039)
 * @return string ID unik (contoh: WK2605090039-17783304478923742)
 */
function generate_unique_attempt_id($order_number)
{
    $micro = substr(str_replace('.', '', (string)microtime(true)), -4);
    $rand  = str_pad(mt_rand(0, 999), 3, '0', STR_PAD_LEFT);
    return $order_number . '-' . time() . $micro . $rand;
}

try {
    // Load dependensi
    require_once '../config/config.php';
    require_once '../sistem/sistem.php';
    require_once '../midtrans/config_midtrans.php';

    if (session_status() === PHP_SESSION_NONE) {
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

    // Ambil Item Pesanan dengan nama variasi
    $sql_items = "
        SELECT oi.id, oi.product_id, oi.variation_id, oi.price, oi.quantity,
               p.name AS product_name,
               pv.name AS variation_name
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

    $order_items = [];
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

        $variation_id = isset($item['variation_id']) ? (int)$item['variation_id'] : 0;
        $item_id = $variation_id > 0
            ? ((string)$item['product_id'] . '-' . (string)$variation_id)
            : (string)$item['product_id'];

        $midtrans_items[] = [
            'id'       => $item_id,
            'price'    => (int)$item['price'],
            'quantity' => (int)$item['quantity'],
            'name'     => substr($item_name, 0, 50),
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
            'name'     => 'Biaya Pengiriman',
        ];
    }

    // Generate ID Order Unik untuk Midtrans
    $attempt_order_number = generate_unique_attempt_id($order['order_number']);

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
                'first_name'   => substr($order['full_name'], 0, 50),
                'address'      => substr($order['address_line_1'], 0, 200),
                'city'         => $order['city'],
                'postal_code'  => $order['postal_code'],
                'country_code' => 'IDN',
            ],
            'shipping_address' => [
                'first_name'   => substr($order['full_name'], 0, 50),
                'address'      => substr($order['address_line_1'], 0, 200),
                'city'         => $order['city'],
                'postal_code'  => $order['postal_code'],
                'country_code' => 'IDN',
            ],
        ],
        'item_details' => $midtrans_items,
        'callbacks' => [
            'finish' => BASE_URL . '/profile/profile.php?tab=orders',
        ],
    ];

    // Request Token ke Midtrans
    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);

    // Simpan Attempt ke Database dengan retry loop
    $max_retries = 3;
    $attempt_saved = false;

    for ($retry = 0; $retry < $max_retries; $retry++) {
        $stmt_attempt = $conn->prepare(
            "INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')"
        );
        if (!$stmt_attempt) {
            break;
        }

        $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);

        if ($stmt_attempt->execute()) {
            $attempt_saved = true;
            $stmt_attempt->close();
            break;
        }

        // Jika error karena duplicate key (errno 1062), regenerate dan retry
        if ($conn->errno === 1062) {
            error_log("Duplicate attempt_order_number detected (retry {$retry}): {$attempt_order_number}");
            $stmt_attempt->close();
            usleep(50000); // Tunggu 50ms sebelum retry
            $attempt_order_number = generate_unique_attempt_id($order['order_number']);
            continue;
        }

        $stmt_attempt->close();
        break;
    }

    if (!$attempt_saved) {
        error_log("Failed to save payment attempt after {$max_retries} retries for order: {$order_id}");
    }

    // Return JSON Success
    echo json_encode([
        'success'      => true,
        'snap_token'   => $snapToken,
        'db_order_id'  => $order_id,
        'order_number' => $order['order_number'],
    ]);

} catch (Exception $e) {
    // Return JSON Error
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
