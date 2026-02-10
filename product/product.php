<?php
// File: product/product.php
// VERSI UPGRADE V2.2: RED THEME & CLEAN DESIGN
// Programmer IQ 180 Edition

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// --- 1. Validasi & Pengambilan Data Produk Utama ---
if (!isset($_GET['id'])) {
    redirect("/index.php");
}

$product_id = decode_id($_GET['id']);

if (!$product_id) {
    set_flashdata('error', 'ID produk tidak valid.');
    redirect("/index.php");
}

// Query Produk Utama
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

// --- 2. Logika Variasi (CORE UPDATE) ---
$variations = [];
$min_price = $product['price'];
$max_price = $product['price'];
$total_variation_stock = 0;

if ($product['has_variation'] == 1) {
    $stmt_var = $conn->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY price ASC");
    $stmt_var->bind_param("i", $product_id);
    $stmt_var->execute();
    $res_var = $stmt_var->get_result();
    
    $prices = [];
    while ($row = $res_var->fetch_assoc()) {
        $variations[] = $row;
        $prices[] = $row['price'];
        $total_variation_stock += $row['stock'];
    }
    $stmt_var->close();

    if (!empty($prices)) {
        $min_price = min($prices);
        $max_price = max($prices);
    }
    
    // Override stok tampilan utama dengan total stok variasi agar sinkron
    $product['stock'] = $total_variation_stock;
}

// --- 3. Logika Limit Pembelian ---
$user_id = $_SESSION['user_id'] ?? 0;
$max_quantity_allowed = (int)$product['stock']; 
$limit_message = '';
$limit = (int)($product['purchase_limit'] ?? 0);

if ($limit > 0) {
    $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product['id']);

    if ($user_id > 0) {
        $already_bought = get_user_purchase_count($conn, $user_id, $product['id'], $product['stock_cycle_id']);
        $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product['id'], $product['stock_cycle_id']);
        $total_committed = $already_bought + $pending_bought;
        $remaining_quota = max(0, $limit - $total_committed);
        $can_add_to_cart = max(0, $remaining_quota - $quantity_in_cart);
        $max_quantity_allowed = min((int)$product['stock'], $can_add_to_cart);
        $limit_message = "Limit: {$limit}. Terpakai: {$total_committed}. Sisa Kuota: " . max(0, $remaining_quota);
    } else {
        $remaining_quota = max(0, $limit - $quantity_in_cart);
        $max_quantity_allowed = min((int)$product['stock'], $remaining_quota);
        $limit_message = "Limit pembelian: {$limit}. Sisa: " . max(0, $max_quantity_allowed);
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($product['name']) ?> - <?= get_setting($conn, 'store_name') ?></title>
    <!-- Pastikan Partial Head dipanggil di partial.php, tapi di sini kita butuh script spesifik -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-in-out; }
        @keyframes fadeIn { from { opacity: 0.5; } to { opacity: 1; } }
        
        /* Updated Style: RED THEME */
        .var-btn.selected {
            border-color: #b91c1c; /* red-700 */
            background-color: #fef2f2; /* red-50 */
            color: #b91c1c; /* red-700 */
            box-shadow: 0 0 0 1px #b91c1c;
        }
        .var-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #f3f4f6;
            text-decoration: line-through;
        }
    </style>
</head>
<body class="bg-white text-gray-800">

    <?php navbar($conn) ?>

    <main class="container mx-auto px-4 py-8 max-w-7xl">
        <div class="mb-6">
             <?php flash_message() ?>
        </div>
        
        <!-- Breadcrumb Simple -->
        <nav class="flex mb-6 text-sm text-gray-500">
            <a href="<?= BASE_URL ?>/" class="hover:text-red-700">Beranda</a>
            <span class="mx-2">/</span>
            <a href="<?= BASE_URL ?>/kategori/kategori.php" class="hover:text-red-700">Kategori</a>
            <span class="mx-2">/</span>
            <span class="text-gray-800 font-medium truncate max-w-xs"><?= htmlspecialchars($product['name']) ?></span>
        </nav>
        
        <div class="bg-white rounded-2xl">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 lg:gap-12">
                
                <!-- KOLOM KIRI: GAMBAR PRODUK -->
                <div class="flex flex-col gap-4">
                    <div class="relative bg-gray-50 rounded-xl overflow-hidden group border border-gray-100">
                        <!-- Gambar Utama -->
                        <img id="mainImage" 
                             src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                             alt="<?= htmlspecialchars($product['name']) ?>" 
                             class="w-full h-auto max-h-[500px] object-contain transition-transform duration-300 group-hover:scale-105 p-4">
                        
                        <?php if($product['has_variation']): ?>
                            <span class="absolute top-4 right-4 bg-red-700 text-white text-[10px] font-bold px-3 py-1 rounded-full shadow-sm tracking-wide">
                                BERVARIASI
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KOLOM KANAN: DETAIL & FORM -->
                <div class="flex flex-col">
                    <!-- Kategori & Nama -->
                    <div class="mb-4">
                        <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($product['category_id'])) ?>" class="text-xs font-bold text-red-600 uppercase tracking-widest hover:underline mb-2 block">
                            <?= htmlspecialchars($product['category_name']) ?>
                        </a>
                        <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mt-1 leading-tight tracking-tight">
                            <?= htmlspecialchars($product['name']) ?>
                        </h1>
                    </div>
                    
                    <!-- Harga (Dinamis) -->
                    <div class="mb-6 border-b border-gray-100 pb-6">
                        <p id="displayPrice" class="text-3xl sm:text-4xl font-bold text-red-700">
                            <?php if ($product['has_variation'] && $min_price != $max_price): ?>
                                <!-- Tampilkan Range jika ada variasi harga beda -->
                                <?= format_rupiah($min_price) ?> - <?= format_rupiah($max_price) ?>
                            <?php else: ?>
                                <?= format_rupiah($product['price']) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Deskripsi Singkat -->
                    <div class="prose prose-sm text-gray-600 mb-8 max-h-60 overflow-y-auto custom-scrollbar leading-relaxed">
                        <?= nl2br(htmlspecialchars($product['description'])) ?>
                    </div>

                    <form action="<?= BASE_URL ?>/cart/add_to_cart.php" method="POST" id="addToCartForm">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <!-- Input Hidden Variation ID (Akan diisi via JS) -->
                        <input type="hidden" name="variation_id" id="selectedVariationId" value="">
                        
                        <!-- === LOGIKA PILIHAN VARIASI === -->
                        <?php if ($product['has_variation'] && !empty($variations)): ?>
                            <div class="mb-8">
                                <label class="block text-sm font-bold text-gray-900 mb-3">Pilih Variasi:</label>
                                <div class="flex flex-wrap gap-3" id="variationContainer">
                                    <?php foreach ($variations as $var): ?>
                                        <button type="button" 
                                                class="var-btn px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium transition-all hover:border-red-400 focus:outline-none <?= $var['stock'] <= 0 ? 'disabled' : '' ?>"
                                                data-id="<?= $var['id'] ?>"
                                                data-price="<?= $var['price'] ?>"
                                                data-stock="<?= $var['stock'] ?>"
                                                data-image="<?= !empty($var['image']) ? BASE_URL . '/assets/images/produk/' . $var['image'] : '' ?>"
                                                <?= $var['stock'] <= 0 ? 'disabled' : '' ?>>
                                            <?= htmlspecialchars($var['name']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <p id="variationError" class="text-red-500 text-sm mt-2 hidden flex items-center">
                                    <i class="fas fa-exclamation-circle mr-1"></i> Mohon pilih variasi terlebih dahulu.
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Informasi Stok & Limit -->
                        <div class="bg-gray-50 rounded-xl p-4 mb-6 border border-gray-100">
                            <div class="flex justify-between items-center mb-1">
                                <span class="text-sm font-medium text-gray-600">Stok Tersedia</span>
                                <span id="displayStock" class="font-bold text-gray-900 text-lg"><?= $product['stock'] ?></span>
                            </div>
                            <?php if (!empty($limit_message)): ?>
                                <div class="text-xs text-red-700 bg-red-50 px-3 py-2 rounded mt-2 border border-red-100">
                                    <i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($limit_message) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tombol Aksi -->
                        <div class="flex items-center gap-4">
                            <!-- Input Jumlah -->
                            <div class="w-32">
                                <label for="quantity" class="sr-only">Jumlah</label>
                                <div class="relative flex items-center">
                                    <button type="button" id="btnMinus" class="bg-white hover:bg-gray-100 border border-gray-300 rounded-l-lg p-3 h-12 focus:outline-none transition-colors">
                                        <i class="fas fa-minus text-gray-600 text-xs"></i>
                                    </button>
                                    <input type="number" id="quantity" name="quantity" 
                                           class="bg-white border-y border-gray-300 h-12 text-center text-gray-900 font-bold text-base focus:outline-none block w-full" 
                                           value="1" min="1" max="<?= $max_quantity_allowed ?>" required>
                                    <button type="button" id="btnPlus" class="bg-white hover:bg-gray-100 border border-gray-300 rounded-r-lg p-3 h-12 focus:outline-none transition-colors">
                                        <i class="fas fa-plus text-gray-600 text-xs"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" id="btnAddToCart" class="flex-1 text-white bg-red-700 hover:bg-red-800 focus:ring-4 focus:ring-red-200 font-bold rounded-xl text-base px-5 py-3 h-12 text-center transition-all shadow-md hover:shadow-lg disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed disabled:shadow-none flex items-center justify-center">
                                <i class="fas fa-cart-plus mr-2"></i> Tambah ke Keranjang
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
    
    <?php footer($conn) ?>

    <!-- JAVASCRIPT LOGIKA TAMPILAN -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const hasVariation = <?= $product['has_variation'] ?>;
        const basePrice = <?= $product['price'] ?>;
        const baseImage = "<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>";
        const maxLimit = <?= $limit > 0 ? $limit : 0 ?>;
        let currentMaxStock = <?= $max_quantity_allowed ?>; 

        const displayPrice = document.getElementById('displayPrice');
        const displayStock = document.getElementById('displayStock');
        const mainImage = document.getElementById('mainImage');
        const inputQty = document.getElementById('quantity');
        const hiddenVarId = document.getElementById('selectedVariationId');
        const btnAdd = document.getElementById('btnAddToCart');
        const varBtns = document.querySelectorAll('.var-btn');
        const variationError = document.getElementById('variationError');
        const btnMinus = document.getElementById('btnMinus');
        const btnPlus = document.getElementById('btnPlus');

        const formatRupiah = (number) => {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(number);
        };

        if (hasVariation) {
            btnAdd.disabled = true;
            btnAdd.innerHTML = "<span class='text-sm'>Pilih Variasi Dulu</span>";
            btnAdd.classList.add('bg-gray-300', 'text-gray-500');
            btnAdd.classList.remove('bg-red-700', 'text-white');

            varBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    if (this.classList.contains('disabled')) return;
                    varBtns.forEach(b => b.classList.remove('selected'));
                    this.classList.add('selected');
                    variationError.classList.add('hidden');

                    const id = this.dataset.id;
                    const price = parseFloat(this.dataset.price);
                    const stock = parseInt(this.dataset.stock);
                    const image = this.dataset.image;

                    displayPrice.textContent = formatRupiah(price);
                    displayPrice.classList.add('fade-in');
                    setTimeout(() => displayPrice.classList.remove('fade-in'), 300);

                    displayStock.textContent = stock;
                    let finalMax = maxLimit > 0 ? Math.min(stock, maxLimit) : stock; 
                    
                    inputQty.max = finalMax;
                    inputQty.value = 1;

                    if (image && image.trim() !== "") {
                        mainImage.src = image;
                    } else {
                        mainImage.src = baseImage;
                    }

                    hiddenVarId.value = id;

                    btnAdd.disabled = false;
                    btnAdd.innerHTML = "<i class='fas fa-cart-plus mr-2'></i> Tambah ke Keranjang";
                    btnAdd.classList.remove('bg-gray-300', 'text-gray-500');
                    btnAdd.classList.add('bg-red-700', 'text-white', 'hover:bg-red-800');
                });
            });
        }

        btnMinus.addEventListener('click', () => {
            let val = parseInt(inputQty.value) || 1;
            if (val > 1) inputQty.value = val - 1;
        });

        btnPlus.addEventListener('click', () => {
            let val = parseInt(inputQty.value) || 1;
            let max = parseInt(inputQty.max) || 100;
            if (val < max) inputQty.value = val + 1;
        });

        document.getElementById('addToCartForm').addEventListener('submit', function(e) {
            if (hasVariation && hiddenVarId.value === "") {
                e.preventDefault();
                variationError.classList.remove('hidden');
                document.getElementById('variationContainer').scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });
    </script>
</body>
</html>