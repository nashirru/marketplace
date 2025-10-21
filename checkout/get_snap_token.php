<?php
// File: checkout/get_snap_token.php
// Versi Final - Logika Lanjut Bayar yang Benar

error_reporting(0);
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['order_id'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$order_id = (int)$_POST['order_id'];
$user_id = $_SESSION['user_id'];

$conn->begin_transaction();
try {
    $stmt_order = $conn->prepare(
        "SELECT o.order_number, o.total, o.full_name, o.phone_number, u.email 
         FROM orders o JOIN users u ON o.user_id = u.id
         WHERE o.id = ? AND o.user_id = ? AND o.status = 'waiting_payment'"
    );
    $stmt_order->bind_param("ii", $order_id, $user_id);
    $stmt_order->execute();
    $order_data = $stmt_order->get_result()->fetch_assoc();
    $stmt_order->close();

    if (!$order_data) {
        throw new Exception("Pesanan tidak ditemukan atau tidak dapat dibayar.");
    }

    $stmt_last_attempt = $conn->prepare("SELECT snap_token, attempt_order_number FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_last_attempt->bind_param("i", $order_id);
    $stmt_last_attempt->execute();
    $last_attempt = $stmt_last_attempt->get_result()->fetch_assoc();
    $stmt_last_attempt->close();

    if ($last_attempt && !empty($last_attempt['snap_token'])) {
        $conn->commit();
        echo json_encode([
            'success' => true, 
            'snap_token' => $last_attempt['snap_token'],
            'order_id' => $last_attempt['attempt_order_number'] // Kirim juga attempt_order_number
        ]);
        exit;
    }

    $stmt_count = $conn->prepare("SELECT COUNT(id) as attempt_count FROM payment_attempts WHERE order_id = ?");
    $stmt_count->bind_param("i", $order_id);
    $stmt_count->execute();
    $attempt_count = (int)$stmt_count->get_result()->fetch_assoc()['attempt_count'];
    $stmt_count->close();
    
    $new_attempt_number = $attempt_count + 1;
    $attempt_order_number = $order_data['order_number'] . '-' . $new_attempt_number;

    $order_items = get_order_items_with_details($conn, $order_id);
    if (empty($order_items)) {
        throw new Exception("Detail produk untuk pesanan ini tidak ditemukan.");
    }
    
    $midtrans_items = [];
    foreach ($order_items as $item) {
        $midtrans_items[] = ['id' => $item['product_id'], 'price' => (int)$item['price'], 'quantity' => (int)$item['quantity'], 'name' => $item['name']];
    }

    $transaction_params = [
        'transaction_details' => ['order_id' => $attempt_order_number, 'gross_amount' => (int)$order_data['total']],
        'customer_details' => ['first_name' => $order_data['full_name'], 'email' => $order_data['email'], 'phone' => $order_data['phone_number']],
        'item_details' => $midtrans_items
    ];

    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);

    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token) VALUES (?, ?, ?)");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

    $conn->commit();
    echo json_encode([
        'success' => true, 
        'snap_token' => $snapToken,
        'order_id' => $attempt_order_number
    ]);
    exit;

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Gagal membuat sesi pembayaran: ' . $e->getMessage()]);
    exit;
}