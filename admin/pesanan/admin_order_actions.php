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