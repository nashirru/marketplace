<?php
// File: checkout/check_payment_status.php
// File baru untuk mengecek status pembayaran dari Midtrans

header('Content-Type: application/json');

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['order_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$order_id = (int)$_POST['order_id'];
$user_id = $_SESSION['user_id'];

try {
    // Ambil order dari database
    $stmt = $conn->prepare("SELECT order_number FROM orders WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order_data) {
        throw new Exception("Order not found");
    }

    // Ambil attempt_order_number terakhir
    $stmt_attempt = $conn->prepare("SELECT attempt_order_number FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_attempt->bind_param("i", $order_id);
    $stmt_attempt->execute();
    $attempt_data = $stmt_attempt->get_result()->fetch_assoc();
    $stmt_attempt->close();

    if (!$attempt_data) {
        throw new Exception("Payment attempt not found");
    }

    $attempt_order_number = $attempt_data['attempt_order_number'];

    // Cek status dari Midtrans API
    $status = \Midtrans\Transaction::status($attempt_order_number);

    $transaction_status = $status->transaction_status;
    $fraud_status = isset($status->fraud_status) ? $status->fraud_status : 'accept';
    $expiry_time = isset($status->expiry_time) ? $status->expiry_time : null;

    // Update status di database berdasarkan response Midtrans
    $new_status = null;
    
    if ($transaction_status == 'capture') {
        if ($fraud_status == 'accept') {
            $new_status = 'belum_dicetak';
        }
    } else if ($transaction_status == 'settlement') {
        $new_status = 'belum_dicetak';
    } else if ($transaction_status == 'pending') {
        $new_status = 'waiting_payment';
    } else if (in_array($transaction_status, ['deny', 'expire', 'cancel'])) {
        $new_status = 'cancelled';
    }

    // Update database jika ada perubahan status
    if ($new_status && $new_status !== 'waiting_payment') {
        $stmt_update = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
        $transaction_id = $status->transaction_id ?? null;
        $stmt_update->bind_param("ssi", $new_status, $transaction_id, $order_id);
        $stmt_update->execute();
        $stmt_update->close();

        // Kirim notifikasi ke user
        if ($new_status === 'belum_dicetak') {
            create_notification($conn, $user_id, "Pembayaran untuk pesanan #{$order_data['order_number']} berhasil. Pesanan Anda sedang diproses.");
        } else if ($new_status === 'cancelled') {
            create_notification($conn, $user_id, "Pembayaran untuk pesanan #{$order_data['order_number']} dibatalkan atau kedaluwarsa.");
        }
    }

    // Format expiry time untuk ditampilkan
    $formatted_expiry = null;
    if ($expiry_time) {
        $expiry_dt = new DateTime($expiry_time);
        $formatted_expiry = $expiry_dt->format('d M Y H:i') . ' WIB';
    }

    echo json_encode([
        'success' => true,
        'status' => $transaction_status,
        'fraud_status' => $fraud_status,
        'expiry_time' => $formatted_expiry,
        'order_status' => $new_status ?? 'waiting_payment'
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}