<?php
// File: admin/user/user.php
if (!defined('BASE_URL')) die('Akses dilarang');
?>

<!-- Tampilkan notifikasi -->
<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<!-- 
    FITUR 1: SEARCH SUPER CEPAT (CLIENT-SIDE)
    Input ini akan memfilter card pengguna secara real-time pakai JavaScript
-->
<div class="mb-6 relative">
    <label for="user-search-input" class="sr-only">Cari Pengguna</label>
    <input type="search" id="user-search-input" onkeyup="filterUsers()" placeholder="Cari berdasarkan nama, email, atau alamat..." class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
    <div class="absolute left-3 top-0 h-full flex items-center">
        <i class="fas fa-search text-gray-400"></i>
    </div>
</div>

<!-- 
    FITUR 2 & 3: UI KARTU & TAMPILAN ALAMAT
    Kita ganti <table> dengan <div> berbasis grid.
-->
<div id="user-card-container" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php
        // 1. Ambil semua pengguna
        $result_users = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
        
        // 2. Siapkan query untuk alamat (agar lebih efisien)
        $stmt_addr = $conn->prepare("SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");

        if (!$stmt_addr) {
            die("Gagal mempersiapkan statement alamat: " . $conn->error);
        }

        while($user = $result_users->fetch_assoc()):
            
            // 3. Ambil semua alamat untuk pengguna ini
            $stmt_addr->bind_param("i", $user['id']);
            $stmt_addr->execute();
            $result_addr = $stmt_addr->get_result();
            $addresses = $result_addr->fetch_all(MYSQLI_ASSOC);
    ?>
    <!-- Ini adalah 1 Kartu Pengguna -->
    <div class="bg-white p-5 rounded-lg shadow-md border border-gray-200 user-card flex flex-col">
        
        <!-- Bagian Info User -->
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center space-x-3 min-w-0">
                <i class="fas fa-user-circle text-4xl text-indigo-600"></i>
                <div class="min-w-0">
                    <h3 class="font-bold text-lg text-gray-900 truncate user-name" title="<?= htmlspecialchars($user['name']) ?>"><?= htmlspecialchars($user['name']) ?></h3>
                    <p class="text-sm text-gray-500 truncate user-email" title="<?= htmlspecialchars($user['email']) ?>"><?= htmlspecialchars($user['email']) ?></p>
                </div>
            </div>
            <!-- Form Ganti Role -->
            <div>
                <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" class="user-role-form">
                    <input type="hidden" name="id" value="<?= $user['id'] ?>">
                    <select name="role" onchange="this.form.submit()" class="border-gray-300 rounded-md text-sm p-1.5 focus:ring-indigo-500 shadow-sm" aria-label="Ganti role untuk <?= htmlspecialchars($user['name']) ?>">
                        <option value="user" <?= $user['role'] == 'user' ? 'selected' : '' ?>>User</option>
                        <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <input type="hidden" name="update_user_role" value="1">
                </form>
            </div>
        </div>

        <!-- Bagian Alamat User (FITUR 2) -->
        <div class="mb-4 flex-grow">
            <h4 class="font-semibold text-sm text-gray-700 mb-2">
                <i class="fas fa-map-marked-alt mr-2 text-gray-400"></i>Alamat Tersimpan (<?= count($addresses) ?>)
            </h4>
            <div class="text-xs text-gray-600 space-y-2 max-h-32 overflow-y-auto pr-2 user-addresses custom-scrollbar">
                <?php if (empty($addresses)): ?>
                    <p class="text-gray-400 italic px-2 py-1">Belum ada alamat tersimpan.</p>
                <?php else: ?>
                    <?php foreach ($addresses as $addr): ?>
                        <div class="p-2 rounded-md <?= $addr['is_default'] ? 'bg-indigo-50 border border-indigo-200' : 'bg-gray-50' ?>">
                            <p class="font-medium truncate">
                                <?php if ($addr['is_default']): ?>
                                    <i class="fas fa-star text-indigo-500 mr-1" title="Alamat Utama"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($addr['full_name']) ?> (<?= htmlspecialchars($addr['phone_number']) ?>)
                            </p>
                            <p class="truncate"><?= htmlspecialchars($addr['address_line_1']) ?>, <?= htmlspecialchars($addr['city']) ?></p>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Bagian Aksi (FITUR 4: Font Awesome) -->
        <div class="flex items-center justify-end space-x-3 border-t border-gray-200 pt-4 mt-auto">
            <details class="relative">
                <summary class="cursor-pointer text-indigo-600 hover:text-indigo-800 text-sm font-medium flex items-center p-2 rounded-md hover:bg-indigo-50 transition-colors">
                    <i class="fas fa-paper-plane mr-2"></i> Kirim Pesan
                </summary>
                <div class="absolute right-0 z-10 mt-2 w-64 bg-white p-4 rounded-lg shadow-lg border">
                    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <label for="message-<?= $user['id'] ?>" class="block text-xs font-medium text-gray-700 mb-1">Pesan untuk <?= htmlspecialchars($user['name']) ?></label>
                        <textarea name="message" id="message-<?= $user['id'] ?>" rows="3" required class="w-full border-gray-300 rounded-md text-sm p-2"></textarea>
                        <button type="submit" name="send_admin_message" class="mt-2 w-full px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Kirim</button>
                    </form>
                </div>
            </details>
            
            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus pengguna ini?');">
                <input type="hidden" name="id" value="<?= $user['id'] ?>">
                <button type="submit" name="delete_user" class="text-red-600 hover:text-red-800 text-sm font-medium flex items-center p-2 rounded-md hover:bg-red-50 transition-colors">
                    <i class="fas fa-trash-alt mr-2"></i> Hapus
                </button>
            </form>
        </div>
    </div>
    <!-- Akhir 1 Kartu Pengguna -->
    <?php
        endwhile;
        $stmt_addr->close(); // Tutup statement alamat
    ?>
</div>

<!-- JavaScript untuk Search (FITUR 1) -->
<script>
function filterUsers() {
    // 1. Dapatkan term pencarian
    let searchTerm = document.getElementById('user-search-input').value.toLowerCase();
    
    // 2. Dapatkan semua card
    let cards = document.querySelectorAll('.user-card');

    // 3. Loop setiap card
    cards.forEach(card => {
        // 4. Ambil teks dari nama, email, dan alamat
        let name = card.querySelector('.user-name').textContent.toLowerCase();
        let email = card.querySelector('.user-email').textContent.toLowerCase();
        let addresses = card.querySelector('.user-addresses').textContent.toLowerCase();
        
        // 5. Gabungkan semua teks yang bisa dicari
        let searchableText = name + " " + email + " " + addresses;
        
        // 6. Tampilkan atau sembunyikan card
        if (searchableText.includes(searchTerm)) {
            card.style.display = 'flex'; // 'flex' karena kita pakai flex-col
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<!-- Style tambahan untuk scrollbar -->
<style>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #c5c5c5;
    border-radius: 10px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}
</style>