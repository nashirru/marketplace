<?php
// File: admin/produk/produk.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';

if ($action == 'add' || $action == 'edit') {
    // Jika aksinya adalah tambah atau edit, kita muat file form
    include 'form_produk.php';
} else {
    // --- PENGATURAN PAGINASI, FILTER, DAN PENCARIAN ---
    $page = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;
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
    $where_conditions = [];

    if ($category_filter > 0) {
        $where_conditions[] = "p.category_id = ?";
        $params[] = $category_filter;
        $types .= "i";
    }
    if (!empty($search_query)) {
        $search_term = "%" . $search_query . "%";
        $where_conditions[] = "p.name LIKE ?";
        $params[] = $search_term;
        $types .= "s";
    }

    $where_clause = !empty($where_conditions) ? " WHERE " . implode(" AND ", $where_conditions) : "";

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
    // Query tidak berubah, mengambil purchase_limit
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
        $products[] = $row;
    }
    $stmt->close();
?>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <form method="GET" class="flex items-center gap-4">
            <input type="hidden" name="page" value="produk">
            <div class="relative">
                <input type="text" name="q" placeholder="Cari nama produk..." value="<?= htmlspecialchars($search_query) ?>" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-md shadow-sm">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </div>
            <select name="category" class="border-gray-300 rounded-md shadow-sm">
                <option value="0">Semua Kategori</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $category['id'] ?>" <?= $category_filter == $category['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700">Filter</button>
        </form>

        <a href="?page=produk&action=add" class="px-4 py-2 bg-green-600 text-white font-semibold rounded-md hover:bg-green-700 shadow flex items-center gap-2">
            <i class="fas fa-plus-circle"></i> Tambah Produk
        </a>
    </div>

    <div class="bg-white p-4 rounded-lg shadow-md overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gambar</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nama Produk</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Kategori</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Harga</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Stok</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Limit Beli</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <tr>
                            <td class="px-4 py-4 whitespace-nowrap">
                                <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-12 h-12 object-cover rounded-md">
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-800"><?= htmlspecialchars($product['name']) ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($product['category_name']) ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($product['price']) ?></td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-center font-semibold <?= $product['stock'] < 10 ? 'text-red-600' : 'text-gray-700' ?>">
                                <?= $product['stock'] ?>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-center text-gray-500">
                                <?= (isset($product['purchase_limit']) && $product['purchase_limit'] > 0) ? $product['purchase_limit'] : '<i class="fas fa-infinity text-gray-400"></i>' ?>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm">
                                <div class="flex items-center space-x-3">
                                    <a href="?page=produk&action=edit&id=<?= $product['id'] ?>" class="text-indigo-500 hover:text-indigo-700 transition" title="Edit Produk"><i class="fas fa-edit fa-lg"></i></a>
                                    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Anda yakin ingin menghapus produk ini?');">
                                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                        <button type="submit" name="delete_product" class="text-red-500 hover:text-red-700 transition" title="Hapus Produk"><i class="fas fa-trash-alt fa-lg"></i></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-8 text-gray-500">Tidak ada produk yang ditemukan.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginasi -->
    <div class="mt-6 flex justify-end">
        <nav class="inline-flex rounded-md shadow-sm -space-x-px">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?page=produk&q=<?= urlencode($search_query) ?>&category=<?= $category_filter ?>&p=<?= $i ?>"
                   class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?= $i == $page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </nav>
    </div>
<?php } ?>