<?php
// File: admin/pesanan/sistem_keamanan_midtrans.php
// VERSI IQ 180 FIX: Support Restock Variasi
//
// Modul ini berisi semua logika baru untuk:
// 1. Mencatat upaya pembatalan (Logging)
// 2. Menghubungi API Midtrans untuk status real-time
// 3. Menjalankan "Safe Cancel" (Orkestrasi)
// 4. Menjalankan logika restock (DENGAN VARIASI) & notifikasi

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mencatat setiap upaya pembatalan ke database log.
 */
function log_cancel_attempt($conn, $order_id, $midtrans_order_id, $midtrans_status, $decision, $message) {
    // Ambil ID admin dari sesi, jika ada.
    $admin_id_to_bind = $_SESSION['admin_id'] ?? null; 

    try {
        $stmt = $conn->prepare(
            "INSERT INTO admin_cancel_logs (order_id, admin_id, midtrans_order_id, midtrans_status, system_decision, message) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->bind_param("iissss", $order_id, $admin_id_to_bind, $midtrans_order_id, $midtrans_status, $decision, $message);
        $stmt->execute();
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Gagal menulis ke admin_cancel_logs: " . $e->getMessage());
    }
}

/**
 * Menghubungi API Midtrans untuk mendapatkan status transaksi terbaru.
 * Menggunakan cURL.
 *
 * @return array ['status' => 'settlement', 'order_id' => 'INV-123'] atau ['error' => 'Pesan error']
 */
function get_midtrans_status($conn, $order_id, $midtrans_server_key, $midtrans_is_production) {
    // 1. Dapatkan 'attempt_order_number' (ID Midtrans) dari 'order_id' (ID lokal)
    $stmt_attempt = $conn->prepare("SELECT attempt_order_number FROM payment_attempts WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    if (!$stmt_attempt) {
         return ['error' => 'DB prepare failed: ' . $conn->error];
    }
    $stmt_attempt->bind_param("i", $order_id);
    $stmt_attempt->execute();
    $attempt_data = $stmt_attempt->get_result()->fetch_assoc();
    $stmt_attempt->close();

    $midtrans_order_id = $attempt_data['attempt_order_number'] ?? null;

    // Jika tidak ada di payment_attempts (order lama/zombie), JANGAN hubungi Midtrans.
    // Langsung anggap "not_found", yang akan mengizinkan pembatalan.
    if (empty($midtrans_order_id)) {
        return ['status' => 'not_found', 'order_id' => 'N/A (Lama)'];
    }

    // 2. Tentukan URL API (Sandbox atau Produksi)
    $base_url = $midtrans_is_production 
        ? 'https://api.midtrans.com/v2' 
        : 'https://api.sandbox.midtrans.com/v2';
    
    $url = $base_url . "/" . $midtrans_order_id . "/status";

    // 3. Inisialisasi cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    curl_setopt($ch, CURLOPT_USERPWD, $midtrans_server_key . ':'); // Basic Auth
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['error' => 'cURL Error: ' . $curl_error, 'order_id' => $midtrans_order_id];
    }

    $result = json_decode($response, true);

    // Jika 404 dari Midtrans, kita anggap "not_found" (aman dibatalkan)
    if ($http_code == 404) {
         return ['status' => 'not_found', 'order_id' => $midtrans_order_id];
    }
    
    // Ini menangani error "Unauthorized Payload" (401) atau error server (500)
    if ($http_code != 200 && $http_code != 201) {
        return ['error' => 'Midtrans API Error: ' . ($result['status_message'] ?? $response), 'order_id' => $midtrans_order_id];
    }

    // Sukses
    return [
        'status' => $result['transaction_status'] ?? 'unknown',
        'order_id' => $midtrans_order_id,
        'transaction_id' => $result['transaction_id'] ?? null
    ];
}


/**
 * Fungsi pembatalan pesanan yang aman.
 * Ini adalah orkestrator utama.
 *
 * @return array ['success' => true/false, 'message' => 'Pesan untuk admin']
 */
function perform_safe_cancel($conn, $order_id, $admin_reason = "Dibatalkan oleh Admin", $midtrans_server_key, $midtrans_is_production) {
    
    // 1. Ambil status saat ini (untuk row lock) dan user_id
    $stmt_current = $conn->prepare("SELECT user_id, status FROM orders WHERE id = ? FOR UPDATE");
    $stmt_current->bind_param("i", $order_id);
    $stmt_current->execute();
    $order_data = $stmt_current->get_result()->fetch_assoc();
    $stmt_current->close();

    if (!$order_data) {
        return ['success' => false, 'message' => "Order ID $order_id tidak ditemukan.", 'commit_even_on_fail' => true];
    }

    $current_status = $order_data['status'];
    $user_id = $order_data['user_id'];

    // Jika status LOKAL sudah lunas, tidak perlu cek Midtrans. Langsung tolak.
    $paid_statuses = ['belum_dicetak', 'processed', 'shipped', 'completed'];
    if (in_array($current_status, $paid_statuses)) {
        $message = "Pesanan #$order_id sudah lunas (status: $current_status) dan tidak bisa dibatalkan.";
        log_cancel_attempt($conn, $order_id, 'N/A (Lokal)', $current_status, 'rejected-local', $message);
        return ['success' => false, 'message' => $message, 'commit_even_on_fail' => true];
    }
    
    // Jika status LOKAL sudah 'cancelled', tidak perlu aksi lagi.
    if ($current_status == 'cancelled') {
        return ['success' => true, 'message' => "Pesanan #$order_id sudah berstatus Dibatalkan."];
    }

    // 2. Hubungi Midtrans untuk status real-time
    $midtrans_result = get_midtrans_status($conn, $order_id, $midtrans_server_key, $midtrans_is_production);
    
    $midtrans_status = $midtrans_result['status'] ?? 'error';
    $midtrans_order_id = $midtrans_result['order_id'] ?? 'N/A';
    $midtrans_txn_id = $midtrans_result['transaction_id'] ?? null;

    if (isset($midtrans_result['error'])) {
        $message = 'Gagal cek status Midtrans: ' . $midtrans_result['error'];
        log_cancel_attempt($conn, $order_id, $midtrans_order_id, 'error', 'error-api', $message);
        return ['success' => false, 'message' => $message, 'commit_even_on_fail' => true];
    }

    // ====================================================================
    // 3. LOGIKA KEPUTUSAN (Decision Logic)
    // ====================================================================

    // KASUS 1: Pembayaran sudah lunas di Midtrans ('settlement' atau 'capture')
    if ($midtrans_status == 'settlement' || $midtrans_status == 'capture') {
        
        // SINKRONISASI: Status lokal salah. Seharusnya 'belum_dicetak'.
        $stmt_update = $conn->prepare("UPDATE orders SET status = 'belum_dicetak', midtrans_transaction_id = ? WHERE id = ?");
        $stmt_update->bind_param("si", $midtrans_txn_id, $order_id);
        $stmt_update->execute();
        $stmt_update->close();

        // Jalankan logika sukses (dari webhook)
        run_payment_success_logic($conn, $order_id, $user_id);
        
        $message = "Pesanan #$order_id SUDAH DIBAYAR di Midtrans (Status: $midtrans_status). Pembatalan DITOLAK. Status lokal telah diperbarui.";
        log_cancel_attempt($conn, $order_id, $midtrans_order_id, $midtrans_status, 'rejected-paid', $message);
        
        return ['success' => false, 'message' => $message, 'commit_even_on_fail' => true];
    }

    // KASUS 2: Pembayaran belum lunas ('pending', 'expire', 'cancel', 'not_found', 'deny')
    // Kita AMAN untuk membatalkan.
    else {
        
        $reason = empty(trim($admin_reason)) ? "Dibatalkan oleh Admin" : $admin_reason;

        $stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ? WHERE id = ?");
        $stmt_cancel->bind_param("si", $reason, $order_id);
        $stmt_cancel->execute();
        $stmt_cancel->close();

        // Jalankan logika batal (dari webhook)
        run_payment_cancel_logic($conn, $order_id, $user_id, "Pesanan dibatalkan oleh admin.");

        $message = "Pesanan #$order_id berhasil dibatalkan. (Status Midtrans: $midtrans_status).";
        log_cancel_attempt($conn, $order_id, $midtrans_order_id, $midtrans_status, 'cancelled', $message);

        return ['success' => true, 'message' => $message];
    }
}


// ====================================================================
// FUNGSI HELPER (Logika diambil dari midtrans_webhook.php Anda)
// ====================================================================

/**
 * Logika yang dijalankan saat pembayaran BERHASIL.
 * (Mencatat riwayat pembelian, kirim notifikasi)
 */
function run_payment_success_logic($conn, $order_id, $user_id) {
    try {
        $stmt_items = $conn->prepare(
            "SELECT oi.product_id, oi.quantity, p.stock_cycle_id
             FROM order_items oi 
             JOIN products p ON oi.product_id = p.id 
             WHERE oi.order_id = ?"
        );
        $stmt_items->bind_param("i", $order_id);
        $stmt_items->execute();
        $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();

        if (!empty($order_items)) {
            $stmt_record = $conn->prepare(
                "INSERT INTO user_purchase_records 
                 (user_id, product_id, stock_cycle_id, quantity_purchased, last_purchase_date) 
                 VALUES (?, ?, ?, ?, NOW()) 
                 ON DUPLICATE KEY UPDATE 
                     quantity_purchased = quantity_purchased + VALUES(quantity_purchased),
                     last_purchase_date = NOW()"
            );
            
            foreach ($order_items as $item) {
                $stock_cycle_id = $item['stock_cycle_id'] ?? 1; 
                
                $stmt_record->bind_param(
                    "iiii", 
                    $user_id, 
                    $item['product_id'], 
                    $stock_cycle_id, 
                    $item['quantity']
                );
                $stmt_record->execute();
            }
            $stmt_record->close();
        }
        
        if (function_exists('create_notification')) {
            create_notification($conn, $user_id, "Pembayaran pesanan Anda telah dikonfirmasi.");
        }

    } catch (Exception $e) {
        error_log("run_payment_success_logic failed for order $order_id: " . $e->getMessage());
    }
}

/**
 * Logika yang dijalankan saat pembayaran GAGAL/DIBATALKAN.
 * (Mengembalikan stok DENGAN VARIASI, kirim notifikasi)
 */
function run_payment_cancel_logic($conn, $order_id, $user_id, $notification_message) {
    try {
        // [PERBAIKAN IQ 180] Ambil juga variation_id
        $stmt_items = $conn->prepare("SELECT product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
        $stmt_items->bind_param("i", $order_id);
        $stmt_items->execute();
        $order_items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();
        
        // Siapkan Statement
        $stmt_restock_main = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
        $stmt_restock_var  = $conn->prepare("UPDATE product_variations SET stock = stock + ? WHERE id = ?");
        
        foreach ($order_items as $item) {
            $qty = (int)$item['quantity'];
            $pid = (int)$item['product_id'];
            $vid = isset($item['variation_id']) ? (int)$item['variation_id'] : 0;

            // 1. KEMBALIKAN STOK UTAMA (Selalu, karena stok utama adalah agregat)
            $stmt_restock_main->bind_param("ii", $qty, $pid);
            $stmt_restock_main->execute();

            // 2. KEMBALIKAN STOK VARIASI (Jika ada ID variasi)
            // Ini memastikan jika "Variasi Merah" dibatalkan, stok "Merah" bertambah
            if ($vid > 0) {
                $stmt_restock_var->bind_param("ii", $qty, $vid);
                $stmt_restock_var->execute();
            }
        }
        
        $stmt_restock_main->close();
        $stmt_restock_var->close();

        if (function_exists('create_notification')) {
             create_notification($conn, $user_id, $notification_message);
        }

    } catch (Exception $e) {
         error_log("run_payment_cancel_logic failed for order $order_id: " . $e->getMessage());
    }
}

?>