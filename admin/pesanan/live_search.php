<?php
// File: admin/pesanan/live_search.php
// File ini SEKARANG menjadi endpoint AJAX utama untuk mengambil data tabel.
require_once '../../config/config.php';
require_once '../../sistem/sistem.php';

check_admin();

// --- PENGATURAN PAGINASI, FILTER, DAN PENCARIAN ---
$current_page = max(1, (int)($_GET['p'] ?? 1));
$limit = max(1, (int)($_GET['limit'] ?? 10));
$status_filter = $_GET['status'] ?? 'semua'; // 'status' dari JS
$search_query = $_GET['q'] ?? ''; // 'q' dari JS

$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

$status_map = [
    'semua' => 'Semua Pesanan', 'waiting_payment' => 'Menunggu Pembayaran', 'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak', 'processed' => 'Diproses', 'shipped' => 'Dikirim',
    'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'
];

// Opsi Aksi Massal (perlu didefinisikan untuk order_rows.php)
// --- PERBAIKAN DI SINI ---
// Tambahkan 'waiting_payment' ke daftar status yang punya bulk action
$bulk_action_options = in_array($status_filter, ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped']);
// --- AKHIR PERBAIKAN ---

// --- PENGAMBILAN DATA (Menggunakan fungsi konsisten) ---
$data = get_orders_with_items_by_status($conn, [
    'status' => $status_filter,
    'search' => $search_query,
    'limit' => $limit,
    'page' => $current_page // 'page' adalah parameter yang benar untuk fungsi ini
]);
$orders = $data['orders'];
$total_records = $data['total'];
$total_pages = max(1, ceil($total_records / $limit)); // Minimal 1 halaman
$start_index = ($total_records > 0) ? max(1, ($current_page - 1) * $limit + 1) : 0;
$end_index = min($current_page * $limit, $total_records);

// Fungsi get_status_class (diperlukan oleh order_rows.php)
function get_status_class($status) {
    $classes = [
        'completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800',
        'processed' => 'bg-cyan-100 text-cyan-800', 'belum_dicetak' => 'bg-purple-100 text-purple-800',
        'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}

// ====================================================================
// --- PERMINTAAN KUSTOM: Urutkan berdasarkan nama jika status 'siap kirim' ---
// ====================================================================
// Ini dilakukan di PHP setelah data diambil, karena kita tidak mengubah sistem.php
$sortable_statuses = ['belum_dicetak', 'processed', 'shipped'];
if (in_array($status_filter, $sortable_statuses)) {
    usort($orders, function($a, $b) {
        // Urutkan berdasarkan 'user_name' (case-insensitive)
        $name_cmp = strcasecmp($a['user_name'], $b['user_name']);
        
        if ($name_cmp !== 0) {
            // Jika nama berbeda, urutkan berdasarkan nama
            return $name_cmp;
        }
        
        // Jika nama sama, urutkan berdasarkan 'order_number' agar tetap konsisten
        return strcmp($a['order_number'], $b['order_number']);
    });
}
// ====================================================================
// --- AKHIR PERMINTAAN KUSTOM ---
// ====================================================================


// --- GENERATE OUTPUT ---

// 1. Render Header
ob_start();
include 'order_table_header.php'; // File baru untuk header
$header_html = ob_get_clean();

// 2. Render Baris Tabel
ob_start();
// Render baris tabel (menggunakan file yang sudah ada)
// $orders sekarang sudah ter-sort jika diperlukan
include 'order_rows.php';
$rows_html = ob_get_clean();

// 3. Render Paginasi
ob_start();
// Render paginasi (menggunakan file yang sudah ada dan dimodifikasi)
include 'pagination.php';
$pagination_html = ob_get_clean();

// 4. Render Tombol Bulk Action
ob_start();
include 'bulk_actions.php'; // File baru untuk bulk actions
$bulk_actions_html = ob_get_clean();

// 5. Render Tombol Print
ob_start();
include 'print_button.php'; // File baru untuk tombol print
$print_button_html = ob_get_clean();


// Kirim response sebagai JSON
header('Content-Type: application/json');
echo json_encode([
    'header' => $header_html,
    'rows' => $rows_html,
    'pagination' => $pagination_html,
    'bulk_actions' => $bulk_actions_html,
    'print_button' => $print_button_html,
    'total_results' => $total_records,
    'start_index' => $start_index,
    'end_index' => $end_index,
    'debug_status' => $status_filter // untuk debug
]);
?>