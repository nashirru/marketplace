<?php
// File: sistem/cron_cancle_orders.php
// Skrip ini HANYA untuk dipanggil oleh Cron Job di server Anda.
// Ia akan membatalkan pesanan 'waiting_payment' yang lebih tua dari 2 JAM.

// Atur zona waktu agar sesuai dengan database Anda (PENTING!)
date_default_timezone_set('Asia/Jakarta');

// Rahasiakan file ini dari eksekusi browser langsung
// Hanya izinkan eksekusi via Command Line (CLI) atau dengan Kunci Rahasia
$CRON_SECRET_KEY = "WarokKitePalingMantap7788"; // Pastikan ini sama dengan di hPanel

if (php_sapi_name() !== 'cli' && (empty($_GET['key']) || $_GET['key'] !== $CRON_SECRET_KEY)) {
    http_response_code(403);
    die("Akses dilarang. Skrip ini hanya untuk cron job.");
}

// Set header ke text agar output log-nya rapi
header('Content-Type: text/plain');

// Kita butuh koneksi DB dari config dan fungsi notifikasi dari sistem
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/sistem.php'; // Memuat create_notification()

echo "===== Mulai Cron Job Pembatalan Pesanan (Interval 2 Jam) =====\n";
echo "Waktu Eksekusi: " . date('Y-m-d H:i:s') . "\n\n";

// ==================================================================
// PERUBAHAN DI SINI: Dari 'INTERVAL 1 DAY' menjadi 'INTERVAL 2 HOUR'
// ==================================================================
$stmt_find = $conn->prepare("
    SELECT id, user_id, order_number 
    FROM orders 
    WHERE status = 'waiting_payment' 
    AND created_at < NOW() - INTERVAL 2 HOUR
");

if (!$stmt_find) {
    die("Gagal mempersiapkan statement pencarian: " . $conn->error . "\n");
}

$stmt_find->execute();
$result = $stmt_find->get_result();
$orders_to_cancel = $result->fetch_all(MYSQLI_ASSOC);
$stmt_find->close();

if (empty($orders_to_cancel)) {
    echo "Tidak ada pesanan yang kedaluwarsa (2 jam) untuk dibatalkan.\n";
    echo "===== Selesai =====\n";
    exit;
}

echo "Ditemukan " . count($orders_to_cancel) . " pesanan untuk dibatalkan...\n\n";

// 2. Siapkan statement (query) yang akan dipakai berulang kali
$stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
$stmt_restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");

// Menambahkan 'cancel_reason' pada query update
$stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
$cancel_reason_cron = "Dibatalkan otomatis oleh sistem (melebihi batas waktu 2 jam)";

$cancelled_count = 0;
$failed_count = 0;

// 3. Loop setiap pesanan dan proses satu per satu
foreach ($orders_to_cancel as $order) {
    $order_id = $order['id'];
    $user_id = $order['user_id'];
    $order_number = $order['order_number'];

    // Kita pakai transaction per order, jadi jika 1 order gagal, yg lain tetap jalan
    $conn->begin_transaction();
    try {
        // a. Ambil semua item di pesanan itu
        $stmt_items->bind_param("i", $order_id);
        $stmt_items->execute();
        $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
        
        // b. Kembalikan stok untuk setiap item
        foreach ($items as $item) {
            $stmt_restock->bind_param("ii", $item['quantity'], $item['product_id']);
            $stmt_restock->execute();
        }
        
        // c. Ubah status pesanan menjadi 'cancelled'
        $stmt_cancel->bind_param("si", $cancel_reason_cron, $order_id);
        $stmt_cancel->execute();
        
        // d. Kirim notifikasi ke user (pakai fungsi dari sistem.php)
        // ==================================================================
        // PERUBAHAN DI SINI: Ganti pesan notifikasi ke "2 jam"
        // ==================================================================
        $message = "Pesanan Anda #{$order_number} telah otomatis dibatalkan karena melewati batas waktu pembayaran 2 jam.";
        create_notification($conn, $user_id, $message);
        
        // Jika semua berhasil, commit
        $conn->commit();
        echo "BERHASIL: Pesanan #{$order_number} (ID: $order_id) dibatalkan, stok dikembalikan, notifikasi dikirim.\n";
        $cancelled_count++;
        
    } catch (Exception $e) {
        // Jika ada error di tengah jalan, batalkan semua perubahan untuk order ini
        $conn->rollback();
        echo "GAGAL: Pesanan ID: $order_id. Error: " . $e->getMessage() . "\n";
        // Catat error ini di log server Anda
        error_log("Cron Job Gagal (Order ID: $order_id): " . $e->getMessage());
        $failed_count++;
    }
}

// 4. Tutup statement yang sudah disiapkan
$stmt_items->close();
$stmt_restock->close();
$stmt_cancel->close();
$conn->close();

echo "\n===== Selesai =====\n";
echo "Total Berhasil Dibatalkan: $cancelled_count\n";
echo "Total Gagal: $failed_count\n";
?>