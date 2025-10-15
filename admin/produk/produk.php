<?php
// File: admin/produk/produk.php
if (!defined('BASE_URL')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';
?>
<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<?php if ($action == 'list'): ?>
<div class="flex justify-end mb-4">
    <a href="?page=produk&action=add" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Tambah Produk Baru</a>
</div>
<div class="bg-white p-4 rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead><tr><th class="px-4 py-3 text-left font-medium">Produk</th><th class="px-4 py-3 text-left font-medium">Kategori</th><th class="px-4 py-3 text-left font-medium">Harga</th><th class="px-4 py-3 text-left font-medium">Stok</th><th class="px-4 py-3 text-left font-medium">Aksi</th></tr></thead>
        <tbody>
        <?php
            $result = $conn->query("SELECT p.*, c.name as category_name FROM products p JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC");
            while($row = $result->fetch_assoc()):
        ?>
            <tr>
                <td class="px-4 py-4 flex items-center gap-3"><img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($row['image']) ?>" alt="" class="w-10 h-10 rounded object-cover"><?= htmlspecialchars($row['name']) ?></td>
                <td class="px-4 py-4"><?= htmlspecialchars($row['category_name']) ?></td>
                <td class="px-4 py-4"><?= format_rupiah($row['price']) ?></td>
                <td class="px-4 py-4"><?= $row['stock'] ?></td>
                <td class="px-4 py-4 flex gap-2">
                    <a href="?page=produk&action=edit&id=<?= $row['id'] ?>" class="text-indigo-600">Edit</a>
                    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus produk ini?');">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" name="delete_produk" class="text-red-600">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php elseif ($action == 'add' || $action == 'edit'): 
    $produk = ['id'=>'', 'name'=>'', 'category_id'=>'', 'price'=>'', 'stock'=>'', 'description'=>'', 'image'=>''];
    if ($action == 'edit' && isset($_GET['id'])) {
        $id_to_edit = $_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->bind_param("i", $id_to_edit);
        $stmt->execute();
        $res = $stmt->get_result();
        if($res->num_rows > 0) $produk = $res->fetch_assoc();
        $stmt->close();
    }
?>
<div class="bg-white p-6 rounded-lg shadow-md">
    <h2 class="text-lg font-semibold mb-4"><?= $action == 'add' ? 'Tambah Produk' : 'Edit Produk' ?></h2>
    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $produk['id'] ?>">
        <input type="hidden" name="current_image" value="<?= $produk['image'] ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="name" class="block text-sm font-medium">Nama Produk</label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($produk['name']) ?>" required class="mt-1 block w-full border-gray-300 rounded-md">
            </div>
            <div>
                <label for="category_id" class="block text-sm font-medium">Kategori</label>
                <select name="category_id" id="category_id" required class="mt-1 block w-full border-gray-300 rounded-md">
                    <?php 
                    $cats = $conn->query("SELECT * FROM categories ORDER BY name");
                    while($cat = $cats->fetch_assoc()) {
                        echo "<option value='{$cat['id']}' ".($produk['category_id'] == $cat['id'] ? 'selected' : '').">".htmlspecialchars($cat['name'])."</option>";
                    }
                    ?>
                </select>
            </div>
            <div>
                <label for="price" class="block text-sm font-medium">Harga</label>
                <input type="number" name="price" id="price" value="<?= $produk['price'] ?>" required class="mt-1 block w-full border-gray-300 rounded-md">
            </div>
            <div>
                <label for="stock" class="block text-sm font-medium">Stok</label>
                <input type="number" name="stock" id="stock" value="<?= $produk['stock'] ?>" required class="mt-1 block w-full border-gray-300 rounded-md">
            </div>
            <div class="md:col-span-2">
                <label for="description" class="block text-sm font-medium">Deskripsi</label>
                <textarea name="description" id="description" rows="4" class="mt-1 block w-full border-gray-300 rounded-md"><?= htmlspecialchars($produk['description']) ?></textarea>
            </div>
            <div>
                <label for="image" class="block text-sm font-medium">Gambar Produk</label>
                <input type="file" name="image" id="image" class="mt-1 block w-full text-sm">
                <?php if($action == 'edit' && $produk['image']): ?>
                <img src="<?= BASE_URL ?>/assets/images/produk/<?= $produk['image'] ?>" class="mt-2 h-20 rounded">
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-6 flex gap-2">
            <button type="submit" name="save_produk" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Simpan</button>
            <a href="?page=produk" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md">Batal</a>
        </div>
    </form>
</div>
<?php endif; ?>