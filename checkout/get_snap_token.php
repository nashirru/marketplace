<?php
// File: checkout/get_snap_token.php
// PERBAIKAN: Menambahkan (string) pada 'id' di item_details

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
        try {
            $status = \Midtrans\Transaction::status($last_attempt['attempt_order_number']);
            if ($status->transaction_status == 'pending') {
                $conn->commit();
                echo json_encode([
                    'success' => true, 
                    'snap_token' => $last_attempt['snap_token'],
                    'order_id' => $last_attempt['attempt_order_number'],
                    'db_order_id' => $order_id
                ]);
                exit;
            }
        } catch (Exception $e) {
            // Token expired atau invalid, lanjut buat baru
        }
    }

    $attempt_order_number = $order_data['order_number'] . '-T' . time();

    $order_items = get_order_items_with_details($conn, $order_id);
    if (empty($order_items)) {
        throw new Exception("Detail produk untuk pesanan ini tidak ditemukan.");
    }
    
    $midtrans_items = [];
    foreach ($order_items as $item) {
        // ============================================================
        // INI ADALAH PERBAIKAN KRITIS UNTUK MIDTRANS
        // ============================================================
        $midtrans_items[] = [
            'id' => (string)$item['product_id'], // WAJIB (string)
            'price' => (int)$item['price'], 
            'quantity' => (int)$item['quantity'], 
            'name' => $item['name']
        ];
    }

    $transaction_params = [
        'transaction_details' => [
            'order_id' => $attempt_order_number, 
            'gross_amount' => (int)$order_data['total']
        ],
        'customer_details' => [
            'first_name' => $order_data['full_name'], 
            'email' => $order_data['email'], 
            'phone' => $order_data['phone_number']
        ],
        'item_details' => $midtrans_items
    ];

    $snapToken = \Midtrans\Snap::getSnapToken($transaction_params);

    $stmt_attempt = $conn->prepare("INSERT INTO payment_attempts (order_id, attempt_order_number, snap_token, status) VALUES (?, ?, ?, 'pending')");
    $stmt_attempt->bind_param("iss", $order_id, $attempt_order_number, $snapToken);
    $stmt_attempt->execute();
    $stmt_attempt->close();

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
    echo json_encode(['success' => false, 'message' => 'Gagal membuat sesi pembayaran: ' . $e->getMessage()]);
    exit;
}
?>