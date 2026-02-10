<?php
// File: admin/ajax_get_chart_data.php
// Endpoint AJAX untuk mengambil data pendapatan berdasarkan rentang waktu.

require_once '../../config/config.php'; // Sesuaikan path jika perlu
require_once '../../sistem/sistem.php'; // Sesuaikan path jika perlu

// Validasi admin session (jika perlu, tapi diasumsikan sudah ada di sistem.php/config.php)
check_admin(); // Pastikan fungsi check_admin() ada

header('Content-Type: application/json');

// --- PENTING: OPTIMASI DATABASE ---
// Pastikan Anda memiliki INDEX pada kolom `created_at` di tabel `orders`.
// Contoh SQL: ALTER TABLE `orders` ADD INDEX `idx_created_at` (`created_at`);
// Tanpa index ini, query akan sangat lambat jika data pesanan banyak!

// --- Pengambilan Parameter ---
$range = $_GET['range'] ?? '7d';
$start_date_str = $_GET['start_date'] ?? null;
$end_date_str = $_GET['end_date'] ?? null;
$status_pendapatan_list = "('belum_dicetak', 'processed', 'shipped', 'completed')"; // Status yg dihitung pendapatan

// --- Penentuan Rentang Tanggal ---
$start_date = null;
$end_date = null;
$group_format = '%Y-%m-%d'; // Format grouping default (per hari)
$label_format = 'd M';     // Format label default (tanggal bulan)

try {
    $today = new DateTime('now', new DateTimeZone('Asia/Jakarta')); // Sesuaikan timezone

    switch ($range) {
        case '30d':
            $start_date = (clone $today)->modify('-29 days'); // 30 hari termasuk hari ini
            $end_date = clone $today;
            break;
        case 'this_month':
            $start_date = new DateTime('first day of this month', new DateTimeZone('Asia/Jakarta'));
            $end_date = clone $today; // Sampai hari ini di bulan ini
            break;
        case 'this_year':
            $start_date = new DateTime('first day of january this year', new DateTimeZone('Asia/Jakarta'));
            $end_date = clone $today; // Sampai hari ini di tahun ini
            $group_format = '%Y-%m'; // Group per bulan untuk range tahunan
            $label_format = 'M Y';    // Label: Jan 2024, Feb 2024, dst.
            break;
        case 'custom':
            if ($start_date_str && $end_date_str) {
                $start_date = new DateTime($start_date_str, new DateTimeZone('Asia/Jakarta'));
                $end_date = new DateTime($end_date_str, new DateTimeZone('Asia/Jakarta'));
                 // Jika rentang kustom > 90 hari, group per bulan
                 $diff_days = $start_date->diff($end_date)->days;
                 if ($diff_days > 90) {
                     $group_format = '%Y-%m';
                     $label_format = 'M Y';
                 }

            } else {
                throw new Exception("Tanggal kustom tidak valid.");
            }
            break;
        case '7d':
        default: // Default ke 7 hari terakhir
            $start_date = (clone $today)->modify('-6 days'); // 7 hari termasuk hari ini
            $end_date = clone $today;
            break;
    }

    // Pastikan tanggal valid
    if (!$start_date || !$end_date) {
        throw new Exception("Rentang tanggal tidak valid.");
    }
     // Pastikan end_date tidak di masa depan (jika perlu)
     if ($end_date > $today) {
        $end_date = clone $today;
    }


    // --- Query Database ---
    // Gunakan prepared statement untuk keamanan
    $sql = "
        SELECT DATE_FORMAT(created_at, ?) as date_group, SUM(total) as total_sales
        FROM orders
        WHERE created_at BETWEEN ? AND ?
          AND status IN $status_pendapatan_list
        GROUP BY date_group
        ORDER BY date_group ASC
    ";

    // Format tanggal untuk query SQL (YYYY-MM-DD HH:MM:SS)
    $start_sql = $start_date->format('Y-m-d 00:00:00');
    $end_sql = $end_date->format('Y-m-d 23:59:59');

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Gagal mempersiapkan statement: " . $conn->error);
    }
    $stmt->bind_param("sss", $group_format, $start_sql, $end_sql);
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
    $interval_unit = ($group_format === '%Y-%m') ? 'P1M' : 'P1D'; // Interval per bulan atau per hari

    while ($current_date <= $end_date) {
        $date_key = $current_date->format(($group_format === '%Y-%m') ? 'Y-m' : 'Y-m-d');
        $label = $current_date->format($label_format); // Format label sesuai range

        $labels[] = $label;
        $data[] = $sales_data[$date_key] ?? 0; // Masukkan 0 jika tidak ada penjualan

        $current_date->add(new DateInterval($interval_unit));
        // Khusus untuk grouping bulan, pastikan iterasi berhenti setelah bulan dari $end_date
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
    // Tangani error
    http_response_code(500); // Internal Server Error
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>