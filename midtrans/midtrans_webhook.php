<?php
// File: midtrans/midtrans_webhook.php
// VERSI FINAL: Siap produksi (log debug dihapus)

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1); // Biarkan error log standar PHP tetap nyala

// Log kustom (write_log) dan file log kustom (midtrans.log) telah dihapus.
// Hanya error fatal yang akan dicatat di log error default server Anda.

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../midtrans/config_midtrans.php'; // WAJIB ADA SERVER KEY
require_once __DIR__ . '/../sistem/sistem.php';

try {
    $raw_body = @file_get_contents('php://input');
    if (empty($raw_body)) {
        http_response_code(200);
        echo "OK (Empty)";
        exit;
    }

    $notif = json_decode($raw_body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || empty($notif['order_id'])) {
        http_response_code(400); 
        error_log("Invalid webhook: JSON parse failed or missing order_id. Body: " . $raw_body); // Log error standar
        echo "Invalid notification.";
        exit;
    }

    // ============================================================
    // [VALIDASI MANUAL]
    // ============================================================
    if (empty(\Midtrans\Config::$serverKey)) {
        error_log("CRITICAL: Midtrans Server Key is EMPTY in config_midtrans.php!"); // Log error standar
        http_response_code(500);
        die("Server Key not configured.");
    }
    
    $local_signature = hash("sha512", $notif['order_id'] . $notif['status_code'] . $notif['gross_amount'] . \Midtrans\Config::$serverKey);

    if ($notif['signature_key'] !== $local_signature) {
        http_response_code(403);
        error_log("CRITICAL: INVALID MIDTRANS SIGNATURE KEY! Check Server Key."); // Log error standar
        die("Forbidden: Invalid signature.");
    }
    // ============================================================

    $transaction_status = $notif['transaction_status'];
    $transaction_id = $notif['transaction_id'];
    $fraud_status = $notif['fraud_status'] ?? 'accept';
    $attempt_order_number = $notif['order_id'];
    
    // Ambil order_id dari payment_attempts
    $stmt_get_order = $conn->prepare("SELECT order_id FROM payment_attempts WHERE attempt_order_number = ?");
    $stmt_get_order->bind_param("s", $attempt_order_number);
    $stmt_get_order->execute();
    $order_data = $stmt_get_order->get_result()->fetch_assoc();
    $stmt_get_order->close();

    if (!$order_data) {
        http_response_code(404);
        error_log("Midtrans Webhook: Order not found for attempt: $attempt_order_number"); // Log error standar
        die("Order not found.");
    }

    $order_id = $order_data['order_id'];
    
    // Mulai Transaction Database
    $conn->begin_transaction();

    try {
        // Lock row
        $current_status_res = $conn->query("SELECT status, user_id FROM orders WHERE id=$order_id FOR UPDATE");
        $current_order = $current_status_res->fetch_assoc();
        $current_status = $current_order['status'];
        $user_id = $current_order['user_id'];

        // Hanya proses jika status masih 'waiting_payment'
        if ($current_status !== 'waiting_payment') {
            $conn->commit();
            http_response_code(200);
            echo "OK (Already Processed)";
            exit;
        }

        $new_status = null;
        $is_success = false;
        
        if ($transaction_status == 'settlement' || ($transaction_status == 'capture' && $fraud_status == 'accept')) {
            $new_status = 'belum_dicetak';
            $is_success = true;
        } else if (in_array($transaction_status, ['cancel', 'deny', 'expire'])) {
            $new_status = 'cancelled';
        } else {
            // Status: 'pending', 'challenge', dll tidak perlu di-handle,
            // biarkan order tetap 'waiting_payment'.
        }

        if ($new_status) {
            // Update status order
            $stmt = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
            $stmt->bind_param("ssi", $new_status, $transaction_id, $order_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update order status: " . $stmt->error);
            }
            $stmt->close();

            // PENCATATAN RIWAYAT PEMBELIAN (JIKA BERHASIL)
            if ($is_success) {
                
                $stmt_items = $conn->prepare("
                    SELECT oi.product_id, oi.quantity, p.stock_cycle_id, p.name as product_name
                    FROM order_items oi 
                    JOIN products p ON oi.product_id = p.id 
                    WHERE oi.order_id = ?
                ");
                $stmt_items->bind_param("i", $order_id);
                $stmt_items->execute();
                $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();

                if (!empty($order_items)) {
                    $stmt_record = $conn->prepare("
                        INSERT INTO user_purchase_records 
                        (user_id, product_id, stock_cycle_id, quantity_purchased, last_purchase_date) 
                        VALUES (?, ?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE 
                            quantity_purchased = quantity_purchased + VALUES(quantity_purchased),
                            last_purchase_date = NOW()
                    ");
                    
                    foreach ($order_items as $item) {
                        $stmt_record->bind_param(
                            "iiii", 
                            $user_id, 
                            $item['product_id'], 
                            $item['stock_cycle_id'], 
                            $item['quantity']
                        );
                        
                        if (!$stmt_record->execute()) {
                            error_log("Midtrans Webhook: Failed to record purchase for Product: {$item['product_id']} - " . $stmt_record->error);
                        }
                    }
                    $stmt_record->close();
                }

                create_notification($conn, $user_id, "Pembayaran berhasil! Pesanan Anda sedang diproses.");
            }
            
            // KEMBALIKAN STOK (JIKA DIBATALKAN)
            if ($new_status === 'cancelled') {
                
                $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmt_items->bind_param("i", $order_id);
                $stmt_items->execute();
                $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();
                
                $stmt_restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($order_items as $item) {
                    $stmt_restock->bind_param("ii", $item['quantity'], $item['product_id']);
                    if (!$stmt_restock->execute()) {
                        error_log("Midtrans Webhook: Failed to restock Product {$item['product_id']}: " . $stmt_restock->error);
                    }
                }
                $stmt_restock->close();

                create_notification($conn, $user_id, "Pembayaran dibatalkan atau kedaluwarsa. Stok telah dikembalikan.");
            }
        }

        $conn->commit();
        http_response_code(200);
        echo "OK";

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Midtrans Webhook FATAL: DB Transaction Error: " . $e->getMessage()); // Log error standar
        http_response_code(500);
        echo "ERROR: " . $e->getMessage();
    }

} catch (Exception $e) {
    error_log("Midtrans Webhook FATAL: Unhandled Error: " . $e->getMessage()); // Log error standar
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}
?>