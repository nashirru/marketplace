<?php
// File: product/index.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// --- PENGATURAN PAGINASI ---
$page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 15; // Jumlah produk per halaman
$offset = ($page - 1) * $limit;

// --- HITUNG TOTAL PRODUK (TANPA FILTER STOK) ---
$total_result = $conn->query("SELECT COUNT(id) as total FROM products WHERE is_active = 1"); // Filter produk aktif
$total_products = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_products / $limit);

// --- ✅ PERUBAHAN UTAMA: AMBIL DATA PRODUK DENGAN PRIORITAS STOK ---
$products = [];
$stmt = $conn->prepare("
    SELECT p.*, SUM(oi.quantity) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed', 'belum_dicetak')
    WHERE p.is_active = 1 -- Hanya tampilkan produk aktif
    GROUP BY p.id
    -- ✅ LOGIKA PRIORITAS STOK:
    -- (p.stock <= 0) ASC -> Stok > 0 (FALSE/0) akan di atas Stok 0 (TRUE/1)
    -- p.created_at DESC -> Urutkan berdasarkan produk terbaru setelah prioritas stok
    ORDER BY (p.stock <= 0) ASC, p.created_at DESC 
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
    <!-- PERBAIKAN: Menggunakan partial.php untuk head -->
    <?php page_head($page_title, $conn); ?>
<body class="bg-gray-50">

    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 mt-8 min-h-screen">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Semua Produk</h1>
        
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6">
            <?php if (!empty($products)): ?>
                <?php foreach ($products as $product): ?>
                    <!-- Menggunakan fungsi product_card() agar konsisten -->
                    <?php product_card($product); ?>
                <?php endforeach; ?>
            <?php else: ?>
                 <div class="col-span-full bg-white text-center p-12 rounded-lg shadow">
                    <p class="font-semibold text-gray-700">Belum ada produk aktif yang tersedia.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Paginasi BARU (Estetik, Bulat, Responsif) -->
        <?php if ($total_pages > 1): ?>
        <div class="mt-10 flex justify-center">
            <nav class="flex justify-center items-center" aria-label="Pagination">
                <?php
                // Tentukan berapa banyak tombol nomor yang akan ditampilkan di sekitar halaman saat ini
                $range = 2;
                $start_index = max(1, $page - $range);
                $end_index = min($total_pages, $page + $range);

                // Sesuaikan rentang agar paginasi terlihat rapi (logika paginasi pintar)
                if ($end_index - $start_index < (2 * $range) && $total_pages > (2 * $range) + 1) {
                    if ($page <= $range + 1) {
                        $end_index = min($total_pages, (2 * $range) + 1);
                    } elseif ($page >= $total_pages - $range) {
                        $start_index = max(1, $total_pages - (2 * $range));
                    }
                }
                ?>

                <div class="flex items-center space-x-1">
                    <!-- Tombol Sebelumnya -->
                    <?php if ($page > 1): ?>
                        <a href="?p=<?= $page - 1 ?>"
                           class="px-3 py-1 text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1 text-sm font-medium rounded-full text-gray-400 bg-gray-50 border border-gray-200 cursor-not-allowed">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </span>
                    <?php endif; ?>
                    
                    <!-- Paginasi Awal (Halaman 1) -->
                    <?php if ($start_index > 1): ?>
                        <a href="?p=1"
                           class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                            1
                        </a>
                        <?php if ($start_index > 2): ?>
                            <span class="text-sm px-2 py-1 text-gray-500">...</span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Tombol Nomor Halaman -->
                    <?php for ($i = $start_index; $i <= $end_index; $i++): ?>
                        <a href="?p=<?= $i ?>"
                           class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full transition-colors 
                                  <?= $i == $page 
                                      ? 'bg-indigo-600 text-white shadow-md border border-indigo-600' 
                                      : 'bg-white text-gray-700 border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>

                    <!-- Paginasi Akhir (Total Halaman) -->
                    <?php if ($end_index < $total_pages): ?>
                        <?php if ($end_index < $total_pages - 1): ?>
                            <span class="text-sm px-2 py-1 text-gray-500">...</span>
                        <?php endif; ?>
                        <a href="?p=<?= $total_pages ?>"
                           class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                            <?= $total_pages ?>
                        </a>
                    <?php endif; ?>

                    <!-- Tombol Selanjutnya -->
                    <?php if ($page < $total_pages): ?>
                        <a href="?p=<?= $page + 1 ?>"
                           class="px-3 py-1 text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1 text-sm font-medium rounded-full text-gray-400 bg-gray-50 border border-gray-200 cursor-not-allowed">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
        <?php endif; ?>
        <!-- AKHIR Paginasi BARU -->
    </main>

    <?php footer($conn); ?>

</body>
</html>