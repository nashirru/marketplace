<?php
// File: checkout/get_encoded_id.php
// FILE BARU: Tugasnya hanya 1, mengambil ID dan mengenkripsinya
// Ini dibutuhkan oleh JavaScript untuk membuat tombol Invoice secara real-time.

header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../sistem/sistem.php'; // (encode_id ada di sini)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Validasi minimal
if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$order_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

// Verifikasi bahwa order ID ini milik user yang login
// (Ini langkah keamanan sederhana)
$stmt = $conn->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    // Sukses, enkripsi ID
    echo json_encode([
        'success' => true,
        'encoded_id' => urlencode(encode_id($order_id))
    ]);
} else {
    // Gagal, order tidak ditemukan atau bukan milik user
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Order not found or not authorized']);
}
$stmt->close();
?>