<?php
/**
 * API ORDERS MODULE v5.5 (IQ 180 - Midtrans Integrated & Detail View)
 * =================================================================================
 * File: api/api-orders.php
 * Status: PRODUCTION READY
 * * CHANGELOG:
 * 1. [FEATURE] Added 'detail' action to fetch complete order info.
 * 2. [UI] Added 'Lihat Detail' button in table rows.
 * 3. [LOGIC] Integrated 'sistem_keamanan_midtrans.php' for safe cancellation.
 * 4. [CLEANUP] Removed 'Verifikasi' status support as requested.
 * =================================================================================
 */

// 1. INCLUDE CORE HELPER
require_once 'api_helper.php';

// 2. VALIDASI KEAMANAN
api_check_admin();

// 3. SETUP & DEPENDENCY HANDLING
$root_path = dirname(__DIR__); 

// Load System Config
$sys_config = $root_path . '/sistem/sistem.php';
if (file_exists($sys_config)) include_once $sys_config;

// Load Midtrans Config & Security Helper
$midtrans_config = $root_path . '/midtrans/config_midtrans.php';
$midtrans_helper = $root_path . '/admin/pesanan/sistem_keamanan_midtrans.php'; // Adjust path if needed
$midtrans_ready = false;

if (file_exists($midtrans_config)) {
    try {
        include_once $midtrans_config;
        if (class_exists('Midtrans\Config')) {
            $midtrans_ready = true;
        }
    } catch (Throwable $t) {
        error_log("Midtrans Load Error: " . $t->getMessage());
    }
}

// Load Security Helper (perform_safe_cancel)
if (file_exists($midtrans_helper)) {
    include_once $midtrans_helper;
}

// 4. ROUTER ACTION
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handle_list_orders($conn);
            break;
            
        case 'detail':
            handle_get_detail($conn);
            break;
            
        case 'process_order': 
            handle_bulk_status_update($conn, 'processed');
            break;
            
        case 'send_order': 
            handle_bulk_status_update($conn, 'shipped');
            break;
            
        case 'complete_order': 
            handle_bulk_status_update($conn, 'completed');
            break;
            
        case 'cancel_order': 
            handle_bulk_status_update($conn, 'cancelled');
            break;
            
        case 'flexible_update_status': 
            handle_flexible_update($conn);
            break;
            
        case 'approve_payment':
            handle_bulk_status_update($conn, 'belum_dicetak'); 
            break;

        default:
            send_response(false, 'Action tidak dikenali.', [], 400);
    }
} catch (Exception $e) {
    error_log("API Logic Error: " . $e->getMessage());
    send_response(false, 'Terjadi Kesalahan: ' . $e->getMessage(), [], 500);
} catch (Throwable $t) {
    error_log("API Fatal Error: " . $t->getMessage());
    send_response(false, 'Critical Server Error.', [], 500);
}

// =================================================================================
// HANDLER: GET DETAIL (NEW FEATURE)
// =================================================================================
function handle_get_detail($conn) {
    $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    
    if ($order_id <= 0) {
        send_response(false, 'ID Pesanan tidak valid.');
    }

    // 1. Get Main Order Data
    $sql = "SELECT 
                o.id, o.order_number, o.total, o.status, o.created_at, 
                o.midtrans_payment_type, o.shipping_fee_actual, o.tracking_number,
                o.full_name, o.phone_number, o.address_line_1, o.city, o.province, o.postal_code,
                u.name as user_name, u.email as user_email
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.id
            WHERE o.id = ?";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        send_response(false, 'Pesanan tidak ditemukan.');
    }

    // 2. Get Order Items
    $sql_items = "SELECT 
                    oi.product_id, oi.quantity, oi.price,
                    p.name as product_name, p.image as product_image
                  FROM order_items oi
                  LEFT JOIN products p ON oi.product_id = p.id
                  WHERE oi.order_id = ?";
                  
    $stmt_items = $conn->prepare($sql_items);
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    
    $items = [];
    while ($row = $result_items->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt_items->close();

    // 3. Format Response
    $data = [
        'order' => $order,
        'items' => $items,
        'formatted_total' => 'Rp ' . number_format($order['total'], 0, ',', '.'),
        'formatted_date' => date('d M Y, H:i', strtotime($order['created_at'])),
        'status_label' => ucwords(str_replace('_', ' ', $order['status']))
    ];

    send_response(true, 'Detail loaded', $data);
}

// =================================================================================
// HANDLER: LIST ORDERS
// =================================================================================
function handle_list_orders($conn) {
    // --- Filter Inputs ---
    $status_filter = $_GET['status'] ?? 'semua';
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $period = $_GET['period'] ?? 'week';
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    
    $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    if ($page < 1) $page = 1;
    $offset = ($page - 1) * $limit;

    // --- Build Query ---
    $where_clauses = ["1=1"]; 
    $params = [];
    $types = "";

    // 1. Status Filter (Removed waiting_approval from explicit checks if needed, but keeping array for safety)
    $valid_statuses = ['waiting_payment','waiting_approval','belum_dicetak','processed','shipped','completed','cancelled'];
    
    if ($status_filter !== 'semua') {
        if (in_array($status_filter, $valid_statuses)) {
            $where_clauses[] = "o.status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }
    }

    // 2. Search
    if (!empty($search)) {
        $search_term = "%$search%";
        $where_clauses[] = "(o.id LIKE ? OR o.order_number LIKE ? OR o.full_name LIKE ? OR u.name LIKE ?)";
        $params[] = $search_term; $params[] = $search_term; $params[] = $search_term; $params[] = $search_term; 
        $types .= "ssss";
    }

    // 3. Date
    if ($period === 'week') {
        $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
    } elseif ($period === 'month') {
        $where_clauses[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
    } elseif ($period === 'custom' && !empty($start_date) && !empty($end_date)) {
        $where_clauses[] = "DATE(o.created_at) BETWEEN ? AND ?";
        $params[] = $start_date; $params[] = $end_date;
        $types .= "ss";
    }

    $where_sql = implode(" AND ", $where_clauses);

    // --- Count Total ---
    try {
        $count_sql = "SELECT COUNT(*) as total FROM orders o LEFT JOIN users u ON o.user_id = u.id WHERE $where_sql";
        $stmt_count = $conn->prepare($count_sql);
        if (!empty($params)) $stmt_count->bind_param($types, ...$params);
        $stmt_count->execute();
        $total_records = $stmt_count->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_count->close();
    } catch (Exception $e) {
        $total_records = 0;
    }

    // --- Get Data ---
    $sql = "SELECT o.id, o.order_number, o.total, o.status, o.midtrans_payment_type, o.created_at, 
                   o.full_name as guest_name, u.name as user_account_name
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.id 
            WHERE $where_sql 
            ORDER BY o.created_at DESC 
            LIMIT ? OFFSET ?";
            
    $params_list = $params; $params_list[] = $limit; $params_list[] = $offset;
    $types_list = $types . "ii";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types_list, ...$params_list);
        $stmt->execute();
        $result = $stmt->get_result();
        $orders = [];
        while ($row = $result->fetch_assoc()) $orders[] = $row;
        $stmt->close();
    } catch (Exception $e) {
        send_response(false, "DB Error: " . $e->getMessage(), [], 500);
    }

    // --- Render HTML ---
    $header_html = '
    <tr>
        <th class="p-4 w-4"><div class="flex items-center"><input id="select-all-checkbox" type="checkbox" class="w-4 h-4 text-indigo-600 bg-gray-100 rounded border-gray-300"></div></th>
        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">No. Pesanan & Pelanggan</th>
        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Tanggal</th>
        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Status & Info</th>
        <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Total</th>
        <th class="px-6 py-3 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Aksi</th>
    </tr>';

    $rows_html = '';
    if (empty($orders)) {
        $rows_html = '<tr><td colspan="6" class="text-center py-12 text-gray-400 italic bg-gray-50/50 rounded-lg">Tidak ada data pesanan.</td></tr>';
    } else {
        foreach ($orders as $order) {
            $customer_name = !empty($order['user_account_name']) ? $order['user_account_name'] : ($order['guest_name'] ?? 'Guest');
            $display_payment = !empty($order['midtrans_payment_type']) ? str_replace('_', ' ', $order['midtrans_payment_type']) : 'Manual';
            $display_id = !empty($order['order_number']) ? $order['order_number'] : '#' . $order['id'];
            
            $status_badges = [
                'waiting_payment' => 'bg-orange-100 text-orange-800 border-orange-200',
                'waiting_approval' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
                'belum_dicetak' => 'bg-purple-100 text-purple-800 border-purple-200',
                'processed' => 'bg-cyan-100 text-cyan-800 border-cyan-200',
                'shipped' => 'bg-blue-100 text-blue-800 border-blue-200',
                'completed' => 'bg-green-100 text-green-800 border-green-200',
                'cancelled' => 'bg-red-100 text-red-800 border-red-200',
            ];
            $badge_class = $status_badges[$order['status']] ?? 'bg-gray-100 text-gray-800';
            $status_label = ucwords(str_replace('_', ' ', $order['status']));
            $badge = "<span class='px-2.5 py-0.5 inline-flex text-[10px] font-bold uppercase rounded-full border {$badge_class}'>{$status_label}</span>";
            
            // Buttons Logic
            $action_buttons = '';
            
            // Tombol Lihat Detail (BARU)
            $action_buttons .= '<button class="btn-view-detail w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition flex items-center justify-center mx-1 shadow-sm" data-order-id="'.$order['id'].'" title="Lihat Detail Lengkap"><i class="fas fa-eye"></i></button>';

            // Aksi Cepat berdasarkan status
            if ($order['status'] == 'belum_dicetak') {
                $action_buttons .= '<button class="btn-update-status w-8 h-8 rounded-full bg-cyan-50 text-cyan-600 hover:bg-cyan-600 hover:text-white transition flex items-center justify-center mx-1 shadow-sm" data-order-id="'.$order['id'].'" data-action="process_order" data-action-name="Proses Pesanan" title="Mulai Packing"><i class="fas fa-box-open"></i></button>';
            }
            elseif ($order['status'] == 'processed') {
                $action_buttons .= '<button class="btn-update-status w-8 h-8 rounded-full bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition flex items-center justify-center mx-1 shadow-sm" data-order-id="'.$order['id'].'" data-action="send_order" data-action-name="Kirim Pesanan" title="Kirim Barang"><i class="fas fa-truck"></i></button>';
            }
            elseif ($order['status'] == 'waiting_payment') {
                // Opsional: Manual approve jika diperlukan, tapi sebaiknya via midtrans
            }

            $action_buttons .= '<button class="btn-flexible-update w-8 h-8 rounded-full bg-gray-50 text-gray-500 hover:bg-gray-600 hover:text-white transition flex items-center justify-center mx-1 shadow-sm" data-order-id="'.$order['id'].'" data-current-status="'.$order['status'].'" title="Edit Status"><i class="fas fa-edit"></i></button>';
            
            $rows_html .= '
            <tr class="hover:bg-indigo-50/30 transition group border-b border-gray-50">
                <td class="p-4"><input type="checkbox" class="order-checkbox w-4 h-4 text-indigo-600 border-gray-300 rounded" value="'.$order['id'].'"></td>
                <td class="px-6 py-4">
                    <div class="flex flex-col">
                        <span class="text-sm font-bold text-indigo-900">'.$display_id.'</span>
                        <span class="text-xs text-gray-500"><i class="fas fa-user-circle text-gray-300"></i> '.htmlspecialchars($customer_name).'</span>
                    </div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">'.date('d M Y H:i', strtotime($order['created_at'])).'</td>
                <td class="px-6 py-4">
                    <div class="mb-1">'.$badge.'</div>
                    <div class="text-[10px] font-bold text-gray-400 uppercase"><i class="far fa-credit-card"></i> '.strtoupper($display_payment).'</div>
                </td>
                <td class="px-6 py-4 text-sm font-bold text-gray-800 font-mono">Rp '.number_format($order['total'], 0, ',', '.').'</td>
                <td class="px-6 py-4 text-center"><div class="flex items-center justify-center">'.$action_buttons.'</div></td>
            </tr>';
        }
    }

    // --- Pagination ---
    $total_pages = ceil($total_records / $limit);
    $pagination_html = '';
    if ($total_pages > 1) {
        $pagination_html = '<nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">';
        // Prev
        $disabled_prev = ($page <= 1) ? 'disabled pointer-events-none opacity-50 bg-gray-50' : 'bg-white hover:bg-gray-50';
        $pagination_html .= '<a href="#" data-page="'.($page-1).'" class="'.$disabled_prev.' relative inline-flex items-center px-3 py-2 rounded-l-md border border-gray-300 text-sm font-medium text-gray-500"><i class="fas fa-chevron-left"></i></a>';
        
        // Simple range
        $start = max(1, $page - 2); $end = min($total_pages, $page + 2);
        for ($i=$start; $i<=$end; $i++) {
            $act = ($i==$page) ? 'bg-indigo-50 border-indigo-500 text-indigo-600 font-bold' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50';
            $pagination_html .= '<a href="#" data-page="'.$i.'" class="'.$act.' relative inline-flex items-center px-4 py-2 border text-sm font-medium">'.$i.'</a>';
        }
        
        // Next
        $disabled_next = ($page >= $total_pages) ? 'disabled pointer-events-none opacity-50 bg-gray-50' : 'bg-white hover:bg-gray-50';
        $pagination_html .= '<a href="#" data-page="'.($page+1).'" class="'.$disabled_next.' relative inline-flex items-center px-3 py-2 rounded-r-md border border-gray-300 text-sm font-medium text-gray-500"><i class="fas fa-chevron-right"></i></a>';
        $pagination_html .= '</nav>';
    }

    // --- Bulk Action HTML ---
    $bulk_actions_html = '
        <div id="bulk-toolbar" class="hidden flex items-center gap-3 p-2.5 bg-indigo-600 text-white rounded-xl shadow-lg border border-indigo-500">
            <span class="text-sm font-bold ml-2"><span id="selected-count">0</span> Dipilih</span>
            <div class="h-6 w-px bg-indigo-400 mx-1"></div>
            <button name="action" value="print_and_process" class="px-3 py-1.5 bg-white text-indigo-700 text-xs font-bold rounded hover:bg-gray-100 flex items-center gap-1"><i class="fas fa-print"></i> Cetak</button>
            <button name="action" value="send_order" class="px-3 py-1.5 bg-blue-500 text-white text-xs font-bold rounded hover:bg-blue-600 flex items-center gap-1 border border-blue-400"><i class="fas fa-truck"></i> Kirim</button>
            <button name="action" value="cancel_order" class="px-3 py-1.5 bg-red-500 text-white text-xs font-bold rounded hover:bg-red-600 flex items-center gap-1 border border-red-400 ml-auto"><i class="fas fa-times"></i> Batal</button>
        </div>
    ';

    send_response(true, "Loaded", [
        'header' => $header_html,
        'rows' => $rows_html,
        'pagination' => $pagination_html,
        'bulk_actions' => $bulk_actions_html,
        'total_results' => $total_records,
        'start_index' => ($total_records > 0) ? $offset + 1 : 0,
        'end_index' => ($total_records > 0) ? min($offset + $limit, $total_records) : 0
    ]);
}

// =================================================================================
// HANDLER: FLEXIBLE UPDATE (Midtrans Integrated)
// =================================================================================
function handle_flexible_update($conn) {
    global $midtrans_ready;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $order_id = (int)($input['order_id'] ?? 0);
    $new_status = trim($input['new_status'] ?? '');
    $reason = trim($input['cancel_reason'] ?? '');

    if (!$order_id || empty($new_status)) send_response(false, 'Parameter tidak lengkap.', [], 400);

    // --- LOGIKA PEMBATALAN MIDTRANS ---
    if ($new_status === 'cancelled') {
        // Cek apakah fungsi helper tersedia (dari sistem_keamanan_midtrans.php)
        if (function_exists('perform_safe_cancel')) {
            // Kita butuh server key midtrans
            // Ambil dari Config jika belum ada
            $serverKey = \Midtrans\Config::$serverKey ?? 'SB-Mid-server-xxx'; // Fallback atau ambil dari DB
            $isProduction = \Midtrans\Config::$isProduction ?? false;

            // Jalankan Safe Cancel
            $result = perform_safe_cancel($conn, $order_id, $reason, $serverKey, $isProduction);
            
            if ($result['success']) {
                send_response(true, $result['message']);
            } else {
                send_response(false, "Gagal Batal Midtrans: " . $result['message']);
            }
            return;
        }
    }

    // --- LOGIKA UPDATE BIASA (Non-Cancel) ---
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $order_id);
        if (!$stmt->execute()) throw new Exception("DB Error");
        $conn->commit();
        send_response(true, "Status pesanan #$order_id berhasil diubah.");
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, "Error: " . $e->getMessage(), [], 500);
    }
}

// =================================================================================
// HANDLER: BULK UPDATE
// =================================================================================
function handle_bulk_status_update($conn, $target_status) {
    global $midtrans_ready;
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;
    
    $ids = $input['selected_orders'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    $ids = array_filter(array_map('intval', $ids));

    if (empty($ids)) send_response(false, 'Tidak ada pesanan dipilih.', [], 400);

    // --- SPECIAL CASE: CANCEL BULK WITH MIDTRANS ---
    if ($target_status === 'cancelled' && function_exists('perform_safe_cancel')) {
        $serverKey = \Midtrans\Config::$serverKey ?? '';
        $isProduction = \Midtrans\Config::$isProduction ?? false;
        $success_count = 0;
        $fail_count = 0;
        
        foreach ($ids as $oid) {
            $res = perform_safe_cancel($conn, $oid, "Bulk Cancel by Admin", $serverKey, $isProduction);
            if ($res['success']) $success_count++;
            else $fail_count++;
        }
        
        send_response(true, "Bulk Cancel Selesai. Sukses: $success_count, Gagal/Skip: $fail_count");
        return;
    }

    // --- NORMAL BULK UPDATE ---
    $ids_str = implode(',', $ids);
    $conn->begin_transaction();
    try {
        $conn->query("UPDATE orders SET status = '$target_status' WHERE id IN ($ids_str)");
        $conn->commit();
        send_response(true, "Berhasil update status.");
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, "Error: " . $e->getMessage(), [], 500);
    }
}
?>