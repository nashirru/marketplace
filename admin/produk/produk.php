<?php
// File: admin/produk/produk.php
// VERSI UPGRADE V3: DYNAMIC PAGINATION & RESET LIMIT BUTTON
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';

// --- TANGKAP PARAMETER PENCARIAN & FILTER UNTUK PERSISTENCE ---
// Kita simpan ini agar nanti kalau user klik "Edit", "Hapus", dll, 
// kita bisa kembalikan mereka ke state ini.
$current_q = $_GET['q'] ?? '';
$current_category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$current_status = $_GET['status'] ?? 'active'; // Default active (ON)
$current_page_num = isset($_GET['p']) ? (int)$_GET['p'] : 1;

// --- PARAMETER LIMIT PAGINASI DINAMIS (NEW FEATURE) ---
$current_limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
// Validasi agar user tidak input angka aneh, default 10
if (!in_array($current_limit, [10, 25, 50, 100])) $current_limit = 10;

if ($action == 'add' || $action == 'edit') {
    // Jika aksinya adalah tambah atau edit, kita muat file form
    // Form produk juga harus dimodifikasi untuk menerima parameter return ini
    include 'form_produk.php';
} else {
    // --- PENGATURAN PAGINASI, FILTER, DAN PENCARIAN ---
    $page = $current_page_num;
    $limit = $current_limit; // Gunakan limit dinamis
    $offset = ($page - 1) * $limit;
    
    // Ambil semua kategori untuk filter dropdown
    $categories = [];
    $cat_result = $conn->query("SELECT id, name FROM categories ORDER BY name ASC");
    while ($row = $cat_result->fetch_assoc()) {
        $categories[] = $row;
    }

    // --- MEMBUAT QUERY DINAMIS ---
    $params = [];
    $types = "";
    $where_conditions = [];

    // 1. FILTER STATUS (ON/OFF Logic)
    if ($current_status == 'active') {
        $where_conditions[] = "p.is_active = 1";
    } elseif ($current_status == 'inactive') {
        $where_conditions[] = "p.is_active = 0";
    }
    // Jika 'all', tidak ada filter is_active

    // 2. FILTER KATEGORI
    if ($current_category > 0) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $current_category;
        $types .= "i";
    }

    // 3. FILTER PENCARIAN
    if (!empty($current_q)) {
        $search_term = "%" . $current_q . "%";
        $where_conditions[] = "p.name LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }

    $where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";

    // Hitung Total Data
    $total_query = "SELECT COUNT(p.id) as total FROM products p" . $where_clause;
    $stmt_total = $conn->prepare($total_query);
    if (!empty($params)) {
        $stmt_total->bind_param($types, ...$params);
    }
    $stmt_total->execute();
    $total_results = $stmt_total->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_results / $limit);
    $stmt_total->close();

    // Ambil data produk
    $products = [];
    $sql = "SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id" . $where_clause . " ORDER BY p.created_at DESC LIMIT ? OFFSET ?";
    $stmt_params = $params;
    $stmt_params[] = $limit;
    $stmt_params[] = $offset;
    $stmt_types = $types . "ii";

    $stmt = $conn->prepare($sql);
    if (!empty($stmt_params)) {
        $stmt->bind_param($stmt_types, ...$stmt_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Logika tambahan untuk range harga variasi
        if ($row['has_variation']) {
            $pid = $row['id'];
            $v_res = $conn->query("SELECT MIN(price) as min_p, MAX(price) as max_p, SUM(stock) as total_s FROM product_variations WHERE product_id = $pid");
            $v_data = $v_res->fetch_assoc();
            $row['min_price'] = $v_data['min_p'];
            $row['max_price'] = $v_data['max_p'];
            $row['stock'] = $v_data['total_s'] ?? 0;
        }
        $products[] = $row;
    }
    $stmt->close();
?>
    <!-- UI FILTER & TABS -->
    <div class="mb-6 space-y-4">
        
        <!-- Tab Navigasi Status (On/Off) -->
        <div class="border-b border-gray-200">
            <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <a href="?page=produk&status=active&q=<?= urlencode($current_q) ?>&category=<?= $current_category ?>&limit=<?= $current_limit ?>" 
                   class="<?= $current_status == 'active' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
                    <i class="fas fa-check-circle mr-2"></i> Produk Aktif
                </a>
                <a href="?page=produk&status=inactive&q=<?= urlencode($current_q) ?>&category=<?= $current_category ?>&limit=<?= $current_limit ?>" 
                   class="<?= $current_status == 'inactive' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm flex items-center">
                    <i class="fas fa-archive mr-2"></i> Produk Non-Aktif
                </a>
            </nav>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <form method="GET" class="flex items-center gap-4 flex-grow md:flex-grow-0 flex-wrap">
                <input type="hidden" name="page" value="produk">
                <input type="hidden" name="status" value="<?= htmlspecialchars($current_status) ?>">
                
                <!-- NEW: Dropdown Limit Per Halaman -->
                <div class="flex items-center space-x-2">
                     <span class="text-sm text-gray-500 hidden sm:inline">Show</span>
                     <select name="limit" onchange="this.form.submit()" class="border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm py-2 pl-3 pr-8 cursor-pointer">
                        <?php foreach ([10, 25, 50, 100] as $lim_opt): ?>
                            <option value="<?= $lim_opt ?>" <?= $current_limit == $lim_opt ? 'selected' : '' ?>>
                                <?= $lim_opt ?>
                            </option>
                        <?php endforeach; ?>
                     </select>
                </div>

                <div class="relative flex-grow md:w-64">
                    <input type="text" name="q" placeholder="Cari nama produk..." value="<?= htmlspecialchars($current_q) ?>" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 transition">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                </div>
                
                <select name="category" onchange="this.form.submit()" class="border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 cursor-pointer w-full md:w-auto">
                    <option value="0">Semua Kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= $category['id'] ?>" <?= $current_category == $category['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($category['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <a href="?page=produk&action=add&q=<?= urlencode($current_q) ?>&category=<?= $current_category ?>&status=<?= $current_status ?>&p=<?= $current_page_num ?>&limit=<?= $current_limit ?>" class="px-5 py-2.5 bg-green-600 text-white font-semibold rounded-lg hover:bg-green-700 shadow-md transform hover:scale-105 transition flex items-center gap-2">
                <i class="fas fa-plus-circle"></i> Tambah Produk
            </a>
        </div>
    </div>

    <!-- TABEL DATA -->
    <div class="bg-white p-1 rounded-xl shadow-lg overflow-hidden border border-gray-100">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Gambar</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Info Produk</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Kategori</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Harga</th>
                        <th class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Stok</th>
                        <th class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $product): ?>
                            <tr class="hover:bg-indigo-50 transition duration-150">
                                <!-- Gambar -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="relative group w-14 h-14">
                                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" onerror="this.src='https://via.placeholder.com/150?text=No+Img'" alt="<?= htmlspecialchars($product['name']) ?>" class="w-14 h-14 object-cover rounded-lg shadow-sm border border-gray-200">
                                        <?php if($product['has_variation']): ?>
                                            <div class="absolute -bottom-1 -right-1 bg-indigo-600 text-white text-[10px] px-1.5 py-0.5 rounded-full shadow border border-white">
                                                <i class="fas fa-layer-group"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                
                                <!-- Info Produk -->
                                <td class="px-6 py-4">
                                    <div class="text-sm font-bold text-gray-900"><?= htmlspecialchars($product['name']) ?></div>
                                    <?php if($product['has_variation']): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-800 mt-1">
                                            Variasi Aktif
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Kategori -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <span class="px-2 py-1 bg-gray-100 rounded text-gray-600 text-xs">
                                        <?= htmlspecialchars($product['category_name']) ?>
                                    </span>
                                </td>

                                <!-- Harga -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-700">
                                    <?php if ($product['has_variation']): ?>
                                        <div class="flex flex-col">
                                            <span class="text-xs text-gray-400">Mulai dari</span>
                                            <span class="text-indigo-600"><?= format_rupiah($product['min_price']) ?></span>
                                            <?php if($product['min_price'] != $product['max_price']): ?>
                                                <span class="text-xs text-gray-400 text-center">-</span>
                                                <span class="text-indigo-600"><?= format_rupiah($product['max_price']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?= format_rupiah($product['price']) ?>
                                    <?php endif; ?>
                                </td>

                                <!-- Stok -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center justify-center px-3 py-1 text-xs font-bold leading-none rounded-full <?= $product['stock'] < 10 ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                                        <?= $product['stock'] ?> Unit
                                    </span>
                                </td>

                                <!-- Toggle Status -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <form action="admin.php" method="POST" class="toggle-form inline-block">
                                        <input type="hidden" name="page" value="produk">
                                        <input type="hidden" name="action" value="toggle_product_status">
                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                        
                                        <!-- INJECT HIDDEN FIELD UNTUK MEMBAWA STATE KEMBALI -->
                                        <input type="hidden" name="return_q" value="<?= htmlspecialchars($current_q) ?>">
                                        <input type="hidden" name="return_category" value="<?= htmlspecialchars($current_category) ?>">
                                        <input type="hidden" name="return_status" value="<?= htmlspecialchars($current_status) ?>">
                                        <input type="hidden" name="return_page" value="<?= htmlspecialchars($current_page_num) ?>">
                                        <input type="hidden" name="return_limit" value="<?= htmlspecialchars($current_limit) ?>">

                                        <?php if ($product['is_active']): ?>
                                            <input type="hidden" name="new_status" value="0">
                                            <button type="submit" class="relative inline-flex items-center h-6 rounded-full w-11 transition-colors focus:outline-none bg-green-500" title="Klik untuk Nonaktifkan">
                                                <span class="sr-only">Aktif</span>
                                                <span class="translate-x-6 inline-block w-4 h-4 transform bg-white rounded-full transition-transform"></span>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="new_status" value="1">
                                            <button type="submit" class="relative inline-flex items-center h-6 rounded-full w-11 transition-colors focus:outline-none bg-gray-200" title="Klik untuk Aktifkan">
                                                <span class="sr-only">Nonaktif</span>
                                                <span class="translate-x-1 inline-block w-4 h-4 transform bg-white rounded-full transition-transform"></span>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>

                                <!-- Aksi Edit, Reset Limit & Delete -->
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                    <div class="flex items-center justify-center space-x-2">
                                        <!-- Edit Link dengan Parameter Lengkap -->
                                        <a href="?page=produk&action=edit&id=<?= $product['id'] ?>&q=<?= urlencode($current_q) ?>&category=<?= $current_category ?>&status=<?= $current_status ?>&p=<?= $current_page_num ?>&limit=<?= $current_limit ?>" class="text-indigo-600 hover:text-indigo-900 bg-indigo-50 p-2 rounded-lg hover:bg-indigo-100 transition" title="Edit Produk">
                                            <i class="fas fa-edit"></i>
                                        </a>

                                        <!-- NEW: TOMBOL RESET LIMIT -->
                                        <!-- Hanya muncul jika produk memiliki limit pembelian > 0 -->
                                        <?php if ($product['purchase_limit'] > 0): ?>
                                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('RESET LIMIT: Tindakan ini akan mereset kuota pembelian user untuk produk ini. User yang sudah mencapai limit akan bisa membeli lagi. Lanjutkan?');" class="inline">
                                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                <input type="hidden" name="reset_limit" value="1">

                                                <input type="hidden" name="return_q" value="<?= htmlspecialchars($current_q) ?>">
                                                <input type="hidden" name="return_category" value="<?= htmlspecialchars($current_category) ?>">
                                                <input type="hidden" name="return_status" value="<?= htmlspecialchars($current_status) ?>">
                                                <input type="hidden" name="return_page" value="<?= htmlspecialchars($current_page_num) ?>">
                                                <input type="hidden" name="return_limit" value="<?= htmlspecialchars($current_limit) ?>">

                                                <button type="submit" class="text-orange-500 hover:text-orange-700 bg-orange-50 p-2 rounded-lg hover:bg-orange-100 transition" title="Reset Limit User">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Form Delete dengan Hidden Field State -->
                                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Anda yakin ingin mengubah status produk ini menjadi non-aktif?');" class="inline">
                                            <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                            <input type="hidden" name="delete_product" value="1">

                                            <!-- INJECT HIDDEN FIELD UNTUK MEMBAWA STATE KEMBALI -->
                                            <input type="hidden" name="return_q" value="<?= htmlspecialchars($current_q) ?>">
                                            <input type="hidden" name="return_category" value="<?= htmlspecialchars($current_category) ?>">
                                            <input type="hidden" name="return_status" value="<?= htmlspecialchars($current_status) ?>">
                                            <input type="hidden" name="return_page" value="<?= htmlspecialchars($current_page_num) ?>">
                                            <input type="hidden" name="return_limit" value="<?= htmlspecialchars($current_limit) ?>">

                                            <button type="submit" class="text-red-600 hover:text-red-900 bg-red-50 p-2 rounded-lg hover:bg-red-100 transition" title="Arsipkan (Nonaktifkan)">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-gray-500 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300 m-4">
                                <i class="fas fa-box-open text-4xl mb-3 text-gray-300"></i>
                                <p>Tidak ada produk yang ditemukan dengan filter ini.</p>
                                <a href="?page=produk" class="text-indigo-600 hover:underline text-sm mt-2 inline-block">Reset Filter</a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Paginasi Cerdas dengan Support Limit Dinamis -->
    <?php if ($total_pages > 1): ?>
    <div class="mt-6 flex justify-between items-center">
        <div class="text-sm text-gray-500">
            Menampilkan <?= $offset + 1 ?> sampai <?= min($offset + $limit, $total_results) ?> dari <?= $total_results ?> data
        </div>
        <nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=produk&q=<?= urlencode($current_q) ?>&category=<?= $current_category ?>&status=<?= $current_status ?>&p=<?= $i ?>&limit=<?= $current_limit ?>"
                   class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?= $i == $page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </nav>
    </div>
    <?php endif; ?>

<?php } ?>