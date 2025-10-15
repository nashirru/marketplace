<?php
// File: admin/pesanan/pesanan.php

// Pastikan file ini tidak diakses langsung
if (!defined('BASE_URL')) {
    die('Akses dilarang');
}

// Logika Filter
$status_filter = $_GET['status'] ?? 'semua';
$allowed_statuses = ['semua', 'waiting_approval', 'approved', 'proses_pengemasan', 'dikirim', 'selesai'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua'; // Default jika status tidak valid
}

// Mapping status untuk judul
$status_map = [
    'semua' => 'Semua Pesanan',
    'waiting_approval' => 'Dalam Review',
    'approved' => 'Belum Dicetak',
    'proses_pengemasan' => 'Diproses',
    'dikirim' => 'Dikirim',
    'selesai' => 'Selesai'
];


// Ambil data pesanan berdasarkan filter
$orders = [];
$sql = "SELECT o.*, u.name as user_name FROM orders o JOIN users u ON o.user_id = u.id";
if ($status_filter !== 'semua') {
    $sql .= " WHERE o.status = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $status_filter);
} else {
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
$stmt->close();
?>

<!-- Sub Navigasi Filter -->
<div class="mb-6 flex flex-wrap items-center gap-2 border-b border-gray-200 pb-2">
    <?php foreach ($status_map as $status_key => $status_name): ?>
        <a href="?page=pesanan&status=<?= $status_key ?>" 
           class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors 
                  <?= $status_filter == $status_key 
                      ? 'bg-indigo-600 text-white shadow' 
                      : 'text-gray-600 hover:bg-gray-200' ?>">
            <?= $status_name ?>
        </a>
    <?php endforeach; ?>
</div>

<?= flash_message('update_success'); ?>
<?= flash_message('update_error'); ?>

<div class="bg-white p-4 rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order ID</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
        <?php if(!empty($orders)): ?>
            <?php foreach($orders as $order): ?>
                <tr>
                    <td class="px-4 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#WK<?= $order['id'] ?></td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($order['user_name']) ?></td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($order['total']) ?></td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                            <?php 
                                switch($order['status']) {
                                    case 'selesai': echo 'bg-green-100 text-green-800'; break;
                                    case 'dikirim': echo 'bg-blue-100 text-blue-800'; break;
                                    case 'proses_pengemasan': echo 'bg-yellow-100 text-yellow-800'; break;
                                    case 'approved': echo 'bg-purple-100 text-purple-800'; break;
                                    default: echo 'bg-red-100 text-red-800';
                                }
                            ?>">
                            <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                        </span>
                    </td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm flex items-center gap-2">
                        <!-- Tombol Cetak Resi (Kondisional) -->
                        <?php if ($order['status'] === 'approved'): ?>
                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" class="inline-block">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <input type="hidden" name="current_page" value="<?= $_SERVER['REQUEST_URI'] ?>">
                                <button type="submit" name="cetak_resi" class="px-2 py-1 text-xs bg-green-600 text-white rounded-md hover:bg-green-700">Cetak Resi</button>
                            </form>
                        <?php endif; ?>

                        <!-- Form Update Status -->
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" class="flex items-center space-x-2">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="current_page" value="<?= $_SERVER['REQUEST_URI'] ?>">
                            <select name="status" class="text-xs border-gray-300 rounded-md shadow-sm">
                                <option value="waiting_approval" <?= $order['status'] == 'waiting_approval' ? 'selected' : '' ?>>Review</option>
                                <option value="approved" <?= $order['status'] == 'approved' ? 'selected' : '' ?>>Setujui</option>
                                <option value="proses_pengemasan" <?= $order['status'] == 'proses_pengemasan' ? 'selected' : '' ?>>Kemas</option>
                                <option value="dikirim" <?= $order['status'] == 'dikirim' ? 'selected' : '' ?>>Kirim</option>
                                <option value="selesai" <?= $order['status'] == 'selesai' ? 'selected' : '' ?>>Selesai</option>
                            </select>
                            <button type="submit" name="update_status" class="px-2 py-1 text-xs bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Update</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="5" class="text-center py-8 text-gray-500">Tidak ada pesanan dengan status "<?= htmlspecialchars($status_map[$status_filter]) ?>".</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>