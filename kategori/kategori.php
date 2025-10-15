<?php
// File: kategori/kategori.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

// Mendapatkan ID kategori dari URL, jika ada
$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$category_name = "Semua Kategori";
$products = [];

// Query untuk mengambil produk
if ($category_id > 0) {
    // Ambil produk berdasarkan kategori tertentu
    $stmt_cat = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt_cat->bind_param("i", $category_id);
    $stmt_cat->execute();
    $result_cat = $stmt_cat->get_result();
    if($result_cat->num_rows > 0){
        $category_name = $result_cat->fetch_assoc()['name'];
    }

    $stmt_prod = $conn->prepare("SELECT * FROM products WHERE category_id = ? ORDER BY created_at DESC");
    $stmt_prod->bind_param("i", $category_id);
    $stmt_prod->execute();
    $result_prod = $stmt_prod->get_result();
    while($row = $result_prod->fetch_assoc()){
        $products[] = $row;
    }
} else {
    // Ambil semua produk jika tidak ada ID kategori
    $result_prod = $conn->query("SELECT * FROM products ORDER BY created_at DESC");
    while($row = $result_prod->fetch_assoc()){
        $products[] = $row;
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($category_name) ?> - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    
    <?= navbar($conn) ?>

    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8"><?= htmlspecialchars($category_name) ?></h1>
        
        <?php if(empty($products)): ?>
            <div class="text-center py-16 bg-white rounded-lg shadow-md">
                <p class="text-gray-500">Belum ada produk di kategori ini.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 sm:gap-6">
                 <?php foreach($products as $product): ?>
                <a href="<?= BASE_URL ?>/product/product.php?id=<?= $product['id'] ?>" class="group block bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-xl transition-shadow duration-300">
                    <div class="w-full h-40 sm:h-48 overflow-hidden">
                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                             alt="<?= htmlspecialchars($product['name']) ?>" 
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                             onerror="this.onerror=null;this.src='https://placehold.co/400x400/E2E8F0/4A5568?text=Gambar+Rusak';">
                    </div>
                    <div class="p-3 sm:p-4">
                        <h3 class="text-sm sm:text-base font-semibold text-gray-800 truncate"><?= htmlspecialchars($product['name']) ?></h3>
                        <p class="text-base sm:text-lg font-bold text-indigo-600 mt-1"><?= format_rupiah($product['price']) ?></p>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <?= footer($conn) ?>
</body>
</html>