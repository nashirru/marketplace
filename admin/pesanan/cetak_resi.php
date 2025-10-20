<?php
// File: admin/pesanan/cetak_resi_dompdf.php
// Versi ini menggunakan Dompdf untuk hasil render HTML & CSS yang lebih baik.

// Sertakan file konfigurasi, sistem, dan autoloader Dompdf
include '../../config/config.php';
include '../../sistem/sistem.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Cek sesi admin dan load settings
check_admin();
load_settings($conn);

// Ambil informasi toko dari settings
$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
$store_phone = get_setting($conn, 'store_phone') ?? '-';

// --- LOGIKA PENGAMBILAN DATA PESANAN ---
$orders_to_print = [];
$filename = 'resi_pengiriman.pdf';

if (isset($_GET['status']) && $_GET['status'] == 'belum_dicetak') {
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $result = $conn->query("SELECT * FROM orders WHERE status = 'belum_dicetak' ORDER BY created_at ASC");
    if ($result) {
        while($order = $result->fetch_assoc()) {
            $orders_to_print[] = $order;
        }
    }
} 
elseif (isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    $filename = 'resi_' . $order_id . '.pdf';
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $orders_to_print[] = $result->fetch_assoc();
    }
    $stmt->close();
} else {
    die('Parameter tidak valid.');
}

if (empty($orders_to_print)) {
    set_flashdata('info', 'Tidak ada resi yang perlu dicetak.');
    redirect('/admin/admin.php?page=pesanan');
}

// --- PROSES PEMBUATAN KONTEN HTML ---
$template_html = file_get_contents(__DIR__ . '/resi_template.html');
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
        $items_html .= '<tr>
            <td style="text-align: left; padding: 8px;">' . $no++ . '</td>
            <td style="text-align: left; padding: 8px;">' . htmlspecialchars($item['name']) . '</td>
            <td style="text-align: center; padding: 8px;">' . $item['quantity'] . '</td>
        </tr>';
    }

    $full_address = htmlspecialchars($order['address_line_1']) . 
                    (!empty($order['address_line_2']) ? ', ' . htmlspecialchars($order['address_line_2']) : '') . 
                    ', ' . htmlspecialchars($order['subdistrict']) . 
                    ', ' . htmlspecialchars($order['city']) . 
                    ', ' . htmlspecialchars($order['province']) . ' ' . htmlspecialchars($order['postal_code']);

    $placeholders = [
        '{{NAMA_PENGIRIM}}'    => htmlspecialchars($store_name),
        '{{HP_PENGIRIM}}'      => htmlspecialchars($store_phone),
        '{{PENERIMA_NAMA}}'    => htmlspecialchars(strtoupper($order['full_name'])),
        '{{PENERIMA_HP}}'      => htmlspecialchars($order['phone_number']),
        '{{PENERIMA_ALAMAT}}'  => $full_address,
        '{{ORDER_NUMBER}}'     => htmlspecialchars($order['order_number']),
        '{{PRODUK_ITEMS}}'     => $items_html,
    ];
    
    // PERBAIKAN: Bungkus setiap resi dalam div dengan class .receipt-wrapper
    $all_receipts_html .= '<div class="receipt-wrapper">' . str_replace(array_keys($placeholders), array_values($placeholders), $template_html) . '</div>';
}

// --- PROSES GENERATE PDF DENGAN DOMPDF ---
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);

// PERBAIKAN: Tambahkan style untuk .receipt-wrapper di dalam HTML yang akan di-render
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
$width_in_points = 4.72 * 72;
$height_in_points = 7.35 * 72;
$custom_paper = array(0, 0, $width_in_points, $height_in_points);
$dompdf->setPaper($custom_paper);

$dompdf->render();
$dompdf->stream($filename, ["Attachment" => false]);

?>