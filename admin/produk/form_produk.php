<?php
// File: admin/produk/form_produk.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// =================================================================================
// INTELLIGENT INITIALIZATION (IQ 180)
// =================================================================================
// Ambil Parameter State Sebelumnya (Penting untuk Redirect Balik)
$return_q = $_GET['q'] ?? '';
$return_category = $_GET['category'] ?? 0;
$return_status = $_GET['status'] ?? 'active';
$return_page = $_GET['p'] ?? 1;

// Inisialisasi variabel produk dengan nilai default yang aman
$product = [
    'id' => '', 
    'name' => '', 
    'category_id' => '', 
    'price' => '',
    'stock' => '', 
    'description' => '', 
    'image' => '', 
    'purchase_limit' => null,
    'has_variation' => 0 // Default tidak ada variasi
];

$page_title = "Tambah Produk Baru";
$form_action = "save_product";
$variations = []; // Array untuk menampung data variasi jika mode edit

// Ambil semua kategori untuk dropdown
$categories = [];
$cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// =================================================================================
// EDIT MODE LOGIC
// =================================================================================
if ($action == 'edit' && isset($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    
    // Ambil data utama produk
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        // Normalisasi data purchase_limit
        $product['purchase_limit'] = ($product['purchase_limit'] == 0) ? null : $product['purchase_limit'];
        $page_title = "Edit Produk: " . htmlspecialchars($product['name']);
        
        // Cek apakah produk memiliki variasi
        if ($product['has_variation'] == 1) {
            $stmt_var = $conn->prepare("SELECT * FROM product_variations WHERE product_id = ? ORDER BY id ASC");
            $stmt_var->bind_param("i", $product_id);
            $stmt_var->execute();
            $res_var = $stmt_var->get_result();
            while($row_var = $res_var->fetch_assoc()){
                $variations[] = $row_var;
            }
            $stmt_var->close();
        }
    } else {
        set_flashdata('error', 'Produk tidak ditemukan.');
        redirect('/admin/admin.php?page=produk');
    }
    $stmt->close();
}
?>

<div class="bg-white p-6 rounded-xl shadow-lg border border-gray-100 max-w-5xl mx-auto transition-all duration-300">
    <div class="flex items-center justify-between border-b pb-4 mb-6">
        <h2 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
            <i class="fas fa-box-open text-indigo-600"></i> <?= $page_title ?>
        </h2>
        <span class="text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">Mode Admin Cerdas</span>
    </div>

    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" enctype="multipart/form-data" id="productForm" class="space-y-6">
        <!-- Hidden Inputs for ID & Logic -->
        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
        <input type="hidden" name="has_variation" id="input_has_variation" value="<?= $product['has_variation'] ?>"> 
        
        <!-- Hidden Inputs for REDIRECT STATE (IQ 180 Feature) -->
        <input type="hidden" name="return_q" value="<?= htmlspecialchars($return_q) ?>">
        <input type="hidden" name="return_category" value="<?= htmlspecialchars($return_category) ?>">
        <input type="hidden" name="return_status" value="<?= htmlspecialchars($return_status) ?>">
        <input type="hidden" name="return_page" value="<?= htmlspecialchars($return_page) ?>">
        
        <!-- ======================================================================= -->
        <!-- BAGIAN 1: INFORMASI DASAR PRODUK -->
        <!-- ======================================================================= -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nama Produk -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <label for="name" class="block text-sm font-bold text-gray-700 mb-2">Nama Produk <span class="text-red-500">*</span></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400"><i class="fas fa-tag"></i></span>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($product['name']) ?>" required 
                           class="w-full pl-10 pr-4 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition-colors" placeholder="Masukkan nama produk...">
                </div>
            </div>

            <!-- Kategori -->
            <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <label for="category_id" class="block text-sm font-bold text-gray-700 mb-2">Kategori <span class="text-red-500">*</span></label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400"><i class="fas fa-layer-group"></i></span>
                    <select id="category_id" name="category_id" required class="w-full pl-10 pr-4 py-2 border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500 cursor-pointer bg-white">
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category['id'] ?>" <?= $product['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ======================================================================= -->
        <!-- BAGIAN 2: LOGIKA VARIASI (SISTEM CERDAS) -->
        <!-- ======================================================================= -->
        <div class="border-t border-b border-gray-200 py-6 my-6">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Tipe & Harga Produk</h3>
                    <p class="text-sm text-gray-500">Aktifkan variasi jika produk memiliki opsi (warna, ukuran, dll).</p>
                </div>
                
                <!-- Toggle Switch Variasi -->
                <label for="toggle_variation" class="flex items-center cursor-pointer select-none">
                    <div class="relative">
                        <input type="checkbox" id="toggle_variation" class="sr-only" <?= $product['has_variation'] ? 'checked' : '' ?>>
                        <div class="block bg-gray-300 w-14 h-8 rounded-full transition-colors duration-300 toggle-bg"></div>
                        <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition-transform duration-300 toggle-dot shadow-sm"></div>
                    </div>
                    <div class="ml-3 font-medium text-gray-700" id="toggle_label">Produk Standar</div>
                </label>
            </div>

            <!-- CONTAINER A: INPUT STANDARD (Harga & Stok Tunggal) -->
            <!-- Disembunyikan jika variasi aktif -->
            <div id="standard_input_container" class="grid grid-cols-1 md:grid-cols-2 gap-6 transition-all duration-300 <?= $product['has_variation'] ? 'hidden opacity-0 h-0 overflow-hidden' : '' ?>">
                <div>
                    <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Harga Satuan (Rp) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-gray-500 font-bold">Rp</span>
                        <!-- PERBAIKAN: step="0.01" ditambahkan untuk support desimal tanpa validasi error -->
                        <input type="number" id="price" name="price" value="<?= htmlspecialchars($product['price']) ?>" step="0.01"
                               class="w-full pl-10 border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="0">
                    </div>
                </div>
                <div>
                    <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Stok Total <span class="text-red-500">*</span></label>
                    <input type="number" id="stock" name="stock" value="<?= htmlspecialchars($product['stock']) ?>" 
                           class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
            </div>

            <!-- CONTAINER B: INPUT VARIASI (Sistem Dinamis Max 9) -->
            <div id="variation_input_container" class="space-y-4 transition-all duration-300 <?= !$product['has_variation'] ? 'hidden opacity-0 h-0 overflow-hidden' : '' ?>">
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start gap-3">
                        <i class="fas fa-info-circle text-indigo-600 mt-1"></i>
                        <div class="text-sm text-indigo-800">
                            <strong>Mode Variasi Aktif:</strong> 
                            <ul class="list-disc ml-4 mt-1">
                                <li>Harga dan Foto akan mengikuti masing-masing variasi.</li>
                                <li>Total stok produk akan dihitung otomatis dari jumlah stok semua variasi.</li>
                                <li>Maksimal 9 variasi diperbolehkan agar performa aplikasi tetap ringan.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Container untuk Baris-baris Variasi -->
                <div id="variations_wrapper" class="space-y-3">
                    <!-- Baris variasi akan di-generate oleh JS di sini -->
                    <?php 
                    // Jika mode edit dan ada variasi, render PHP. Jika tidak, JS akan handle initial row.
                    if ($action == 'edit' && !empty($variations)): 
                        foreach($variations as $index => $var):
                    ?>
                        <div class="variation-row bg-white border border-gray-300 rounded-lg p-4 shadow-sm relative group hover:border-indigo-400 transition-colors" data-index="<?= $index ?>">
                            <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button type="button" class="btn-remove-variation text-red-400 hover:text-red-600 p-1" title="Hapus Variasi Ini">
                                    <i class="fas fa-times-circle text-xl"></i>
                                </button>
                            </div>
                            
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Variasi #<span class="var-number"><?= $index + 1 ?></span></h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                                <!-- Nama Variasi -->
                                <div class="md:col-span-4">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Nama Variasi (Warna/Ukuran)</label>
                                    <input type="hidden" name="variation_id[]" value="<?= $var['id'] ?>">
                                    <input type="text" name="variation_name[]" value="<?= htmlspecialchars($var['name']) ?>" class="var-name-input w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Merah / XL / Spesial" required>
                                </div>
                                
                                <!-- Harga Variasi -->
                                <div class="md:col-span-3">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Harga (Rp)</label>
                                    <!-- PERBAIKAN: step="0.01" -->
                                    <input type="number" name="variation_price[]" value="<?= htmlspecialchars($var['price']) ?>" step="0.01" class="w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="10000" required>
                                </div>
                                
                                <!-- Stok Variasi -->
                                <div class="md:col-span-2">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Stok</label>
                                    <input type="number" name="variation_stock[]" value="<?= htmlspecialchars($var['stock']) ?>" class="var-stock-input w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="50" required>
                                </div>

                                <!-- Gambar Variasi -->
                                <div class="md:col-span-3">
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Foto Variasi</label>
                                    <div class="flex items-center gap-2">
                                        <?php if(!empty($var['image'])): ?>
                                            <img src="<?= BASE_URL ?>/assets/images/produk/<?= $var['image'] ?>" class="h-9 w-9 object-cover rounded border">
                                        <?php endif; ?>
                                        <input type="file" name="variation_image[]" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                    </div>
                                    <input type="hidden" name="existing_variation_image[]" value="<?= $var['image'] ?>">
                                </div>
                            </div>
                        </div>
                    <?php 
                        endforeach; 
                    endif; 
                    ?>
                </div>

                <!-- Tombol Tambah Variasi -->
                <button type="button" id="btn_add_variation" class="mt-2 w-full py-2 border-2 border-dashed border-indigo-300 rounded-lg text-indigo-600 font-semibold hover:bg-indigo-50 hover:border-indigo-500 transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-plus"></i> Tambah Variasi Baru
                </button>
                <p id="max_warning" class="text-xs text-red-500 text-center hidden mt-1">Maksimal 9 variasi telah tercapai.</p>
            </div>
        </div>

        <!-- ======================================================================= -->
        <!-- BAGIAN 3: PENGATURAN TAMBAHAN (Limit & Deskripsi) -->
        <!-- ======================================================================= -->
        
        <!-- Limit Pembelian -->
        <div class="mb-4 p-5 bg-white rounded-lg border border-gray-200 shadow-sm">
            <h4 class="text-sm font-bold text-gray-700 mb-3 border-b pb-2">Pembatasan Pembelian</h4>
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <label class="flex items-center cursor-pointer group">
                    <input type="radio" name="limit_type" value="unlimited" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" <?= (is_null($product['purchase_limit']) || $product['purchase_limit'] == 0) ? 'checked' : '' ?>>
                    <span class="ml-2 text-sm text-gray-700 group-hover:text-indigo-600 transition">Tidak Terbatas (Unlimited)</span>
                </label>
                <div class="flex items-center group">
                    <input type="radio" name="limit_type" value="limited" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" <?= (!is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) ? 'checked' : '' ?>>
                    <span class="ml-2 text-sm text-gray-700 group-hover:text-indigo-600 mr-2">Batasi Maksimal:</span>
                    <input type="number" name="purchase_limit" id="purchase_limit_input"
                           value="<?= (!is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) ? htmlspecialchars($product['purchase_limit']) : '1' ?>" 
                           min="1" class="w-20 border-gray-300 rounded-md shadow-sm text-sm focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                </div>
            </div>
        </div>

        <!-- Deskripsi -->
        <div>
            <label for="description" class="block text-sm font-bold text-gray-700 mb-2">Deskripsi Produk <span class="text-red-500">*</span></label>
            <textarea id="description" name="description" rows="6" required class="w-full border-gray-300 rounded-lg shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="Jelaskan detail produk secara lengkap..."><?= htmlspecialchars($product['description']) ?></textarea>
        </div>

        <!-- Gambar Utama / Sampul -->
        <div class="bg-indigo-50 p-5 rounded-lg border border-indigo-100">
            <label class="block text-sm font-bold text-indigo-900 mb-2">
                Foto Sampul Utama
                <span class="font-normal text-indigo-600 text-xs ml-1">(Wajib ada, akan dikompres otomatis)</span>
            </label>
            
            <div class="flex flex-col md:flex-row items-center gap-4">
                <?php if ($action == 'edit' && !empty($product['image'])): ?>
                    <div class="relative group">
                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" class="h-32 w-32 object-cover rounded-lg border-2 border-white shadow-md">
                        <div class="absolute inset-0 bg-black bg-opacity-40 rounded-lg flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                            <span class="text-white text-xs font-bold">Sampul Saat Ini</span>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="flex-1 w-full">
                    <input type="file" id="image" name="image" accept="image/*" class="block w-full text-sm text-slate-500
                        file:mr-4 file:py-2.5 file:px-4
                        file:rounded-full file:border-0
                        file:text-sm file:font-semibold
                        file:bg-indigo-600 file:text-white
                        hover:file:bg-indigo-700
                        transition-all cursor-pointer bg-white rounded-full border border-gray-200"
                        <?= ($action == 'add') ? 'required' : '' ?>>
                    <p class="mt-2 text-xs text-gray-500">Format: JPG, PNG, WEBP. Sistem akan otomatis mengompres gambar agar ringan.</p>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center justify-end gap-4 border-t pt-6 mt-6">
            <!-- Tombol Batal Juga Mengembalikan ke Pencarian Awal -->
            <a href="?page=produk&q=<?= urlencode($return_q) ?>&category=<?= $return_category ?>&status=<?= $return_status ?>&p=<?= $return_page ?>" class="px-6 py-2.5 bg-white text-gray-700 border border-gray-300 font-medium rounded-lg hover:bg-gray-50 transition shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i> Batal
            </a>
            <button type="submit" name="save_product" class="px-8 py-2.5 bg-gradient-to-r from-indigo-600 to-blue-600 text-white font-bold rounded-lg hover:from-indigo-700 hover:to-blue-700 shadow-lg transform hover:scale-[1.02] transition-all duration-200 flex items-center">
                <i class="fas fa-save mr-2"></i> Simpan Produk
            </button>
        </div>
    </form>
</div>

<!-- ======================================================================= -->
<!-- JAVASCRIPT: LOGIKA FRONTEND (IQ 180 STYLE) -->
<!-- ======================================================================= -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- 1. INISIALISASI VARIABEL UI ---
        const toggleVariation = document.getElementById('toggle_variation');
        const toggleLabel = document.getElementById('toggle_label');
        const inputHasVariation = document.getElementById('input_has_variation');
        const standardContainer = document.getElementById('standard_input_container');
        const variationContainer = document.getElementById('variation_input_container');
        const variationsWrapper = document.getElementById('variations_wrapper');
        const btnAddVariation = document.getElementById('btn_add_variation');
        const maxWarning = document.getElementById('max_warning');
        
        // Input standar
        const stdPrice = document.getElementById('price');
        const stdStock = document.getElementById('stock');
        
        // Limit logic
        const limitTypeRadios = document.querySelectorAll('input[name="limit_type"]');
        const limitInput = document.getElementById('purchase_limit_input');

        const MAX_VARIATIONS = 9;

        // --- 2. FUNGSI UTAMA: RENDER BARIS VARIASI ---
        function createVariationRow(index) {
            const div = document.createElement('div');
            div.className = 'variation-row bg-white border border-gray-300 rounded-lg p-4 shadow-sm relative group hover:border-indigo-400 transition-colors animate-fade-in-down';
            div.dataset.index = index;
            
            div.innerHTML = `
                <div class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button type="button" class="btn-remove-variation text-red-400 hover:text-red-600 p-1" title="Hapus Variasi Ini">
                        <i class="fas fa-times-circle text-xl"></i>
                    </button>
                </div>
                
                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Variasi #<span class="var-number">${index + 1}</span></h4>
                
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                    <div class="md:col-span-4">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Nama Variasi <span class="text-red-500">*</span></label>
                        <input type="text" name="variation_name[]" class="var-name-input w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Merah / XL" required>
                    </div>
                    
                    <div class="md:col-span-3">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Harga (Rp) <span class="text-red-500">*</span></label>
                        <input type="number" name="variation_price[]" step="0.01" class="w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="10000" required>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Stok <span class="text-red-500">*</span></label>
                        <input type="number" name="variation_stock[]" class="var-stock-input w-full text-sm border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="0" required>
                    </div>

                    <div class="md:col-span-3">
                        <label class="block text-xs font-medium text-gray-700 mb-1">Foto Variasi <span class="text-red-500">*</span></label>
                        <input type="file" name="variation_image[]" accept="image/*" class="w-full text-xs text-gray-500 file:mr-2 file:py-1 file:px-2 file:rounded-full file:border-0 file:text-xs file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
                    </div>
                </div>
            `;
            return div;
        }

        // --- 3. LOGIKA TOGGLE VARIASI ---
        function handleToggle() {
            const isChecked = toggleVariation.checked;
            inputHasVariation.value = isChecked ? '1' : '0';
            
            // Visual Toggle
            if (isChecked) {
                document.querySelector('.toggle-bg').classList.replace('bg-gray-300', 'bg-indigo-600');
                document.querySelector('.toggle-dot').classList.add('translate-x-6');
                toggleLabel.textContent = "Produk Bervariasi";
                
                // Show/Hide Containers
                standardContainer.classList.add('hidden', 'opacity-0', 'h-0');
                variationContainer.classList.remove('hidden', 'opacity-0', 'h-0');
                
                // Disable Standard Inputs (agar tidak bentrok saat submit/required check)
                stdPrice.required = false;
                stdStock.required = false;
                // Enable Variation Inputs
                toggleVariationInputs(true);
                
                // Jika kosong, tambah 1 baris
                if (variationsWrapper.children.length === 0) {
                    addVariation();
                }
            } else {
                document.querySelector('.toggle-bg').classList.replace('bg-indigo-600', 'bg-gray-300');
                document.querySelector('.toggle-dot').classList.remove('translate-x-6');
                toggleLabel.textContent = "Produk Standar";
                
                standardContainer.classList.remove('hidden', 'opacity-0', 'h-0');
                variationContainer.classList.add('hidden', 'opacity-0', 'h-0');
                
                stdPrice.required = true;
                stdStock.required = true;
                toggleVariationInputs(false);
            }
        }

        function toggleVariationInputs(enable) {
            const inputs = variationContainer.querySelectorAll('input');
            inputs.forEach(input => {
                // Jangan disable hidden inputs (seperti id untuk edit) kecuali diperlukan
                if(input.type !== 'hidden') input.disabled = !enable;
            });
        }

        // --- 4. MANAJEMEN BARIS VARIASI ---
        function addVariation() {
            const currentCount = variationsWrapper.children.length;
            if (currentCount >= MAX_VARIATIONS) {
                maxWarning.classList.remove('hidden');
                btnAddVariation.classList.add('opacity-50', 'cursor-not-allowed');
                return;
            }
            
            const newRow = createVariationRow(currentCount);
            variationsWrapper.appendChild(newRow);
            updateVariationIndices();
            
            if (variationsWrapper.children.length >= MAX_VARIATIONS) {
                btnAddVariation.classList.add('opacity-50', 'cursor-not-allowed');
            }
        }

        function removeVariation(btn) {
            const row = btn.closest('.variation-row');
            row.remove();
            updateVariationIndices();
            
            maxWarning.classList.add('hidden');
            btnAddVariation.classList.remove('opacity-50', 'cursor-not-allowed');
            
            // Jika semua dihapus saat mode variasi aktif, tambah 1 kosong
            if (variationsWrapper.children.length === 0 && toggleVariation.checked) {
                addVariation();
            }
        }

        function updateVariationIndices() {
            const rows = variationsWrapper.querySelectorAll('.variation-row');
            rows.forEach((row, index) => {
                row.querySelector('.var-number').textContent = index + 1;
            });
        }

        // --- 5. EVENT LISTENERS ---
        toggleVariation.addEventListener('change', handleToggle);
        
        btnAddVariation.addEventListener('click', () => {
            if (!btnAddVariation.classList.contains('cursor-not-allowed')) {
                addVariation();
            }
        });

        variationsWrapper.addEventListener('click', (e) => {
            if (e.target.closest('.btn-remove-variation')) {
                removeVariation(e.target.closest('.btn-remove-variation'));
            }
        });

        // Limit Logic (Bawaan lama)
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
        
        // --- INITIALIZATION ---
        toggleLimitInput();
        handleToggle(); // Set state awal berdasarkan database
    });
</script>

<style>
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in-down {
        animation: fadeInDown 0.3s ease-out forwards;
    }
</style>