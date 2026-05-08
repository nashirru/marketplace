<?php
// File: admin/pesanan/admin_order_actions.php
//
// INI ADALAH FILE BARU.
// File ini khusus menangani SEMUA request AJAX dari halaman pesanan.
// File ini memanggil modul 'sistem_keamanan_midtrans.php'
// 
// Pastikan file 'sistem_keamanan_midtrans.php' ada di folder yang sama
// (yaitu 'admin/pesanan/sistem_keamanan_midtrans.php')

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Sertakan file-file yang diperlukan
// Sesuaikan path ini jika struktur folder Anda berbeda.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../sistem/sistem.php';
// [PERBAIKAN] File ini me-load \Midtrans\Config
require_once __DIR__ . '/../../midtrans/config_midtrans.php'; 
require_once __DIR__ . '/sistem_keamanan_midtrans.php'; // Modul "Safe Cancel"

// 2. Cek otentikasi admin
check_admin();

// 3. Tentukan tipe response (AJAX)
header('Content-Type: application/json');

// ================================================================
// Midtrans Reconciliation Helpers
// - cari order berdasarkan order_id (angka), order_number, atau attempt_order_number (Midtrans)
// - list order cancelled yang ternyata sudah settlement/capture
// ================================================================
function admin_reconcile_find_order($conn, $query) {
    $q = trim((string)$query);
    if ($q === "") return null;

    // 1) numeric -> treat as order.id
    if (preg_match("/^\\d+$/", $q)) {
        $id = (int)$q;
        $stmt = $conn->prepare("SELECT id, order_number, status, user_id, total, created_at FROM orders WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // 2) order_number exact
    $stmt = $conn->prepare("SELECT id, order_number, status, user_id, total, created_at FROM orders WHERE order_number = ? LIMIT 1");
    $stmt->bind_param("s", $q);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row;

    // 3) attempt_order_number (Midtrans order_id)
    $stmt = $conn->prepare("SELECT o.id, o.order_number, o.status, o.user_id, o.total, o.created_at FROM orders o JOIN payment_attempts pa ON pa.order_id = o.id WHERE pa.attempt_order_number = ? ORDER BY pa.id DESC LIMIT 1");
    $stmt->bind_param("s", $q);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function admin_reconcile_is_paid_midtrans_status($midtrans_status) {
    return ($midtrans_status === "settlement" || $midtrans_status === "capture");
}

function admin_reconcile_decrement_stock_for_order($conn, $order_id) {
    $stmt_items = $conn->prepare("SELECT product_id, variation_id, quantity FROM order_items WHERE order_id = ?");
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_items->close();

    if (empty($items)) return;

    // Clamp ke 0 agar tidak jadi negatif (oversell tetap perlu dicek manual).
    $stmt_prod = $conn->prepare("UPDATE products SET stock = GREATEST(stock - ?, 0) WHERE id = ?");
    $stmt_var  = $conn->prepare("UPDATE product_variations SET stock = GREATEST(stock - ?, 0) WHERE id = ?");

    foreach ($items as $it) {
        $qty = (int)($it["quantity"] ?? 0);
        $pid = (int)($it["product_id"] ?? 0);
        $vid = (int)($it["variation_id"] ?? 0);
        if ($qty <= 0 || $pid <= 0) continue;

        // Selalu kurangi stok produk utama (agregat)
        $stmt_prod->bind_param("ii", $qty, $pid);
        $stmt_prod->execute();

        // Kurangi stok variasi jika ada
        if ($vid > 0) {
            $stmt_var->bind_param("ii", $qty, $vid);
            $stmt_var->execute();
        }
    }

    $stmt_prod->close();
    $stmt_var->close();
}


// 4. Ambil aksi dari POST
$action = $_POST['action'] ?? '';
$is_ajax = $_POST['is_ajax'] ?? 0;

// Kita hanya proses request AJAX
if (!$is_ajax) {
    echo json_encode(['success' => false, 'message' => 'Invalid request type.']);
    exit;
}

// Inisialisasi array response
$response = ['success' => false, 'message' => 'Aksi tidak diketahui.'];

// Mulai database transaction untuk keamanan
$conn->begin_transaction();

try {
    switch ($action) {
        
        // ================================================================
        // KASUS: AKSI MASAL "Batalkan Pesanan"
        // (Dipanggil dari tab 'Waiting Payment')
        // ================================================================
        case 'cancel_order':
            $selected_orders = $_POST['selected_orders'] ?? [];
            if (empty($selected_orders)) {
                $response = ['success' => false, 'message' => 'Tidak ada pesanan yang dipilih.'];
                break;
            }

            $success_count = 0;
            $fail_count = 0;
            $messages = [];

            foreach ($selected_orders as $order_id) {
                // ====================================================================
                // [PERBAIKAN WARNING]
                // Ambil key dari \Midtrans\Config, bukan variabel global
                // ====================================================================
                $result = perform_safe_cancel(
                    $conn, 
                    (int)$order_id, 
                    "Dibatalkan oleh Admin (Aksi Masal)",
                    \Midtrans\Config::$serverKey,
                    \Midtrans\Config::$isProduction
                );
                
                if ($result['success']) {
                    $success_count++;
                } else {
                    $fail_count++;
                }
                $messages[] = $result['message']; // Kumpulkan semua pesan
            }

            $response['success'] = true; // Aksi masal dianggap sukses jika berhasil berjalan
            $response['message'] = "Proses selesai. Berhasil: $success_count, Gagal/Ditolak: $fail_count.\n\nDetail:\n- " . implode("\n- ", $messages);
            break;

        // ================================================================
        // KASUS: MODAL "Ubah Status Fleksibel"
        // (Dipanggil dari tombol 'Ubah' di setiap baris)
        // ================================================================
        case 'flexible_update_status':
            $order_id = (int)($_POST['order_id'] ?? 0);
            $new_status = $_POST['new_status'] ?? '';

            if (empty($order_id) || empty($new_status)) {
                 $response = ['success' => false, 'message' => 'Data tidak lengkap.'];
                 break;
            }

            // --- Logika "Safe Cancel" ---
            if ($new_status == 'cancelled') {
                $cancel_reason = $_POST['cancel_reason'] ?? "Dibatalkan oleh Admin";
                // ====================================================================
                // [PERBAIKAN WARNING]
                // Ambil key dari \Midtrans\Config, bukan variabel global
                // ====================================================================
                $response = perform_safe_cancel(
                    $conn, 
                    $order_id, 
                    $cancel_reason,
                    \Midtrans\Config::$serverKey,
                    \Midtrans\Config::$isProduction
                );
            } 
            // --- Logika Status Lain ---
            else {
                $allowed_statuses = ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed'];
                if (in_array($new_status, $allowed_statuses)) {
                    
                    $stmt = $conn->prepare("UPDATE orders SET status = ?, cancel_reason = NULL WHERE id = ?");
                    $stmt->bind_param("si", $new_status, $order_id);
                    $stmt->execute();
                    
                    // [PERBAIKAN] Cek apakah update berhasil
                    if ($stmt->affected_rows > 0) {
                        $response = ['success' => true, 'message' => "Status pesanan #$order_id berhasil diubah menjadi '$new_status'."];
                    } else {
                        // Tidak ada baris yang berubah, mungkin statusnya sudah sama
                        $response = ['success' => true, 'message' => "Status pesanan #$order_id sudah '$new_status'."];
                    }
                    $stmt->close();
                    
                } else {
                    $response = ['success' => false, 'message' => "Status '$new_status' tidak valid."];
                }
            }
            break;

        // ================================================================
        // KASUS: Tombol Aksi Cepat (Approve, Ship, dll)
        // ================================================================
        
        case 'approve_payment': // Setujui (dari waiting_approval)
        case 'process_order':   // Proses (dari belum_dicetak)
        case 'ship_order':      // Kirim (dari processed)
        case 'complete_order':  // Selesai (dari shipped)
            
            $order_id_single = (int)($_POST['order_id'] ?? 0);
            
            // Logika untuk Aksi Cepat Individu atau Massal
            $is_bulk = isset($_POST['selected_orders']);
            if ($is_bulk) {
                 $order_ids_to_process = array_map('intval', $_POST['selected_orders']);
            } elseif ($order_id_single > 0) {
                 $order_ids_to_process = [$order_id_single];
            } else {
                $response = ['success' => false, 'message' => 'Order ID tidak ada.'];
                break;
            }

            
            $status_map_quick = [
                'approve_payment' => ['new' => 'belum_dicetak', 'required' => 'waiting_approval'],
                'process_order' => ['new' => 'processed', 'required' => 'belum_dicetak'],
                'ship_order' => ['new' => 'shipped', 'required' => 'processed'],
                'complete_order' => ['new' => 'completed', 'required' => 'shipped']
            ];

            $new_status = $status_map_quick[$action]['new'];
            $required_status = $status_map_quick[$action]['required'];

            $placeholders = implode(',', array_fill(0, count($order_ids_to_process), '?'));
            $types = str_repeat('i', count($order_ids_to_process));

            $stmt = $conn->prepare("UPDATE orders SET status = ?, cancel_reason = NULL WHERE status = ? AND id IN ($placeholders)");
            $stmt->bind_param("ss" . $types, $new_status, $required_status, ...$order_ids_to_process);
            $stmt->execute();
            $count = $stmt->affected_rows;
            $stmt->close();

            $response = ['success' => true, 'message' => "$count pesanan berhasil diperbarui."];
            break;

        case 'reject_payment': // Tolak (dari waiting_approval)
            $order_id = (int)($_POST['order_id'] ?? 0);
            if (empty($order_id)) {
                 $response = ['success' => false, 'message' => 'Order ID tidak ada.'];
                 break;
            }
            // "Reject" harus selalu aman
            // ====================================================================
            // [PERBAIKAN WARNING]
            // Ambil key dari \Midtrans\Config, bukan variabel global
            // ====================================================================
            $response = perform_safe_cancel(
                $conn, 
                $order_id, 
                "Pembayaran ditolak oleh Admin",
                \Midtrans\Config::$serverKey,
                \Midtrans\Config::$isProduction
            );
            break;
            

        // ================================================================
        // KASUS: Rekonsiliasi Midtrans
        // - Cari order cancelled yang ternyata sudah settlement/capture
        // - Pulihkan status order menjadi belum_dicetak
        // ================================================================
        case 'reconcile_find_paid_cancelled':
            $limit_scan = (int)($_POST['limit'] ?? 20);
            if ($limit_scan < 1) $limit_scan = 1;
            if ($limit_scan > 50) $limit_scan = 50;

            $stmt = $conn->prepare("SELECT o.id, o.order_number, o.total, o.created_at FROM orders o WHERE o.status = 'cancelled' AND EXISTS (SELECT 1 FROM payment_attempts pa WHERE pa.order_id = o.id) ORDER BY o.created_at DESC LIMIT ?");
            $stmt->bind_param("i", $limit_scan);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            $items = [];
            $checked = 0;
            foreach ($rows as $r) {
                $checked++;
                $oid = (int)$r['id'];
                $mid = get_midtrans_status($conn, $oid, \Midtrans\Config::$serverKey, \Midtrans\Config::$isProduction);
                if (isset($mid['error'])) {
                    continue;
                }
                $mid_status = $mid['status'] ?? 'unknown';
                if (!admin_reconcile_is_paid_midtrans_status($mid_status)) {
                    continue;
                }
                $items[] = [
                    'order_id' => $oid,
                    'order_number' => $r['order_number'] ?? '',
                    'total' => $r['total'] ?? null,
                    'created_at' => $r['created_at'] ?? null,
                    'midtrans_status' => $mid_status,
                    'midtrans_order_id' => $mid['order_id'] ?? null,
                    'midtrans_transaction_id' => $mid['transaction_id'] ?? null
                ];
            }

            $response = [
                'success' => true,
                'message' => 'Scan selesai. Ditemukan ' . count($items) . ' order paid tapi cancelled.',
                'checked' => $checked,
                'items' => $items
            ];
            break;

        case 'reconcile_manual_check':
            $q = trim((string)($_POST['query'] ?? ''));
            if ($q === '') {
                $response = ['success' => false, 'message' => 'Query pencarian kosong.'];
                break;
            }
            $ord = admin_reconcile_find_order($conn, $q);
            if (!$ord) {
                $response = ['success' => false, 'message' => 'Order tidak ditemukan. (Coba pakai order_number atau ID Midtrans attempt_order_number)'];
                break;
            }
            $oid = (int)$ord['id'];
            $mid = get_midtrans_status($conn, $oid, \Midtrans\Config::$serverKey, \Midtrans\Config::$isProduction);
            if (isset($mid['error'])) {
                $response = ['success' => false, 'message' => 'Gagal cek Midtrans: ' . $mid['error']];
                break;
            }
            $mid_status = $mid['status'] ?? 'unknown';
            $response = [
                'success' => true,
                'message' => 'OK',
                'item' => [
                    'order_id' => (int)$ord['id'],
                    'order_number' => $ord['order_number'] ?? '',
                    'status' => $ord['status'] ?? '',
                    'total' => $ord['total'] ?? null,
                    'created_at' => $ord['created_at'] ?? null,
                    'midtrans_status' => $mid_status,
                    'midtrans_order_id' => $mid['order_id'] ?? null,
                    'midtrans_transaction_id' => $mid['transaction_id'] ?? null,
                    'eligible_restore' => (admin_reconcile_is_paid_midtrans_status($mid_status) && (($ord['status'] ?? '') === 'cancelled'))
                ]
            ];
            break;

        case 'reconcile_restore_paid_order':
            $order_id_restore = (int)($_POST['order_id'] ?? 0);
            if ($order_id_restore <= 0) {
                $response = ['success' => false, 'message' => 'Order ID tidak valid.'];
                break;
            }

            // Lock order dulu supaya aman dari race
            $stmt_lock = $conn->prepare("SELECT id, order_number, status, user_id FROM orders WHERE id = ? FOR UPDATE");
            $stmt_lock->bind_param("i", $order_id_restore);
            $stmt_lock->execute();
            $ord = $stmt_lock->get_result()->fetch_assoc();
            $stmt_lock->close();
            if (!$ord) {
                $response = ['success' => false, 'message' => 'Order tidak ditemukan.'];
                break;
            }
            if (($ord['status'] ?? '') !== 'cancelled') {
                $response = ['success' => false, 'message' => 'Order tidak dalam status cancelled (status saat ini: ' . ($ord['status'] ?? 'unknown') . ').'];
                break;
            }

            $mid = get_midtrans_status($conn, $order_id_restore, \Midtrans\Config::$serverKey, \Midtrans\Config::$isProduction);
            if (isset($mid['error'])) {
                $response = ['success' => false, 'message' => 'Gagal cek Midtrans: ' . $mid['error']];
                break;
            }
            $mid_status = $mid['status'] ?? 'unknown';
            $mid_txn_id = $mid['transaction_id'] ?? null;

            if (!admin_reconcile_is_paid_midtrans_status($mid_status)) {
                $response = ['success' => false, 'message' => 'Belum bisa dipulihkan karena status Midtrans bukan paid (status: ' . $mid_status . ').'];
                break;
            }

            $stmt_up = $conn->prepare("UPDATE orders SET status = 'belum_dicetak', cancel_reason = NULL, midtrans_transaction_id = ? WHERE id = ?");
            $stmt_up->bind_param("si", $mid_txn_id, $order_id_restore);
            $stmt_up->execute();
            $stmt_up->close();

            // Karena order sebelumnya sempat di-cancel, stok kemungkinan sudah direstock.
            // Kita kurangi lagi stok (clamp 0) agar angka stok tidak membesar.
            admin_reconcile_decrement_stock_for_order($conn, $order_id_restore);

            // Catat pembelian & notifikasi (idempotent karena order tidak bisa restore dua kali).
            $uid = (int)($ord['user_id'] ?? 0);
            if ($uid > 0) {
                run_payment_success_logic($conn, $order_id_restore, $uid);
            }

            $response = ['success' => true, 'message' => 'Order berhasil dipulihkan ke Belum Dicetak. (#' . ($ord['order_number'] ?? $order_id_restore) . ')'];
            break;

        default:
            $response = ['success' => false, 'message' => "Aksi '$action' tidak valid."];
    }

    // 5. Commit atau Rollback
    if ($response['success']) {
        $conn->commit();
    } else {
        // Jika response 'success' false (misal DITOLAK KARENA SUDAH BAYAR),
        // kita tetap commit perubahan yang mungkin terjadi di 'perform_safe_cancel'
        // (seperti sinkronisasi status ke 'belum_dicetak' atau penulisan log)
        if (isset($response['commit_even_on_fail']) && $response['commit_even_on_fail'] === true) {
             $conn->commit();
        } else {
             $conn->rollback();
        }
    }

} catch (Exception $e) {
    // Tangani error fatal
    $conn->rollback();
    // [PERBAIKAN] Tampilkan pesan error yang lebih detail saat debugging
    $error_message = 'Terjadi error Server: ' . $e->getMessage() . " di baris " . $e->getLine();
    $response = ['success' => false, 'message' => $error_message];
    error_log("admin_order_actions.php error ($action): " . $e->getMessage());
}

// 6. Kirim response
echo json_encode($response);
exit;
?>
