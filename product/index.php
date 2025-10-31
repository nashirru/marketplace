<?php
// File: product/index.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// --- PENGATURAN PAGINASI ---
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 15; // Jumlah produk per halaman
$offset = ($page - 1) * $limit;

// --- ✅ PERUBAHAN: HITUNG TOTAL PRODUK (TANPA FILTER STOK) ---
$total_result = $conn->query("SELECT COUNT(id) as total FROM products");
$total_products = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_products / $limit);

// --- ✅ PERUBAHAN: AMBIL DATA PRODUK (TERMASUK STOK 0) DAN TAMBAHKAN 'total_sold' ---
$products = [];
$stmt = $conn->prepare("
    SELECT p.*, SUM(oi.quantity) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
    GROUP BY p.id
    ORDER BY p.created_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->bind_param("ii", $limit, $offset);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();

$page_title = "Semua Produk";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <!-- PERBAIKAN: Menggunakan partial.php untuk head -->
    <?php page_head($page_title, $conn); ?>
</head>
<body class="bg-gray-50">

    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 mt-8 min-h-screen">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Semua Produk</h1>
        
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <!-- ✅ PERUBAHAN: Menggunakan fungsi product_card() agar konsisten -->
                    <?php product_card($product); ?>
                <?php endforeach; ?>
            <?php else: ?>
                 <div class="col-span-full bg-white text-center p-12 rounded-lg shadow">
                    <p class="font-semibold text-gray-700">Belum ada produk yang tersedia.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Paginasi -->
        <div class="mt-10 flex justify-center">
            <nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?p=<?= $i ?>"
                       class="relative inline-flex items-center px-4 py-2 border text-sm font-medium 
                       <?= $i == $page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </nav>
        </div>
    </main>

    <?php footer($conn); ?>

</body>
</html>