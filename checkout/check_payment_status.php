<?php
// File: checkout/check_payment_status.php
// PERBAIKAN: Menangani 404 sebagai "pending" untuk mengatasi race condition

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// ============================================================
// [LOG BARU] Buat file log khusus untuk poller
// ============================================================
$poller_log_file = __DIR__ . '/../logs/poller.log';
ini_set('log_errors', 1);
ini_set('error_log', $poller_log_file);

function poller_log($message) {
    global $poller_log_file;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($poller_log_file, "[$timestamp] $message\n", FILE_APPEND);
}
// ============================================================

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../midtrans/config_midtrans.php'; 

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Cek jika log pertama kali, tambahkan header
if (filesize($poller_log_file) < 100) {
    poller_log("========== POLLER LOG START ==========");
}

poller_log("--- POLLER RUN ---");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    poller_log("ABORT: User not logged in (Unauthorized)");
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['order_id'])) {
    http_response_code(400);
    poller_log("ABORT: Invalid request (Not POST or missing order_id)");
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$order_id = (int)$_POST['order_id'];
$user_id = $_SESSION['user_id'];
$current_db_status = 'error';

poller_log("INFO: Checking Order ID: $order_id for User ID: $user_id");

try {
    // 1. Cek status di database LOKAL dulu
    poller_log("INFO: [Step 1] Checking local DB status...");
    $stmt_check_local = $conn->prepare("SELECT order_number, status FROM orders WHERE id = ? AND user_id = ?");
    $stmt_check_local->bind_param("ii", $order_id, $user_id);
    $stmt_check_local->execute();
    $order_data = $stmt_check_local->get_result()->fetch_assoc();
    $stmt_check_local->close();

    if (!$order_data) {
        poller_log("ERROR: Order ID $order_id not found for User ID $user_id");
        throw new Exception("Order not found");
    }

    $current_db_status = $order_data['status'];
    poller_log("INFO: [Step 1] Local DB status: $current_db_status");
    
    // 2. Jika status di DB LOKAL sudah BUKAN waiting_payment
    if ($current_db_status !== 'waiting_payment') {
        poller_log("INFO: [Step 2] Status is final ($current_db_status). Returning success.");
        echo json_encode([
            'success' => true,
            'order_status' => $current_db_status 
        ]);
        poller_log("--- POLLER SUCCESS (Local) ---");
        exit;
    }

    // 3. Jika status di DB LOKAL MASIH waiting_payment -> TANYA KE MIDTRANS
    poller_log("INFO: [Step 3] Status still waiting_payment. Querying Midtrans API as fallback.");
    
    $stmt_attempt = $conn->prepare("SELECT attempt_order_number FROM payment_attempts WHERE order_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt_attempt->bind_param("i", $order_id);
    $stmt_attempt->execute();
    $attempt_data = $stmt_attempt->get_result()->fetch_assoc();
    $stmt_attempt->close();

    if (!$attempt_data || empty($attempt_data['attempt_order_number'])) {
        poller_log("WARNING: [Step 3] No payment attempt found. Returning waiting_payment.");
        echo json_encode(['success' => true, 'order_status' => 'waiting_payment']);
        poller_log("--- POLLER SUCCESS (No Attempt) ---");
        exit;
    }

    $attempt_order_number = $attempt_data['attempt_order_number'];
    poller_log("INFO: [Step 3] Found attempt_order_number: $attempt_order_number");
    
    $status_midtrans = null;
    $transaction_status = 'pending'; // Default ke pending jika ada error API
    $fraud_status = 'accept';

    try {
        poller_log("INFO: [Step 3] Calling Midtrans Transaction::status API...");
        $status_midtrans = \Midtrans\Transaction::status($attempt_order_number);
        $transaction_status = $status_midtrans->transaction_status;
        $fraud_status = isset($status_midtrans->fraud_status) ? $status_midtrans->fraud_status : 'accept';

    } catch (Exception $midtrans_api_error) {
        poller_log("ERROR: [Step 3] Midtrans API call failed: " . $midtrans_api_error->getMessage());
        
        // ============================================================
        // [PERBAIKAN BARU] Tangani 404 atau error API lainnya
        // ============================================================
        // JANGAN ubah $transaction_status. Biarkan 'pending' (default di atas).
        // Kembalikan status DB saat ini ('waiting_payment') agar poller terus berjalan.
        // Ini akan memberi waktu pada DANA/Midtrans untuk replikasi data.
        poller_log("INFO: [Step 3] Treating as 'pending' due to API error. Will retry.");
        echo json_encode(['success' => true, 'order_status' => $current_db_status]); // Kembalikan status DB saat ini
        poller_log("--- POLLER RETRY (API Error) ---");
        exit;
        // ============================================================
    }

    poller_log("INFO: [Step 3] Midtrans API response: transaction_status=$transaction_status, fraud_status=$fraud_status");

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
    } else if ($transaction_status == 'pending') {
        $new_status = 'waiting_payment';
    }
    
    poller_log("INFO: [Step 4] New status based on API: $new_status");

    // 5. Update database jika ada perubahan status
    if ($new_status !== 'waiting_payment' && $current_db_status === 'waiting_payment') {
        poller_log("INFO: [Step 5] API status ($new_status) differs from DB ($current_db_status). Forcing DB update.");
        $conn->begin_transaction();
        poller_log("INFO: [Step 5] DB Transaction started.");
        try {
            // Gunakan FOR UPDATE untuk mencegah tabrakan dengan webhook
            poller_log("INFO: [Step 5] Locking order row (FOR UPDATE)...");
            $stmt_check_lock = $conn->prepare("SELECT status FROM orders WHERE id = ? FOR UPDATE");
            $stmt_check_lock->bind_param("i", $order_id);
            $stmt_check_lock->execute();
            $status_before_lock = $stmt_check_lock->get_result()->fetch_assoc()['status'];
            $stmt_check_lock->close();
            
            poller_log("INFO: [Step 5] Status (after lock): $status_before_lock");

            // Cek sekali lagi SETELAH lock, mungkin webhook sudah masuk
            if ($status_before_lock === 'waiting_payment') {
                poller_log("INFO: [Step 5] Webhook has not run. Poller is updating status to $new_status...");
                
                $stmt_update = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
                $transaction_id = $status_midtrans->transaction_id ?? null;
                $stmt_update->bind_param("ssi", $new_status, $transaction_id, $order_id);
                $stmt_update->execute();
                $stmt_update->close();

                poller_log("INFO: [Step 5] Order status updated.");

                if ($is_success) {
                    poller_log("INFO: [Step 5] Payment success. Recording purchase history...");
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
                    poller_log("INFO: [Step 5] Purchase history recorded.");

                } else if ($new_status === 'cancelled') {
                    poller_log("INFO: [Step 5] Payment failed/cancelled. Restocking items...");
                    // Kembalikan stok
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
                    poller_log("INFO: [Step 5] Items restocked.");
                }
            } else {
                poller_log("INFO: [Step 5] Webhook already updated the status to $status_before_lock. Poller skipping update.");
            }
            
            $conn->commit();
            poller_log("INFO: [Step 5] DB Transaction committed.");
        } catch (Exception $e) {
            $conn->rollback();
            poller_log("ERROR: [Step 5] DB Transaction failed: " . $e->getMessage());
            error_log("Polling update conflict: " . $e->getMessage());
        }
    }

    // 6. Kembalikan status BARU
    poller_log("INFO: [Step 6] Returning final status to client: $new_status");
    echo json_encode([
        'success' => true,
        'order_status' => $new_status 
    ]);
    poller_log("--- POLLER SUCCESS (API Check) ---");

} catch (Exception $e) {
    http_response_code(400);
    poller_log("FATAL: Unhandled exception: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'order_status' => $current_db_status // Kirim status DB saat ini jika error
    ]);
    poller_log("--- POLLER FAILED (Exception) ---");
}
?>