<?php
// File: midtrans/midtrans_webhook.php
// Versi Final dengan Verifikasi Keamanan, Logging Detail, dan Notifikasi

// Tampilkan semua error untuk mempermudah debugging jika terjadi masalah
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once 'config_midtrans.php';

// -- Persiapan Logging --
$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) {
    // Coba buat direktori jika belum ada, penting untuk hosting baru
    mkdir($log_dir, 0755, true);
}
$log_file = $log_dir . '/midtrans.log';
$error_log_file = $log_dir . '/midtrans_error.log';

// Ambil notifikasi dalam bentuk JSON dari Midtrans
$json_result = file_get_contents('php://input');
$result = json_decode($json_result, true);

// Jika tidak ada data, hentikan proses
if (!$result) {
    http_response_code(400); // Bad Request
    file_put_contents($error_log_file, "WEBHOOK ERROR: Payload JSON tidak valid atau kosong diterima pada " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    exit();
}

// Catat semua notifikasi yang masuk untuk rekam jejak
file_put_contents($log_file, "--- NOTIFIKASI BARU DITERIMA ---\n" . json_encode($result, JSON_PRETTY_PRINT) . "\n\n", FILE_APPEND);

// -- Verifikasi Signature Key (Langkah Keamanan Wajib) --
// Ini untuk memastikan notifikasi benar-benar datang dari Midtrans, bukan dari pihak lain.
$order_id_from_payload = $result['order_id'] ?? '';
$status_code = $result['status_code'] ?? '';
$gross_amount = $result['gross_amount'] ?? '';
$signature_key_from_midtrans = $result['signature_key'] ?? '';
$server_key = \Midtrans\Config::$serverKey;

$my_signature_key = hash('sha512', $order_id_from_payload . $status_code . $gross_amount . $server_key);

if ($my_signature_key !== $signature_key_from_midtrans) {
    http_response_code(403); // Forbidden
    file_put_contents($error_log_file, "WEBHOOK SECURITY: Kunci Tanda Tangan (Signature Key) tidak valid untuk Order ID: {$order_id_from_payload}\n", FILE_APPEND);
    exit();
}

// -- Proses Notifikasi --
$attempt_order_number = $result['order_id'];
$transaction_status = $result['transaction_status'];
$fraud_status = $result['fraud_status'] ?? 'accept'; // Anggap aman jika fraud status tidak ada
$transaction_id = $result['transaction_id'];
$expiry_time = $result['expiry_time'] ?? null;

// Ekstrak nomor pesanan utama dari nomor percobaan pembayaran (cth: WK-123-1 -> WK-123)
$order_parts = explode('-', $attempt_order_number);
if (count($order_parts) < 4) { // Berdasarkan format 'WK-timestamp-userid-attempt'
    file_put_contents($error_log_file, "WEBHOOK PARSE ERROR: Format order_id tidak valid: $attempt_order_number\n", FILE_APPEND);
    http_response_code(400);
    exit();
}
$main_order_number = $order_parts[0] . '-' . $order_parts[1] . '-' . $order_parts[2];

$new_status = null;
$notification_message = '';

// Tentukan status baru di sistem Anda berdasarkan status dari Midtrans
if ($transaction_status == 'capture' || $transaction_status == 'settlement') {
    if ($fraud_status == 'accept') {
        $new_status = 'belum_dicetak';
        $notification_message = "Pembayaran untuk pesanan #{$main_order_number} telah berhasil.";
    }
} else if (in_array($transaction_status, ['cancel', 'deny', 'expire'])) {
    $new_status = 'cancelled';
    $notification_message = "Pembayaran untuk pesanan #{$main_order_number} dibatalkan atau kedaluwarsa.";
}

// Jika ada status baru yang perlu diupdate
if ($new_status) {
    // 1. Ambil data pesanan dari database terlebih dahulu
    $stmt_select = $conn->prepare("SELECT id, user_id, status FROM orders WHERE order_number = ? LIMIT 1");
    $stmt_select->bind_param("s", $main_order_number);
    $stmt_select->execute();
    $order_data = $stmt_select->get_result()->fetch_assoc();
    $stmt_select->close();

    if ($order_data) {
        // 2. Hanya update jika status saat ini adalah 'waiting_payment'
        if ($order_data['status'] === 'waiting_payment') {
            $stmt_update = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ?, expiry_time = ? WHERE id = ?");
            $stmt_update->bind_param("sssi", $new_status, $transaction_id, $expiry_time, $order_data['id']);
            
            if ($stmt_update->execute()) {
                file_put_contents($log_file, "DB UPDATE SUCCESS: Pesanan #{$main_order_number} diupdate menjadi '{$new_status}'.\n", FILE_APPEND);
                // Buat notifikasi untuk pengguna di dalam sistem (jika ada fiturnya)
                if (!empty($notification_message)) {
                    create_notification($conn, $order_data['user_id'], $notification_message);
                }
            } else {
                file_put_contents($error_log_file, "DB UPDATE FAILED: Gagal mengupdate pesanan #{$main_order_number}. Error: " . $stmt_update->error . "\n", FILE_APPEND);
            }
            $stmt_update->close();
        } else {
            file_put_contents($log_file, "DB UPDATE SKIPPED: Notifikasi untuk pesanan #{$main_order_number} diterima, tapi status sudah '{$order_data['status']}'. Tidak ada tindakan.\n", FILE_APPEND);
        }
    } else {
        file_put_contents($error_log_file, "DB LOOKUP FAILED: Pesanan #{$main_order_number} tidak ditemukan di database.\n", FILE_APPEND);
    }
}

// Beri tahu Midtrans bahwa notifikasi sudah diterima dengan sukses
http_response_code(200);
echo "Notification processed successfully.";