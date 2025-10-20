<?php
// File: admin/pesanan/pesanan.php
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang');
}

// Ambil semua parameter dari URL dengan validasi
$status_filter = $_GET['status'] ?? 'semua';
$search_query = $_GET['search'] ?? '';
$limit = max(1, (int)($_GET['limit'] ?? 10)); // Minimal 1

// PERBAIKAN: Mengganti nama parameter paginasi agar tidak bentrok dengan 'page' utama
$current_page = max(1, (int)($_GET['p'] ?? 1)); // Menggunakan 'p' untuk paginasi

$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

$status_map = [
    'semua' => 'Semua Pesanan', 'waiting_payment' => 'Menunggu Pembayaran', 'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak', 'processed' => 'Diproses', 'shipped' => 'Dikirim',
    'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'
];

// Inisialisasi variabel untuk menghindari error tampilan di order_rows.php
$bulk_action_options = in_array($status_filter, ['waiting_approval', 'processed', 'shipped']);

// Ambil data dari DB menggunakan fungsi baru
$data = get_orders_with_items_by_status($conn, [
    'status' => $status_filter, 'search' => $search_query,
    'limit' => $limit, 'page' => $current_page
]);
$orders = $data['orders'];
$total_records = $data['total'];
$total_pages = max(1, ceil($total_records / $limit)); // Minimal 1 halaman

function get_status_class($status) {
    $classes = [
        'completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800',
        'processed' => 'bg-cyan-100 text-cyan-800', 'belum_dicetak' => 'bg-purple-100 text-purple-800',
        'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}
?>

<div class="bg-white p-6 rounded-lg shadow-md">
    <!-- Header Kontrol -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 mb-4">
        <form method="GET" class="flex-grow md:flex-grow-0">
            <input type="hidden" name="page" value="pesanan">
            <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
            <div class="relative">
                <input type="text" name="search" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari No. Pesanan, Nama..." class="pl-10 pr-4 py-2 border rounded-lg w-full md:w-80">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </div>
        </form>
        <div class="flex items-center gap-4">
             <?php if ($status_filter === 'belum_dicetak' && !empty($orders)): ?>
                <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?status=belum_dicetak" target="_blank" class="px-4 py-2 bg-gray-600 text-white text-sm font-semibold rounded-md hover:bg-gray-700 shadow">
                    <i class="fas fa-print mr-2"></i>Cetak Semua Resi
                </a>
            <?php endif; ?>
            <select onchange="window.location.href=this.value" class="border rounded-lg text-sm p-2">
                <?php foreach ([10, 25, 50] as $l): ?>
                    <option value="?page=pesanan&status=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>&limit=<?= $l ?>" <?= $limit == $l ? 'selected' : '' ?>><?= $l ?>/halaman</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Navigasi Tab Status -->
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <?php foreach ($status_map as $key => $value): ?>
            <a href="?page=pesanan&status=<?= $key ?>&search=<?= urlencode($search_query) ?>&limit=<?= $limit ?>" class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors <?= $status_filter == $key ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-200' ?>">
                <?= $value ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="POST" action="<?= BASE_URL ?>/admin/admin.php">
        <input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>">

        <!-- Tombol Aksi Massal -->
        <?php if ($bulk_action_options): ?>
        <div class="mb-4 p-2 bg-gray-50 rounded-lg flex items-center gap-4">
            <span class="text-sm font-medium text-gray-700">Aksi untuk item terpilih:</span>
            <?php if($status_filter == 'waiting_approval'): ?>
                <button type="submit" name="action" value="approve_payment" class="px-3 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600">Setujui</button>
                <button type="submit" name="action" value="reject_payment" class="px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600">Tolak</button>
            <?php elseif($status_filter == 'processed'): ?>
                <button type="submit" name="action" value="ship_order" class="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600">Kirim Pesanan</button>
            <?php elseif($status_filter == 'shipped'): ?>
                <button type="submit" name="action" value="complete_order" class="px-3 py-1 text-xs bg-purple-500 text-white rounded hover:bg-purple-600">Selesaikan Pesanan</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tabel Pesanan -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <?php if ($bulk_action_options): ?>
                        <th class="px-4 py-3"><input type="checkbox" onclick="toggleAll(this)"></th>
                        <?php endif; ?>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pesanan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pelanggan</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Aksi</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($orders)): foreach ($orders as $order): ?>
                        <tr>
                            <?php if ($bulk_action_options): ?>
                            <td class="px-4 py-4"><input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>" class="order-checkbox"></td>
                            <?php endif; ?>
                            <td class="px-6 py-4"><div class="font-bold text-indigo-600"><?= htmlspecialchars($order['order_number']) ?></div><div class="text-xs text-gray-500"><?= date('d M Y, H:i', strtotime($order['created_at'])) ?></div></td>
                            <td class="px-6 py-4"><div class="text-sm"><?= htmlspecialchars($order['user_name']) ?></div><div class="text-xs text-gray-500"><?= htmlspecialchars($order['phone_number']) ?></div></td>
                            <td class="px-6 py-4 font-medium"><?= format_rupiah($order['total']) ?></td>
                            <td class="px-6 py-4 text-center"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= get_status_class($order['status']) ?>"><?= $status_map[$order['status']] ?></span></td>
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <button type="button" onclick="toggleDetails(<?= $order['id'] ?>)" title="Lihat Detail" class="text-gray-500 hover:text-indigo-600"><i class="fas fa-eye"></i></button>
                                    <?php switch($order['status']): case 'waiting_approval': ?>
                                        <button form="form-approve-<?= $order['id'] ?>" type="submit" title="Setujui" class="text-green-500 hover:text-green-700"><i class="fas fa-check-circle"></i></button>
                                        <button form="form-reject-<?= $order['id'] ?>" type="submit" title="Tolak" class="text-red-500 hover:text-red-700"><i class="fas fa-times-circle"></i></button>
                                    <?php break; case 'belum_dicetak': ?>
                                        <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" title="Cetak Resi" class="text-gray-500 hover:text-black"><i class="fas fa-print"></i></a>
                                        <button form="form-process-<?= $order['id'] ?>" type="submit" title="Proses Pesanan" class="text-cyan-500 hover:text-cyan-700"><i class="fas fa-box"></i></button>
                                    <?php break; case 'processed': ?>
                                        <button form="form-ship-<?= $order['id'] ?>" type="submit" title="Kirim Pesanan" class="text-blue-500 hover:text-blue-700"><i class="fas fa-truck"></i></button>
                                    <?php break; case 'shipped': ?>
                                        <button form="form-complete-<?= $order['id'] ?>" type="submit" title="Selesaikan Pesanan" class="text-purple-500 hover:text-purple-700"><i class="fas fa-check-double"></i></button>
                                    <?php break; endswitch; ?>
                                </div>
                            </td>
                        </tr>
                        <tr id="details-<?= $order['id'] ?>" class="hidden bg-gray-50">
                            <td colspan="<?= $bulk_action_options ? 6 : 5 ?>" class="p-4">
                                <!-- Detail Content -->
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h4 class="font-semibold text-xs mb-2 text-gray-600">ITEM PESANAN:</h4>
                                        <div class="space-y-2">
                                            <?php if (!empty($order['items'])): foreach($order['items'] as $item): ?>
                                                <div class="flex items-center text-xs text-gray-700">
                                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" class="w-8 h-8 rounded object-cover mr-3 border">
                                                    <span class="flex-grow"><?= htmlspecialchars($item['product_name']) ?></span>
                                                    <span class="font-medium">x <?= $item['quantity'] ?></span>
                                                </div>
                                            <?php endforeach; else: ?>
                                                <p class="text-xs text-gray-500">Tidak ada item</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if(!empty($order['payment_proof'])): ?>
                                    <div>
                                        <h4 class="font-semibold text-xs mb-2 text-gray-600">BUKTI PEMBAYARAN:</h4>
                                        <a href="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" target="_blank">
                                            <img src="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" class="h-24 rounded border cursor-pointer">
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                        <tr><td colspan="<?= $bulk_action_options ? 6 : 5 ?>" class="text-center py-10 text-gray-500">Tidak ada pesanan ditemukan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
    
    <!-- Form-form individual untuk setiap aksi -->
    <?php if (!empty($orders)): foreach($orders as $order): ?>
        <form id="form-approve-<?= $order['id'] ?>" method="POST" action="<?= BASE_URL ?>/admin/admin.php"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="action" value="approve_payment"><input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>"></form>
        <form id="form-reject-<?= $order['id'] ?>" method="POST" action="<?= BASE_URL ?>/admin/admin.php"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="action" value="reject_payment"><input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>"></form>
        <form id="form-process-<?= $order['id'] ?>" method="POST" action="<?= BASE_URL ?>/admin/admin.php"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="action" value="process_order"><input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>"></form>
        <form id="form-ship-<?= $order['id'] ?>" method="POST" action="<?= BASE_URL ?>/admin/admin.php"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="action" value="ship_order"><input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>"></form>
        <form id="form-complete-<?= $order['id'] ?>" method="POST" action="<?= BASE_URL ?>/admin/admin.php"><input type="hidden" name="order_id" value="<?= $order['id'] ?>"><input type="hidden" name="action" value="complete_order"><input type="hidden" name="active_query_string" value="<?= http_build_query($_GET) ?>"></form>
    <?php endforeach; endif; ?>

    <!-- Paginasi -->
    <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 mt-4">
        <?php 
            $start_index = ($total_records > 0) ? max(1, ($current_page - 1) * $limit + 1) : 0;
            $end_index = min($current_page * $limit, $total_records);
        ?>
        <p class="text-sm text-gray-700">Menampilkan <?= $start_index ?> - <?= $end_index ?> dari <?= $total_records ?> hasil</p>
        <nav class="inline-flex rounded-md shadow-sm -space-x-px">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <!-- PERBAIKAN: Menggunakan 'p' untuk parameter halaman paginasi -->
                <a href="?page=pesanan&status=<?= $status_filter ?>&search=<?= urlencode($search_query) ?>&limit=<?= $limit ?>&p=<?= $i ?>" class="<?= $i == $current_page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?> relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
        </nav>
    </div>
</div>

<script>
    function toggleDetails(orderId) {
        document.getElementById('details-' + orderId).classList.toggle('hidden');
    }
    function toggleAll(source) {
        const checkboxes = document.querySelectorAll('.order-checkbox');
        for (let i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = source.checked;
        }
    }
</script>