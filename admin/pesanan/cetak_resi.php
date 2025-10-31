<?php
// File: admin/pesanan/cetak_resi.php
// Versi ini menggunakan Dompdf DAN menangani update status otomatis.

// Sertakan file konfigurasi, sistem, dan autoloader Dompdf
include '../../config/config.php';
include '../../sistem/sistem.php';
// Pastikan path ke vendor/autoload.php sudah benar
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Cek sesi admin dan load settings
check_admin();
load_settings($conn);

// Ambil informasi toko dari settings
$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
$store_phone = get_setting($conn, 'store_phone') ?? '-';

// --- LOGIKA BARU: PENANGANAN AKSI DAN UPDATE STATUS ---
$action = $_GET['action'] ?? null;
$order_id = (int)($_GET['order_id'] ?? 0);
$status = $_GET['status'] ?? null; // Untuk kompatibilitas link lama

$orders_to_print = [];
$order_ids_to_update = []; // Kumpulan ID yang akan di-update statusnya
$filename = 'resi_pengiriman.pdf';

$sql_where = "";
$sql_params = [];
$sql_types = "";

// 1. TENTUKAN DATA YANG AKAN DIAMBIL BERDASARKAN PARAMETER
if ($action === 'print_all_and_process') {
    // Aksi baru: Cetak semua resi 'belum_dicetak' DAN proses
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";

} elseif ($action === 'print_single_and_process' && $order_id > 0) {
    // Aksi baru: Cetak satu resi 'belum_dicetak' DAN proses
    $filename = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ? AND status = 'belum_dicetak'";
    $sql_params[] = $order_id;
    $sql_types = "i";

} elseif ($order_id > 0) {
    // Aksi lama: Cetak ulang (tanpa 'action'), misal dari status 'processed'
    $filename = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ?";
    $sql_params[] = $order_id;
    $sql_types = "i";

} elseif ($status === 'belum_dicetak') {
    // Aksi lama: Cetak semua dari link 'status=belum_dicetak' (tanpa 'action')
    // Ini TIDAK akan mengupdate status, untuk backward compatibility
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";

} elseif ($status === 'processed') {
    // PERUBAHAN: Aksi baru untuk cetak ulang semua 'processed' (TANPA update status)
    $filename = 'semua_resi_diproses_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'processed'";
    // Penting: 'action' TIDAK diset, jadi 'order_ids_to_update' akan kosong.

} else {
    die('Parameter tidak valid.');
}

// 2. AMBIL DATA PESANAN UNTUK DICETAK
$sql_fetch = "SELECT * FROM orders $sql_where ORDER BY created_at ASC";
$stmt_fetch = $conn->prepare($sql_fetch);
if (!empty($sql_params)) {
    $stmt_fetch->bind_param($sql_types, ...$sql_params);
}
$stmt_fetch->execute();
$result_fetch = $stmt_fetch->get_result();

if ($result_fetch->num_rows > 0) {
    while ($order = $result_fetch->fetch_assoc()) {
        $orders_to_print[] = $order;
        // Kumpulkan ID untuk di-update HANYA JIKA 'action' SPESIFIK dipanggil
        if ($action === 'print_all_and_process' || $action === 'print_single_and_process') {
            $order_ids_to_update[] = $order['id'];
        }
    }
}
$stmt_fetch->close();

// 3. JALANKAN UPDATE STATUS JIKA DIPERLUKAN
if (!empty($order_ids_to_update)) {
    $conn->begin_transaction();
    try {
        $placeholders = implode(',', array_fill(0, count($order_ids_to_update), '?'));
        $types_update = str_repeat('i', count($order_ids_to_update));
        
        $stmt_update = $conn->prepare("UPDATE orders SET status = 'processed' WHERE id IN ($placeholders)");
        $stmt_update->bind_param($types_update, ...$order_ids_to_update);
        $stmt_update->execute();
        $stmt_update->close();
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal memperbarui status pesanan: " . $e->getMessage());
    }
}

// --- AKHIR LOGIKA BARU ---


// 4. LANJUTKAN KE GENERASI PDF (Logika Anda yang sudah ada)
if (empty($orders_to_print)) {
    $status_redirect = $status ?? $status_filter ?? 'semua';
     if ($status === 'processed') {
        set_flashdata('info', 'Tidak ada pesanan \'Diproses\' untuk dicetak.');
        redirect('/admin/admin.php?page=pesanan&status=processed');
    } else {
        set_flashdata('info', 'Tidak ada resi yang perlu dicetak.');
        redirect('/admin/admin.php?page=pesanan&status=belum_dicetak');
    }
}

// --- PROSES PEMBUATAN KONTEN HTML ---
// Asumsi Anda punya file resi_template.html di direktori yang sama
$template_html_path = __DIR__ . '/resi_template.html';
if (!file_exists($template_html_path)) {
    die("Error: File template resi 'resi_template.html' tidak ditemukan.");
}
$template_html = file_get_contents($template_html_path);
$all_receipts_html = '';

foreach ($orders_to_print as $order) {
    // Ambil item dari pesanan
    $stmt_items = $conn->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
    $stmt_items->bind_param("i", $order['id']);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    $order_items = $result_items->fetch_all(MYSQLI_ASSOC);
    $stmt_items->close();

    // Buat baris HTML untuk tabel produk
    $items_html = '';
    $no = 1;
    foreach ($order_items as $item) {
        // ======================================================
        // PERBAIKAN 1: Mengecilkan font & padding baris produk
        // PERBAIKAN 4: Menggunakan htmlspecialchars_decode untuk nama produk
        // ======================================================
        $product_name = htmlspecialchars_decode($item['name'], ENT_QUOTES);
        
        $items_html .= '<tr>
            <td style="text-align: left; padding: 4px; font-size: 9pt; border-top: 1px solid #eee;">' . $no++ . '</td>
            <td style="text-align: left; padding: 4px; font-size: 9pt; border-top: 1px solid #eee;">' . $product_name . '</td>
            <td style="text-align: center; padding: 4px; font-size: 9pt; border-top: 1px solid #eee;">' . $item['quantity'] . '</td>
        </tr>';
    }

    // ======================================================
    // PERBAIKAN 4: Menggunakan htmlspecialchars_decode untuk data alamat
    // Ini akan mengubah '&#039;' kembali menjadi tanda kutip (')
    // ======================================================
    $full_address = htmlspecialchars_decode($order['address_line_1'], ENT_QUOTES) . 
                    (!empty($order['address_line_2']) ? ', ' . htmlspecialchars_decode($order['address_line_2'], ENT_QUOTES) : '') . 
                    ', ' . htmlspecialchars_decode($order['subdistrict'], ENT_QUOTES) . 
                    ', ' . htmlspecialchars_decode($order['city'], ENT_QUOTES) . 
                    ', ' . htmlspecialchars_decode($order['province'], ENT_QUOTES) . ' ' . htmlspecialchars_decode($order['postal_code'], ENT_QUOTES);
    
    // Ganti baris baru literal (dari textarea) dengan spasi agar tidak merusak layout
    $full_address = str_replace(["\r\n", "\r", "\n"], ' ', $full_address);

    $placeholders = [
        '{{NAMA_PENGIRIM}}'    => htmlspecialchars_decode($store_name, ENT_QUOTES),
        '{{HP_PENGIRIM}}'      => htmlspecialchars_decode($store_phone, ENT_QUOTES),
        '{{PENERIMA_NAMA}}'    => htmlspecialchars_decode(strtoupper($order['full_name']), ENT_QUOTES),
        '{{PENERIMA_HP}}'      => htmlspecialchars_decode($order['phone_number'], ENT_QUOTES),
        '{{PENERIMA_ALAMAT}}'  => $full_address, // Sudah di-decode
        '{{ORDER_NUMBER}}'     => htmlspecialchars_decode($order['order_number'], ENT_QUOTES),
        '{{PRODUK_ITEMS}}'     => $items_html, // Sudah di-handle di dalam loop
    ];
    
    // Bungkus setiap resi dalam div dengan class .receipt-wrapper
    $all_receipts_html .= '<div class="receipt-wrapper">' . str_replace(array_keys($placeholders), array_values($placeholders), $template_html) . '</div>';
}

$conn->close();

// --- PROSES GENERATE PDF DENGAN DOMPDF ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');
// Aktifkan 'enable_font_subsetting' untuk performa
$options->set('enable_font_subsetting', true); 
// Set DPI rendah untuk mengurangi ukuran file dan kompleksitas render
$options->set('dpi', 96); 

$dompdf = new Dompdf($options);

// Tambahkan style untuk .receipt-wrapper di dalam HTML yang akan di-render
$final_html = '
<style>
    /* Mengatur agar setiap wrapper resi selalu mulai di halaman baru */
    .receipt-wrapper {
        page-break-before: auto; /* Biarkan Dompdf yang menentukan untuk halaman pertama */
    }
    .receipt-wrapper + .receipt-wrapper {
        page-break-before: always; /* Paksa halaman baru untuk resi kedua dan seterusnya */
    }
</style>
' . $all_receipts_html;

$dompdf->loadHtml($final_html);

// Set ukuran kertas: 4.72 x 7.35 inches (portrait)
// (Ukuran ini dari file Anda)
$width_in_points = 4.72 * 72;
$height_in_points = 7.35 * 72;
$custom_paper = array(0, 0, $width_in_points, $height_in_points);
$dompdf->setPaper($custom_paper);

$dompdf->render();
$dompdf->stream($filename, ["Attachment" => false]); // Tampilkan di browser

?>