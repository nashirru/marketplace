<?php
// File: midtrans/midtrans_webhook.php
// PERBAIKAN: Validasi manual untuk log error yang lebih jelas
// jika Server Key salah.

header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) { 
    mkdir($log_dir, 0755, true); 
}

$log_file = $log_dir . '/midtrans.log';
$error_log_file = $log_dir . '/midtrans_error.log';
ini_set('error_log', $error_log_file);

function write_log($message) {
    global $log_file;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[$timestamp] " . $message . "\n", FILE_APPEND);
}

write_log("========================================");
write_log("INFO: Webhook dipanggil pada " . date('Y-m-d H:i:s'));

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../midtrans/config_midtrans.php'; // WAJIB ADA SERVER KEY
require_once __DIR__ . '/../sistem/sistem.php';

try {
    $raw_body = @file_get_contents('php://input');
    if (empty($raw_body)) {
        http_response_code(200);
        write_log("INFO: Menerima webhook kosong. Diabaikan.");
        echo "OK (Empty)";
        exit;
    }

    $notif = json_decode($raw_body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || empty($notif['order_id'])) {
        http_response_code(400); 
        write_log("ERROR: Webhook tidak valid, JSON parse gagal, atau tidak mengandung order_id. Body: " . $raw_body);
        echo "Invalid notification.";
        exit;
    }

    write_log("INFO: Menerima notifikasi untuk Order ID: " . $notif['order_id']);

    // ============================================================
    // [VALIDASI MANUAL] Ini akan membuktikan Server Key Anda salah
    // ============================================================
    if (empty(\Midtrans\Config::$serverKey)) {
         write_log("CRITICAL: Server Key KOSONG di config_midtrans.php!");
         http_response_code(500);
         die("Server Key not configured.");
    }
    
    $local_signature = hash("sha512", $notif['order_id'] . $notif['status_code'] . $notif['gross_amount'] . \Midtrans\Config::$serverKey);

    if ($notif['signature_key'] !== $local_signature) {
        http_response_code(403);
        write_log("CRITICAL: INVALID SIGNATURE KEY! SERVER KEY ANDA 99% SALAH.");
        write_log("CRITICAL: Pastikan Anda menggunakan Server Key SANDBOX, bukan Client Key.");
        write_log("CRITICAL: Expected: $local_signature");
        write_log("CRITICAL: Got: " . $notif['signature_key']);
        write_log("CRITICAL: Server Key (partial): " . substr(\Midtrans\Config::$serverKey, 0, 8) . "...");
        die("Forbidden: Invalid signature.");
    }
    write_log("SUCCESS: Signature key valid");
    // ============================================================

    $transaction_status = $notif['transaction_status'];
    $transaction_id = $notif['transaction_id'];
    $fraud_status = $notif['fraud_status'] ?? 'accept';
    $attempt_order_number = $notif['order_id'];
    
    write_log("DATA: Attempt Order Number: $attempt_order_number");
    write_log("DATA: Transaction Status: $transaction_status");
    write_log("DATA: Transaction ID: $transaction_id");

    // Ambil order_id dari payment_attempts
    $stmt_get_order = $conn->prepare("SELECT order_id FROM payment_attempts WHERE attempt_order_number = ?");
    $stmt_get_order->bind_param("s", $attempt_order_number);
    $stmt_get_order->execute();
    $order_data = $stmt_get_order->get_result()->fetch_assoc();
    $stmt_get_order->close();

    if (!$order_data) {
        http_response_code(404);
        write_log("ERROR: Order not found for attempt: $attempt_order_number");
        die("Order not found.");
    }

    $order_id = $order_data['order_id'];
    write_log("INFO: Found Order ID: $order_id");
    
    // Mulai Transaction Database
    $conn->begin_transaction();
    write_log("INFO: Database transaction started");

    try {
        // Lock row
        $current_status_res = $conn->query("SELECT status, user_id FROM orders WHERE id=$order_id FOR UPDATE");
        $current_order = $current_status_res->fetch_assoc();
        $current_status = $current_order['status'];
        $user_id = $current_order['user_id'];

        write_log("INFO: Current order status: $current_status");
        write_log("INFO: User ID: $user_id");

        // Hanya proses jika status masih 'waiting_payment'
        if ($current_status !== 'waiting_payment') {
            $conn->commit();
            http_response_code(200);
            write_log("INFO: Order already processed. Current status: $current_status. Ignoring.");
            echo "OK (Already Processed)";
            exit;
        }

        $new_status = null;
        $is_success = false;
        
        if ($transaction_status == 'settlement' || ($transaction_status == 'capture' && $fraud_status == 'accept')) {
            $new_status = 'belum_dicetak';
            $is_success = true;
            write_log("ACTION: Payment SUCCESS - Setting status to 'belum_dicetak'");
        } else if (in_array($transaction_status, ['cancel', 'deny', 'expire'])) {
            $new_status = 'cancelled';
            write_log("ACTION: Payment FAILED/CANCELLED - Setting status to 'cancelled'");
        } else {
            write_log("WARNING: Unhandled transaction status: $transaction_status");
        }

        if ($new_status) {
            // Update status order
            $stmt = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
            $stmt->bind_param("ssi", $new_status, $transaction_id, $order_id);
            
            if ($stmt->execute()) {
                write_log("SUCCESS: Order status updated to '$new_status' for Order ID: $order_id");
            } else {
                throw new Exception("Failed to update order status: " . $stmt->error);
            }
            $stmt->close();

            // PENCATATAN RIWAYAT PEMBELIAN (JIKA BERHASIL)
            if ($is_success) {
                write_log("INFO: Processing purchase records...");
                
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

                write_log("INFO: Found " . count($order_items) . " items in order");

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
                        
                        if ($stmt_record->execute()) {
                            write_log("SUCCESS: Recorded purchase - User: $user_id, Product: {$item['product_id']} ({$item['product_name']}), Cycle: {$item['stock_cycle_id']}, Qty: {$item['quantity']}");
                        } else {
                            write_log("ERROR: Failed to record purchase for Product: {$item['product_id']} - " . $stmt_record->error);
                        }
                    }
                    $stmt_record->close();
                }

                create_notification($conn, $user_id, "Pembayaran berhasil! Pesanan Anda sedang diproses.");
                write_log("INFO: Success notification sent to user $user_id");
            }
            
            // KEMBALIKAN STOK (JIKA DIBATALKAN)
            if ($new_status === 'cancelled') {
                write_log("INFO: Restocking cancelled order items...");
                
                $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                $stmt_items->bind_param("i", $order_id);
                $stmt_items->execute();
                $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();
                
                $stmt_restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                foreach ($order_items as $item) {
                    $stmt_restock->bind_param("ii", $item['quantity'], $item['product_id']);
                    if ($stmt_restock->execute()) {
                        write_log("SUCCESS: Restocked Product {$item['product_id']}: +{$item['quantity']}");
                    } else {
                        write_log("ERROR: Failed to restock Product {$item['product_id']}: " . $stmt_restock->error);
                    }
                }
                $stmt_restock->close();

                create_notification($conn, $user_id, "Pembayaran dibatalkan atau kedaluwarsa. Stok telah dikembalikan.");
                write_log("INFO: Cancellation notification sent to user $user_id");
            }
        }

        $conn->commit();
        write_log("SUCCESS: Database transaction committed");
        http_response_code(200);
        echo "OK";

    } catch (Exception $e) {
        $conn->rollback();
        write_log("FATAL: DB Transaction Error: " . $e->getMessage());
        write_log("FATAL: Stack trace: " . $e->getTraceAsString());
        http_response_code(500);
        echo "ERROR: " . $e->getMessage();
    }

} catch (Exception $e) {
    write_log("FATAL: Unhandled Error: " . $e->getMessage());
    write_log("FATAL: Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo "ERROR: " . $e->getMessage();
}

write_log("========================================\n");
?>