<?php
// File: admin/pesanan/order_rows.php
// File ini HANYA berisi loop <tr> untuk AJAX
// Layout ini harus SAMA PERSIS dengan <tbody> di pesanan.php
?>

<?php if (!empty($orders)): foreach ($orders as $order): ?>
    <tr>
        <?php if ($bulk_action_options): ?>
        <td class="px-4 py-4"><input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>" class="order-checkbox"></td>
        <?php endif; ?>
        <td class="px-6 py-4"><div class="font-bold text-indigo-600"><?= htmlspecialchars($order['order_number']) ?></div><div class="text-xs text-gray-500"><?= date('d M Y, H:i', strtotime($order['created_at'])) ?></div></td>
        <td class="px-6 py-4"><div class="text-sm"><?= htmlspecialchars($order['user_name']) ?></div><div class="text-xs text-gray-500"><?= htmlspecialchars($order['phone_number']) ?></div></td>
        <td class="px-6 py-4 font-medium"><?= format_rupiah($order['total']) ?></td>
        <td class="px-6 py-4 text-center"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= get_status_class($order['status']) ?>"><?= $status_map[$order['status']] ?></span></td>
        
        <!-- Kolom Aksi 1 (Detail/Cetak) -->
        <td class="px-6 py-4 text-center align-top">
            <div class="flex items-center justify-center gap-2">
                <!-- Tombol detail SEKARANG punya class btn-toggle-detail -->
                <button type="button" data-order-id="<?= $order['id'] ?>" title="Lihat Detail" class="btn-toggle-detail text-gray-500 hover:text-indigo-600"><i class="fas fa-eye"></i></button>
                
                <?php if($order['status'] == 'belum_dicetak'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_single_and_process&order_id=<?= $order['id'] ?>" 
                       target="_blank" title="Cetak Resi & Proses" 
                       onclick="return confirm('Anda yakin ingin mencetak resi ini?\nStatus pesanan akan diubah menjadi \'Diproses\'.');"
                       class="text-gray-500 hover:text-black"><i class="fas fa-print"></i></a>
                <?php elseif($order['status'] == 'processed'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" title="Cetak Ulang Resi" class="text-gray-500 hover:text-black"><i class="fas fa-print"></i></a>
                <?php endif; ?>
            </div>
        </td>

        <!-- Kolom Aksi 2 (Update Status) -->
        <td class="px-6 py-4 text-center align-top">
            <div class="flex items-center justify-center gap-2"> 
                <?php switch($order['status']): 
                    case 'waiting_approval': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="approve_payment" data-action-name="Setujui" title="Setujui" class="btn-update-status text-green-500 hover:text-green-700"><i class="fas fa-check-circle"></i></button>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="reject_payment" data-action-name="Tolak" title="Tolak" class="btn-update-status text-red-500 hover:text-red-700"><i class="fas fa-times-circle"></i></button>
                    <?php break; case 'belum_dicetak': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="process_order" data-action-name="Proses" title="Proses Pesanan" class="btn-update-status text-cyan-500 hover:text-cyan-700"><i class="fas fa-box"></i></button>
                    <?php break; case 'processed': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="ship_order" data-action-name="Kirim" title="Kirim Pesanan" class="btn-update-status text-blue-500 hover:text-blue-700"><i class="fas fa-truck"></i></button>
                    <?php break; case 'shipped': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="complete_order" data-action-name="Selesaikan" title="Selesaikan Pesanan" class="btn-update-status text-purple-500 hover:text-purple-700"><i class="fas fa-check-double"></i></button>
                    <?php break; 
                    default: ?>
                        <span class="text-gray-400 text-xs">-</span>
                <?php endswitch; ?>
            </div>
        </td>
    </tr>
    <!-- Baris Detail (tetap dikontrol oleh JS di pesanan.php) -->
    <tr id="details-<?= $order['id'] ?>" class="hidden bg-gray-50">
        <td colspan="<?= $bulk_action_options ? 7 : 6 ?>" class="p-4">
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
    <tr><td colspan="<?= $bulk_action_options ? 7 : 6 ?>" class="text-center py-10 text-gray-500">Tidak ada pesanan ditemukan.</td></tr>
<?php endif; ?>