<?php
// File: admin/log_pembatalan/log_pembatalan_ajax.php
// Endpoint AJAX untuk mengambil data log pembatalan.
// VERSI AMAN: Menghapus join ke tabel admin untuk isolasi masalah.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../../config/config.php';
require_once '../../sistem/sistem.php';

check_admin();

// --- PENGATURAN PAGINASI, FILTER, DAN PENCARIAN ---
$current_page = max(1, (int)($_GET['p'] ?? 1));
$limit = max(1, (int)($_GET['limit'] ?? 25));
$search_query = $_GET['q'] ?? ''; 
$period = $_GET['period'] ?? 'week'; 
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Tentukan rentang tanggal
if ($period === 'week') {
    $start_date = date('Y-m-d', strtotime('-6 days'));
    $end_date = date('Y-m-d');
} elseif ($period === 'month') {
    $start_date = date('Y-m-d', strtotime('-1 month'));
    $end_date = date('Y-m-d');
} elseif ($period === 'all') {
    $start_date = '';
    $end_date = '';
}

// --- PENGAMBILAN DATA LOG ---

$where_clauses = [];
$params = [];
$types = "";

// 1. Filter Pencarian (Hanya order_id dan midtrans_order_id)
if (!empty($search_query)) {
    $search_term = '%' . $search_query . '%';
    // PENCARIAN NAMA ADMIN DIHAPUS SEMENTARA
    $where_clauses[] = "(l.order_id LIKE ? OR l.midtrans_order_id LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "ss";
}

// 2. Filter Tanggal
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "DATE(l.timestamp) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Menggunakan try-catch untuk menangani error SQL
try {
    // Query untuk TOTAL RECORDS (JOIN DIHAPUS)
    $sql_total = "SELECT COUNT(l.log_id) as total 
                  FROM admin_cancel_logs l 
                  $where_sql";
    $stmt_total = $conn->prepare($sql_total);
    if (!$stmt_total) {
        throw new Exception("SQL Prepare Error (Total): " . $conn->error);
    }
    if (!empty($types)) {
        $stmt_total->bind_param($types, ...$params);
    }
    $stmt_total->execute();
    $total_records = $stmt_total->get_result()->fetch_assoc()['total'];
    $stmt_total->close();

    $total_pages = max(1, ceil($total_records / $limit));
    $offset = ($current_page - 1) * $limit;
    $start_index = ($total_records > 0) ? $offset + 1 : 0;
    $end_index = min($current_page * $limit, $total_records);

    // Query untuk DATA LOG (JOIN DIHAPUS)
    $sql_data = "SELECT l.* FROM admin_cancel_logs l 
                 $where_sql 
                 ORDER BY l.timestamp DESC 
                 LIMIT ? OFFSET ?";
    
    // Tambahkan $limit dan $offset ke params dan types
    $data_params = $params; // Salin params filter
    $data_types = $types;   // Salin types filter
    $data_params[] = $limit;
    $data_params[] = $offset;
    $data_types .= "ii";

    $stmt_data = $conn->prepare($sql_data);
    if (!$stmt_data) {
        throw new Exception("SQL Prepare Error (Data): " . $conn->error);
    }
    $stmt_data->bind_param($data_types, ...$data_params);
    $stmt_data->execute();
    $logs = $stmt_data->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_data->close();

} catch (Exception $e) {
    // Jika terjadi error SQL, kirim response error
    header('Content-Type: application/json', true, 500); // Kirim status 500
    echo json_encode([
        'error' => 'Terjadi error pada server.',
        'detail' => $e->getMessage() // Pesan error detail
    ]);
    exit;
}


// --- GENERATE OUTPUT HTML ---

// 1. Render Header
ob_start();
?>
<tr>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Waktu</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Midtrans ID</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admin ID</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Midtrans</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Keputusan Sistem</th>
    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pesan</th>
</tr>
<?php
$header_html = ob_get_clean();

// 2. Render Baris Tabel
ob_start();
if (count($logs) > 0) {
    foreach ($logs as $log) {
        $decision_class = '';
        switch ($log['system_decision']) {
            case 'cancelled':
                $decision_class = 'bg-green-100 text-green-800';
                break;
            case 'rejected-paid':
            case 'rejected-local-paid':
                $decision_class = 'bg-red-100 text-red-800';
                break;
            case 'error': 
            case 'error-check-failed':
                $decision_class = 'bg-yellow-100 text-yellow-800';
                break;
            default:
                $decision_class = 'bg-gray-100 text-gray-800';
        }
?>
    <tr>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= date('d M Y, H:i:s', strtotime($log['timestamp'])) ?></td>
        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#<?= htmlspecialchars($log['order_id']) ?></td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($log['midtrans_order_id'] ?? 'N/A') ?></td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= htmlspecialchars($log['admin_id'] ?? 'Sistem') ?></td>
        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= htmlspecialchars($log['midtrans_status'] ?? 'N/A') ?></td>
        <td class="px-6 py-4 whitespace-nowrap">
            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $decision_class ?>">
                <?= htmlspecialchars($log['system_decision']) ?>
            </span>
        </td>
        <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate" title="<?= htmlspecialchars($log['message']) ?>"><?= htmlspecialchars($log['message']) ?></td>
    </tr>
<?php
    }
} else {
?>
    <tr>
        <td colspan="7" class="text-center py-10 text-gray-500">
            <?php if (!empty($search_query)) {
                echo 'Tidak ada log yang cocok dengan pencarian "' . htmlspecialchars($search_query) . '".';
            } else {
                echo 'Tidak ada data log pembatalan.';
            } ?>
        </td>
    </tr>
<?php
}
$rows_html = ob_get_clean();

// 3. Render Paginasi
ob_start();
if ($total_pages > 1) {
    $query_params = [
        'page' => 'log_pembatalan',
        'limit' => $limit,
        'search' => $search_query, 
        'period' => $period,
    ];
    if ($period == 'custom') {
        $query_params['start_date'] = $start_date;
        $query_params['end_date'] = $end_date;
    }

    echo '<nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">';
    
    // Tombol Previous
    $prev_page = $current_page - 1;
    $query_params['p'] = $prev_page;
    $prev_link = '?' . http_build_query($query_params);
    if ($current_page > 1) {
        echo "<a href='$prev_link' class='relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50'><i class='fas fa-chevron-left'></i></a>";
    }

    // Nomor Halaman
    $num_links_around = 1; 
    $show_first_last = true;

    for ($i = 1; $i <= $total_pages; $i++) {
        $query_params['p'] = $i;
        $link = '?' . http_build_query($query_params);

        if ($i == $current_page) {
            echo "<span aria-current='page' class='relative z-10 inline-flex items-center px-4 py-2 border border-indigo-500 bg-indigo-50 text-sm font-medium text-indigo-600'>$i</span>";
        } elseif (
            ($show_first_last && $i == 1) || 
            ($show_first_last && $i == $total_pages) || 
            ($i >= $current_page - $num_links_around && $i <= $current_page + $num_links_around) 
        ) {
            echo "<a href='$link' class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50'>$i</a>";
        } elseif (
            ($i == $current_page - ($num_links_around + 1)) || 
            ($i == $current_page + ($num_links_around + 1))  
        ) {
            echo "<span class='relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700'>...</span>";
        }
    }

    // Tombol Next
    $next_page = $current_page + 1;
    $query_params['p'] = $next_page;
    $next_link = '?' . http_build_query($query_params);
    if ($current_page < $total_pages) {
        echo "<a href='$next_link' class='relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50'><i class='fas fa-chevron-right'></i></a>";
    }
    echo '</nav>';
}
$pagination_html = ob_get_clean();


// Kirim response sebagai JSON
header('Content-Type: application/json');
echo json_encode([
    'header' => $header_html,
    'rows' => $rows_html,
    'pagination' => $pagination_html,
    'total_results' => $total_records,
    'start_index' => $start_index,
    'end_index' => $end_index,
]);
?>