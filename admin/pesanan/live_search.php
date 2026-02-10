<?php
// File: admin/pesanan/live_search.php
// VERSI FIXED IQ 180: Robust Variation Fetching
// PERBAIKAN: Menambahkan fallback JOIN ke tabel product_variations pada blok CATCH
// agar variasi tetap muncul meskipun kolom snapshot di order_items bermasalah.

// Tangkap semua error tingkat rendah untuk debugging (tidak ditampilkan ke user)
error_reporting(E_ALL);
ini_set('display_errors', 0); 

// Buffer output untuk menangkap error text HTML agar tidak merusak JSON
ob_start();

header('Content-Type: application/json');

// Setting zona waktu
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Jakarta');
}

try {
    // Definisi Path Absolut untuk menghindari masalah Relative Path
    $base_dir = dirname(dirname(dirname(__DIR__))); 
    
    $config_path = __DIR__ . '/../../config/config.php';
    $sistem_path = __DIR__ . '/../../sistem/sistem.php';

    if (!file_exists($config_path)) throw new Exception("Config file not found at: $config_path");
    if (!file_exists($sistem_path)) throw new Exception("Sistem file not found at: $sistem_path");

    require_once $config_path;
    require_once $sistem_path;

    check_admin();

    // =================================================================================
    // [FUNGSI LOKAL] PENGAMBILAN DATA PESANAN
    // =================================================================================
    function get_orders_local_search($conn, $options) {
        $status_filter = $options['status'] ?? 'semua';
        $search_query = $options['search'] ?? '';
        $limit = (int)($options['limit'] ?? 10);
        $current_page = max(1, (int)($options['p'] ?? 1));
        $offset = max(0, ($current_page - 1) * $limit);
        
        $start_date = $options['start_date'] ?? '';
        $end_date = $options['end_date'] ?? '';
        
        $where_conditions = [];
        $params = [];
        $types = "";
        
        // 1. Filter Status
        if ($status_filter !== 'semua') {
            $where_conditions[] = "o.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }

        // 2. Filter Pencarian
        if (!empty($search_query)) {
            $search_term = "%" . $search_query . "%";
            $where_conditions[] = "(o.order_number LIKE ? OR u.name LIKE ? OR o.phone_number LIKE ?)";
            $params[] = $search_term; $params[] = $search_term; $params[] = $search_term;
            $types .= "sss";
        }

        // 3. Filter Tanggal
        if (!empty($start_date) && !empty($end_date)) {
            $where_conditions[] = "DATE(o.created_at) BETWEEN ? AND ?";
            $params[] = $start_date; $params[] = $end_date;
            $types .= "ss";
        }

        $where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";
        
        // --- Query Total ---
        $stmt_total = $conn->prepare("SELECT COUNT(o.id) as total FROM orders o LEFT JOIN users u ON o.user_id = u.id" . $where_clause);
        if (!empty($params)) $stmt_total->bind_param($types, ...$params);
        $stmt_total->execute();
        $total_records = (int)$stmt_total->get_result()->fetch_assoc()['total'];
        $stmt_total->close();

        // --- Query Data Pesanan ---
        $orders = [];
        $sql_orders = "SELECT o.*, u.name as user_name, u.email as user_email FROM orders o LEFT JOIN users u ON o.user_id = u.id {$where_clause} ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = $offset; $types .= "ii";
        
        $stmt_orders = $conn->prepare($sql_orders);
        $stmt_orders->bind_param($types, ...$params);
        $stmt_orders->execute();
        $result_orders = $stmt_orders->get_result();
        
        if ($result_orders) {
            $order_ids = [];
            while ($row = $result_orders->fetch_assoc()) {
                $row['user_name'] = $row['user_name'] ?? 'User Dihapus';
                $orders[$row['id']] = $row;
                $orders[$row['id']]['items'] = [];
                $order_ids[] = (int)$row['id'];
            }
            $stmt_orders->close();
            
            if (!empty($order_ids)) {
                $order_ids_str = implode(',', $order_ids);
                
                // --- PENGAMBILAN ITEM DENGAN VARIASI (PERBAIKAN BUG DISINI) ---
                
                // Metode Utama: Menggunakan COALESCE untuk prioritas snapshot order_items, lalu master product_variations
                try {
                    $sql_items = "
                        SELECT oi.*, p.name as product_name, p.image as product_image, 
                               COALESCE(oi.variation_name, pv.name) as variation_name
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        LEFT JOIN product_variations pv ON oi.variation_id = pv.id
                        WHERE oi.order_id IN ({$order_ids_str})
                    ";
                    $result_items = $conn->query($sql_items);
                } catch (Throwable $e) {
                    // Metode Fallback (Jika kolom variation_name di order_items belum ada/error)
                    // FIX: Tetap melakukan JOIN ke product_variations agar nama variasi muncul dari tabel master
                    $sql_items = "
                        SELECT oi.*, p.name as product_name, p.image as product_image,
                               pv.name as variation_name 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        LEFT JOIN product_variations pv ON oi.variation_id = pv.id
                        WHERE oi.order_id IN ({$order_ids_str})
                    ";
                    $result_items = $conn->query($sql_items);
                }
                
                if ($result_items) {
                    while ($item = $result_items->fetch_assoc()) {
                        if (isset($orders[$item['order_id']])) {
                            $orders[$item['order_id']]['items'][] = $item;
                        }
                    }
                }
            }
        } else {
             $stmt_orders->close();
        }

        return ['orders' => array_values($orders), 'total' => $total_records];
    }

    // --- MAIN LOGIC ---
    $current_page = max(1, (int)($_GET['p'] ?? 1));
    $limit = max(1, (int)($_GET['limit'] ?? 10));
    $status_filter = $_GET['status'] ?? 'semua';
    $search_query = $_GET['q'] ?? '';
    $period = $_GET['period'] ?? 'week';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';

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

    $bulk_action_options = in_array($status_filter, ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped']);

    $options = [
        'status' => $status_filter,
        'search' => $search_query,
        'limit' => $limit,
        'p' => $current_page,
        'start_date' => $start_date,
        'end_date' => $end_date
    ];

    $data = get_orders_local_search($conn, $options);
    $orders = $data['orders'];
    $total_records = $data['total'];
    $total_pages = max(1, ceil($total_records / $limit));
    $start_index = ($total_records > 0) ? max(1, ($current_page - 1) * $limit + 1) : 0;
    $end_index = min($current_page * $limit, $total_records);

    // Helpers Status Class
    if (!function_exists('get_status_class')) {
        function get_status_class($status) {
            $classes = [
                'completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800',
                'processed' => 'bg-purple-100 text-purple-800', 'belum_dicetak' => 'bg-cyan-100 text-cyan-800',
                'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800',
                'cancelled' => 'bg-red-100 text-red-800',
            ];
            return $classes[$status] ?? 'bg-gray-100 text-gray-800';
        }
    }

    // Sort Logic untuk Status Tertentu
    if (in_array($status_filter, ['belum_dicetak', 'processed', 'shipped'])) {
        usort($orders, function($a, $b) {
            $name_cmp = strcasecmp($a['user_name'], $b['user_name']);
            return ($name_cmp !== 0) ? $name_cmp : strcmp($a['order_number'], $b['order_number']);
        });
    }

    // --- RENDER PARTS ---
    // Bersihkan buffer sebelum render partials agar tidak ada output liar
    ob_clean(); 

    // Header
    ob_start();
    if (file_exists('order_table_header.php')) include 'order_table_header.php'; 
    else echo '<tr><th class="px-6 py-3">Order Info</th><th class="px-6 py-3">Pelanggan</th><th class="px-6 py-3">Total</th><th class="px-6 py-3">Status</th><th class="px-6 py-3">Aksi</th></tr>';
    $header_html = ob_get_clean();

    // Rows
    ob_start();
    if (file_exists('order_rows.php')) include 'order_rows.php';
    else echo '<tr><td colspan="8" class="text-center text-red-500 p-4">File order_rows.php hilang.</td></tr>';
    $rows_html = ob_get_clean();

    // Pagination
    ob_start();
    if (file_exists('pagination.php')) include 'pagination.php';
    $pagination_html = ob_get_clean();

    // Bulk Actions
    ob_start();
    if (file_exists('bulk_actions.php')) include 'bulk_actions.php'; 
    $bulk_actions_html = ob_get_clean();

    // Print Button
    ob_start();
    if (file_exists('print_button.php')) include 'print_button.php'; 
    $print_button_html = ob_get_clean();

    echo json_encode([
        'header' => $header_html,
        'rows' => $rows_html,
        'pagination' => $pagination_html,
        'bulk_actions' => $bulk_actions_html,
        'print_button' => $print_button_html,
        'total_results' => $total_records,
        'start_index' => $start_index,
        'end_index' => $end_index,
        'debug_status' => $status_filter,
        'debug_dates' => "Start: $start_date, End: $end_date"
    ]);

} catch (Throwable $e) {
    // Tangkap SEMUA error
    ob_end_clean(); 
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Server Error: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
?>