<?php
// File: admin/pesanan/cetak_resi.php

include '../../config/config.php';
include '../../sistem/sistem.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

check_admin();
load_settings($conn);

$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
$store_phone = get_setting($conn, 'store_phone') ?? '-';

// --- SETUP QUERY ---
$action = $_GET['action'] ?? null;
$order_id = (int)($_GET['order_id'] ?? 0);
$status = $_GET['status'] ?? null;

$orders_to_print = [];
$order_ids_to_update = []; 
$filename = 'resi_pengiriman.pdf';

$sql_where = "";
$sql_params = [];
$sql_types = "";

// Logika Filter
if ($action === 'print_all_and_process') {
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";
} elseif ($action === 'print_single_and_process' && $order_id > 0) {
    $filename = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ? AND status = 'belum_dicetak'";
    $sql_params[] = $order_id;
    $sql_types = "i";
} elseif ($order_id > 0) {
    $filename = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ?";
    $sql_params[] = $order_id;
    $sql_types = "i";
} elseif ($status === 'belum_dicetak') {
    $filename = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";
} elseif ($status === 'processed') {
    $filename = 'semua_resi_diproses_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'processed'";
} else {
    die('Parameter tidak valid.');
}

// 1. AMBIL PESANAN
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
        if ($action === 'print_all_and_process' || $action === 'print_single_and_process') {
            $order_ids_to_update[] = $order['id'];
        }
    }
}
$stmt_fetch->close();

// 2. UPDATE STATUS (Jika Perlu)
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
        die("Gagal update status: " . $e->getMessage());
    }
}

// 3. GENERATE HTML
if (empty($orders_to_print)) {
    die("Tidak ada resi untuk dicetak.");
}

$template_html_path = __DIR__ . '/resi_template.html';
if (!file_exists($template_html_path)) {
    die("Error: File template resi tidak ditemukan.");
}
$template_html = file_get_contents($template_html_path);
$all_receipts_html = '';
$printed_at_wib = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$printed_at_label = $printed_at_wib->format('d M Y H:i:s') . ' WIB';

foreach ($orders_to_print as $order) {
    // 4. AMBIL ITEM PESANAN DENGAN VARIASI (LOGIKA PERBAIKAN)
    // FIX: Menggunakan LEFT JOIN ke product_variations sebagai fallback di CATCH block juga
    try {
        $stmt_items = $conn->prepare("
            SELECT oi.*, p.name, 
                   COALESCE(oi.variation_name, pv.name) as final_variation_name
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id 
            LEFT JOIN product_variations pv ON oi.variation_id = pv.id
            WHERE oi.order_id = ?
        ");
        if(!$stmt_items) throw new Exception("Query error");
        $stmt_items->bind_param("i", $order['id']);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        $order_items = $result_items->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();
    } catch (Exception $e) {
        // Fallback Query Darurat: Tetap JOIN Variasi agar data muncul
        $stmt_items = $conn->prepare("
            SELECT oi.*, p.name, pv.name as variation_name 
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id 
            LEFT JOIN product_variations pv ON oi.variation_id = pv.id 
            WHERE oi.order_id = ?
        ");
        $stmt_items->bind_param("i", $order['id']);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        $order_items = $result_items->fetch_all(MYSQLI_ASSOC);
        $stmt_items->close();
    }
    $items_html = '';
    $no = 1;
    foreach ($order_items as $item) {
        $product_name_display = htmlspecialchars_decode($item['name'], ENT_QUOTES);
        
        // GABUNGKAN NAMA VARIASI JIKA ADA (Prioritas: final_variation_name -> variation_name)
        $var_name = $item['final_variation_name'] ?? $item['variation_name'] ?? '';
        
        if (!empty($var_name)) {
            // PERBAIKAN FORMAT DISINI SESUAI REQUEST: (variasi : "nama variasi")
            $product_name_display .= ' (variasi : "' . htmlspecialchars_decode($var_name, ENT_QUOTES) . '")';
        }
        
        $items_html .= '<tr>
            <td style="text-align: left; padding: 4px; font-size: 9pt; border-top: 1px solid #eee;">' . $no++ . '</td>
            <td style="text-align: left; padding: 4px; font-size: 9pt; border-top: 1px solid #eee; word-wrap: break-word; overflow-wrap: break-word;">' . $product_name_display . '</td>
            <td style="text-align: center; padding: 4px; font-size: 9pt; border-top: 1px solid #eee;">' . $item['quantity'] . '</td>
        </tr>';
    }

    $full_address = htmlspecialchars_decode($order['address_line_1'], ENT_QUOTES) . 
                    (!empty($order['address_line_2']) ? ', ' . htmlspecialchars_decode($order['address_line_2'], ENT_QUOTES) : '') . 
                    ', ' . htmlspecialchars_decode($order['subdistrict'], ENT_QUOTES) . 
                    ', ' . htmlspecialchars_decode($order['city'], ENT_QUOTES) . 
                    ', ' . htmlspecialchars_decode($order['province'], ENT_QUOTES) . ' ' . htmlspecialchars_decode($order['postal_code'], ENT_QUOTES);
    
    $full_address = str_replace(["\r\n", "\r", "\n"], ' ', $full_address);
    $customer_note = trim((string)($order['customer_note'] ?? ''));
    if ($customer_note === '') {
        $customer_note = '-';
    }
    $customer_note = str_replace(["\r\n", "\r", "\n"], ' ', htmlspecialchars_decode($customer_note, ENT_QUOTES));

    $placeholders = [
        '{{NAMA_PENGIRIM}}'    => htmlspecialchars_decode($store_name, ENT_QUOTES),
        '{{HP_PENGIRIM}}'      => htmlspecialchars_decode($store_phone, ENT_QUOTES),
        '{{PENERIMA_NAMA}}'    => htmlspecialchars_decode(strtoupper($order['full_name']), ENT_QUOTES),
        '{{PENERIMA_HP}}'      => htmlspecialchars_decode($order['phone_number'], ENT_QUOTES),
        '{{PENERIMA_ALAMAT}}'  => $full_address,
        '{{CUSTOMER_NOTE}}'    => $customer_note,
        '{{ORDER_NUMBER}}'     => htmlspecialchars_decode($order['order_number'], ENT_QUOTES),
        '{{PRINTED_AT}}'      => htmlspecialchars($printed_at_label),
        '{{PRODUK_ITEMS}}'     => $items_html,
    ];
    
    $receipt_html = str_replace(array_keys($placeholders), array_values($placeholders), $template_html);
    $all_receipts_html .= '<div class="receipt-wrapper">' . $receipt_html . '</div>';
}

$conn->close();

// 5. RENDER PDF
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');
$options->set('enable_font_subsetting', true); 
$options->set('dpi', 96); 

$dompdf = new Dompdf($options);

$final_html = '
<style>
    .receipt-wrapper { page-break-before: auto; }
    .receipt-wrapper + .receipt-wrapper { page-break-before: always; }
</style>
' . $all_receipts_html;

$dompdf->loadHtml($final_html);
$custom_paper = array(0, 0, 4.72 * 72, 7.35 * 72);
$dompdf->setPaper($custom_paper);
$dompdf->render();
$dompdf->stream($filename, ["Attachment" => false]);
?>





