<?php
// File: rekap/ajax_get_rekap_chart.php
// Endpoint AJAX untuk mengambil data pendapatan (HANYA UNTUK PREVIEW).

// Sesuaikan path ini 2 level ke atas (rekap -> admin -> root)
require_once '../../config/config.php';
require_once '../../sistem/sistem.php';

check_admin();

header('Content-Type: application/json');

// --- Pengambilan Parameter ---
$start_date_str = $_GET['start_date'] ?? null;
$end_date_str = $_GET['end_date'] ?? null;
// ### FITUR BARU: Ambil status filter ###
$status_filter = $_GET['status'] ?? [];


// --- Penentuan Rentang Tanggal ---
$start_date = null;
$end_date = null;
$group_format = '%Y-%m-%d'; // Format grouping default (per hari)
$label_format = 'd M';     // Format label default (tanggal bulan)

try {
    $timezone = new DateTimeZone('Asia/Jakarta');
    $today = new DateTime('now', $timezone);

    if ($start_date_str && $end_date_str) {
        $start_date = new DateTime($start_date_str, $timezone);
        $end_date = new DateTime($end_date_str, $timezone);
        
        $diff_days = $start_date->diff($end_date)->days;
        if ($diff_days > 90) {
            $group_format = '%Y-%m';
            $label_format = 'M Y';
        }
    } else {
        $start_date = (clone $today)->modify('-29 days');
        $end_date = clone $today;
    }

    if ($end_date > $today) {
        $end_date = clone $today;
    }

    // --- Persiapan Query ---
    $params_types = "sss";
    $params_values = [$group_format, $start_date->format('Y-m-d 00:00:00'), $end_date->format('Y-m-d 23:59:59')];
    $status_list_sql = "";

    // ### FITUR BARU: Terapkan filter status ###
    if (!empty($status_filter) && is_array($status_filter)) {
        $placeholders = implode(',', array_fill(0, count($status_filter), '?'));
        $status_list_sql = "AND status IN ($placeholders)";
        $params_types .= str_repeat('s', count($status_filter));
        $params_values = array_merge($params_values, $status_filter);
    } else {
        // Jika tidak ada status dipilih, jangan tampilkan data
        $status_list_sql = "AND 1=0";
    }

    $sql = "
        SELECT DATE_FORMAT(created_at, ?) as date_group, SUM(total) as total_sales
        FROM orders
        WHERE created_at BETWEEN ? AND ?
          $status_list_sql
        GROUP BY date_group
        ORDER BY date_group ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Gagal mempersiapkan statement: " . $conn->error);
    }
    
    $stmt->bind_param($params_types, ...$params_values);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales_data = [];
    while ($row = $result->fetch_assoc()) {
        $sales_data[$row['date_group']] = (float)$row['total_sales'];
    }
    $stmt->close();
    $conn->close();

    // --- Persiapan Data untuk Chart ---
    $labels = [];
    $data = [];
    $current_date = clone $start_date;
    $interval_unit = ($group_format === '%Y-%m') ? 'P1M' : 'P1D';

    while ($current_date <= $end_date) {
        $date_key = $current_date->format(($group_format === '%Y-%m') ? 'Y-m' : 'Y-m-d');
        $label = $current_date->format($label_format);

        $labels[] = $label;
        $data[] = $sales_data[$date_key] ?? 0;

        $current_date->add(new DateInterval($interval_unit));
        if ($group_format === '%Y-%m' && $current_date->format('Y-m') > $end_date->format('Y-m')) {
            break;
        }
    }

    // --- Kirim Response ---
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'data' => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>