<?php
// File: admin/produk/form_produk.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// Inisialisasi variabel
$product = [
    'id' => '', 'name' => '', 'category_id' => '', 'price' => '',
    'stock' => '', 'description' => '', 'image' => '', 'purchase_limit' => null
];
$page_title = "Tambah Produk Baru";
$form_action = "save_product";

// Ambil semua kategori untuk dropdown
$categories = [];
$cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// Jika ini adalah form edit, ambil data produk
if ($action == 'edit' && isset($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        // Konversi 0 menjadi null jika purchase_limit adalah 0 (dianggap unlimited)
        $product['purchase_limit'] = ($product['purchase_limit'] == 0) ? null : $product['purchase_limit'];
        $page_title = "Edit Produk: " . htmlspecialchars($product['name']);
    } else {
        set_flashdata('error', 'Produk tidak ditemukan.');
        redirect('/admin/admin.php?page=produk');
    }
    $stmt->close();
}
?>

<div class="bg-white p-6 rounded-lg shadow-md max-w-3xl mx-auto">
    <h2 class="text-2xl font-bold text-gray-800 mb-6 border-b pb-4"><?= $page_title ?></h2>

    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama Produk</label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required class="w-full border-gray-300 rounded-md shadow-sm">
            </div>
            <div>
                <label for="category_id" class="block text-sm font-medium text-gray-700 mb-1">Kategori</label>
                <select id="category_id" name="category_id" required class="w-full border-gray-300 rounded-md shadow-sm">
                    <option value="">Pilih Kategori...</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $product['category_id'] == $category['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
            <div>
                <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Harga (Rp)</label>
                <input type="number" id="price" name="price" value="<?= htmlspecialchars($product['price']) ?>" required class="w-full border-gray-300 rounded-md shadow-sm" placeholder="Contoh: 50000">
            </div>
            <div>
                <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Stok</label>
                <input type="number" id="stock" name="stock" value="<?= htmlspecialchars($product['stock']) ?>" required class="w-full border-gray-300 rounded-md shadow-sm">
            </div>
        </div>
        
        <!-- ✅ FITUR BARU: BATAS PEMBELIAN -->
        <div class="mb-4 p-4 bg-gray-50 rounded-lg border">
            <label class="block text-sm font-medium text-gray-700 mb-2">Batas Pembelian per Pengguna</label>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                <label class="flex items-center">
                    <input type="radio" name="limit_type" value="unlimited" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" <?= (is_null($product['purchase_limit']) || $product['purchase_limit'] == 0) ? 'checked' : '' ?>>
                    <span class="ml-2 text-sm text-gray-800">Tidak Terbatas (Unlimited)</span>
                </label>
                <div class="flex items-center">
                    <input type="radio" name="limit_type" value="limited" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" <?= (!is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) ? 'checked' : '' ?>>
                    <span class="ml-2 text-sm text-gray-800 mr-2">Batasi ke</span>
                    <input type="number" name="purchase_limit" value="<?= (!is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) ? htmlspecialchars($product['purchase_limit']) : '1' ?>" min="1" class="w-24 border-gray-300 rounded-md shadow-sm text-sm" id="purchase_limit_input">
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Deskripsi</label>
            <textarea id="description" name="description" rows="5" required class="w-full border-gray-300 rounded-md shadow-sm"><?= htmlspecialchars($product['description']) ?></textarea>
        </div>

        <div class="mb-6">
            <label for="image" class="block text-sm font-medium text-gray-700 mb-1">Gambar Produk</label>
            <?php if ($action == 'edit' && !empty($product['image'])): ?>
                <div class="mb-2">
                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" class="h-24 w-auto rounded-md border">
                    <p class="text-xs text-gray-500 mt-1">Gambar saat ini. Upload file baru untuk mengganti.</p>
                </div>
            <?php endif; ?>
            <input type="file" id="image" name="image" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" <?= ($action == 'add') ? 'required' : '' ?>>
        </div>

        <div class="flex items-center justify-end gap-4 border-t pt-4">
            <a href="?page=produk" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Batal</a>
            <button type="submit" name="save_product" class="px-6 py-2 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700 shadow">Simpan Produk</button>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const limitTypeRadios = document.querySelectorAll('input[name="limit_type"]');
        const limitInput = document.getElementById('purchase_limit_input');

        function toggleLimitInput() {
            if (document.querySelector('input[name="limit_type"]:checked').value === 'limited') {
                limitInput.disabled = false;
                limitInput.classList.remove('bg-gray-200', 'cursor-not-allowed');
            } else {
                limitInput.disabled = true;
                limitInput.classList.add('bg-gray-200', 'cursor-not-allowed');
            }
        }

        limitTypeRadios.forEach(radio => radio.addEventListener('change', toggleLimitInput));
        toggleLimitInput(); // Initial check on page load
    });
</script>