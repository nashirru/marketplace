<?php
// File: admin/banner/banner.php
if (!defined('BASE_URL')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';
$banner_to_edit = ['id'=>'', 'title'=>'', 'link_url'=>'', 'is_active'=>1, 'image'=>''];

if ($action == 'edit' && isset($_GET['id'])) {
    $id = $_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM banners WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if($res->num_rows > 0) $banner_to_edit = $res->fetch_assoc();
    $stmt->close();
}
?>
<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-lg font-semibold mb-4"><?= $action == 'edit' ? 'Edit Banner' : 'Tambah Banner' ?></h2>
            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $banner_to_edit['id'] ?>">
                <input type="hidden" name="current_image" value="<?= $banner_to_edit['image'] ?>">
                <div class="mb-4">
                    <label class="block text-sm font-medium">Judul</label>
                    <input type="text" name="title" value="<?= htmlspecialchars($banner_to_edit['title']) ?>" required class="mt-1 block w-full border-gray-300 rounded-md">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium">URL Link</label>
                    <input type="url" name="link_url" value="<?= htmlspecialchars($banner_to_edit['link_url']) ?>" class="mt-1 block w-full border-gray-300 rounded-md">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium">Gambar</label>
                    <input type="file" name="image" class="mt-1 block w-full text-sm">
                    <?php if($action == 'edit' && $banner_to_edit['image']): ?>
                        <img src="<?= BASE_URL ?>/assets/images/banner/<?= $banner_to_edit['image'] ?>" class="mt-2 h-20 rounded">
                    <?php endif; ?>
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" value="1" class="rounded" <?= $banner_to_edit['is_active'] ? 'checked' : '' ?>>
                        <span class="ml-2 text-sm">Aktifkan Banner</span>
                    </label>
                </div>
                <div class="flex gap-2">
                    <button type="submit" name="save_banner" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Simpan</button>
                    <?php if($action == 'edit'): ?><a href="?page=banner" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md">Batal</a><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <div class="lg:col-span-2">
        <div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead><tr><th class="px-4 py-3 text-left font-medium">Banner</th><th class="px-4 py-3 text-left font-medium">Status</th><th class="px-4 py-3 text-left font-medium">Aksi</th></tr></thead>
                <tbody>
                <?php
                    $result = $conn->query("SELECT * FROM banners ORDER BY created_at DESC");
                    while($row = $result->fetch_assoc()):
                ?>
                    <tr>
                        <td class="px-4 py-4 flex items-center gap-3">
                            <img src="<?= BASE_URL ?>/assets/images/banner/<?= htmlspecialchars($row['image']) ?>" alt="" class="w-20 h-10 rounded object-cover">
                            <?= htmlspecialchars($row['title']) ?>
                        </td>
                        <td class="px-4 py-4">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $row['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                            </span>
                        </td>
                        <td class="px-4 py-4 flex gap-2">
                            <a href="?page=banner&action=edit&id=<?= $row['id'] ?>" class="text-indigo-600">Edit</a>
                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus banner ini?');">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_banner" class="text-red-600">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>