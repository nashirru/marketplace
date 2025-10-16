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
// Query disesuaikan untuk hanya mengambil produk yang aktif
$is_active_clause = " WHERE stock > 0 ";

if ($is_searching) {
    $search_param = "%{$search_query}%";
    $stmt = $conn->prepare("SELECT * FROM products " . $is_active_clause . " AND name LIKE ? ORDER BY created_at DESC");
    $stmt->bind_param("s", $search_param);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $products[] = $row;
    $stmt->close();
} else {
    $prod_result = $conn->query("SELECT * FROM products " . $is_active_clause . " ORDER BY created_at DESC LIMIT 12");
    if ($prod_result) {
        while ($row = $prod_result->fetch_assoc()) $products[] = $row;
    }
}

// Hanya ambil data kategori jika tidak sedang melakukan pencarian
$categories = [];
if (!$is_searching) {
    $cat_result = $conn->query("SELECT * FROM categories ORDER BY name ASC LIMIT 6");
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
        .product-card { transition: transform 0.2s, box-shadow 0.2s; }
        .product-card:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body>

    <?php navbar($conn); ?>

    <main class="min-h-screen">
        
        <?php if (!$is_searching): ?>
            <?php banner_slide($conn); ?>
            <div class="container mx-auto px-4 mt-12">
                <h2 class="text-3xl font-bold text-gray-800 mb-6">Kategori Unggulan</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
                    <?php foreach ($categories as $category): ?>
                        <a href="<?= BASE_URL ?>/product/product.php?category=<?= urlencode(encode_id($category['id'])) ?>" class="bg-white rounded-xl shadow-lg hover:shadow-xl transition p-4 flex items-center justify-center text-center product-card h-24">
                            <p class="font-semibold text-gray-700"><?= htmlspecialchars($category['name']) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="container mx-auto px-4 mt-12">
            <h2 class="text-3xl font-bold text-gray-800 mb-6"><?= $is_searching ? 'Hasil Pencarian' : 'Produk Terbaru' ?></h2>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <div class="bg-white rounded-lg shadow-md overflow-hidden group border hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col">
                            <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" class="block">
                                <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                                     alt="<?= htmlspecialchars($product['name']) ?>" 
                                     class="h-40 sm:h-48 w-full object-cover">
                            </a>
                            <div class="p-3 sm:p-4 flex-grow">
                                <h3 class="text-sm sm:text-base font-semibold text-gray-800 line-clamp-2">
                                    <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" class="hover:text-indigo-600">
                                        <?= htmlspecialchars($product['name']) ?>
                                    </a>
                                </h3>
                            </div>
                            <div class="p-3 sm:p-4 border-t">
                                <p class="text-base sm:text-lg font-bold text-indigo-600"><?= format_rupiah($product['price']) ?></p>
                            </div>
                        </div>
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