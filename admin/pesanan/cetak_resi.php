<?php
// File: admin/pesanan/cetak_resi.php
include '../../config/config.php';
include '../../sistem/sistem.php';
check_admin();
load_settings($conn);

// Load TCPDF
$tcpdf_path = $_SERVER['DOCUMENT_ROOT'] . BASE_URL . '/vendor/tecnickcom/tcpdf/tcpdf.php';

if (strpos($tcpdf_path, 'C:\\xampp') !== false) {
    $local_path = dirname(__DIR__, 3) . '/vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($local_path)) {
        require_once($local_path);
    } else {
         die("Error Kritis: Library TCPDF tidak ditemukan. Path: " . htmlspecialchars($local_path));
    }
} elseif (file_exists($tcpdf_path)) {
    require_once($tcpdf_path);
} else {
    die("Error Kritis: Library TCPDF tidak ditemukan. Pastikan file ada di: " . htmlspecialchars($tcpdf_path));
}

// Ambil informasi toko dari settings
$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
$store_phone = get_setting($conn, 'store_phone') ?? '-';
$store_address = get_setting($conn, 'store_address') ?? '-';
$store_address_clean = str_replace(["\r\n", "\n", "\r"], ', ', $store_address); // Membuat alamat jadi satu baris

// Fungsi untuk membuat konten resi dengan styling monokrom dan rapi
function generate_receipt_content($order, $order_items, $store_name, $store_phone, $store_address_clean) {
    $items_html = '';
    $no = 1;
    
    foreach ($order_items as $item) {
        // Hapus background stripe, fokus pada border tabel
        $items_html .= '<tr>
            <td style="padding: 2px 2px; font-size: 8pt; width: 10%; border-right: 1px dashed #000;">' . $no++ . '</td>
            <td style="padding: 2px 2px; font-size: 8pt; width: 70%; border-right: 1px dashed #000;">' . htmlspecialchars($item['name']) . '</td>
            <td style="padding: 2px 2px; text-align: center; font-size: 8pt; width: 20%;">' . $item['quantity'] . '</td>
        </tr>';
    }

    $recipient_address_line1 = htmlspecialchars($order['address_line_1']);
    if (!empty($order['address_line_2'])) {
        $recipient_address_line1 .= ', ' . htmlspecialchars($order['address_line_2']);
    }
    // Pisahkan detail wilayah untuk menghindari tumpang tindih
    $recipient_address_line2 = htmlspecialchars($order['subdistrict']) . ', ' . htmlspecialchars($order['city']) . ', ' . htmlspecialchars($order['province']) . ' ' . htmlspecialchars($order['postal_code']);

    $total_formatted = format_rupiah($order['total']);
    
    // START HTML CONTENT (Monokrom Final)
    $html = '<style>
        .resi-container {
            padding: 0px; 
            font-family: helvetica;
        }
        .header-section {
            border-bottom: 2px solid #000;
            padding-bottom: 3px;
            margin-bottom: 5px;
        }
        .section-title {
            font-size: 7pt; /* Sedikit lebih kecil agar lebih efisien */
            font-weight: bold;
            color: #000;
            margin-bottom: 2px;
            padding-top: 2px;
            border-top: 1px solid #000; /* Garis solid tipis */
        }
        .info-block {
            line-height: 1.1;
        }
    </style>
    
    <div class="resi-container">
        <!-- HEADER / RESI INFO -->
        <div class="header-section">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td width="60%" style="vertical-align: top;">
                        <span style="font-size: 9pt; font-weight: bold;">RESI PENGIRIMAN</span><br>
                        <span style="font-size: 14pt; font-weight: bold; color: #000;">#' . htmlspecialchars($order['order_number']) . '</span>
                    </td>
                    <td width="40%" style="text-align: right; vertical-align: top;">
                        <p style="margin: 0; font-size: 9pt; font-weight: bold;">' . htmlspecialchars($store_name) . '</p>
                        <p style="margin: 2px 0; font-size: 7pt; color: #000;">' . date('d/m/Y H:i', strtotime($order['created_at'])) . '</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- PENGIRIM & PENERIMA SIDE BY SIDE -->
        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 5px;">
            <tr>
                <!-- PENGIRIM (45%) -->
                <td width="45%" style="vertical-align: top; padding-right: 3px; border-right: 1px dashed #000;">
                    <div class="section-title">PENGIRIM (DARI)</div>
                    <div class="info-block">
                        <span style="font-size: 7pt; font-weight: bold;">' . htmlspecialchars($store_name) . '</span><br>
                        <span style="font-size: 6pt;">' . $store_address_clean . '</span><br>
                        <span style="font-size: 6pt;">Tlp: ' . htmlspecialchars($store_phone) . '</span>
                    </div>
                </td>
                
                <!-- PENERIMA (55%) - DIBUAT JELAS -->
                <td width="55%" style="vertical-align: top; padding-left: 5px;">
                    <div class="section-title">PENERIMA (TUJUAN)</div>
                    <div style="border: 2px solid #000; padding: 3px; line-height: 1.2;">
                        <span style="font-size: 8pt; font-weight: bold;">' . strtoupper(htmlspecialchars($order['full_name'])) . '</span><br>
                        <span style="font-size: 7pt;">' . $recipient_address_line1 . '</span><br>
                        <span style="font-size: 6pt;">' . $recipient_address_line2 . '</span><br>
                        <span style="font-size: 7pt; font-weight: bold;">Tlp : ' . htmlspecialchars($order['phone_number']) . '</span> <!-- PERBAIKAN DI SINI -->
                    </div>
                </td>
            </tr>
        </table>

        <!-- DETAIL PRODUK -->
        <div class="section-title" style="margin-bottom: 3px;">DETAIL PRODUK</div>
        <table width="100%" style="border-collapse: collapse; border: 1px solid #000; margin-bottom: 3px;" cellpadding="0" cellspacing="0">
            <tr style="border-bottom: 1px solid #000;">
                <th style="padding: 4px 2px; text-align: left; font-size: 7pt; width: 10%; border-right: 1px solid #000;">No</th>
                <th style="padding: 4px 2px; text-align: left; font-size: 7pt; width: 70%; border-right: 1px solid #000;">Nama Produk</th>
                <th style="padding: 4px 2px; text-align: center; font-size: 7pt; width: 20%;">Qty</th>
            </tr>
            <tbody style="border-top: 1px solid #000;">' . $items_html . '</tbody>
        </table>

        <!-- FOOTER DENGAN TOTAL (Tidak keluar garis) -->
        <table width="100%" cellpadding="0" cellspacing="0" style="border-top: 2px solid #000; padding-top: 2px;">
            <tr>
                <td width="70%" style="font-size: 6pt; color: #000; vertical-align: bottom; padding: 2px 0;">
                    *Harga total hanya untuk informasi internal toko.<br>
                    Periksa kembali barang sebelum diterima.
                </td>
                <td width="30%" style="text-align: right; vertical-align: bottom;">
                    <div style="border: 1px solid #000; padding: 3px 5px;">
                        <span style="font-size: 7pt; font-weight: bold;">TOTAL</span><br>
                        <span style="font-size: 9pt; font-weight: bold;">' . $total_formatted . '</span>
                    </div>
                </td>
            </tr>
        </table>
        
        <!-- FOOTER KECIL -->
        <div style="text-align: center; margin-top: 3px; padding-top: 2px; border-top: 1px dashed #000;">
            <span style="font-size: 6pt; color: #000;">Terima kasih telah berbelanja di ' . htmlspecialchars($store_name) . '!</span>
        </div>
    </div>';
    
    return $html;
}

// Inisialisasi PDF dengan ukuran custom 90mm x 140mm
$pdf = new TCPDF('P', 'mm', array(90, 140), true, 'UTF-8', false);
$pdf->SetCreator(PDF_CREATOR);
$pdf->SetAuthor($store_name . ' Admin');
$pdf->SetTitle('Resi Pengiriman - ' . $store_name);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
// Margin dikurangi menjadi 3mm di semua sisi untuk memaksimalkan ruang
$pdf->SetMargins(3, 3, 3);
$pdf->SetAutoPageBreak(FALSE); // Nonaktifkan auto page break
$pdf->SetFont('helvetica', '', 9);


$order_ids_to_update = [];
$filename = 'resi_pengiriman.pdf';
$orders_to_print = [];

// Logika untuk cetak berdasarkan status
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
    $filename = 'resi_' . $order_id . '_' . date('Ymd_His') . '.pdf';
    
    $stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $order = $result->fetch_assoc();
        $orders_to_print[] = $order;
    } else {
        die('Resi tidak ditemukan atau pesanan tidak ada.');
    }
    $stmt->close();
} else {
    die('Parameter tidak valid. Gunakan ?order_id=X atau ?status=belum_dicetak');
}

// Jika tidak ada order yang ditemukan
if (empty($orders_to_print)) {
    // FIX: Mengganti window.close() dengan redirect yang lebih aman
    echo "<script>alert('Tidak ada resi yang perlu dicetak.'); window.location.href = '" . BASE_URL . "/admin/admin.php?page=pesanan';</script>";
    exit;
}

// FIX KRITIS: Tambahkan AddPage() sebelum memulai loop konten
$pdf->AddPage(); 

// Generate PDF untuk setiap order
$is_first_page = true;
foreach ($orders_to_print as $order) {
    // Tambah halaman baru untuk setiap resi (kecuali yang pertama)
    if (!$is_first_page) {
        $pdf->AddPage();
    }
    $is_first_page = false;
    
    // Ambil order items
    // NOTE: Query ini rawan terjadi error binding jika order_id tidak ada
    $stmt_items = $conn->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
    $stmt_items->bind_param("i", $order['id']);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    $order_items = [];
    while ($item = $result_items->fetch_assoc()) {
        $order_items[] = $item;
    }
    $stmt_items->close();
    
    // Generate HTML content
    // PASS ALAMAT TOKO YANG SUDAH DI-CLEAN
    $html = generate_receipt_content($order, $order_items, $store_name, $store_phone, $store_address_clean);
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Simpan order_id untuk update status nanti
    // KODE INI TIDAK LAGI DIGUNAKAN UNTUK UPDATE OTOMATIS
    if ($order['status'] == 'belum_dicetak') {
        $order_ids_to_update[] = (int)$order['id'];
    }
}

// Output PDF ke browser
$pdf->Output($filename, 'I');


// =========================================================
// LOGIKA UPDATE STATUS DIHAPUS. STATUS TIDAK BERUBAH OTOMATIS.
// =========================================================
?>