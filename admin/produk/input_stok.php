<?php
// File: admin/produk/input_stok.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// --- PENGATURAN FILTER DAN PENCARIAN ---
$search_query = $_GET['q'] ?? '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;

// Ambil semua kategori untuk filter dropdown
$categories = [];
$cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
while ($row = $cat_result->fetch_assoc()) {
    $categories[] = $row;
}

// --- MEMBUAT QUERY DINAMIS ---
$params = [];
$types = "";
$where_conditions = ["p.is_active = 1"]; // Selalu filter produk yang aktif

if ($category_filter > 0) {
    $where_conditions[] = "p.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

if (!empty($search_query)) {
    $where_conditions[] = "p.name LIKE ?";
    $params[] = "%" . $search_query . "%";
    $types .= "s";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// --- Ambil produk berdasarkan filter/pencarian, DIURUTKAN BERDASARKAN KATEGORI ---
$products = [];
$sql = "SELECT p.id, p.name, p.stock, p.image, p.category_id, c.name as category_name 
        FROM products p 
        JOIN categories c ON p.category_id = c.id
        $where_clause 
        ORDER BY c.name ASC, p.name ASC"; // Urutkan berdasarkan kategori dulu, lalu nama produk

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

while ($row = $result->fetch_assoc()) {
    $products[] = $row;
}
if (isset($stmt)) $stmt->close();

// --- KELOMPOKKAN PRODUK BERDASARKAN KATEGORI ---
$products_by_category = [];
foreach ($products as $product) {
    $cat_id = $product['category_id'];
    $cat_name = $product['category_name'];
    
    if (!isset($products_by_category[$cat_id])) {
        $products_by_category[$cat_id] = [
            'name' => $cat_name,
            'products' => []
        ];
    }
    $products_by_category[$cat_id]['products'][] = $product;
}

?>

<!-- Info Box -->
<div class="p-4 bg-gradient-to-r from-yellow-50 to-amber-50 border-l-4 border-yellow-400 text-yellow-900 rounded-lg shadow-sm mb-6">
    <div class="flex items-start">
        <i class="fas fa-info-circle text-yellow-500 text-xl mr-3 mt-0.5"></i>
        <div>
            <p class="font-bold text-lg mb-1">Mode Input Stok Cepat</p>
            <p class="text-sm">Fitur ini digunakan untuk <strong>MENAMBAHKAN</strong> stok pada produk yang dipilih secara massal. Nilai yang Anda masukkan adalah <strong>jumlah yang ditambahkan</strong>, bukan nilai stok total.</p>
        </div>
    </div>
</div>

<!-- --- FORM PENCARIAN DAN FILTER --- -->
<form method="GET" action="admin.php" class="mb-6">
    <input type="hidden" name="page" value="input_stok">
    <div class="flex flex-col md:flex-row gap-4 bg-white p-5 rounded-xl shadow-lg border border-gray-200">
        <div class="flex-grow">
            <label for="search_q" class="sr-only">Cari Produk</label>
            <div class="relative">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                <input type="text" id="search_q" name="q" value="<?= htmlspecialchars($search_query) ?>" 
                       placeholder="Cari berdasarkan nama produk..." 
                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150">
            </div>
        </div>
        <div class="w-full md:w-56">
            <label for="category_filter" class="sr-only">Filter Kategori</label>
            <select id="category_filter" name="category" class="w-full p-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition duration-150 bg-white">
                <option value="0">Semua Kategori</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $category_filter == $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex space-x-2 w-full md:w-auto">
            <button type="submit" class="flex-1 px-5 py-2.5 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition transform hover:scale-[1.02] flex items-center justify-center">
                <i class="fas fa-filter mr-2"></i> Filter
            </button>
            <?php if (!empty($search_query) || $category_filter > 0): ?>
                <a href="?page=input_stok" class="flex-1 px-5 py-2.5 bg-gray-500 text-white font-semibold rounded-lg shadow-md hover:bg-gray-600 transition text-center flex items-center justify-center">
                    <i class="fas fa-times mr-2"></i> Reset
                </a>
            <?php endif; ?>
        </div>
    </div>
</form>
<!-- --- AKHIR FORM PENCARIAN DAN FILTER --- -->

<form method="POST" action="admin.php" id="bulkStockForm" class="space-y-6">
    <input type="hidden" name="bulk_stock_update" value="1">
    
    <!-- Header Sticky (Pilih Semua & Konfirmasi) -->
    <div class="flex items-center justify-between bg-gradient-to-r from-white to-indigo-50 p-4 rounded-xl shadow-lg sticky top-0 md:top-6 z-20 border border-indigo-100 h-16">
        <div class="flex items-center gap-3">
            <input type="checkbox" id="select_all" class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
            <label for="select_all" class="text-gray-700 font-semibold cursor-pointer text-sm md:text-base flex items-center">
                <i class="fas fa-check-double mr-2 text-indigo-600"></i>
                Pilih Semua Halaman Ini
            </label>
        </div>
        
        <!-- Tombol Konfirmasi -->
        <button type="button" id="openConfirmModalButton" disabled 
                class="px-6 py-2.5 text-sm font-bold rounded-full shadow-lg transition transform duration-150
                       bg-indigo-400 text-white hover:bg-indigo-500 hover:scale-105
                       disabled:bg-gray-300 disabled:cursor-not-allowed disabled:transform-none flex items-center gap-2">
            <i class="fas fa-check-circle"></i> 
            <span>Konfirmasi & Tambah Stok (<span id="selectedCount">0</span>)</span>
        </button>
    </div>

    <!-- Container Produk Berdasarkan Kategori -->
    <div class="space-y-6">
        <?php if (!empty($products_by_category)): ?>
            <?php foreach ($products_by_category as $cat_id => $category_data): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden border border-gray-200">
                    <!-- Header Kategori -->
                    <div class="bg-gradient-to-r from-indigo-600 to-indigo-500 p-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <i class="fas fa-layer-group text-white text-xl"></i>
                            <h3 class="text-white font-bold text-lg"><?= htmlspecialchars($category_data['name']) ?></h3>
                            <span class="bg-white/20 text-white px-3 py-1 rounded-full text-xs font-semibold">
                                <?= count($category_data['products']) ?> Produk
                            </span>
                        </div>
                        <!-- Checkbox Pilih Semua Per Kategori -->
                        <label class="flex items-center gap-2 text-white cursor-pointer hover:text-indigo-100 transition">
                            <input type="checkbox" class="category-select-all h-4 w-4 rounded border-white text-indigo-600 focus:ring-2 focus:ring-white cursor-pointer" data-category-id="<?= $cat_id ?>">
                            <span class="text-sm font-medium">Pilih Semua</span>
                        </label>
                    </div>
                    
                    <!-- Tabel Produk -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="p-3 text-left text-xs font-semibold text-gray-600 uppercase w-12">Pilih</th>
                                    <th class="p-3 text-left text-xs font-semibold text-gray-600 uppercase w-16">Gambar</th>
                                    <th class="p-3 text-left text-xs font-semibold text-gray-600 uppercase">Nama Produk</th>
                                    <th class="p-3 text-center text-xs font-semibold text-gray-600 uppercase">Stok Saat Ini</th>
                                    <th class="p-3 text-center text-xs font-semibold text-gray-600 uppercase w-48">Jumlah Stok Tambahan</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-100">
                                <?php foreach ($category_data['products'] as $product): ?>
                                    <tr class="product-row hover:bg-indigo-50 transition duration-100" 
                                        data-product-id="<?= $product['id'] ?>" 
                                        data-product-name="<?= htmlspecialchars($product['name']) ?>"
                                        data-category-id="<?= $cat_id ?>">
                                        <td class="p-3 whitespace-nowrap text-center">
                                            <input type="checkbox" 
                                                   name="product_id[]" 
                                                   value="<?= $product['id'] ?>" 
                                                   class="product-checkbox h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 whitespace-nowrap">
                                            <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                                                 alt="<?= htmlspecialchars($product['name']) ?>" 
                                                 class="w-12 h-12 object-cover rounded-lg shadow-sm border border-gray-200">
                                        </td>
                                        <td class="p-3 text-sm font-medium text-gray-800">
                                            <?= htmlspecialchars($product['name']) ?>
                                        </td>
                                        <td class="p-3 whitespace-nowrap text-center">
                                            <span class="inline-flex items-center justify-center px-3 py-1.5 bg-gray-100 text-gray-800 font-bold rounded-lg text-sm border border-gray-300">
                                                <i class="fas fa-boxes mr-1.5 text-gray-500"></i>
                                                <?= $product['stock'] ?>
                                            </span>
                                        </td>
                                        <td class="p-3 whitespace-nowrap text-center">
                                            <input type="number" 
                                                   name="stock_value[]"
                                                   placeholder="Jumlah Tambahan"
                                                   min="1"
                                                   value=""
                                                   data-product-id="<?= $product['id'] ?>"
                                                   class="stock-input w-full max-w-xs mx-auto p-2 border-2 border-gray-300 rounded-lg shadow-sm text-center disabled:bg-gray-100 disabled:text-gray-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 transition duration-150">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="bg-white rounded-xl shadow-lg p-12 text-center border border-gray-200">
                <i class="fas fa-box-open text-gray-300 text-6xl mb-4"></i>
                <p class="text-gray-500 text-lg font-medium">Tidak ada produk aktif yang ditemukan dengan filter ini.</p>
                <p class="text-gray-400 text-sm mt-2">Coba ubah filter atau kata kunci pencarian Anda.</p>
            </div>
        <?php endif; ?>
    </div>
</form>

<!-- Confirmation Modal -->
<div id="confirmationModal" class="fixed inset-0 bg-gray-900 bg-opacity-75 hidden z-50 overflow-y-auto transition duration-300 ease-in-out" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl transform transition-all scale-95 opacity-0 duration-300" id="modalContent">
            
            <!-- Modal Header -->
            <div class="p-6 border-b bg-gradient-to-r from-indigo-50 to-indigo-100 rounded-t-xl">
                <h3 class="text-2xl font-bold text-gray-900 flex items-center" id="modal-title">
                    <i class="fas fa-shield-alt text-indigo-600 mr-3"></i> Konfirmasi Aksi Massal
                </h3>
            </div>
            
            <!-- Modal Body -->
            <div class="p-6 max-h-[70vh] overflow-y-auto">
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 mb-4">
                    <p class="text-gray-700 font-semibold text-lg flex items-center">
                        <i class="fas fa-info-circle text-indigo-600 mr-2"></i>
                        Anda akan menambahkan stok ke <span id="modalTotalCount" class="text-indigo-600 font-extrabold mx-1">0</span> produk.
                    </p>
                </div>
                <p class="text-gray-600 text-sm mb-4">Pastikan jumlah tambahan stok sudah benar. Aksi ini tidak dapat dibatalkan.</p>
                
                <div class="border border-gray-300 rounded-lg bg-gray-50 shadow-inner">
                    <div class="bg-gray-100 px-4 py-3 border-b border-gray-300 rounded-t-lg">
                        <div class="flex justify-between font-bold text-sm text-gray-700">
                            <span>Nama Produk</span>
                            <span>Tambahan Stok</span>
                        </div>
                    </div>
                    <ul id="modalConfirmationList" class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                        <!-- Daftar produk akan di-inject di sini oleh JavaScript -->
                    </ul>
                </div>
                
                <div class="mt-4 bg-red-50 border border-red-200 rounded-lg p-3 flex items-start">
                    <i class="fas fa-exclamation-triangle text-red-600 mr-2 mt-0.5"></i>
                    <p class="text-xs text-red-700">
                        <strong>Perhatian:</strong> Stok akan ditambahkan ke jumlah stok saat ini, dan tidak akan mengganti nilai total.
                    </p>
                </div>
            </div>
            
            <!-- Modal Footer -->
            <div class="p-4 bg-gray-50 flex justify-end space-x-3 rounded-b-xl border-t">
                <button type="button" id="cancelConfirmButton" class="px-6 py-2.5 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 transition shadow-sm">
                    <i class="fas fa-times mr-1"></i> Batal
                </button>
                <button type="button" id="finalConfirmButton" class="px-6 py-2.5 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 transition shadow-lg transform hover:scale-[1.02]">
                    <i class="fas fa-cloud-upload-alt mr-1"></i> Ya, Tambahkan Stok Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('bulkStockForm');
        const selectAllCheckbox = document.getElementById('select_all');
        const productCheckboxes = document.querySelectorAll('.product-checkbox');
        const categorySelectAllCheckboxes = document.querySelectorAll('.category-select-all');
        const openConfirmModalButton = document.getElementById('openConfirmModalButton');
        const selectedCountSpan = document.getElementById('selectedCount');
        
        // Modal Elements
        const modal = document.getElementById('confirmationModal');
        const modalContent = document.getElementById('modalContent');
        const modalList = document.getElementById('modalConfirmationList');
        const modalTotalCount = document.getElementById('modalTotalCount');
        const cancelConfirmButton = document.getElementById('cancelConfirmButton');
        const finalConfirmButton = document.getElementById('finalConfirmButton');

        // Fungsi untuk mendapatkan input stok terkait
        function getStockInput(productId) {
            return document.querySelector(`.stock-input[data-product-id="${productId}"]`);
        }
        
        // Fungsi untuk menampilkan modal dengan transisi
        function showModal() {
            modal.classList.remove('hidden');
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }

        // Fungsi untuk menyembunyikan modal dengan transisi
        function hideModal() {
            modalContent.classList.remove('scale-100', 'opacity-100');
            modalContent.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
        
        // Fungsi untuk mengupdate status tombol konfirmasi dan hitungan
        function updateConfirmationStatus() {
            let selectedCount = 0;
            productCheckboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedCount++;
                }
            });
            selectedCountSpan.textContent = selectedCount;
            openConfirmModalButton.disabled = selectedCount === 0;
            
            if (selectedCount > 0) {
                openConfirmModalButton.classList.replace('bg-indigo-400', 'bg-indigo-600');
            } else {
                openConfirmModalButton.classList.replace('bg-indigo-600', 'bg-indigo-400');
            }
        }

        // Fungsi untuk update status checkbox "Pilih Semua" per kategori
        function updateCategorySelectAll(categoryId) {
            const categoryCheckbox = document.querySelector(`.category-select-all[data-category-id="${categoryId}"]`);
            const categoryProducts = document.querySelectorAll(`.product-checkbox[value]`);
            const categoryProductsInThisCategory = Array.from(categoryProducts).filter(cb => {
                return cb.closest('.product-row').dataset.categoryId == categoryId;
            });
            
            const allChecked = categoryProductsInThisCategory.every(cb => cb.checked);
            const someChecked = categoryProductsInThisCategory.some(cb => cb.checked);
            
            if (categoryCheckbox) {
                categoryCheckbox.checked = allChecked;
                categoryCheckbox.indeterminate = someChecked && !allChecked;
            }
        }

        // Fungsi untuk update status checkbox "Pilih Semua" global
        function updateGlobalSelectAll() {
            const allChecked = Array.from(productCheckboxes).every(cb => cb.checked);
            const someChecked = Array.from(productCheckboxes).some(cb => cb.checked);
            
            selectAllCheckbox.checked = allChecked;
            selectAllCheckbox.indeterminate = someChecked && !allChecked;
        }

        // Event listener untuk checkbox per produk
        productCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => {
                const row = e.target.closest('.product-row');
                const productId = row.dataset.productId;
                const categoryId = row.dataset.categoryId;
                const stockInput = getStockInput(productId);
                
                if (e.target.checked) {
                    stockInput.disabled = false;
                    if (stockInput.value === '' || stockInput.value === '0') {
                        stockInput.value = 1; 
                    }
                    row.classList.add('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                } else {
                    stockInput.disabled = true;
                    row.classList.remove('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                }
                
                updateConfirmationStatus();
                updateCategorySelectAll(categoryId);
                updateGlobalSelectAll();
            });
            
            // Inisialisasi style saat refresh
            if (checkbox.checked) {
                const row = checkbox.closest('.product-row');
                row.classList.add('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                getStockInput(row.dataset.productId).disabled = false;
            }
        });

        // Event listener untuk Pilih Semua Global
        selectAllCheckbox.addEventListener('change', (e) => {
            const isChecked = e.target.checked;
            productCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
                const row = checkbox.closest('.product-row');
                const productId = row.dataset.productId;
                const categoryId = row.dataset.categoryId;
                const stockInput = getStockInput(productId);
                
                stockInput.disabled = !isChecked;
                if (isChecked && (stockInput.value === '' || stockInput.value === '0')) {
                    stockInput.value = 1;
                    row.classList.add('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                } else if (!isChecked) {
                    stockInput.value = ''; 
                    row.classList.remove('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                }
                
                updateCategorySelectAll(categoryId);
            });
            updateConfirmationStatus();
        });

        // Event listener untuk Pilih Semua Per Kategori
        categorySelectAllCheckboxes.forEach(catCheckbox => {
            catCheckbox.addEventListener('change', (e) => {
                const categoryId = e.target.dataset.categoryId;
                const isChecked = e.target.checked;
                
                const categoryProducts = Array.from(productCheckboxes).filter(cb => {
                    return cb.closest('.product-row').dataset.categoryId == categoryId;
                });
                
                categoryProducts.forEach(checkbox => {
                    checkbox.checked = isChecked;
                    const row = checkbox.closest('.product-row');
                    const productId = row.dataset.productId;
                    const stockInput = getStockInput(productId);
                    
                    stockInput.disabled = !isChecked;
                    if (isChecked && (stockInput.value === '' || stockInput.value === '0')) {
                        stockInput.value = 1;
                        row.classList.add('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                    } else if (!isChecked) {
                        stockInput.value = ''; 
                        row.classList.remove('bg-indigo-50', 'border-l-4', 'border-indigo-500');
                    }
                });
                
                updateConfirmationStatus();
                updateGlobalSelectAll();
            });
        });
        
        // Tombol Buka Modal
        openConfirmModalButton.addEventListener('click', () => {
            let totalSelected = 0;
            let invalidInput = false;
            let modalListHTML = '';
            
            document.querySelectorAll('.stock-input').forEach(input => input.name = '');
            document.querySelectorAll('.product-checkbox').forEach(input => input.name = '');

            productCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('.product-row');
                const productId = row.dataset.productId;
                const productName = row.dataset.productName;
                const stockInput = getStockInput(productId);
                
                stockInput.classList.remove('border-red-500', 'ring-red-500', 'border-2');

                if (checkbox.checked) {
                    totalSelected++;
                    const value = parseInt(stockInput.value);
                    
                    if (isNaN(value) || value <= 0) {
                        invalidInput = true;
                        stockInput.classList.add('border-red-500', 'ring-red-500', 'border-2'); 
                    } else {
                        stockInput.name = `stock_value[${checkbox.value}]`; 
                        checkbox.name = `product_id[${checkbox.value}]`;
                        
                        modalListHTML += `
                            <li class="flex justify-between items-center py-3 px-4 hover:bg-gray-50 transition">
                                <span class="font-medium text-gray-800 flex-1">${productName}</span>
                                <span class="px-4 py-1.5 bg-gradient-to-r from-indigo-500 to-indigo-600 text-white font-bold rounded-full text-sm shadow-md">
                                    <i class="fas fa-plus-circle mr-1"></i>${value}
                                </span>
                            </li>
                        `;
                    }
                }
            });

            if (totalSelected === 0) {
                alert('Anda harus memilih minimal satu produk untuk diupdate stoknya.');
                return;
            } else if (invalidInput) {
                alert('Semua produk yang dipilih harus memiliki nilai stok tambahan (minimal 1). Mohon periksa input yang ditandai merah.');
                document.querySelector('.stock-input.border-red-500')?.focus();
                return;
            }
            
            modalTotalCount.textContent = totalSelected;
            modalList.innerHTML = modalListHTML;
            showModal();
        });
        
        // Tombol Batal Modal
        cancelConfirmButton.addEventListener('click', hideModal);

        // Tombol Konfirmasi Final
        finalConfirmButton.addEventListener('click', () => {
            form.submit();
        });
        
        // Mencegah submit form biasa
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            openConfirmModalButton.click();
        });

        // Inisialisasi status awal
        updateConfirmationStatus();
        
        // Update status checkbox kategori saat load
        categorySelectAllCheckboxes.forEach(catCheckbox => {
            updateCategorySelectAll(catCheckbox.dataset.categoryId);
        });
        updateGlobalSelectAll();
    });
</script>