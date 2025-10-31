<?php
// File: admin/pembayaran/pembayaran.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';
$id_to_edit = (int)($_GET['id'] ?? 0);
$method_to_edit = ['id' => '', 'name' => '', 'details' => '', 'is_active' => 1];

if ($action == 'edit' && $id_to_edit > 0) {
    $stmt = $conn->prepare("SELECT * FROM payment_methods WHERE id = ?");
    $stmt->bind_param("i", $id_to_edit);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $method_to_edit = $result->fetch_assoc();
    } else {
        set_flashdata('error', 'Metode pembayaran tidak ditemukan.');
        redirect('/admin/admin.php?page=pembayaran');
    }
    $stmt->close();
}
?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-lg font-semibold mb-4"><?= $action == 'edit' ? 'Edit Metode Pembayaran' : 'Tambah Metode Baru' ?></h2>
            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                <input type="hidden" name="id" value="<?= $method_to_edit['id'] ?>">
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700">Nama Metode</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($method_to_edit['name']) ?>" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" placeholder="Cth: Bank BCA">
                </div>
                <div class="mb-4">
                    <label for="details" class="block text-sm font-medium text-gray-700">Detail</label>
                    <textarea id="details" name="details" rows="4" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm" placeholder="No. Rek: 123456789&#10;a/n: Nama Pemilik"><?= htmlspecialchars($method_to_edit['details']) ?></textarea>
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" value="1" class="h-4 w-4 rounded text-indigo-600 border-gray-300" <?= $method_to_edit['is_active'] ? 'checked' : '' ?>>
                        <span class="ml-2 text-sm text-gray-700">Aktifkan metode ini</span>
                    </label>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" name="save_payment_method" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Simpan</button>
                    <?php if ($action == 'edit'): ?>
                        <a href="?page=pembayaran" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Batal</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="lg:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase">Metode</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-500 uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                <?php
                    $result = $conn->query("SELECT * FROM payment_methods ORDER BY name ASC");
                    while($row = $result->fetch_assoc()):
                ?>
                    <tr>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <div class="font-semibold text-gray-800"><?= htmlspecialchars($row['name']) ?></div>
                            <div class="text-sm text-gray-500"><?= nl2br(htmlspecialchars($row['details'])) ?></div>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $row['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-sm font-medium flex items-center gap-4">
                            <a href="?page=pembayaran&action=edit&id=<?= $row['id'] ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus metode ini?');">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_payment_method" class="text-red-600 hover:text-red-900">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>