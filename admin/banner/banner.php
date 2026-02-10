<?php
// File: admin/banner/banner.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

$action = $_GET['action'] ?? 'list';
$banner = null;

if ($action == 'edit' && isset($_GET['id'])) {
    $banner_id = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM banners WHERE id = ?");
    $stmt->bind_param("i", $banner_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $banner = $result->fetch_assoc();
    } else {
        set_flashdata('error', 'Banner tidak ditemukan.');
        redirect('/admin/admin.php?page=banner');
    }
    $stmt->close();
}
?>

<?php if ($action == 'list'): ?>

<div class="flex justify-between items-center mb-6">
    <h2 class="text-2xl font-semibold text-gray-700">Daftar Banner</h2>
    <a href="?page=banner&action=add" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">Tambah Banner Baru</a>
</div>

<div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gambar</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Judul</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Link</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
        <?php
            $result = $conn->query("SELECT * FROM banners ORDER BY created_at DESC");
            if ($result && $result->num_rows > 0):
                while($row = $result->fetch_assoc()):
        ?>
            <tr>
                <td class="px-4 py-4 whitespace-nowrap">
                    <img src="<?= BASE_URL ?>/assets/images/banner/<?= $row['image'] ?>" alt="<?= htmlspecialchars($row['title']) ?>" class="h-16 w-32 object-cover rounded">
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($row['title']) ?></td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                    <a href="<?= htmlspecialchars($row['link_url']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-900 truncate max-w-xs block">
                        <?= htmlspecialchars($row['link_url'] ?: '-') ?>
                    </a>
                </td>
                <td class="px-4 py-4 whitespace-nowrap">
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $row['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                        <?= $row['is_active'] ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm font-medium flex items-center gap-4">
                    <a href="?page=banner&action=edit&id=<?= $row['id'] ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                    <form method="POST" action="<?= BASE_URL ?>/admin/admin.php" class="inline-block" onsubmit="return confirm('Yakin ingin menghapus banner ini?');">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" name="delete_banner" class="text-red-600 hover:text-red-900">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php 
                endwhile;
            else:
        ?>
            <tr>
                <td colspan="5" class="px-4 py-4 text-center text-gray-500">Belum ada banner yang ditambahkan.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php else: // Mode Add atau Edit ?>

<div class="bg-white p-6 rounded-lg shadow-md max-w-3xl mx-auto">
    <h2 class="text-xl font-semibold mb-4"><?= $action == 'add' ? 'Tambah' : 'Edit' ?> Banner</h2>
    
    <form method="POST" action="<?= BASE_URL ?>/admin/admin.php" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $banner['id'] ?? '' ?>">

        <div class="space-y-4">
            <div>
                <label for="title" class="block text-sm font-medium text-gray-700">Judul Banner</label>
                <input type="text" name="title" id="title" value="<?= htmlspecialchars($banner['title'] ?? '') ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
            </div>
            
            <div>
                <label for="link_url" class="block text-sm font-medium text-gray-700">Link URL (Opsional)</label>
                <input type="url" name="link_url" id="link_url" value="<?= htmlspecialchars($banner['link_url'] ?? '') ?>" placeholder="https://..." class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2">
            </div>
            
            <div>
                <label class="flex items-center">
                    <input type="checkbox" name="is_active" value="1" <?= (!isset($banner) || $banner['is_active']) ? 'checked' : '' ?> class="h-4 w-4 text-indigo-600 border-gray-300 rounded">
                    <span class="ml-2 text-sm text-gray-700">Aktifkan banner</span>
                </label>
            </div>

            <div>
                <label for="image" class="block text-sm font-medium text-gray-700">Gambar Banner</label>
                <input type="file" name="image" id="image" class="mt-1 block w-full text-sm" <?= $action == 'add' ? 'required' : '' ?>>
                <?php if($action == 'edit' && !empty($banner['image'])): ?>
                <div class="mt-2">
                    <p class="text-xs text-gray-500">Gambar saat ini:</p>
                    <img src="<?= BASE_URL ?>/assets/images/banner/<?= $banner['image'] ?>" class="mt-1 h-24 object-cover rounded shadow-md border">
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mt-6 flex gap-2">
            <button type="submit" name="save_banner" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
                Simpan Banner
            </button>
            <a href="?page=banner" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300">Batal</a>
        </div>
    </form>
</div>

<?php endif; ?>