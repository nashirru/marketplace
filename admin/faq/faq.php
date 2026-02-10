<?php
// File: admin/faq/faq.php
// Halaman ini di-include oleh admin.php, jadi $conn sudah tersedia.

if (!defined('IS_ADMIN_PAGE')) {
    die("Akses langsung tidak diizinkan.");
}

// Cek apakah sedang dalam mode edit atau tambah baru
$action = $_GET['action'] ?? 'list';
$faq_id = (int)($_GET['id'] ?? 0);
$is_editing = ($action === 'edit' && $faq_id > 0);
$is_adding = ($action === 'add');

$question = '';
$answer = '';
$sort_order = 0; // BARU
$is_active = 1;

if ($is_editing) {
    // Ambil data untuk mode edit
    $stmt = $conn->prepare("SELECT * FROM faq WHERE id = ?");
    $stmt->bind_param("i", $faq_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $faq = $result->fetch_assoc();
        $question = $faq['question'];
        $answer = $faq['answer'];
        $sort_order = (int)$faq['sort_order']; // BARU
        $is_active = $faq['is_active'];
    } else {
        // ID tidak ditemukan, kembali ke list
        set_flashdata('error', 'FAQ tidak ditemukan.');
        redirect('/admin/admin.php?page=faq');
    }
    $stmt->close();
}

// Tampilkan form jika 'add' atau 'edit'
if ($is_adding || $is_editing):
?>
    <div class="bg-white p-6 rounded-lg shadow-md max-w-2xl mx-auto">
        <h2 class="text-2xl font-bold mb-6"><?= $is_editing ? 'Edit FAQ' : 'Tambah FAQ Baru' ?></h2>
        
        <!-- Form ini akan di-handle oleh logika di admin.php -->
        <form action="<?= BASE_URL ?>/admin/admin.php?page=faq" method="POST">
            <!-- Hidden ID untuk update -->
            <input type="hidden" name="id" value="<?= $faq_id ?>">

            <div class="mb-4">
                <label for="question" class="block text-sm font-semibold text-gray-700 mb-2">Pertanyaan</label>
                <textarea id="question" name="question" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" required><?= htmlspecialchars($question) ?></textarea>
            </div>

            <div class="mb-4">
                <label for="answer" class="block text-sm font-semibold text-gray-700 mb-2">Jawaban</label>
                <textarea id="answer" name="answer" rows="6" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" required><?= htmlspecialchars($answer) ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Gunakan 'Enter' untuk baris baru. Jawaban akan ditampilkan apa adanya.</p>
            </div>

            <!-- FIELD 'sort_order' BARU -->
            <div class="mb-4">
                <label for="sort_order" class="block text-sm font-semibold text-gray-700 mb-2">Nomor Urut</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= $sort_order ?>" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500" required>
                <p class="text-xs text-gray-500 mt-1">Angka lebih kecil akan tampil lebih dulu (misal: 1, 2, 3, ...).</p>
            </div>

            <div class="mb-6">
                <label class="flex items-center space-x-2">
                    <input type="checkbox" name="is_active" value="1" <?= $is_active ? 'checked' : '' ?> class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    <span class="text-sm font-medium text-gray-700">Aktif (Tampilkan di halaman bantuan)</span>
                </label>
            </div>

            <div class="flex items-center justify-end space-x-3">
                <a href="?page=faq" class="py-2 px-4 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition">Batal</a>
                <button type="submit" name="save_faq" class="py-2 px-5 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700 transition">
                    <?= $is_editing ? 'Update FAQ' : 'Simpan FAQ' ?>
                </button>
            </div>
        </form>
    </div>

<?php
// Tampilkan tabel jika mode 'list' (default)
else:
?>
    <div class="mb-6 flex justify-end">
        <a href="?page=faq&action=add" class="py-2 px-5 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700 transition">
            <i class="fas fa-plus mr-2"></i> Tambah FAQ Baru
        </a>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md overflow-x-auto">
        <h2 class="text-2xl font-bold mb-6">Daftar FAQ</h2>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <!-- KOLOM 'Nomor Urut' BARU -->
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Urutan</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pertanyaan</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                // Diurutkan berdasarkan 'sort_order' BARU
                $result = $conn->query("SELECT * FROM faq ORDER BY sort_order ASC, id ASC");
                if ($result->num_rows > 0):
                    while ($faq = $result->fetch_assoc()):
                ?>
                        <tr>
                            <!-- DATA 'sort_order' BARU -->
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-sm font-medium text-gray-900"><?= (int)$faq['sort_order'] ?></span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars(substr($faq['question'], 0, 80)) . (strlen($faq['question']) > 80 ? '...' : '') ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php if ($faq['is_active']): ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Aktif</span>
                                <?php else: ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Nonaktif</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                <a href="?page=faq&action=edit&id=<?= $faq['id'] ?>" class="text-indigo-600 hover:text-indigo-900" title="Edit">
                                    <i class="fas fa-pencil-alt"></i>
                                </a>
                                <!-- Tombol Hapus pakai form -->
                                <form action="<?= BASE_URL ?>/admin/admin.php?page=faq" method="POST" class="inline-block" onsubmit="return confirm('Apakah Anda yakin ingin menghapus FAQ ini?');">
                                    <input type="hidden" name="id" value="<?= $faq['id'] ?>">
                                    <button type="submit" name="delete_faq" class="text-red-600 hover:text-red-900" title="Hapus">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php
                    endwhile;
                else:
                ?>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                            Belum ada FAQ yang ditambahkan.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php
endif; // Selesai
?>