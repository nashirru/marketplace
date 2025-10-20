<?php
// File: midtrans/midtrans_webhook.php
// Contoh kode PHP untuk menerima dan memverifikasi notifikasi dari Midtrans (HTTP Notification).

require_once '../config/config.php';
require_once 'config_midtrans.php';

// Inisialisasi handler notifikasi dari SDK Midtrans
try {
    $notif = new \Midtrans\Notification();
} catch (Exception $e) {
    http_response_code(500);
    echo "Error creating notification handler: " . $e->getMessage();
    exit();
}

// Ambil order_id (yang kita set sebagai order_number)
$order_number = $notif->order_id;
$transaction_status = $notif->transaction_status;
$fraud_status = $notif->fraud_status;
$transaction_id = $notif->transaction_id;

// Log untuk debugging (opsional, tapi sangat membantu)
// Pastikan folder 'logs' ada dan bisa ditulis (writable)
$log_message = "Webhook diterima: Order Number: $order_number, Status: $transaction_status, Fraud: $fraud_status, ID: $transaction_id\n";
file_put_contents(__DIR__ . '/../logs/midtrans.log', $log_message, FILE_APPEND);


// Verifikasi signature key (SANGAT PENTING untuk keamanan)
// Signature key akan dihitung oleh SDK dan dicocokkan dengan header notifikasi
// Jika tidak valid, SDK akan throw Exception dan script berhenti di sini.
// Proses verifikasi sudah otomatis terjadi saat membuat instance `new \Midtrans\Notification()`.
// Jadi, jika script lanjut, berarti notifikasi valid.


// Siapkan statement untuk update database
$stmt = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE order_number = ? AND status = 'waiting_payment'");
if (!$stmt) {
    http_response_code(500);
    file_put_contents(__DIR__ . '/../logs/midtrans_error.log', "Gagal prepare statement: " . $conn->error . "\n", FILE_APPEND);
    exit();
}


// Mapping Status Midtrans ke Status di Sistem Anda
$new_status = '';

if ($transaction_status == 'capture') {
    // Untuk transaksi kartu kredit
    if ($fraud_status == 'accept') {
        // Transaksi aman, pembayaran berhasil
        $new_status = 'belum_dicetak';
    }
} else if ($transaction_status == 'settlement') {
    // Untuk metode pembayaran lain (Gopay, Virtual Account, dll)
    // Pembayaran dianggap berhasil
    $new_status = 'belum_dicetak';
} else if (in_array($transaction_status, ['cancel', 'deny', 'expire'])) {
    // Pembayaran dibatalkan, ditolak, atau kedaluwarsa
    $new_status = 'cancelled';
}
// Untuk status 'pending', kita tidak melakukan apa-apa, biarkan status tetap 'waiting_payment'.


// Jika ada status baru yang perlu di-update
if (!empty($new_status)) {
    $stmt->bind_param("sss", $new_status, $transaction_id, $order_number);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Update berhasil
             file_put_contents(__DIR__ . '/../logs/midtrans.log', "SUCCESS: Order $order_number diupdate menjadi $new_status\n", FILE_APPEND);
        } else {
            // Tidak ada baris yang ter-update. Mungkin statusnya bukan 'waiting_payment' lagi atau order_number tidak ditemukan.
            file_put_contents(__DIR__ . '/../logs/midtrans.log', "INFO: Tidak ada baris terupdate untuk Order $order_number. Status saat ini mungkin bukan 'waiting_payment'.\n", FILE_APPEND);
        }
    } else {
        // Gagal eksekusi query
        http_response_code(500);
        file_put_contents(__DIR__ . '/../logs/midtrans_error.log', "Gagal eksekusi update untuk Order $order_number: " . $stmt->error . "\n", FILE_APPEND);
    }
    
    $stmt->close();
}

$conn->close();

// Beri respons 200 OK ke Midtrans untuk menandakan notifikasi sudah diterima
http_response_code(200);
echo "OK";