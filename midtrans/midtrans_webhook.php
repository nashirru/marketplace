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
        http_response_code(200);
        error_log("Midtrans Webhook: Order not found for attempt: $attempt_order_number");
        echo "OK (Order Not Found)";
        exit;
    }

    $order_id = $order_data['order_id'];
    
    // Mulai Transaction Database
    $conn->begin_transaction();

    try {
        // Lock row
        $stmt_lock_order = $conn->prepare("SELECT status, user_id FROM orders WHERE id = ? FOR UPDATE");
        $stmt_lock_order->bind_param("i", $order_id);
        $stmt_lock_order->execute();
        $current_order = $stmt_lock_order->get_result()->fetch_assoc();
        $stmt_lock_order->close();
        if (!$current_order) {
            throw new Exception("Order data not found during lock.");
        }
        $current_status = $current_order['status'];
        $user_id = $current_order['user_id'];

        // Proses webhook jika status masih menunggu bayar ATAU sudah terlanjur cancelled (race condition).
        // Jika cancelled lalu ternyata settlement/capture, kita pulihkan ke belum_dicetak.
        $paid_statuses = ['belum_dicetak', 'processed', 'shipped', 'completed'];
        if (in_array($current_status, $paid_statuses, true)) {
            $conn->commit();
            http_response_code(200);
            echo "OK (Already Paid)";
            exit;
        }

        $processable_statuses = ['waiting_payment', 'cancelled'];
        if (!in_array($current_status, $processable_statuses, true)) {
            $conn->commit();
            http_response_code(200);
            echo "OK (Already Processed)";
            exit;
        }

        $was_cancelled = ($current_status === 'cancelled');
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
            // Jika sudah cancelled sebelumnya, notifikasi cancel/expire berikutnya jangan restock dua kali.
            if ($new_status === 'cancelled' && $was_cancelled) {
                $conn->commit();
                http_response_code(200);
                echo "OK (Already Cancelled)";
                exit;
            }
            // Update status order
            // Update status order
            if ($new_status === 'belum_dicetak') {
                $stmt = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ?, cancel_reason = NULL WHERE id = ?");
            } else {
                $stmt = $conn->prepare("UPDATE orders SET status = ?, midtrans_transaction_id = ? WHERE id = ?");
            }
            $stmt->bind_param("ssi", $new_status, $transaction_id, $order_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update order status: " . $stmt->error);
            }
            $stmt->close();

            // PENCATATAN RIWAYAT PEMBELIAN (JIKA BERHASIL)
            if ($is_success) {
                // [RACE CONDITION FIX] Jika order sempat cancelled lalu tiba-tiba paid, stok biasanya sudah direstock.
                // Kita kurangi lagi (clamp ke 0) supaya stok tidak membesar.
                // RE-DEDUCT STOCK
                if ($was_cancelled) {
                    $stmt_stock_items = $conn->prepare("SELECT product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
                    $stmt_stock_items->bind_param("i", $order_id);
                    $stmt_stock_items->execute();
                    $stock_items = $stmt_stock_items->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt_stock_items->close();

                    if (!empty($stock_items)) {
                        $stmt_prod_dec = $conn->prepare("UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?");
                        $stmt_var_dec  = $conn->prepare("UPDATE product_variations SET stock = GREATEST(stock - ?, 0) WHERE id = ?");
                        foreach ($stock_items as $it) {
                            $qty = (int)($it['quantity'] ?? 0);
                            $pid = (int)($it['product_id'] ?? 0);
                            $vid = (int)($it['variation_id'] ?? 0);
                            if ($qty <= 0 || $pid <= 0) continue;
                            $stmt_prod_dec->bind_param("ii", $qty, $pid);
                            $stmt_prod_dec->execute();
                            if ($vid > 0) {
                                $stmt_var_dec->bind_param("ii", $qty, $vid);
                                $stmt_var_dec->execute();
                            }
                        }
                        $stmt_prod_dec->close();
                        $stmt_var_dec->close();
                    }
                }

                
                                $has_order_items_stock_cycle = false;
                $check_col = $conn->query("SHOW COLUMNS FROM order_items LIKE 'stock_cycle_id'");
                if ($check_col && $check_col->num_rows > 0) {
                    $has_order_items_stock_cycle = true;
                }

                if ($has_order_items_stock_cycle) {
                    $stmt_items = $conn->prepare("
                        SELECT oi.product_id, oi.quantity, oi.stock_cycle_id, p.name as product_name
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                } else {
                    $stmt_items = $conn->prepare("
                        SELECT oi.product_id, oi.quantity, p.stock_cycle_id, p.name as product_name
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        WHERE oi.order_id = ?
                    ");
                }
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
                
                $stmt_items = $conn->prepare("SELECT product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
                $stmt_items->bind_param("i", $order_id);
                $stmt_items->execute();
                $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt_items->close();
                
                $stmt_restock_product = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt_restock_variation = $conn->prepare("UPDATE product_variations SET stock = stock + ? WHERE id = ?");
                foreach ($order_items as $item) {
                    $variation_id = isset($item['variation_id']) ? (int)$item['variation_id'] : 0;
                    if ($variation_id > 0) {
                        $stmt_restock_variation->bind_param("ii", $item['quantity'], $variation_id);
                        if (!$stmt_restock_variation->execute()) {
                            error_log("Midtrans Webhook: Failed to restock Variation {$variation_id}: " . $stmt_restock_variation->error);
                        }
                    } else {
                        $stmt_restock_product->bind_param("ii", $item['quantity'], $item['product_id']);
                        if (!$stmt_restock_product->execute()) {
                            error_log("Midtrans Webhook: Failed to restock Product {$item['product_id']}: " . $stmt_restock_product->error);
                        }
                    }
                }
                $stmt_restock_product->close();
                $stmt_restock_variation->close();

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


