<?php
// File: admin/import/ajax_search_orders.php
// File BARU untuk menangani pencarian order di modal match manual

// Load config dan sistem
include '../../config/config.php';
include '../../sistem/sistem.php';

// Keamanan: Hanya Admin
check_admin();

// Set header sebagai JSON
header('Content-Type: application/json');

$search_term = $_POST['search_term'] ?? '';

if (strlen($search_term) < 3) {
    echo json_encode([]);
    exit;
}

$search_param = "%" . $search_term . "%";
$orders = [];

// Cari order yang BELUM selesai, berdasarkan nomor order, nama, atau HP
$stmt = $conn->prepare("
    SELECT id, order_number, full_name, address_line_1, status
    FROM orders 
    WHERE (order_number LIKE ? OR full_name LIKE ? OR phone_number LIKE ?)
      AND status NOT IN ('completed', 'cancelled')
    LIMIT 10
");
$stmt->bind_param("sss", $search_param, $search_param, $search_param);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
$stmt->close();

echo json_encode($orders);
exit;
?>