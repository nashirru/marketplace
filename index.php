<?php
// File: index.php - Halaman Beranda Utama Marketplace

require_once 'config/config.php';
require_once 'sistem/sistem.php';
require_once 'partial/partial.php';

load_settings($conn);

// =================================================================
// LOGIKA PENCARIAN & TAMPILAN PRODUKKKK
// =================================================================
$search_query = isset($_GET['s']) ? sanitize_input($_GET['s']) : '';
$is_searching = !empty($search_query);

$products = [];
// ✅ PERUBAHAN: Menghapus 'WHERE p.stock > 0'
$base_product_query = "
    SELECT p.*, SUM(oi.quantity) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
"; // <-- Klausa 'WHERE' dihapus dari sini

if ($is_searching) {
    $search_param = "%{$search_query}%";
    // Tambahkan 'WHERE' di sini karena base query sudah tidak memilikinya
    $stmt = $conn->prepare($base_product_query . " WHERE p.name LIKE ? GROUP BY p.id ORDER BY p.created_at DESC");
    $stmt->bind_param("s", $search_param);
} else {
    // Langsung GROUP BY karena tidak ada filter 'WHERE'
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
<?php page_head($page_title, $conn); // Memanggil fungsi head terpusat ?>
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
                        <?php category_card($category); // Menggunakan fungsi card baru ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="container mx-auto px-4 mt-12">
            <h2 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6"><?= $is_searching ? 'Hasil Pencarian' : 'Produk Terbaru' ?></h2>
            
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <?php product_card($product); // Menggunakan fungsi card baru ?>
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