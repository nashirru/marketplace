<?php
// File: product/product.php
// Versi dengan Pengecekan Limit Berlapis (Termasuk Pending Order & Guest)

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

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
    WHERE p.id = ? AND p.is_active = 1
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flashdata('error', 'Produk yang Anda cari tidak ditemukan atau tidak aktif.');
    redirect("/index.php");
}

$product = $result->fetch_assoc();
$stmt->close();

$user_id = $_SESSION['user_id'] ?? 0;
$max_quantity_allowed = (int)$product['stock'];
$limit_message = '';
$limit = (int)($product['purchase_limit'] ?? 0);

// [PENGECEKAN BERLAPIS DIPERBARUI]
if ($limit > 0) {
    // Ambil jumlah di keranjang (berfungsi untuk guest maupun user)
    // Untuk guest (user_id 0), $conn tidak diperlukan
    $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product['id']);

    if ($user_id > 0) {
        // --- Logika untuk USER LOGIN (menggunakan DB) ---
        $already_bought = get_user_purchase_count($conn, $user_id, $product['id'], $product['stock_cycle_id']);
        $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product['id'], $product['stock_cycle_id']);
        
        $total_committed = $already_bought + $pending_bought;
        $remaining_quota = max(0, $limit - $total_committed);
        
        // Sisa yang bisa ditambahkan (sudah dikurangi yg di keranjang)
        $can_add_to_cart = max(0, $remaining_quota - $quantity_in_cart);
        
        $max_quantity_allowed = min((int)$product['stock'], $can_add_to_cart);
        
        $limit_message = "Limit pembelian: {$limit} buah. ";
        if ($total_committed > 0) {
            $limit_message .= "Kuota terpakai: {$total_committed} (Dibeli: {$already_bought}, Pending: {$pending_bought}). ";
        }
        if ($quantity_in_cart > 0) {
             $limit_message .= "Di keranjang: {$quantity_in_cart}. ";
        }
        $limit_message .= "Sisa kuota Anda: " . max(0, $remaining_quota) . " buah.";
        
    } else {
        // --- ✅ PERBAIKAN: Logika untuk GUEST (menggunakan Session) ---
        $remaining_quota = max(0, $limit - $quantity_in_cart);
        $max_quantity_allowed = min((int)$product['stock'], $remaining_quota);
        
        $limit_message = "Limit pembelian: {$limit} buah. ";
        if ($quantity_in_cart > 0) {
             $limit_message .= "Ada {$quantity_in_cart} di keranjang Anda. ";
        }
        $limit_message .= "Anda bisa tambah " . max(0, $max_quantity_allowed) . " buah lagi.";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> - <?= get_setting($conn, 'store_name') ?></title>
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
        <div class="bg-white p-4 sm:p-8 rounded-xl shadow-lg">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 sm:gap-8">
                <div class="flex justify-center items-center">
                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                         alt="<?= htmlspecialchars($product['name']) ?>" 
                         class="w-full h-auto max-h-[450px] rounded-lg object-contain">
                </div>

                <div>
                    <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($product['category_id'])) ?>" class="text-sm text-indigo-600 font-medium hover:underline"><?= htmlspecialchars($product['category_name']) ?></a>
                    
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mt-2"><?= htmlspecialchars($product['name']) ?></h1>
                    
                    <p class="text-4xl font-bold text-indigo-600 my-4"><?= format_rupiah($product['price']) ?></p>

                    <div class="border-t pt-4">
                        <h3 class="font-semibold text-gray-800 mb-2">Deskripsi Produk</h3>
                        <div class="text-gray-600 leading-relaxed prose max-w-none"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
                    </div>

                    <div class="mt-6">
                        <p class="text-sm text-gray-600 mb-2">Stok Tersedia: <span class="font-semibold text-gray-800"><?= $product['stock'] ?></span></p>
                        
                        <?php if (!empty($limit_message)): ?>
                            <div class="text-sm text-yellow-800 bg-yellow-100 p-3 rounded-md my-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                <?= htmlspecialchars($limit_message) ?>
                            </div>
                        <?php endif; ?>

                        <form action="<?= BASE_URL ?>/cart/add_to_cart.php" method="POST">
                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                            
                            <!-- ✅ Logika Pengecekan Diperbarui: $max_quantity_allowed kini sudah menghitung sisa kuota guest/user -->
                            <?php if ($product['stock'] > 0 && $max_quantity_allowed > 0): ?>
                                <div class="flex flex-wrap items-center gap-4">
                                   <div class="flex items-center gap-2">
                                       <label for="quantity" class="text-sm font-medium text-gray-700">Jumlah:</label>
                                       <!-- ✅ Input 'max' sekarang sudah benar untuk GUEST dan USER -->
                                       <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?= $max_quantity_allowed ?>" class="w-20 border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                                   </div>
                                   <button type="submit" class="w-full sm:w-auto flex-1 px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition-colors shadow-sm disabled:opacity-50">
                                      <i class="fas fa-cart-plus mr-2"></i> Tambah ke Keranjang
                                   </button>
                                </div>
                            <?php else: ?>
                                <div class="mt-4 p-4 bg-red-100 text-red-700 rounded-lg">
                                    <p class="font-semibold">
                                        <!-- ✅ Pesan dinamis berdasarkan stok atau kuota -->
                                        <?= ($product['stock'] <= 0) ? 'Stok Habis' : 'Kuota Pembelian Penuh' ?>
                                    </p>
                                    <p class="text-sm">
                                        <?php if($product['stock'] > 0): ?>
                                            Anda telah mencapai batas pembelian/pemesanan untuk produk ini (termasuk di keranjang).
                                        <?php else: ?>
                                            Produk ini tidak dapat ditambahkan ke keranjang saat ini.
                                        <?php endif; ?>
                                    </p>
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