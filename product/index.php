<?php
// File: product/index.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// --- PENGATURAN PAGINASI ---
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 15; // Jumlah produk per halaman
$offset = ($page - 1) * $limit;

// --- HITUNG TOTAL PRODUK ---
$total_result = $conn->query("SELECT COUNT(id) as total FROM products WHERE stock > 0");
$total_products = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_products / $limit);

// --- AMBIL DATA PRODUK UNTUK HALAMAN SAAT INI ---
$products = [];
$stmt = $conn->prepare("SELECT * FROM products WHERE stock > 0 ORDER BY created_at DESC LIMIT ? OFFSET ?");
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }
    </style>
</head>
<body>

    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 mt-8 min-h-screen">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Semua Produk</h1>
        
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