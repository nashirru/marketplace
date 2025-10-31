<?php
// File: kategori/kategori.php
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

$category_id = isset($_GET['id']) ? decode_id($_GET['id']) : null;
$page_title = "Semua Kategori";
$current_category_name = '';

if ($category_id) {
    // Tampilan Produk per Kategori
    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $current_category_name = $row['name'];
        $page_title = "Kategori: " . htmlspecialchars($current_category_name);
    } else {
        redirect(BASE_URL . '/kategori/kategori.php');
    }
    $stmt->close();

    $products = [];
    // ✅ PERUBAHAN: Menghapus 'AND p.stock > 0' dari kueri
    $stmt_prod = $conn->prepare("
        SELECT p.*, SUM(oi.quantity) as total_sold
        FROM products p
        LEFT JOIN order_items oi ON p.id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
        WHERE p.category_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt_prod->bind_param("i", $category_id);
    $stmt_prod->execute();
    $result_prod = $stmt_prod->get_result();
    while ($row = $result_prod->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt_prod->close();

} else {
    // Tampilan Semua Kategori
    $categories = [];
    $result_cat = $conn->query("
        SELECT c.*
        FROM categories c
        ORDER BY c.name ASC
    ");
    while ($row = $result_cat->fetch_assoc()) {
        $categories[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <!-- PERBAIKAN: Menggunakan partial.php untuk head -->
    <?php page_head($page_title, $conn); ?>
</head>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="container mx-auto px-4">
             <?php flash_message(); ?>
        </div>
        
        <?php if ($category_id): ?>
            <!-- Tampilan Produk dalam Kategori -->
            <div class="flex items-center mb-8">
                 <!-- PERBAIKAN: Link kembali ke Beranda (index.php) -->
                 <a href="<?= BASE_URL ?>/" class="text-indigo-600 hover:text-indigo-800 mr-2"><i class="fas fa-arrow-left"></i> Kembali ke Beranda</a>
                 <span class="text-gray-400">/</span>
                 <h1 class="text-3xl font-bold text-gray-800 ml-2"><?= htmlspecialchars($current_category_name) ?></h1>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <?php product_card($product); // ✅ Memanggil fungsi partial ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-full bg-white text-center p-12 rounded-lg shadow">
                        <p class="font-semibold text-gray-700">Belum ada produk di kategori ini.</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Tampilan Semua Kategori -->
            <h1 class="text-3xl font-bold text-gray-800 mb-8">Semua Kategori</h1>
            <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-4 sm:gap-6">
                <?php foreach ($categories as $category): ?>
                    <?php category_card($category); // ✅ Memanggil fungsi partial ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php footer($conn); ?>
</body>
</html>