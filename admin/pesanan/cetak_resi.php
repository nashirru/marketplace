<?php
// File: admin/pesanan/cetak_resi.php
// VERSI TERBARU: Layout disesuaikan dengan template PDF baru (Tahoma 10pt)

include '../../config/config.php';
include '../../sistem/sistem.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

check_admin();
load_settings($conn);

$store_name  = get_setting($conn, 'store_name')  ?? 'Warok Kite';
$store_phone = get_setting($conn, 'store_phone') ?? '-';
$store_city  = get_setting($conn, 'store_city')  ?? '';   // opsional: tambah kota pengirim di settings

// --- SETUP QUERY ---
$action   = $_POST['action']   ?? $_GET['action']   ?? null;
$order_id = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
$status   = $_POST['status']   ?? $_GET['status']   ?? null;
$filter_status = $_POST['filter_status'] ?? $_GET['filter_status'] ?? null;
$selected_order_ids_raw = $_POST['order_ids'] ?? $_GET['order_ids'] ?? null;

$orders_to_print    = [];
$order_ids_to_update = [];
$filename = 'resi_pengiriman.pdf';

$sql_where  = "";
$sql_params = [];
$sql_types  = "";

if ($action === 'print_all_and_process') {
    $filename  = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";
} elseif ($action === 'print_selected') {
    $ids = is_array($selected_order_ids_raw) ? $selected_order_ids_raw : [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (empty($ids)) {
        die('Tidak ada pesanan terpilih untuk dicetak.');
    }

    $filename = 'resi_terpilih_' . date('Ymd_His') . '.pdf';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql_where = "WHERE id IN ($placeholders)";
    $sql_params = $ids;
    $sql_types  = str_repeat('i', count($ids));

    // Opsional: batasi status (mis. hanya 'processed')
    if (!empty($filter_status)) {
        $allowed = ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
        if (in_array($filter_status, $allowed, true)) {
            $sql_where .= " AND status = ?";
            $sql_params[] = $filter_status;
            $sql_types .= "s";
        }
    }
} elseif ($action === 'print_single_and_process' && $order_id > 0) {
    $filename  = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ? AND status = 'belum_dicetak'";
    $sql_params[] = $order_id;
    $sql_types    = "i";
} elseif ($order_id > 0) {
    $filename  = 'resi_' . $order_id . '.pdf';
    $sql_where = "WHERE id = ?";
    $sql_params[] = $order_id;
    $sql_types    = "i";
} elseif ($status === 'belum_dicetak') {
    $filename  = 'semua_resi_belum_dicetak_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'belum_dicetak'";
} elseif ($status === 'processed') {
    $filename  = 'semua_resi_diproses_' . date('Ymd_His') . '.pdf';
    $sql_where = "WHERE status = 'processed'";
} else {
    die('Parameter tidak valid.');
}

// 1. AMBIL PESANAN
$sql_fetch   = "SELECT * FROM orders $sql_where ORDER BY created_at ASC";
$stmt_fetch  = $conn->prepare($sql_fetch);
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
        $placeholders  = implode(',', array_fill(0, count($order_ids_to_update), '?'));
        $types_update  = str_repeat('i', count($order_ids_to_update));
        $stmt_update   = $conn->prepare("UPDATE orders SET status = 'processed' WHERE id IN ($placeholders)");
        $stmt_update->bind_param($types_update, ...$order_ids_to_update);
        $stmt_update->execute();
        $stmt_update->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        die("Gagal update status: " . $e->getMessage());
    }
}

// 3. AMBIL SEMUA ITEM PESANAN SEKALIGUS (Optimasi: hindari N+1 query)
$order_ids = array_map(fn($o) => (int)$o['id'], $orders_to_print);
$items_by_order = [];
if (!empty($order_ids)) {
    $placeholders_items = implode(',', array_fill(0, count($order_ids), '?'));
    $types_items = str_repeat('i', count($order_ids));

    // Kompatibilitas schema DB:
    // - Sebagian DB lama tidak punya kolom `order_items.variation_name`
    $has_variation_name_col = false;
    try {
        $col_rs = $conn->query("SHOW COLUMNS FROM order_items LIKE 'variation_name'");
        if ($col_rs && $col_rs->num_rows > 0) {
            $has_variation_name_col = true;
        }
        if ($col_rs) {
            $col_rs->free();
        }
    } catch (Throwable $e) {
        $has_variation_name_col = false;
    }

    $variation_select = $has_variation_name_col
        ? "COALESCE(oi.variation_name, pv.name) AS final_variation_name"
        : "pv.name AS final_variation_name";

    $stmt_items = $conn->prepare("
        SELECT
            oi.order_id,
            oi.quantity,
            p.name AS product_name,
            $variation_select
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        LEFT JOIN product_variations pv ON oi.variation_id = pv.id
        WHERE oi.order_id IN ($placeholders_items)
        ORDER BY oi.order_id ASC, oi.id ASC
    ");
    if (!$stmt_items) {
        die('Gagal menyiapkan query item pesanan.');
    }
    $stmt_items->bind_param($types_items, ...$order_ids);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    while ($row = $result_items->fetch_assoc()) {
        $oid = (int)$row['order_id'];
        if (!isset($items_by_order[$oid])) $items_by_order[$oid] = [];
        $items_by_order[$oid][] = $row;
    }
    $stmt_items->close();
}

// 4. GENERATE HTML
if (empty($orders_to_print)) {
    die("Tidak ada resi untuk dicetak.");
}

$template_html_path = __DIR__ . '/resi_template.html';
if (!file_exists($template_html_path)) {
    die("Error: File template resi tidak ditemukan.");
}
$template_html     = file_get_contents($template_html_path);
$all_receipts_html = '';

foreach ($orders_to_print as $order) {
    $order_id_int = (int)($order['id'] ?? 0);
    $order_items = $items_by_order[$order_id_int] ?? [];
    $item_count = count($order_items);

    // Build baris produk
    $items_html = '';
    $no = 1;
    foreach ($order_items as $item) {
        $product_name_display = htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES, 'UTF-8');

        $var_name = $item['final_variation_name'] ?? '';
        if (!empty($var_name)) {
            $product_name_display .= ' (' . htmlspecialchars($var_name, ENT_QUOTES, 'UTF-8') . ')';
        }

        $items_html .= '<tr>
            <td class="col-no">' . $no++ . '</td>
            <td class="col-produk">' . $product_name_display . '</td>
            <td class="col-qty">' . (int)$item['quantity'] . '</td>
        </tr>';
    }

    // Build alamat lengkap
    $addr_parts = [
        htmlspecialchars($order['address_line_1'] ?? '', ENT_QUOTES, 'UTF-8'),
    ];
    if (!empty($order['address_line_2'])) {
        $addr_parts[] = htmlspecialchars($order['address_line_2'], ENT_QUOTES, 'UTF-8');
    }
    $addr_parts[] = htmlspecialchars($order['subdistrict'] ?? '', ENT_QUOTES, 'UTF-8');
    $addr_parts[] = htmlspecialchars($order['city']        ?? '', ENT_QUOTES, 'UTF-8');
    $addr_parts[] = htmlspecialchars($order['province']    ?? '', ENT_QUOTES, 'UTF-8');

    $postal = trim($order['postal_code'] ?? '');
    if (!empty($postal)) {
        $addr_parts[] = $postal;
    }

    $full_address = implode(', ', array_filter($addr_parts));
    $full_address = str_replace(["\r\n", "\r", "\n"], ' ', $full_address);

    // Format tanggal order
    $order_date_raw  = $order['created_at'] ?? '';
    $order_date_str  = '';
    if (!empty($order_date_raw)) {
        $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        // Format: 31 Mar 2026 11:48:50 WIB
        $bulan_id = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        $m = (int)$dt->format('n');
        $order_date_str = $dt->format('d') . ' ' . $bulan_id[$m - 1] . ' ' . $dt->format('Y H:i:s') . ' WIB';
    }

    // Catatan pesanan
    $catatan = htmlspecialchars(trim($order['customer_note'] ?? $order['notes'] ?? $order['catatan'] ?? ''), ENT_QUOTES, 'UTF-8');
    if (empty($catatan)) {
        $catatan = '-';
    }
    // Jika ada newline dalam catatan, jadikan spasi
    $catatan = str_replace(["\r\n", "\r", "\n"], ' ', $catatan);

    // Nama pengirim: tambahkan kota jika ada
    $pengirim_nama = htmlspecialchars($store_name, ENT_QUOTES, 'UTF-8');
    if (!empty($store_city)) {
        $pengirim_nama .= ' - ' . htmlspecialchars($store_city, ENT_QUOTES, 'UTF-8');
    }

    // Nomor order (tanpa prefix #WK di placeholder utama, sudah ada di template)
    $order_number = htmlspecialchars($order['order_number'], ENT_QUOTES, 'UTF-8');

    $placeholders = [
        '{{NAMA_PENGIRIM}}'   => $pengirim_nama,
        '{{HP_PENGIRIM}}'     => htmlspecialchars($store_phone, ENT_QUOTES, 'UTF-8'),
        '{{PENERIMA_NAMA}}'   => htmlspecialchars(strtoupper($order['full_name']), ENT_QUOTES, 'UTF-8'),
        '{{PENERIMA_HP}}'     => htmlspecialchars($order['phone_number'], ENT_QUOTES, 'UTF-8'),
        '{{PENERIMA_ALAMAT}}' => $full_address,
        '{{ORDER_NUMBER}}'    => $order_number,
        '{{PAGE_VERTICAL}}'  => ($item_count >= 9 ? '<div class="page-vertical">#' . $order_number . '</div>' : ''),
        '{{ORDER_DATE}}'      => $order_date_str,
        '{{CATATAN_PESANAN}}' => $catatan,
        '{{PRODUK_ITEMS}}'    => $items_html,
        // Untuk teks vertikal kanan: '#WK{{ORDER_NUMBER}}' → replace full pattern
        '#WK{{ORDER_NUMBER}}' => '#' . $order_number,
    ];

    $all_receipts_html .= '<div class="receipt-wrapper">'
        . str_replace(array_keys($placeholders), array_values($placeholders), $template_html)
        . '</div>';
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

// Ukuran kertas: lebar 4.72in × tinggi 7.35in (A6-ish / label resi)
$custom_paper = [0, 0, 4.72 * 72, 7.35 * 72];
$dompdf->setPaper($custom_paper);
$dompdf->render();
$dompdf->stream($filename, ["Attachment" => false]);
?>









