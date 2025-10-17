<?php
// File: index.php - Halaman Beranda Utama Marketplace

require_once 'config/config.php';
require_once 'sistem/sistem.php';
require_once 'partial/partial.php';

load_settings($conn);

// =================================================================
// LOGIKA PENCARIAN & TAMPILAN PRODUK
// =================================================================
$search_query = isset($_GET['s']) ? sanitize_input($_GET['s']) : '';
$is_searching = !empty($search_query);

$products = [];
// ✅ PERBAIKAN QUERY: Menambahkan 'total_sold' untuk setiap produk
$base_product_query = "
    SELECT p.*, SUM(oi.quantity) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
    WHERE p.stock > 0
";

if ($is_searching) {
    $search_param = "%{$search_query}%";
    $stmt = $conn->prepare($base_product_query . " AND p.name LIKE ? GROUP BY p.id ORDER BY p.created_at DESC");
    $stmt->bind_param("s", $search_param);
} else {
    $stmt = $conn->prepare($base_product_query . " GROUP BY p.id ORDER BY p.created_at DESC LIMIT 12");
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
$stmt->close();


// ✅ PERBAIKAN QUERY: Menghitung jumlah terjual untuk setiap kategori
$categories = [];
if (!$is_searching) {
    $cat_result = $conn->query("
        SELECT c.*, SUM(oi.quantity) as total_sold
        FROM categories c
        LEFT JOIN products p ON c.id = p.category_id
        LEFT JOIN order_items oi ON p.id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    if ($cat_result) {
        while ($row = $cat_result->fetch_assoc()) $categories[] = $row;
    }
}

$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite Marketplace';
$page_title = $is_searching ? 'Hasil Pencarian untuk "' . htmlspecialchars($search_query) . '"' : htmlspecialchars($store_name) . ' - Beranda';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }
    </style>
</head>
<body class="bg-gray-50">

    <?php navbar($conn); ?>
    
    <div class="container mx-auto px-4">
         <?php flash_message(); ?>
    </div>

    <main class="min-h-screen">
        
        <?php if (!$is_searching): ?>
            <?php banner_slide($conn); ?>
            <div class="container mx-auto px-4 mt-8 sm:mt-12">
                <h2 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6">Kategori Unggulan</h2>
                <div class="grid grid-cols-3 sm:grid-cols-3 md:grid-cols-6 gap-4">
                    <?php foreach ($categories as $category): ?>
                        <?php category_card($category); // ✅ Menggunakan fungsi card baru ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="container mx-auto px-4 mt-12">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6"><?= $is_searching ? 'Hasil Pencarian' : 'Produk Terbaru' ?></h2>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <?php product_card($product); // ✅ Menggunakan fungsi card baru ?>
                    <?php endforeach; ?>
                <?php else: ?>
                     <div class="col-span-full bg-white text-center p-12 rounded-lg shadow">
                        <p class="font-semibold text-gray-700">Produk tidak ditemukan.</p>
                        <p class="text-sm text-gray-500 mt-2">Coba gunakan kata kunci lain atau <a href="<?= BASE_URL ?>/" class="font-bold underline text-indigo-600">kembali ke beranda</a>.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!$is_searching && !empty($products)): ?>
            <div class="text-center mt-8">
                 <a href="<?= BASE_URL ?>/product/" class="inline-block px-8 py-3 bg-indigo-500 text-white font-semibold rounded-full hover:bg-indigo-600 transition shadow-lg">Lihat Semua Produk</a>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <?php footer($conn); ?>

</body>
</html>