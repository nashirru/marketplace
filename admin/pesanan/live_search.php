<?php
// File: admin/pesanan/live_search.php
// File ini HANYA untuk menangani request AJAX dari live search
require_once '../../config/config.php';
require_once '../../sistem/sistem.php';

check_admin();

// --- PENGATURAN PAGINASI, FILTER, DAN PENCARIAN ---
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;
$status_filter = $_GET['status'] ?? 'semua';
$search_query = $_GET['q'] ?? '';

$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

$status_map = [
    'semua' => 'Semua Pesanan',
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum di Cetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

function get_admin_status_class($status) {
     switch ($status) {
        case 'completed': return 'bg-green-100 text-green-800';
        case 'shipped': return 'bg-blue-100 text-blue-800';
        case 'processed': return 'bg-indigo-100 text-indigo-800';
        case 'belum_dicetak': return 'bg-cyan-100 text-cyan-800';
        case 'waiting_approval': return 'bg-yellow-100 text-yellow-800';
        case 'waiting_payment': return 'bg-orange-100 text-orange-800';
        case 'cancelled': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

// --- PENGAMBILAN DATA ---
$params = [];
$types = "";
$where_conditions = [];

if ($status_filter !== 'semua') {
    $where_conditions[] = "o.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}
if (!empty($search_query)) {
    $search_term = "%" . $search_query . "%";
    $where_conditions[] = "(o.order_number LIKE ? OR u.name LIKE ? OR o.phone_number LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}
$where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";

// Hitung total
$total_query = "SELECT COUNT(o.id) as total FROM orders o JOIN users u ON o.user_id = u.id" . $where_clause;
$stmt_total = $conn->prepare($total_query);
if (!empty($params)) $stmt_total->bind_param($types, ...$params);
$stmt_total->execute();
$total_results = $stmt_total->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_results / $limit);
$stmt_total->close();

// Ambil data pesanan
$orders = [];
$sql = "SELECT o.*, u.name as user_name, o.phone_number FROM orders o JOIN users u ON o.user_id = u.id" . $where_clause . " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
$stmt_params = $params;
$stmt_params[] = $limit;
$stmt_params[] = $offset;
$stmt_types = $types . "ii";

$stmt = $conn->prepare($sql);
if (!empty($stmt_params)) $stmt->bind_param($stmt_types, ...$stmt_params);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) $orders[] = $row;
$stmt->close();

// Opsi Aksi Massal
$bulk_action_options = [];
if ($status_filter === 'waiting_approval' || $status_filter === 'processed' || $status_filter === 'shipped') {
    $bulk_action_options = true;
}


// --- GENERATE OUTPUT ---
ob_start();
// Render baris tabel
include 'order_rows.php';
$rows_html = ob_get_clean();

ob_start();
// Render paginasi
include 'pagination.php';
$pagination_html = ob_get_clean();

// Kirim response sebagai JSON
header('Content-Type: application/json');
echo json_encode([
    'rows' => $rows_html,
    'pagination' => $pagination_html,
    'total_results' => $total_results
]);