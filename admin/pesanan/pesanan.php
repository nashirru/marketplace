<?php
// File: admin/pesanan/pesanan.php
if (!defined('BASE_URL')) die('Akses dilarang');

// Logika Filter
$status_filter = $_GET['status'] ?? 'semua';
// Tambahkan status baru ke dalam array yang diizinkan
$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

// Mapping status baru untuk judul, filter, dan dropdown
$status_map = [
    'semua' => 'Semua Pesanan',
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum di Cetak', // Status baru untuk Admin
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

// Fungsi untuk kelas warna status
function get_admin_status_class($status) {
    switch ($status) {
        case 'completed': return 'bg-green-100 text-green-800';
        case 'shipped': return 'bg-blue-100 text-blue-800';
        case 'processed': return 'bg-purple-100 text-purple-800';
        case 'belum_dicetak': return 'bg-teal-100 text-teal-800'; // Warna baru
        case 'waiting_approval': return 'bg-yellow-100 text-yellow-800';
        case 'waiting_payment': return 'bg-orange-100 text-orange-800';
        case 'cancelled': return 'bg-red-100 text-red-800';
        default: return 'bg-gray-100 text-gray-800';
    }
}

// Ambil data pesanan
$orders = [];
$sql = "SELECT o.*, u.name as user_name FROM orders o JOIN users u ON o.user_id = u.id";
if ($status_filter !== 'semua') {
    $sql .= " WHERE o.status = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $status_filter);
} else {
    $sql .= " ORDER BY o.created_at DESC";
    $stmt = $conn->prepare($sql);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
$stmt->close();
?>

<!-- Kontrol Atas -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4">
    <!-- Sub Navigasi Filter -->
    <div class="flex flex-wrap items-center gap-2">
        <?php foreach ($status_map as $status_key => $status_name): ?>
            <a href="?page=pesanan&status=<?= $status_key ?>" 
               class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors 
                      <?= $status_filter == $status_key ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-200' ?>">
                <?= $status_name ?>
            </a>
        <?php endforeach; ?>
    </div>
    
    <!-- Tombol Cetak Semua Resi (hanya muncul saat filter 'Belum di Cetak') -->
    <?php if ($status_filter === 'belum_dicetak' && !empty($orders)): ?>
    <div>
        <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_all" 
           target="_blank"
           class="px-4 py-2 bg-green-600 text-white text-sm font-semibold rounded-md hover:bg-green-700 shadow">
            Cetak Semua Resi
        </a>
    </div>
    <?php endif; ?>
</div>


<?= flash_message('success'); ?>
<?= flash_message('error'); ?>

<div class="bg-white p-4 rounded-lg shadow-md overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pelanggan</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Bukti Bayar</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Aksi</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
        <?php if(!empty($orders)): ?>
            <?php foreach($orders as $order): ?>
                <tr>
                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-800">#WK<?= $order['id'] ?></a>
                    </td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($order['user_name']) ?></td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($order['total']) ?></td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <?php if (!empty($order['payment_proof'])): ?>
                            <a href="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" target="_blank" class="text-indigo-600 hover:underline">Lihat</a>
                        <?php else: ?>
                            <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= get_admin_status_class($order['status']) ?>">
                            <?= htmlspecialchars($status_map[$order['status']]) ?>
                        </span>
                    </td>
                    <td class="px-4 py-4 whitespace-nowrap text-sm">
                        <div class="flex items-center space-x-2">
                             <!-- Tombol Cetak Resi Individual -->
                            <?php if ($order['status'] == 'belum_dicetak'): ?>
                                <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" class="px-2 py-1 text-xs bg-green-500 text-white rounded-md hover:bg-green-600">Cetak Resi</a>
                            <?php endif; ?>

                            <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <input type="hidden" name="current_page" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                                <select name="status" class="text-xs border-gray-300 rounded-md shadow-sm">
                                    <?php 
                                    // PERBAIKAN: Tampilkan semua status yang relevan di dropdown, termasuk "Belum di Cetak"
                                    $dropdown_statuses = $status_map;
                                    unset($dropdown_statuses['semua']); // Hapus 'semua' karena bukan status valid untuk update
                                    
                                    foreach($dropdown_statuses as $key => $value): 
                                    ?>
                                    <option value="<?= $key ?>" <?= $order['status'] == $key ? 'selected' : '' ?>><?= $value ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" class="px-2 py-1 text-xs bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Update</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" class="text-center py-8 text-gray-500">Tidak ada pesanan dengan status "<?= htmlspecialchars($status_map[$status_filter]) ?>".</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>