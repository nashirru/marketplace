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

// --- LOGIKA PENGAMBILAN DATA PESANAN (SAMA SEPERTI SEBELUMNYA) ---
$orders_to_print = [];
$filename = 'resi_pengiriman.pdf';

// Logika untuk cetak semua resi yang belum dicetak
if (isset($_GET['status']) && $_GET['status'] == 'belum_dicetak') {
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $result = $conn->query("SELECT * FROM orders WHERE status = 'belum_dicetak' ORDER BY created_at ASC");
    if ($result && $result->num_rows > 0) {
        while($order = $result->fetch_assoc()) {
            $orders_to_print[] = $order;
        }
    }
} 
// Logika untuk cetak satu resi berdasarkan order_id
elseif (isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    $filename = 'resi_' . $order_id . '.pdf';
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $orders_to_print[] = $result->fetch_assoc();
    } else {
        die('Resi tidak ditemukan atau pesanan tidak ada.');
    }
    $stmt->close();
} else {
    die('Parameter tidak valid. Gunakan ?order_id=X atau ?status=belum_dicetak');
}

// Jika tidak ada pesanan yang ditemukan, redirect kembali
if (empty($orders_to_print)) {
    echo "<script>alert('Tidak ada resi yang perlu dicetak.'); window.location.href = '" . BASE_URL . "/admin/admin.php?page=pesanan';</script>";
    exit;
}

// --- PROSES PEMBUATAN KONTEN HTML ---

// Baca file template
$template_path = __DIR__ . '/resi_template.html';
if (!file_exists($template_path)) {
    die('Error Kritis: File template resi_template.html tidak ditemukan di folder yang sama!');
}
$template_html = file_get_contents($template_path);
$all_receipts_html = '';
$is_first = true;

// Loop untuk setiap pesanan dan generate HTML-nya
foreach ($orders_to_print as $order) {
    // Tambahkan pemisah halaman (page break) untuk resi kedua dan seterusnya
    if (!$is_first) {
        $all_receipts_html .= '<div style="page-break-before: always;"></div>';
    }
    $is_first = false;

    // Ambil item dari pesanan
    $stmt_items = $conn->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
    $stmt_items->bind_param("i", $order['id']);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    $order_items = [];
    while ($item = $result_items->fetch_assoc()) {
        $order_items[] = $item;
    }
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

    // Gabungkan alamat menjadi satu baris yang rapi
    $full_address = htmlspecialchars($order['address_line_1']);
    if (!empty($order['address_line_2'])) {
        $full_address .= ', ' . htmlspecialchars($order['address_line_2']);
    }
    $full_address .= ', ' . htmlspecialchars($order['subdistrict']) . ', ' . htmlspecialchars($order['city']) . ', ' . htmlspecialchars($order['province']) . ' ' . htmlspecialchars($order['postal_code']);

    // Ganti semua placeholder di template dengan data dinamis
    $current_receipt_html = $template_html;
    $placeholders = [
        '{{NAMA_PENGIRIM}}'    => htmlspecialchars($store_name),
        '{{HP_PENGIRIM}}'      => htmlspecialchars($store_phone),
        '{{PENERIMA_NAMA}}'    => htmlspecialchars(strtoupper($order['full_name'])),
        '{{PENERIMA_HP}}'      => htmlspecialchars($order['phone_number']),
        '{{PENERIMA_ALAMAT}}'  => $full_address,
        '{{ORDER_NUMBER}}'     => htmlspecialchars($order['order_number']),
        '{{PRODUK_ITEMS}}'     => $items_html,
    ];
    $current_receipt_html = str_replace(array_keys($placeholders), array_values($placeholders), $current_receipt_html);
    
    $all_receipts_html .= $current_receipt_html;
}

// --- PROSES GENERATE PDF DENGAN DOMPDF ---

// Konfigurasi Dompdf
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true); // Penting jika ada gambar/font dari URL
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($all_receipts_html);

// Set ukuran kertas: 4.72 x 7.35 inches (portrait)
// Dompdf menggunakan points (1 inch = 72 points)
$width_in_points = 4.72 * 72;
$height_in_points = 7.35 * 72;
$custom_paper = array(0, 0, $width_in_points, $height_in_points);
$dompdf->setPaper($custom_paper);

// Render HTML menjadi PDF
$dompdf->render();

// Tampilkan PDF di browser (stream) tanpa memaksa download
$dompdf->stream($filename, ["Attachment" => false]);

?>