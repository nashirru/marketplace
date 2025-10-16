<?php
// File: admin/pesanan/order_rows.php
// Bagian ini hanya berisi loop untuk menampilkan baris-baris tabel pesanan.
// Tujuannya agar bisa dipanggil ulang oleh live_search.php
?>

<?php if(!empty($orders)): ?>
    <?php foreach($orders as $order): ?>
        <tr>
            <?php if (!empty($bulk_action_options)): ?>
            <td class="px-4 py-4">
                <input type="checkbox" name="order_ids[]" value="<?= $order['id'] ?>" class="order-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
            </td>
            <?php endif; ?>
            <td class="px-4 py-4 whitespace-nowrap text-sm">
                <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" target="_blank" class="font-medium text-indigo-600 hover:text-indigo-800">
                   <i class="fas fa-receipt mr-1"></i> <?= htmlspecialchars($order['order_number']) ?>
                </a>
            </td>
            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($order['user_name']) ?></td>
            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($order['phone_number']) ?></td>
            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($order['total']) ?></td>
            <td class="px-4 py-4 whitespace-nowrap text-sm text-center">
                <?php if (!empty($order['payment_proof'])): ?>
                    <a href="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" target="_blank" class="text-indigo-600 hover:underline">
                        <i class="fas fa-eye"></i> Lihat
                    </a>
                <?php else: ?>
                    <span class="text-gray-400">-</span>
                <?php endif; ?>
            </td>
            <td class="px-4 py-4 whitespace-nowrap text-sm text-center">
                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= get_admin_status_class($order['status']) ?>">
                    <?= htmlspecialchars($status_map[$order['status']]) ?>
                </span>
            </td>
            <td class="px-4 py-4 whitespace-nowrap text-sm">
                <div class="flex items-center space-x-3">
                    
                    <?php if ($order['status'] == 'waiting_approval'): ?>
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Anda yakin ingin MENYETUJUI pesanan ini?');">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <button type="submit" name="verify_order" class="text-green-500 hover:text-green-700 transition" title="Setujui Pesanan"><i class="fas fa-check-circle fa-lg"></i></button>
                        </form>
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST" onsubmit="return confirm('Anda yakin ingin MENOLAK pesanan ini?');">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <button type="submit" name="verify_order" class="text-red-500 hover:text-red-700 transition" title="Tolak Pesanan"><i class="fas fa-times-circle fa-lg"></i></button>
                        </form>

                    <?php elseif ($order['status'] == 'belum_dicetak'): ?>
                        <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" class="text-cyan-500 hover:text-cyan-700 transition" title="Cetak Resi"><i class="fas fa-print fa-lg"></i></a>
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <button type="submit" name="update_status_simple" value="processed" class="text-indigo-500 hover:text-indigo-700 transition" title="Proses Pesanan"><i class="fas fa-box-open fa-lg"></i></button>
                        </form>

                    <?php elseif ($order['status'] == 'processed'): ?>
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <button type="submit" name="update_status_simple" value="shipped" class="text-blue-500 hover:text-blue-700 transition" title="Kirim Pesanan"><i class="fas fa-shipping-fast fa-lg"></i></button>
                        </form>

                    <?php elseif ($order['status'] == 'shipped'): ?>
                        <form action="<?= BASE_URL ?>/admin/admin.php" method="POST">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="current_status" value="<?= htmlspecialchars($status_filter) ?>">
                            <button type="submit" name="update_status_simple" value="completed" class="text-green-500 hover:text-green-700 transition" title="Selesaikan Pesanan"><i class="fas fa-user-check fa-lg"></i></button>
                        </form>

                    <?php else: ?>
                        <span class="text-gray-400 text-xs italic">Tidak ada aksi</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="<?= !empty($bulk_action_options) ? '8' : '7' ?>" class="text-center py-8 text-gray-500">Tidak ada pesanan yang ditemukan.</td>
    </tr>
<?php endif; ?>