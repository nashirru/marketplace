<?php
// File: debug_check_records.php
// Letakkan di root folder untuk debugging
// Akses: http://localhost/warokkite/debug_check_records.php

require_once 'config/config.php';
require_once 'sistem/sistem.php';

// Cek apakah user login sebagai admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    die("Access denied. Admin only.");
}

$user_id = $_GET['user_id'] ?? $_SESSION['user_id'];
$product_id = $_GET['product_id'] ?? null;

echo "<h1>Debug: Purchase Records Checker</h1>";
echo "<p>User ID yang dicek: <strong>$user_id</strong></p>";
echo "<hr>";

// 1. CEK DATA PRODUK
if ($product_id) {
    $stmt = $conn->prepare("SELECT id, name, stock, purchase_limit, stock_cycle_id, last_stock_reset FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($product) {
        echo "<h2>📦 Product Info (ID: $product_id)</h2>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>Nama</th><td>{$product['name']}</td></tr>";
        echo "<tr><th>Stock</th><td>{$product['stock']}</td></tr>";
        echo "<tr><th>Purchase Limit</th><td>{$product['purchase_limit']}</td></tr>";
        echo "<tr><th>Stock Cycle ID</th><td><strong style='color:red;'>{$product['stock_cycle_id']}</strong></td></tr>";
        echo "<tr><th>Last Stock Reset</th><td>{$product['last_stock_reset']}</td></tr>";
        echo "</table>";
    }
}

// 2. CEK RECORD PEMBELIAN USER
echo "<h2>📊 User Purchase Records (User ID: $user_id)</h2>";
$stmt = $conn->prepare("
    SELECT upr.*, p.name as product_name, p.stock_cycle_id as current_cycle 
    FROM user_purchase_records upr
    JOIN products p ON upr.product_id = p.id
    WHERE upr.user_id = ?
    ORDER BY upr.last_purchase_date DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($records)) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Product</th><th>Recorded Cycle</th><th>Current Cycle</th><th>Qty Purchased</th><th>Last Purchase</th><th>Match?</th></tr>";
    foreach ($records as $rec) {
        $match = ($rec['stock_cycle_id'] == $rec['current_cycle']) ? "✅ YES" : "❌ NO (Cycle Changed)";
        $row_color = ($rec['stock_cycle_id'] == $rec['current_cycle']) ? "background-color: #ffcccc;" : "";
        echo "<tr style='$row_color'>";
        echo "<td>{$rec['product_name']} (ID: {$rec['product_id']})</td>";
        echo "<td><strong>{$rec['stock_cycle_id']}</strong></td>";
        echo "<td><strong>{$rec['current_cycle']}</strong></td>";
        echo "<td>{$rec['quantity_purchased']}</td>";
        echo "<td>{$rec['last_purchase_date']}</td>";
        echo "<td>$match</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red;'><strong>⚠️ TIDAK ADA RECORD PEMBELIAN!</strong> Ini berarti webhook tidak mencatat pembelian.</p>";
}

// 3. CEK ORDER USER YANG SUDAH DIBAYAR
echo "<h2>🛒 Paid Orders (User ID: $user_id)</h2>";
$stmt = $conn->prepare("
    SELECT id, order_number, status, total, created_at 
    FROM orders 
    WHERE user_id = ? AND status IN ('belum_dicetak', 'processed', 'shipped', 'completed')
    ORDER BY created_at DESC
    LIMIT 10
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($orders)) {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Order Number</th><th>Status</th><th>Total</th><th>Created At</th></tr>";
    foreach ($orders as $order) {
        echo "<tr>";
        echo "<td>{$order['order_number']}</td>";
        echo "<td>{$order['status']}</td>";
        echo "<td>" . format_rupiah($order['total']) . "</td>";
        echo "<td>{$order['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Tidak ada pesanan yang sudah dibayar.</p>";
}

// 4. CEK LOG FILE
echo "<h2>📄 Latest Webhook Logs</h2>";
$log_file = __DIR__ . '/logs/midtrans.log';
if (file_exists($log_file)) {
    $lines = file($log_file);
    $last_50_lines = array_slice($lines, -50);
    echo "<pre style='background:#f4f4f4; padding:10px; overflow:auto; max-height:400px;'>";
    echo htmlspecialchars(implode('', $last_50_lines));
    echo "</pre>";
} else {
    echo "<p style='color:red;'>⚠️ Log file tidak ditemukan di: $log_file</p>";
}

echo "<hr>";
echo "<h3>🔧 Tools</h3>";
echo "<ul>";
echo "<li><a href='?user_id=$user_id'>Refresh</a></li>";
echo "<li><a href='?user_id=$user_id&product_id=1'>Cek Product ID 1</a></li>";
echo "<li><a href='admin/admin.php'>Go to Admin Panel</a></li>";
echo "</ul>";