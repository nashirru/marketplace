<?php
// File: checkout/check_payment_status.php
// PERBAIKAN FINAL: Mengembalikan logika Fallback ke API Midtrans.
// Ini adalah satu-satunya cara untuk mengalahkan "Stale Read" dari database.

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php'; // Diperlukan untuk \Midtrans\Transaction

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
$current_db_status = 'error';

try {
    // 1. Cek status di database LOKAL dulu (tanpa lock, baca cepat)
    $stmt_check_local = $conn->prepare("SELECT order_number, status FROM orders WHERE id = ? AND user_id = ?");
    $stmt_check_local->bind_param("ii", $order_id, $user_id);
    $stmt_check_local->execute();
    $order_data = $stmt_check_local->get_result()->fetch_assoc();
    $stmt_check_local->close();

    if (!$order_data) {
        throw new Exception("Order not found");
    }

    $current_db_status = $order_data['status'];
    
    // 2. Jika status di DB LOKAL sudah BUKAN waiting_payment (misal webhook sudah jalan)
    if ($current_db_status !== 'waiting_payment') {
        echo json_encode([
            'success' => true,
            'order_status' => $current_db_status // Kirim status DB terbaru (misal: 'belum_dicetak')
        ]);
        exit;
    }

    // 3. Jika status di DB LOKAL MASIH waiting_payment (KEMUNGKINAN STALE READ)
    // TANYA KE MIDTRANS SEBAGAI FALLBACK
    
    // Ambil attempt_order_number terakhir (cth: WK2510230015-T1761197337)
    $stmt_attempt = $conn->prepare("SELECT attempt_order_number FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_attempt->bind_param("i", $order_id);
    $stmt_attempt->execute();
    $attempt_data = $stmt_attempt->get_result()->fetch_assoc();
    $stmt_attempt->close();

    if (!$attempt_data || empty($attempt_data['attempt_order_number'])) {
        // Belum ada percobaan bayar, status tetap waiting
        echo json_encode(['success' => true, 'order_status' => 'waiting_payment']);
        exit;
    }

    $attempt_order_number = $attempt_data['attempt_order_number'];

    // Cek status dari Midtrans API (Sumber Kebenaran)
    $status_midtrans = \Midtrans\Transaction::status($attempt_order_number);

    $transaction_status = $status_midtrans->transaction_status;
    $fraud_status = isset($status_midtrans->fraud_status) ? $status_midtrans->fraud_status : 'accept';

    // 4. Tentukan status baru berdasarkan response Midtrans
    $new_status = 'waiting_payment'; // default
    $is_success = false;
    
    if ($transaction_status == 'capture' && $fraud_status == 'accept') {
        $new_status = 'belum_dicetak';
        $is_success = true;
    } else if ($transaction_status == 'settlement') {
        $new_status = 'belum_dicetak';
        $is_success = true;
    } else if (in_array($transaction_status, ['deny', 'expire', 'cancel'])) {
        $new_status = 'cancelled';
    }

    // 5. Update database jika ada perubahan status
    // Ini adalah bagian PENTING untuk "memaksa" update jika webhook telat
    if ($new_status !== 'waiting_payment' && $current_db_status === 'waiting_payment') {
        $conn->begin_transaction();
        try {
            // Gunakan FOR UPDATE untuk mencegah tabrakan dengan webhook
            $stmt_check_lock = $conn->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
            $stmt_check_lock->bind_param("i", $order_id);
            $stmt_check_lock->execute();
            $status_before_lock = $stmt_check_lock->get_result()->fetch_assoc()['status'];
            $stmt_check_lock->close();
            
            // Cek sekali lagi SETELAH lock, mungkin webhook sudah masuk
            if ($status_before_lock === 'waiting_payment') {
                $stmt_update = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
                $transaction_id = $status_midtrans->transaction_id ?? null;
                $stmt_update->bind_param("ssi", $new_status, $transaction_id, $order_id);
                $stmt_update->execute();
                $stmt_update->close();

                // Panggil fungsi notifikasi DAN pencatatan riwayat (mirroring webhook)
                if ($is_success) {
                    // Catat riwayat pembelian
                    $stmt_items = $conn->prepare("SELECT oi.product_id, oi.quantity, p.stock_cycle_id FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
                    $stmt_items->bind_param("i", $order_id);
                    $stmt_items->execute();
                    $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt_items->close();

                    $stmt_record = $conn->prepare("INSERT INTO user_purchase_records (user_id, product_id, stock_cycle_id, quantity_purchased, last_purchase_date) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE quantity_purchased = quantity_purchased + VALUES(quantity_purchased), last_purchase_date = NOW()");
                    foreach ($order_items as $item) {
                        $stmt_record->bind_param("iiii", $user_id, $item['product_id'], $item['stock_cycle_id'], $item['quantity']);
                        $stmt_record->execute();
                    }
                    $stmt_record->close();
                    create_notification($conn, $user_id, "Pembayaran untuk pesanan #{$order_data['order_number']} berhasil. Pesanan Anda sedang diproses.");

                } else if ($new_status === 'cancelled') {
                    // Kembalikan stok (logika dari file asli Anda)
                    $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                    $stmt_items->bind_param("i", $order_id);
                    $stmt_items->execute();
                    $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt_items->close();
                    
                    $stmt_restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    foreach ($order_items as $item) {
                        $stmt_restock->bind_param("ii", $item['quantity'], $item['product_id']);
                        $stmt_restock->execute();
                    }
                    $stmt_restock->close();
                    create_notification($conn, $user_id, "Pembayaran untuk pesanan #{$order_data['order_number']} dibatalkan atau kedaluwarsa.");
                }
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            // abaikan error, biarkan webhook yang menangani nanti
            error_log("Polling update conflict: " . $e->getMessage());
        }
    }

    // 6. Kembalikan status BARU
    echo json_encode([
        'success' => true,
        'order_status' => $new_status
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'order_status' => $current_db_status // Kirim status DB saat ini jika error
    ]);
}
?>