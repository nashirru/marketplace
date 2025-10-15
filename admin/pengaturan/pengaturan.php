<?php
// File: admin/pengaturan/pengaturan.php
if (!defined('BASE_URL')) die('Akses dilarang');
?>
<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-lg font-semibold mb-4">Formulir Pengaturan Toko</h2>
        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" enctype="multipart/form-data">
            
            <!-- Logo Toko -->
            <div class="mb-6">
                <label for="store_logo" class="block text-sm font-medium text-gray-700 mb-1">Logo Toko</label>
                <?php 
                $logo = get_setting('store_logo');
                if ($logo && file_exists('../assets/images/settings/' . $logo)): ?>
                    <img src="<?= BASE_URL ?>/assets/images/settings/<?= $logo ?>" alt="Logo Saat Ini" class="h-16 w-auto mb-2 rounded-md border p-1">
                <?php endif; ?>
                <input type="file" name="store_logo" id="store_logo" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                <p class="text-xs text-gray-500 mt-1">Kosongkan jika tidak ingin mengubah logo.</p>
            </div>

            <!-- Deskripsi Toko -->
            <div class="mb-4">
                <label for="store_description" class="block text-sm font-medium text-gray-700">Deskripsi Toko di Footer</label>
                <textarea name="store_description" id="store_description" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"><?= htmlspecialchars(get_setting('store_description')) ?></textarea>
            </div>

            <!-- Link Facebook -->
            <div class="mb-4">
                <label for="facebook_url" class="block text-sm font-medium text-gray-700">URL Facebook</label>
                <input type="url" name="facebook_url" id="facebook_url" value="<?= htmlspecialchars(get_setting('facebook_url')) ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="https://facebook.com/namahalaman">
            </div>

            <!-- Link Instagram -->
            <div class="mb-4">
                <label for="instagram_url" class="block text-sm font-medium text-gray-700">URL Instagram</label>
                <input type="url" name="instagram_url" id="instagram_url" value="<?= htmlspecialchars(get_setting('instagram_url')) ?>" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm" placeholder="https://instagram.com/namaakun">
            </div>

            <!-- Tombol Simpan -->
            <div class="mt-6">
                <button type="submit" name="save_settings" class="w-full px-4 py-2 bg-indigo-600 text-white font-semibold rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Simpan Perubahan
                </button>
            </div>
        </form>
    </div>
</div>