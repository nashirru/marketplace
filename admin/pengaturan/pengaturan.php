<?php
// File: admin/pengaturan/pengaturan.php
// Pastikan hanya bisa diakses dari admin.php
if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// Ambil nilai pengaturan saat ini
$store_name = get_setting($conn, 'store_name') ?? 'Nama Toko Default';
$store_description = get_setting($conn, 'store_description') ?? 'Deskripsi singkat tentang toko Anda.';
$store_logo = get_setting($conn, 'store_logo');
$store_address = get_setting($conn, 'store_address');
$store_phone = get_setting($conn, 'store_phone');
$store_email = get_setting($conn, 'store_email');
$store_facebook = get_setting($conn, 'store_facebook');
$store_tiktok = get_setting($conn, 'store_tiktok'); // Mengambil nilai TikTok

// Tentukan path logo saat ini
$current_logo_path = BASE_URL . '/assets/images/settings/' . ($store_logo ?? 'default_logo.png');

// Pastikan direktori base terdefinisi untuk cek file lokal
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)) . '/');
}

?>

<div class="bg-white p-6 rounded-lg shadow-xl max-w-4xl">
    <h2 class="text-2xl font-bold mb-6 text-gray-800 border-b pb-3">Pengaturan Informasi Toko</h2>
    
    <form action="admin.php?page=pengaturan_toko" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="save_settings" value="1">

        <!-- Logo Saat Ini -->
        <div class="mb-6 border p-4 rounded-lg bg-gray-50">
            <h3 class="text-lg font-semibold mb-2">Logo Toko Saat Ini</h3>
            <?php if ($store_logo && file_exists(BASE_PATH . 'assets/images/settings/' . $store_logo)): ?>
                <img src="<?= $current_logo_path ?>" alt="Current Store Logo" class="h-24 w-24 object-contain rounded-lg border p-1 bg-white">
            <?php else: ?>
                <p class="text-red-500">Logo belum diatur atau file tidak ditemukan.</p>
                <img src="https://placehold.co/96x96/E2E8F0/4A5568?text=NO%20LOGO" alt="Placeholder Logo" class="h-24 w-24 object-contain rounded-lg border p-1 bg-white">
            <?php endif; ?>
            <p class="text-sm text-gray-500 mt-2">Path: `<?= htmlspecialchars($store_logo) ?? 'N/A' ?>`</p>
        </div>
        
        <!-- Upload Logo Baru -->
        <div class="mb-4">
            <label for="store_logo" class="block text-sm font-medium text-gray-700">Ganti Logo Baru</label>
            <input type="file" name="store_logo" id="store_logo" accept="image/*" class="mt-1 block w-full text-sm text-gray-500 border border-gray-300 rounded-md p-2">
            <p class="mt-1 text-xs text-gray-500">Maksimum ukuran 1MB. (JPG, PNG, GIF)</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nama Toko (INPUT SUDAH ADA) -->
            <div class="mb-4">
                <label for="store_name" class="block text-sm font-medium text-gray-700">Nama Toko</label>
                <input type="text" name="store_name" id="store_name" value="<?= htmlspecialchars($store_name) ?>" required class="mt-1 block w-full border border-gray-300 rounded-md p-2">
            </div>

            <!-- Email Toko -->
            <div class="mb-4">
                <label for="store_email" class="block text-sm font-medium text-gray-700">Email Toko</label>
                <input type="email" name="store_email" id="store_email" value="<?= htmlspecialchars($store_email) ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2">
            </div>
            
            <!-- Telepon Toko -->
            <div class="mb-4">
                <label for="store_phone" class="block text-sm font-medium text-gray-700">Nomor Telepon/WhatsApp</label>
                <input type="text" name="store_phone" id="store_phone" value="<?= htmlspecialchars($store_phone) ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2">
            </div>

            <!-- Alamat Toko (INPUT SUDAH ADA) -->
            <div class="mb-4">
                <label for="store_address" class="block text-sm font-medium text-gray-700">Alamat Lengkap</label>
                <textarea name="store_address" id="store_address" rows="1" class="mt-1 block w-full border border-gray-300 rounded-md p-2"><?= htmlspecialchars($store_address) ?></textarea>
            </div>
            
            <!-- Link Facebook -->
            <div class="mb-4">
                <label for="store_facebook" class="block text-sm font-medium text-gray-700">Link Facebook Saat Ini</label>
                <input type="url" name="store_facebook" id="store_facebook" value="<?= htmlspecialchars($store_facebook) ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2" placeholder="https://facebook.com/namatoko">
            </div>
            
            <!-- Link TikTok -->
            <div class="mb-4">
                <label for="store_tiktok" class="block text-sm font-medium text-gray-700">Link TikTok</label>
                <input type="url" name="store_tiktok" id="store_tiktok" value="<?= htmlspecialchars($store_tiktok) ?>" class="mt-1 block w-full border border-gray-300 rounded-md p-2" placeholder="https://tiktok.com/@namatoko">
            </div>
        </div>

        <!-- Deskripsi Toko -->
        <div class="mb-6">
            <label for="store_description" class="block text-sm font-medium text-gray-700">Deskripsi Toko Saat Ini</label>
            <textarea name="store_description" id="store_description" rows="4" class="mt-1 block w-full border border-gray-300 rounded-md p-2" required><?= htmlspecialchars($store_description) ?></textarea>
        </div>

        <button type="submit" class="w-full md:w-auto px-6 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition duration-150 shadow-md">
            Simpan Pengaturan
        </button>
    </form>
</div>