<?php
// File: product/product.php
include '../config/config.php';
include '../sistem/sistem.php';

// Cek apakah ada ID produk
if (!isset($_GET['id'])) {
    redirect('/'); // Redirect ke homepage jika tidak ada ID
}

$product_id = sanitize_input($_GET['id']);

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
    echo "Produk tidak ditemukan.";
    exit;
}

$product = $result->fetch_assoc();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?php include '../partial/partial.php'; ?>
    <?= navbar() ?>

    <main class="container mx-auto px-4 py-8">
        <!-- PERBAIKAN: Tempat untuk menampilkan notifikasi -->
        <div class="mb-6">
            <?= flash_message('success') ?>
            <?= flash_message('error') ?>
        </div>
        <div class="bg-white p-6 sm:p-8 rounded-lg shadow-md">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Gambar Produk -->
                <div>
                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                         alt="<?= htmlspecialchars($product['name']) ?>" 
                         class="w-full h-auto max-h-96 rounded-lg object-contain"
                         onerror="this.onerror=null;this.src='https://placehold.co/600x600/E2E8F0/4A5568?text=Gambar+Rusak';">
                </div>

                <!-- Info Produk -->
                <div>
                    <a href="#" class="text-sm text-indigo-600 font-medium"><?= htmlspecialchars($product['category_name']) ?></a>
                    
                    <h1 class="text-3xl font-bold text-gray-800 mt-2"><?= htmlspecialchars($product['name']) ?></h1>
                    
                    <p class="text-sm text-gray-500 mt-2">
                        dijual oleh 
                        <span class="font-semibold text-gray-700">Warok Kite</span>
                    </p>
                    
                    <p class="text-4xl font-bold text-indigo-600 my-4"><?= format_rupiah($product['price']) ?></p>

                    <div class="border-t pt-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Deskripsi Produk</h3>
                        <p class="text-gray-600 leading-relaxed"><?= nl2br(htmlspecialchars($product['description'])) ?></p>
                    </div>

                    <div class="mt-6">
                        <p class="text-sm text-gray-600 mb-2">Stok Tersedia: <span class="font-semibold"><?= $product['stock'] ?></span></p>
                        
                        <form action="<?= BASE_URL ?>/cart/cart.php" method="POST">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            <input type="hidden" name="action" value="add">
                            
                            <div class="flex items-center gap-4">
                               <label for="quantity" class="text-sm">Jumlah:</label>
                               <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $product['stock'] ?>" class="w-20 border-gray-300 rounded-md">
                               <button type="submit" class="flex-1 px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
                                  Tambah ke Keranjang
                               </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?= footer() ?>

</body>
</html>