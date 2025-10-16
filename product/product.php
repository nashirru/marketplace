<?php
// File: product/product.php

// ✅ PERBAIKAN: Menggunakan require_once untuk mencegah error redeclare
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// Cek apakah ada ID produk yang dienkripsi
if (!isset($_GET['id'])) {
    redirect("/index.php");
}

$product_id = decode_id($_GET['id']);

if (!$product_id) {
    set_flashdata('error', 'ID produk tidak valid.');
    redirect("/index.php");
}

$stmt = $conn->prepare("
    SELECT p.*, c.name AS category_name 
    FROM products p
    JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flashdata('error', 'Produk yang Anda cari tidak ditemukan.');
    redirect("/index.php");
}

$product = $result->fetch_assoc();
$stmt->close();

// ✅ FITUR BARU: Logika untuk menghitung kuantitas maksimal yang bisa dibeli
$user_id = $_SESSION['user_id'] ?? 0;
$max_quantity_allowed = $product['stock']; // Default max adalah stok yang tersedia
$limit_message = '';

if (!is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) {
    if ($user_id > 0) { // Cek hanya jika user login
        $already_bought = get_user_purchase_count($conn, $user_id, $product['id']);
        $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product['id']);
        
        $remaining_limit = $product['purchase_limit'] - ($already_bought + $quantity_in_cart);
        
        if ($remaining_limit <= 0) {
            $max_quantity_allowed = 0; // Tidak bisa beli lagi
            $limit_message = "Anda telah mencapai batas maksimal pembelian untuk produk ini.";
        } else {
            // User bisa beli, tapi terbatas oleh stok atau sisa limit
            $max_quantity_allowed = min($product['stock'], $remaining_limit);
            $limit_message = "Anda dapat membeli maksimal {$product['purchase_limit']} buah. Sisa kuota Anda: {$remaining_limit} buah.";
        }
    } else {
        // Untuk user guest (belum login), limitnya adalah limit produk itu sendiri atau stok
        $max_quantity_allowed = min($product['stock'], $product['purchase_limit']);
        $limit_message = "Setiap pengguna terdaftar dapat membeli maksimal {$product['purchase_limit']} buah produk ini.";
    }
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?php navbar($conn) ?>

    <main class="container mx-auto px-4 py-8">
        <div class="mb-6">
             <?php flash_message() ?>
        </div>
        <div class="bg-white p-6 sm:p-8 rounded-xl shadow-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Gambar Produk -->
                <div>
                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                         alt="<?= htmlspecialchars($product['name']) ?>" 
                         class="w-full h-auto max-h-96 rounded-lg object-contain"
                         onerror="this.onerror=null;this.src='https://placehold.co/600x600/E2E8F0/4A5568?text=Gambar+Produk';">
                </div>

                <!-- Info Produk -->
                <div>
                    <a href="#" class="text-sm text-indigo-600 font-medium hover:underline"><?= htmlspecialchars($product['category_name']) ?></a>
                    
                    <h1 class="text-3xl font-bold text-gray-800 mt-2"><?= htmlspecialchars($product['name']) ?></h1>
                    
                    <p class="text-sm text-gray-500 mt-2">
                        dijual oleh 
                        <span class="font-semibold text-gray-700">Warok Kite</span>
                    </p>
                    
                    <p class="text-4xl font-bold text-indigo-600 my-4"><?= format_rupiah($product['price']) ?></p>

                    <div class="border-t pt-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Deskripsi Produk</h3>
                        <div class="text-gray-600 leading-relaxed prose"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
                    </div>

                    <div class="mt-6">
                        <p class="text-sm text-gray-600 mb-2">Stok Tersedia: <span class="font-semibold text-gray-800"><?= $product['stock'] ?></span></p>
                        
                        <!-- ✅ FITUR BARU: Menampilkan pesan limit -->
                        <?php if (!empty($limit_message)): ?>
                            <div class="text-sm text-yellow-800 bg-yellow-100 p-3 rounded-md my-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?= htmlspecialchars($limit_message) ?>
                            </div>
                        <?php endif; ?>

                        <form action="<?= BASE_URL ?>/cart/add_to_cart.php" method="POST">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <input type="hidden" name="action" value="add">
                            
                            <?php if ($product['stock'] > 0 && $max_quantity_allowed > 0): ?>
                                <div class="flex items-center gap-4">
                                   <label for="quantity" class="text-sm font-medium text-gray-700">Jumlah:</label>
                                   <!-- ✅ FITUR BARU: Atribut max disesuaikan -->
                                   <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $max_quantity_allowed ?>" class="w-20 border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                   <button type="submit" class="flex-1 px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50"
                                    <?= $max_quantity_allowed <= 0 ? 'disabled' : '' ?>
                                   >
                                      <i class="fas fa-cart-plus mr-2"></i> Tambah ke Keranjang
                                   </button>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 p-4 bg-red-100 text-red-700 rounded-lg">
                                    <p class="font-semibold">
                                        <?php if ($product['stock'] <= 0): ?>
                                            Stok Habis
                                        <?php else: ?>
                                            Kuota Pembelian Penuh
                                        <?php endif; ?>
                                    </p>
                                    <p>Produk ini tidak tersedia untuk Anda saat ini.</p>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <?php footer($conn) ?>

</body>
</html>