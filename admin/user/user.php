<?php
// File: admin/user/user.php
if (!defined('BASE_URL')) die('Akses dilarang');
?>
<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<div class="bg-white p-4 rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left font-medium">Nama</th>
                <th class="px-4 py-3 text-left font-medium">Email</th>
                <th class="px-4 py-3 text-left font-medium">Role</th>
                <th class="px-4 py-3 text-left font-medium">Aksi</th>
            </tr>
        </thead>
        <tbody>
        <?php
            $result = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
            while($row = $result->fetch_assoc()):
        ?>
            <tr>
                <td class="px-4 py-4"><?= htmlspecialchars($row['name']) ?></td>
                <td class="px-4 py-4"><?= htmlspecialchars($row['email']) ?></td>
                <td class="px-4 py-4">
                    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <select name="role" onchange="this.form.submit()" class="border-gray-300 rounded-md text-sm">
                            <option value="user" <?= $row['role'] == 'user' ? 'selected' : '' ?>>User</option>
                            <option value="admin" <?= $row['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                        <input type="hidden" name="update_user_role" value="1">
                    </form>
                </td>
                <td class="px-4 py-4 flex items-center gap-4">
                    <!-- Tombol Kirim Pesan -->
                    <details class="relative">
                        <summary class="cursor-pointer text-indigo-600 hover:text-indigo-900 text-sm font-medium">Kirim Pesan</summary>
                        <div class="absolute right-0 z-10 mt-2 w-64 bg-white p-4 rounded-lg shadow-lg border">
                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                <label for="message-<?= $row['id'] ?>" class="block text-xs font-medium text-gray-700 mb-1">Pesan untuk <?= htmlspecialchars($row['name']) ?></label>
                                <textarea name="message" id="message-<?= $row['id'] ?>" rows="3" required class="w-full border-gray-300 rounded-md text-sm p-2"></textarea>
                                <button type="submit" name="send_admin_message" class="mt-2 w-full px-3 py-1.5 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-700">Kirim</button>
                            </form>
                        </div>
                    </details>
                    <!-- Tombol Hapus -->
                    <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus pengguna ini?');">
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" name="delete_user" class="text-red-600 hover:text-red-900 text-sm font-medium">Hapus</button>
                    </form>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>