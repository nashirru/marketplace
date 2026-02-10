<?php
// File: rekap/export_laporan.php
// Skrip ini menangani pembuatan file Excel atau PDF.

// Sesuaikan path ini 2 level ke atas (rekap -> admin -> root)
require_once '../../config/config.php';
require_once '../../sistem/sistem.php';

// PENTING: Sertakan autoloader Composer
$autoloader = '../../vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Error: Composer autoloader not found. Please run 'composer install' in your project root.");
}
require_once $autoloader;

// Gunakan library
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

// Pastikan admin yang login
check_admin();

// --- Ambil Data POST (General) ---
$export_format = $_POST['export_format'] ?? 'excel';
$report_type = $_POST['report_type'] ?? 'pesanan';
$start_date = $_POST['start_date'] ?? date('Y-m-d', strtotime('-29 days'));
$end_date = $_POST['end_date'] ?? date('Y-m-d');

// --- Ambil Opsi General ---
$include_address = isset($_POST['include_address']) && $_POST['include_address'] == '1';
$include_chart = isset($_POST['include_chart']) && $_POST['include_chart'] == '1';
$chart_image_base64 = $_POST['chart_image_base64'] ?? '';
$include_summary = isset($_POST['include_summary']) && $_POST['include_summary'] == '1';
$hide_financial = isset($_POST['hide_financial']) && $_POST['hide_financial'] == '1';

// --- Ambil Opsi Spesifik (Tergantung report_type) ---
$order_status_filter = $_POST['order_status'] ?? [];
$hide_product_id = isset($_POST['hide_product_id']) && $_POST['hide_product_id'] == '1';
$group_by = $_POST['group_by'] ?? 'none';

// --- PERBAIKAN MEMORI PDF: Tentukan limit ---
$pdf_row_limit = 500; // TURUNKAN LIMIT: 1000 masih terlalu berat untuk Dompdf
$is_pdf_limited = false; // Flag untuk menampilkan peringatan

// --- Persiapan SQL ---
$start_sql = $start_date . ' 00:00:00';
$end_sql = $end_date . ' 23:59:59';

$data_laporan = [];
$total_omzet = 0;
$total_pesanan = 0; // Berarti: Total order / Total Kategori / Total Produk Unik
$total_produk_terjual = 0; // Berarti: Total kuantitas barang
$full_data_count = 0; // Untuk menyimpan jumlah data asli sebelum di-slice

// --- FUNGSI HELPER (Tetap sama) ---
if (!function_exists('format_rupiah')) {
    function format_rupiah($angka) { return 'Rp ' . number_format($angka, 0, ',', '.'); }
}
function format_rupiah_excel($angka) { return (float) $angka; }
if (!function_exists('format_tanggal_indonesia')) {
    function format_tanggal_indonesia($tanggal) {
        if (empty($tanggal) || $tanggal == '0000-00-00 00:00:00') return '-';
        $bulan = [ 1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember' ];
        $pecah = explode(' ', $tanggal)[0]; $pecah = explode('-', $pecah);
        return $pecah[2] . ' ' . $bulan[(int)$pecah[1]] . ' ' . $pecah[0];
    }
}
function format_tanggal_excel($tanggal) {
    if (empty($tanggal) || $tanggal == '0000-00-00 00:00:00') return '-';
    return \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($tanggal);
}

// --- PENGAMBILAN DATA (Logika Baru) ---
try {
    if ($report_type == 'pesanan') {
        $title = "Laporan Pesanan";
        $status_list_sql = "";
        $params_types = "ss";
        $params_values = [$start_sql, $end_sql];

        if (!empty($order_status_filter)) {
            $placeholders = implode(',', array_fill(0, count($order_status_filter), '?'));
            $status_list_sql = "AND o.status IN ($placeholders)";
            $params_types .= str_repeat('s', count($order_status_filter));
            $params_values = array_merge($params_values, $order_status_filter);
        } else {
             $status_list_sql = "AND 1=0"; 
        }
        
        // --- PERBAIKAN MEMORI PDF: Hapus LIMIT dari SQL ---
        $sql = "SELECT 
                    o.*, 
                    u.name as user_name, 
                    u.email as user_email
                FROM orders o
                JOIN users u ON o.user_id = u.id
                WHERE o.created_at BETWEEN ? AND ?
                $status_list_sql
                ORDER BY o.created_at ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($params_types, ...$params_values);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data_laporan[] = $row;
            if (!$hide_financial) {
                $total_omzet += (float)$row['total'];
            }
        }
        $total_pesanan = $result->num_rows; 
        $full_data_count = $total_pesanan;
        $stmt->close();

    } else { // 'produk'
        $title = "Laporan Penjualan Produk";
        $params_types = "ss";
        $params_values = [$start_sql, $end_sql];
        $valid_order_status = "('belum_dicetak', 'processed', 'shipped', 'completed')";

        if ($group_by == 'category') {
            $title .= " (Per Kategori)";
            $sql = "SELECT 
                        c.name as category_name,
                        SUM(oi.quantity) as total_quantity,
                        SUM(oi.price * oi.quantity) as subtotal_omzet
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    JOIN categories c ON p.category_id = c.id
                    JOIN orders o ON oi.order_id = o.id
                    WHERE o.created_at BETWEEN ? AND ?
                      AND o.status IN $valid_order_status
                    GROUP BY c.id, c.name
                    ORDER BY total_quantity DESC";
        } else { // 'none'
            $title .= " (Per Produk)";
            $sql = "SELECT 
                        p.id as product_id,
                        p.name as product_name,
                        p.price as product_price,
                        SUM(oi.quantity) as total_quantity,
                        SUM(oi.price * oi.quantity) as subtotal_omzet
                    FROM order_items oi
                    JOIN products p ON oi.product_id = p.id
                    JOIN orders o ON oi.order_id = o.id
                    WHERE o.created_at BETWEEN ? AND ?
                      AND o.status IN $valid_order_status
                    GROUP BY p.id, p.name, p.price
                    ORDER BY total_quantity DESC";
        }
        
        // --- PERBAIKAN MEMORI PDF: Hapus LIMIT dari SQL ---
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($params_types, ...$params_values);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $data_laporan[] = $row;
            if (!$hide_financial) {
                $total_omzet += (float)$row['subtotal_omzet'];
            }
            $total_produk_terjual += (int)$row['total_quantity'];
        }
        $total_pesanan = count($data_laporan);
        $full_data_count = $total_pesanan;
        $stmt->close();
    }
    
    // --- PERBAIKAN MEMORI PDF: Slice array SETELAH dapat total ---
    if ($export_format == 'pdf' && count($data_laporan) > $pdf_row_limit) {
        $is_pdf_limited = true; // Set flag untuk peringatan
        $data_laporan = array_slice($data_laporan, 0, $pdf_row_limit);
        
        // PENTING: Hitung ulang total omzet & produk HANYA untuk data yang ditampilkan di PDF
        if (!$hide_financial) {
            $total_omzet = 0;
            $total_produk_terjual = 0; // Hanya untuk produk
            
            foreach ($data_laporan as $row) {
                if ($report_type == 'pesanan') {
                    $total_omzet += (float)$row['total'];
                } else {
                    $total_omzet += (float)$row['subtotal_omzet'];
                    if ($group_by != 'category') {
                         $total_produk_terjual += (int)$row['total_quantity'];
                    }
                }
            }
             if ($report_type == 'produk' && $group_by == 'category'){
                // Jika per kategori, $total_produk_terjual harus dihitung ulang dari data yg di-slice
                $total_produk_terjual = 0;
                foreach($data_laporan as $row){
                    $total_produk_terjual += (int)$row['total_quantity'];
                }
            }
        }
        // $total_pesanan (jumlah baris) akan di-update di summary box PDF
        $total_pesanan = count($data_laporan); 
    }

} catch (Exception $e) {
    die("Gagal mengambil data: " . $e.getMessage());
}
$conn->close();

// --- TENTUKAN NAMA FILE ---
$filename_base = "Laporan_{$report_type}_" . date('Y-m-d');

// =========================================================================
// --- LOGIKA EKSPOR EXCEL (.xlsx) ---
// =========================================================================
if ($export_format == 'excel') {
    // ... (LOGIKA EXCEL ANDA TIDAK SAYA UBAH, SUDAH BENAR) ...
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $current_row = 1;

    // --- Styling ---
    $style_title = [ 'font' => ['bold' => true, 'size' => 16], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $style_subtitle = [ 'font' => ['bold' => false, 'size' => 12], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $style_summary_header = [ 'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4A5568']] ];
    $style_summary_value = [ 'font' => ['bold' => true, 'size' => 14], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $style_table_header = [ 'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D3748']], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER] ];
    $format_rupiah_excel = 'Rp #,##0';
    $format_number_excel = '#,##0';
    $format_tanggal_excel_style = 'dd/mm/yyyy';

    // --- Judul ---
    $sheet->mergeCells('A1:F1');
    $sheet->setCellValue('A1', $title);
    $sheet->getStyle('A1')->applyFromArray($style_title);
    
    $sheet->mergeCells('A2:F2');
    $sheet->setCellValue('A2', "Periode: " . format_tanggal_indonesia($start_date) . " s/d " . format_tanggal_indonesia($end_date));
    $sheet->getStyle('A2')->applyFromArray($style_subtitle);
    $sheet->getRowDimension(2)->setRowHeight(20);
    $current_row = 3;

    // --- Ringkasan (Summary) ---
    if ($include_summary) {
        $current_row = 4;
        $summary_col_index = 2; // Mulai dari kolom B
        
        if (!$hide_financial) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '4', 'Total Omzet');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '5', format_rupiah_excel($total_omzet));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '4')->applyFromArray($style_summary_header)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '5')->applyFromArray($style_summary_value)->getNumberFormat()->setFormatCode($format_rupiah_excel);
            $summary_col_index += 2; // Geser 2 kolom
        }
        
        $summary_label = 'Total Pesanan';
        if ($report_type == 'produk') {
            $summary_label = ($group_by == 'category') ? 'Total Kategori' : 'Jenis Produk Terjual';
        }
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '4', $summary_label);
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '5', (int)$total_pesanan); // Ini total asli
        $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '4')->applyFromArray($style_summary_header)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '5')->applyFromArray($style_summary_value)->getNumberFormat()->setFormatCode($format_number_excel);
        $summary_col_index += 2;
        
        if ($report_type == 'produk') {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '4', 'Total Produk Terjual');
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($summary_col_index) . '5', (int)$total_produk_terjual); // Ini total asli
            $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '4')->applyFromArray($style_summary_header)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle(Coordinate::stringFromColumnIndex($summary_col_index) . '5')->applyFromArray($style_summary_value)->getNumberFormat()->setFormatCode($format_number_excel);
        }
        
        $sheet->getRowDimension(4)->setRowHeight(20);
        $sheet->getRowDimension(5)->setRowHeight(20);
        $current_row = 7;
    } else {
        $current_row = 4;
    }

    // --- Header Tabel ---
    $row_start_tabel = $current_row;
    $col_index = 1;
    $headers = [];
    
    if ($report_type == 'pesanan') {
        $headers = ['No.', 'Tanggal', 'No. Pesanan', 'Pelanggan', 'Email'];
        if ($include_address) $headers[] = 'Alamat Pengiriman';
        $headers[] = 'Status';
        if (!$hide_financial) $headers[] = 'Total';
    } else { // 'produk'
        if ($group_by == 'category') {
            $headers = ['No.', 'Nama Kategori'];
            $headers[] = 'Total Terjual';
            if (!$hide_financial) $headers[] = 'Total Omzet';
        } else {
            $headers = ['No.'];
            if (!$hide_product_id) $headers[] = 'ID Produk';
            $headers[] = 'Nama Produk';
            if (!$hide_financial) $headers[] = 'Harga Satuan';
            $headers[] = 'Total Terjual';
            if (!$hide_financial) $headers[] = 'Total Omzet';
        }
    }
    
    foreach($headers as $header) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $row_start_tabel, $header);
    }
    $last_col_index = count($headers);
    $last_col_letter = Coordinate::stringFromColumnIndex($last_col_index);
    $sheet->getStyle("A{$row_start_tabel}:{$last_col_letter}{$row_start_tabel}")->applyFromArray($style_table_header);

    // --- Isi Data ---
    $current_row = $row_start_tabel + 1;
    $no = 1;
    foreach ($data_laporan as $row) {
        $col_index = 1;
        
        if ($report_type == 'pesanan') {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $no++);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, format_tanggal_excel($row['created_at']));
            $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_tanggal_excel_style);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['order_number']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['user_name']);
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['user_email']);
            if ($include_address) {
                $alamat_lengkap = "{$row['full_name']} ({$row['phone_number']})\n{$row['address_line_1']}\n{$row['subdistrict']}, {$row['city']}\n{$row['province']}, {$row['postal_code']}";
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $alamat_lengkap);
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index - 1) . $current_row)->getAlignment()->setWrapText(true);
            }
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, ucfirst(str_replace('_', ' ', $row['status'])));
            if (!$hide_financial) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, format_rupiah_excel($row['total']));
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_rupiah_excel);
            }
        } else { // 'produk'
            if ($group_by == 'category') {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $no++);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['category_name']);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, (int)$row['total_quantity']);
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_number_excel);
                if (!$hide_financial) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, format_rupiah_excel($row['subtotal_omzet']));
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_rupiah_excel);
                }
            } else {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $no++);
                if (!$hide_product_id) $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['product_id']);
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index++) . $current_row, $row['product_name']);
                if (!$hide_financial) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, format_rupiah_excel($row['product_price']));
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_rupiah_excel);
                }
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, (int)$row['total_quantity']);
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_number_excel);
                if (!$hide_financial) {
                    $sheet->setCellValue(Coordinate::stringFromColumnIndex($col_index) . $current_row, format_rupiah_excel($row['subtotal_omzet']));
                    $sheet->getStyle(Coordinate::stringFromColumnIndex($col_index++) . $current_row)->getNumberFormat()->setFormatCode($format_rupiah_excel);
                }
            }
        }
        $current_row++;
    }

    // --- Auto Size Kolom ---
    for ($i = 1; $i <= $last_col_index; $i++) {
        $col_letter = Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col_letter)->setAutoSize(true);
    }
    if ($report_type == 'pesanan' && $include_address) {
        $col_letter_alamat = Coordinate::stringFromColumnIndex(array_search('Alamat Pengiriman', $headers) + 1);
        $sheet->getColumnDimension($col_letter_alamat)->setAutoSize(false);
        $sheet->getColumnDimension($col_letter_alamat)->setWidth(40);
    }

    // --- Set Header dan Kirim File ---
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// =========================================================================
// --- LOGIKA EKSPOR PDF (.pdf) ---
// =========================================================================

elseif ($export_format == 'pdf') {

    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title><?= $title ?></title>
        <style>
            body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 12px; color: #333; box-sizing: border-box; }
            .container { width: 98%; margin: 10px auto; }
            h1 { text-align: center; margin-bottom: 5px; font-size: 20px; color: #2D3748; }
            .info { text-align: center; font-size: 12px; margin-bottom: 20px; color: #555; }
            
            /* --- PERBAIKAN MEMORI PDF: CSS Peringatan --- */
            .warning-box {
                background-color: #FFFBEB;
                color: #B45309;
                border: 1px solid #FDE68A;
                padding: 15px;
                margin-bottom: 20px;
                text-align: center;
                font-size: 12px;
                border-radius: 8px;
            }
            
            .summary-table { width: 100%; border-collapse: collapse; border: 1px solid #e2e8f0; background-color: #f7fafc; margin-bottom: 20px; border-radius: 8px; overflow: hidden; }
            .summary-table td { text-align: center; padding: 15px 10px; box-sizing: border-box; vertical-align: top; }
            .summary-table td h3 { margin: 0 0 8px 0; font-size: 13px; color: #718096; text-transform: uppercase; font-weight: bold; }
            .summary-table td p { margin: 5px 0 0 0; font-size: 18px; font-weight: bold; color: #2C5282; }
            .border-left { border-left: 1px solid #e2e8f0; }
            .border-right { border-right: 1px solid #e2e8f0; }
            
            table.data-table { 
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                font-size: 10px;
                /* --- PERBAIKAN MEMORI PDF: Hapus table-layout: fixed; --- */
                /* table-layout: fixed; */
            }
            table.data-table th, table.data-table td { border: 1px solid #e2e8f0; padding: 8px; text-align: left; vertical-align: top; word-wrap: break-word; }
            table.data-table th { background-color: #4A5568; color: #FFFFFF; font-weight: bold; text-transform: uppercase; font-size: 10px; padding: 10px 8px; }
            table.data-table tr:nth-child(even) { background-color: #f7fafc; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .chart-container { text-align: center; margin-bottom: 20px; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; }
            .chart-container h3 { font-size: 16px; color: #2D3748; margin-bottom: 15px; }
            .chart-container img { max-width: 100%; height: auto; }
            .address { font-size: 9px; line-height: 1.4; word-wrap: break-word; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1><?= $title ?></h1>
            <div class="info">
                Periode: <?= format_tanggal_indonesia($start_date) ?> s/d <?= format_tanggal_indonesia($end_date) ?>
                <br>Dicetak pada: <?= format_tanggal_indonesia(date('Y-m-d')) . ' ' . date('H:i:s') ?>
            </div>
            
            <!-- --- PERBAIKAN MEMORI PDF: Tampilkan Peringatan jika data di-limit --- -->
            <?php if ($is_pdf_limited): ?>
                <div class="warning-box">
                    <strong>PERINGATAN:</strong> Data yang ditampilkan untuk PDF ini telah dibatasi hingga <strong><?= $pdf_row_limit ?> baris pertama</strong>
                    untuk mencegah error memori. Total di Ringkasan juga disesuaikan dengan data yang tampil.
                    <br><strong>Gunakan Ekspor Excel untuk melihat data lengkap (<?= $full_data_count ?> baris) dan total keseluruhan.</strong>
                </div>
            <?php endif; ?>


            <?php if ($report_type == 'pesanan' && $export_format == 'pdf' && $include_chart && !empty($chart_image_base64) && !$hide_financial): ?>
                <div class="chart-container">
                    <h3>Grafik Omzet Pesanan</h3>
                    <img src="<?= $chart_image_base64 ?>">
                </div>
            <?php endif; ?>

            <?php if ($include_summary): ?>
            <table class="summary-table">
                <tr>
                    <?php 
                        // Hitung kolom summary
                        $summary_cols = 0;
                        if (!$hide_financial) $summary_cols++;
                        if ($report_type == 'pesanan') $summary_cols++;
                        else $summary_cols += 2; 
                        
                        $width_percentage = $summary_cols > 0 ? (100 / $summary_cols) : 100;
                    ?>
                    <?php if (!$hide_financial): ?>
                        <td style="width: <?= $width_percentage ?>%;">
                            <h3>Total Omzet</h3>
                            <!-- Total ini adalah total dari data yang di-slice -->
                            <p><?= format_rupiah($total_omzet) ?></p> 
                        </td>
                    <?php endif; ?>
                    <?php 
                        $summary_label = 'Total Pesanan';
                        if ($report_type == 'produk') {
                            $summary_label = ($group_by == 'category') ? 'Total Kategori' : 'Jenis Produk Terjual';
                        }
                    ?>
                    <td class="border-left" style="width: <?= $width_percentage ?>%;">
                        <h3><?= $summary_label ?></h3>
                        <!-- $total_pesanan adalah count dari data yang di-slice -->
                        <p><?= number_format($total_pesanan, 0, ',', '.') ?><?= $is_pdf_limited ? '+' : '' ?></p>
                    </td>
                    <?php if ($report_type == 'produk'): ?>
                        <td class="border-left" style="width: <?= $width_percentage ?>%;">
                            <h3>Total Produk Terjual</h3>
                             <!-- $total_produk_terjual adalah sum dari data yang di-slice -->
                            <p><?= number_format($total_produk_terjual, 0, ',', '.') ?><?= $is_pdf_limited ? '+' : '' ?></p>
                        </td>
                    <?php endif; ?>
                </tr>
            </table>
            <?php endif; ?>

            <table class="data-table">
                <thead>
                    <tr>
                    <?php 
                    // Buat $headers untuk PDF
                    if ($report_type == 'pesanan') {
                        $headers = ['No.', 'Tanggal', 'No. Pesanan', 'Pelanggan', 'Email'];
                        if ($include_address) $headers[] = 'Alamat Pengiriman';
                        $headers[] = 'Status';
                        if (!$hide_financial) $headers[] = 'Total';
                    } else { // 'produk'
                        if ($group_by == 'category') {
                            $headers = ['No.', 'Nama Kategori'];
                            $headers[] = 'Total Terjual';
                            if (!$hide_financial) $headers[] = 'Total Omzet';
                        } else {
                            $headers = ['No.'];
                            if (!$hide_product_id) $headers[] = 'ID Produk';
                            $headers[] = 'Nama Produk';
                            if (!$hide_financial) $headers[] = 'Harga Satuan';
                            $headers[] = 'Total Terjual';
                            if (!$hide_financial) $headers[] = 'Total Omzet';
                        }
                    }
                    ?>
                    <?php foreach ($headers as $header): ?>
                        <th class="text-center"><?= $header ?></th>
                    <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data_laporan)): ?>
                        <tr>
                            <td colspan="<?= count($headers) ?>" class="text-center">
                                Tidak ada data untuk kriteria ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($data_laporan as $row): ?>
                            <tr>
                                <?php if ($report_type == 'pesanan'): ?>
                                    <td class="text-center"><?= $no++ ?></td>
                                    <td><?= format_tanggal_indonesia($row['created_at']) ?></td>
                                    <td><?= htmlspecialchars($row['order_number']) ?></td>
                                    <td><?= htmlspecialchars($row['user_name']) ?><br><small><?= htmlspecialchars($row['user_email']) ?></small></td>
                                    <?php if ($include_address): ?>
                                        <td class="address">
                                            <b><?= htmlspecialchars($row['full_name']) ?></b> (<?= htmlspecialchars($row['phone_number']) ?>)<br>
                                            <?= htmlspecialchars($row['address_line_1']) ?><br>
                                            <?= htmlspecialchars($row['subdistrict']) ?>, <?= htmlspecialchars($row['city']) ?><br>
                                            <?= htmlspecialchars($row['province']) ?>, <?= htmlspecialchars($row['postal_code']) ?>
                                        </td>
                                    <?php endif; ?>
                                    <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $row['status']))) ?></td>
                                    <?php if (!$hide_financial): ?>
                                        <td class="text-right"><?= format_rupiah($row['total']) ?></td>
                                    <?php endif; ?>
                                <?php else: // 'produk' ?>
                                    <?php if ($group_by == 'category'): ?>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($row['category_name']) ?></td>
                                        <td class="text-center"><?= number_format($row['total_quantity'], 0, ',', '.') ?></td>
                                        <?php if (!$hide_financial): ?>
                                            <td class="text-right"><?= format_rupiah($row['subtotal_omzet']) ?></td>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <td class="text-center"><?= $no++ ?></td>
                                        <?php if (!$hide_product_id): ?>
                                            <td class="text-center"><?= htmlspecialchars($row['product_id']) ?></td>
                                        <?php endif; ?>
                                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                                        <?php if (!$hide_financial): ?>
                                            <td class="text-right"><?= format_rupiah($row['product_price']) ?></td>
                                        <?php endif; ?>
                                        <td class="text-center"><?= number_format($row['total_quantity'], 0, ',', '.') ?></td>
                                        <?php if (!$hide_financial): ?>
                                            <td class="text-right"><?= format_rupiah($row['subtotal_omzet']) ?></td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </body>
    </html>
    <?php
    $html_content = ob_get_clean();

    // --- Dompdf Generation ---
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html_content);
    
    $orientation = 'portrait';
    if ($report_type == 'pesanan' && $include_address) {
        $orientation = 'landscape';
    } else if ($report_type == 'produk' && !$hide_financial && $group_by == 'none') {
        $orientation = 'landscape';
    }
    
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();
    $dompdf->stream($filename_base . '.pdf', ['Attachment' => 0]);
    exit;
}
?>