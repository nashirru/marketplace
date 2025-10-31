<?php
// File: admin/pengaturan/pengaturan.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// Ambil semua nilai pengaturan yang relevan
$settings_keys = [
    'store_name', 'store_description', 'store_logo', 'store_address',
    'store_phone', 'store_email', 'store_facebook', 'store_tiktok'
];
$settings = [];
foreach ($settings_keys as $key) {
    $settings[$key] = get_setting($conn, $key);
}

$current_logo_path = BASE_URL . '/assets/images/settings/' . ($settings['store_logo'] ?? 'default_logo.png');

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)) . '/');
}
?>

<div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl mx-auto">
    <h2 class="text-2xl font-bold mb-6 text-gray-800 border-b pb-3">Pengaturan Informasi Toko</h2>
    
    <form action="admin.php?page=pengaturan_toko" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_settings" value="1">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <!-- Kolom Kiri: Logo & Info Dasar -->
            <div class="md:col-span-1">
                <h3 class="text-lg font-semibold mb-2 text-gray-700">Logo Toko</h3>
                <div class="mb-4 border p-3 rounded-lg bg-gray-50 text-center">
                    <?php if ($settings['store_logo'] && file_exists(BASE_PATH . 'assets/images/settings/' . $settings['store_logo'])): ?>
                        <img src="<?= $current_logo_path ?>" alt="Logo Saat Ini" class="h-24 w-24 object-contain rounded-lg border p-1 bg-white mx-auto">
                    <?php else: ?>
                        <img src="https://placehold.co/96x96/E2E8F0/4A5568?text=NO%20LOGO" alt="Placeholder Logo" class="h-24 w-24 object-contain rounded-lg border p-1 bg-white mx-auto">
                    <?php endif; ?>
                </div>
                <div>
                    <label for="store_logo" class="block text-sm font-medium text-gray-700">Ganti Logo</label>
                    <input type="file" name="store_logo" id="store_logo" accept="image/*" class="mt-1 block w-full text-sm text-gray-500 file:mr-2 file:py-1.5 file:px-3 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                </div>
            </div>

            <!-- Kolom Kanan: Detail Toko -->
            <div class="md:col-span-2">
                <div class="mb-4">
                    <label for="store_name" class="block text-sm font-medium text-gray-700">Nama Toko</label>
                    <input type="text" name="store_name" id="store_name" value="<?= htmlspecialchars($settings['store_name'] ?? '') ?>" required class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="mb-4">
                    <label for="store_description" class="block text-sm font-medium text-gray-700">Deskripsi Toko</label>
                    <textarea name="store_description" id="store_description" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" required><?= htmlspecialchars($settings['store_description'] ?? '') ?></textarea>
                </div>
                 <!-- ✅ ALAMAT TOKO DITAMBAHKAN KEMBALI -->
                <div class="mb-4">
                    <label for="store_address" class="block text-sm font-medium text-gray-700">Alamat Toko</label>
                    <textarea name="store_address" id="store_address" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500"><?= htmlspecialchars($settings['store_address'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        
        <hr class="my-6">
        
        <!-- Kontak dan Media Sosial -->
        <div>
             <h3 class="text-lg font-semibold mb-4 text-gray-700">Kontak & Media Sosial</h3>
             <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4">
                <div>
                    <label for="store_email" class="block text-sm font-medium text-gray-700">Email (Hubungi Kami)</label>
                    <input type="email" name="store_email" id="store_email" value="<?= htmlspecialchars($settings['store_email'] ?? '') ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="contoh@gmail.com">
                </div>
                 <div>
                    <label for="store_phone" class="block text-sm font-medium text-gray-700">Telepon / WhatsApp</label>
                    <input type="text" name="store_phone" id="store_phone" value="<?= htmlspecialchars($settings['store_phone'] ?? '') ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="08123456789">
                </div>
                <div>
                    <label for="store_facebook" class="block text-sm font-medium text-gray-700">URL Facebook</label>
                    <input type="url" name="store_facebook" id="store_facebook" value="<?= htmlspecialchars($settings['store_facebook'] ?? '') ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://facebook.com/namatoko">
                </div>
                <div>
                    <label for="store_tiktok" class="block text-sm font-medium text-gray-700">URL TikTok</label>
                    <input type="url" name="store_tiktok" id="store_tiktok" value="<?= htmlspecialchars($settings['store_tiktok'] ?? '') ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2 shadow-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="https://tiktok.com/@namatoko">
                </div>
             </div>
        </div>

        <div class="mt-8 pt-5 border-t">
            <button type="submit" class="w-full md:w-auto px-6 py-2.5 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition duration-150 shadow-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Simpan Pengaturan
            </button>
        </div>
    </form>
</div>