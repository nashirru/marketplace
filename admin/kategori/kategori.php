<?php
// File: admin/kategori/kategori.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';
$id_to_edit = (int)($_GET['id'] ?? 0);
$kategori_to_edit = ['id' => '', 'name' => ''];

if ($action == 'edit' && $id_to_edit > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id_to_edit);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $kategori_to_edit = $result->fetch_assoc();
    } else {
        set_flashdata('error', 'Kategori tidak ditemukan.');
        redirect('/admin/admin.php?page=kategori');
    }
    $stmt->close();
}
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Form Tambah/Edit Kategori -->
    <div class="lg:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-lg font-semibold mb-4"><?= $action == 'edit' ? 'Edit Kategori' : 'Tambah Kategori Baru' ?></h2>
            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                <input type="hidden" name="id" value="<?= $kategori_to_edit['id'] ?>">
                
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700">Nama Kategori</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($kategori_to_edit['name']) ?>" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex items-center gap-2 border-t pt-4">
                    <button type="submit" name="save_kategori" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Simpan</button>
                    <?php if ($action == 'edit'): ?>
                        <a href="?page=kategori" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Tabel Daftar Kategori -->
    <div class="lg:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase">Nama Kategori</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php
                    $result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
                    while($row = $result->fetch_assoc()):
                ?>
                    <tr>
                        <td class="px-4 py-4 whitespace-nowrap font-medium text-gray-800"><?= htmlspecialchars($row['name']) ?></td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium flex items-center gap-4">
                            <a href="?page=kategori&action=edit&id=<?= $row['id'] ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus kategori ini?');">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_kategori" class="text-red-600 hover:text-red-900">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>