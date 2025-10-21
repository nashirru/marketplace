<?php
// File: midtrans/midtrans_webhook.php
// Versi Production-Ready dengan Verifikasi, Logging Detail, dan Update Aman

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once 'config_midtrans.php';

// Buat direktori logs jika belum ada
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/midtrans.log';
$error_log_file = $log_dir . '/midtrans_error.log';

// Ambil input JSON dari body request
$json_result = file_get_contents('php://input');
$result = json_decode($json_result, true);

// Jika tidak ada input, jangan lakukan apa-apa
if (!$result) {
    http_response_code(400); // Bad Request
    file_put_contents($error_log_file, "WEBHOOK ERROR: Invalid or empty payload received.\n", FILE_APPEND);
    exit();
}

// Log raw payload untuk debugging
file_put_contents($log_file, "--- NEW NOTIFICATION ---\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// Verifikasi Signature Key untuk keamanan
$order_id_from_payload = $result['order_id'];
$status_code = $result['status_code'];
$gross_amount = $result['gross_amount'];
$server_key = \Midtrans\Config::$serverKey;
$my_signature_key = hash('sha512', $order_id_from_payload . $status_code . $gross_amount . $server_key);

if ($my_signature_key !== $result['signature_key']) {
    http_response_code(403); // Forbidden
    file_put_contents($error_log_file, "WEBHOOK SECURITY: Invalid signature key for Order ID: {$order_id_from_payload}\n", FILE_APPEND);
    exit();
}

// Lanjutkan proses jika signature valid
$attempt_order_number = $result['order_id'];
$transaction_status = $result['transaction_status'];
$fraud_status = $result['fraud_status'];
$transaction_id = $result['transaction_id'];
$expiry_time = $result['expiry_time'] ?? null;

// -- Parsing dan Validasi Order Number --
$order_parts = explode('-', $attempt_order_number);
if (count($order_parts) < 4) { // Format: WK-timestamp-userid-attempt
    file_put_contents($error_log_file, "WEBHOOK PARSE ERROR: Invalid order_id format: $attempt_order_number\n", FILE_APPEND);
    http_response_code(400);
    exit();
}
$main_order_number = $order_parts[0] . '-' . $order_parts[1] . '-' . $order_parts[2];

// -- Tentukan Status Baru --
$new_status = null;
$notification_message = '';
if ($transaction_status == 'capture' || $transaction_status == 'settlement') {
    if ($fraud_status == 'accept') {
        $new_status = 'belum_dicetak';
        $notification_message = "Pembayaran untuk pesanan #{$main_order_number} telah berhasil.";
    }
} else if (in_array($transaction_status, ['cancel', 'deny', 'expire'])) {
    $new_status = 'cancelled';
     $notification_message = "Pembayaran untuk pesanan #{$main_order_number} dibatalkan atau kedaluwarsa.";
}

// -- Proses Update Database dengan Aman --
if ($new_status) {
    // 1. Ambil data order dari DB
    $stmt_select = $conn->prepare("SELECT id, user_id, status FROM orders WHERE order_number = ? LIMIT 1");
    $stmt_select->bind_param("s", $main_order_number);
    $stmt_select->execute();
    $order_data = $stmt_select->get_result()->fetch_assoc();
    $stmt_select->close();

    if ($order_data) {
        // 2. Hanya update jika statusnya masih 'waiting_payment'
        if ($order_data['status'] === 'waiting_payment') {
            $stmt_update = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
            $stmt_update->bind_param("ssi", $new_status, $transaction_id, $order_data['id']);
            if ($stmt_update->execute()) {
                file_put_contents($log_file, "DB UPDATE SUCCESS: Order #{$main_order_number} updated to '{$new_status}'.\n", FILE_APPEND);
                // Kirim notifikasi ke user
                if (!empty($notification_message)) {
                    create_notification($conn, $order_data['user_id'], $notification_message);
                }
            } else {
                file_put_contents($error_log_file, "DB UPDATE FAILED: Could not update order #{$main_order_number}. Error: " . $stmt_update->error . "\n", FILE_APPEND);
            }
            $stmt_update->close();
        } else {
            // Jika statusnya bukan 'waiting_payment', berarti sudah pernah diproses.
            file_put_contents($log_file, "DB UPDATE SKIPPED: Notification for order #{$main_order_number} received, but status is already '{$order_data['status']}'. No action needed.\n", FILE_APPEND);
        }
    } else {
        file_put_contents($error_log_file, "DB LOOKUP FAILED: Order #{$main_order_number} not found in database.\n", FILE_APPEND);
    }
}

// Kirim respons OK ke Midtrans
http_response_code(200);
echo "Notification processed.";