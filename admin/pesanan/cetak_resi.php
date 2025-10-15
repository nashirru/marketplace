<?php
// File: admin/pesanan/cetak_resi.php
include '../../config/config.php';
include '../../sistem/sistem.php';
check_admin();

//
// PERBAIKAN: Path disesuaikan dengan lokasi library TCPDF di folder vendor.
// Ini adalah path yang umum jika Anda menggunakan Composer.
//
$tcpdf_path = $_SERVER['DOCUMENT_ROOT'] . BASE_URL . '/vendor/tecnickcom/tcpdf/tcpdf.php';

if (file_exists($tcpdf_path)) {
    require_once($tcpdf_path);
} else {
    die("Error Kritis: Library TCPDF tidak ditemukan. Pastikan file ada di: " . htmlspecialchars($tcpdf_path));
}


// Fungsi untuk membuat konten resi
function generate_receipt_content($order, $order_items) {
    $items_html = '';
    foreach ($order_items as $item) {
        $items_html .= '<tr>
            <td style="border-bottom: 1px solid #ddd; padding: 5px;">' . htmlspecialchars($item['name']) . '</td>
            <td style="border-bottom: 1px solid #ddd; padding: 5px; text-align: center;">' . $item['quantity'] . '</td>
        </tr>';
    }

    $address = htmlspecialchars($order['full_name']) . '<br>' .
               htmlspecialchars($order['address_line_1']) . '<br>' .
               (empty($order['address_line_2']) ? '' : htmlspecialchars($order['address_line_2']) . '<br>') .
               htmlspecialchars($order['subdistrict']) . ', ' . htmlspecialchars($order['city']) . '<br>' .
               htmlspecialchars($order['province']) . ' ' . htmlspecialchars($order['postal_code']) . '<br>' .
               'Telp: ' . htmlspecialchars($order['phone_number']);

    return <<<HTML
    <div style="border: 1px solid #333; padding: 10px; margin-bottom: 15px; page-break-inside: avoid;">
        <table width="100%">
            <tr>
                <td width="50%">
                    <h2 style="font-size: 14pt; font-weight: bold;">Warok Kite</h2>
                    <p style="font-size: 9pt;">Resi Pengiriman</p>
                </td>
                <td width="50%" style="text-align: right;">
                    <p style="font-size: 10pt;"><b>Order ID:</b> #WK{$order['id']}</p>
                    <p style="font-size: 10pt;"><b>Tanggal:</b> {$order['created_at']}</p>
                </td>
            </tr>
        </table>
        <hr style="margin: 10px 0;">
        <p style="font-size: 10pt;"><b>Kepada:</b><br>{$address}</p>
        <br>
        <p style="font-size: 10pt;"><b>Detail Pesanan:</b></p>
        <table width="100%" style="font-size: 9pt; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="border-bottom: 1px solid #333; padding: 5px; text-align: left;">Produk</th>
                    <th style="border-bottom: 1px solid #333; padding: 5px; text-align: center;">Jumlah</th>
                </tr>
            </thead>
            <tbody>
                {$items_html}
            </tbody>
        </table>
    </div>
HTML;
}

// Inisialisasi PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, 'A5', true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor('Warok Kite Admin');
$pdf->SetTitle('Resi Pengiriman');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

$order_ids_to_update = [];
$filename = 'resi_pengiriman.pdf';

// Logika untuk cetak semua resi
if (isset($_GET['action']) && $_GET['action'] == 'print_all') {
    $filename = 'semua_resi_' . date('Y-m-d') . '.pdf';
    $result = $conn->query("SELECT * FROM orders WHERE status = 'belum_dicetak' ORDER BY created_at ASC");
    
    while($order = $result->fetch_assoc()) {
        $order_items = $conn->query("SELECT oi.*, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = " . $order['id'])->fetch_all(MYSQLI_ASSOC);
        $html = generate_receipt_content($order, $order_items);
        $pdf->writeHTML($html, true, false, true, false, '');
        $order_ids_to_update[] = $order['id'];
    }
} 
// Logika untuk cetak satu resi
elseif (isset($_GET['order_id'])) {
    $order_id = (int)$_GET['order_id'];
    $filename = 'resi_WK' . $order_id . '.pdf';
    
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND status = 'belum_dicetak'");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        $order_items = $conn->query("SELECT oi.*, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = " . $order_id)->fetch_all(MYSQLI_ASSOC);
        
        $html = generate_receipt_content($order, $order_items);
        $pdf->writeHTML($html, true, false, true, false, '');
        $order_ids_to_update[] = $order_id;
    } else {
        die('Resi tidak ditemukan atau sudah dicetak.');
    }
} else {
    die('Aksi tidak valid.');
}

// Jika tidak ada resi yang diproses, keluar.
if (empty($order_ids_to_update)) {
    echo "<script>alert('Tidak ada resi yang perlu dicetak.'); window.close();</script>";
    exit;
}

// Output PDF ke browser
$pdf->Output($filename, 'I');

// Update status pesanan di database SETELAH PDF berhasil dibuat
$ids_string = implode(',', $order_ids_to_update);
$conn->query("UPDATE orders SET status = 'processed' WHERE id IN ($ids_string)");

// Kirim notifikasi ke user
foreach ($order_ids_to_update as $id) {
    $user_id_res = $conn->query("SELECT user_id FROM orders WHERE id = $id")->fetch_assoc()['user_id'];
    $message = "Pesanan #WK{$id} sedang kami proses dan siapkan untuk pengiriman.";
    send_notification($conn, $user_id_res, $message);
}
?>