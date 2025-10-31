<?php
// File: admin/pesanan/order_rows.php
// File ini HANYA berisi loop <tr> untuk AJAX
// PERBAIKAN: Ditambahkan logika highlight untuk nama duplikat
?>

<?php if (!empty($orders)): 
    // Loop diubah untuk mendapatkan $index
    foreach ($orders as $index => $order): 
    
    // ====================================================================
    // --- LOGIKA HIGHLIGHT DUPLIKAT ---
    // ====================================================================
    $highlight_class = '';
    // Hanya terapkan highlight jika statusnya di-sort berdasarkan nama
    $sortable_statuses = ['belum_dicetak', 'processed', 'shipped'];
    
    if (in_array($status_filter, $sortable_statuses)) {
        // Ambil nama saat ini
        $current_name = strtolower($order['user_name']);
        
        // Cek nama sebelumnya (jika ada)
        $prev_name = isset($orders[$index - 1]) ? strtolower($orders[$index - 1]['user_name']) : null;
        
        // Cek nama sesudahnya (jika ada)
        $next_name = isset($orders[$index + 1]) ? strtolower($orders[$index + 1]['user_name']) : null;
        
        // Jika nama saat ini sama dengan sebelumnya ATAU sesudahnya, beri highlight
        if (($current_name === $prev_name) || ($current_name === $next_name)) {
            // Kita gunakan warna kuning muda yang lembut
            $highlight_class = 'bg-yellow-50 hover:bg-yellow-100';
        }
    }
    // ====================================================================
    // --- AKHIR LOGIKA HIGHLIGHT ---
    // ====================================================================
?>
    <!-- Terapkan highlight class pada <tr> -->
    <tr class="<?= $highlight_class ?>">
        <?php if ($bulk_action_options): ?>
        <td class="px-4 py-4 whitespace-nowrap"><input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>" class="order-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"></td>
        <?php endif; ?>
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="font-medium text-sm text-indigo-600"><?= htmlspecialchars($order['order_number']) ?></div>
            <div class="text-xs text-gray-500"><?= date('d M Y, H:i', strtotime($order['created_at'])) ?></div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap">
            <!-- Terapkan highlight pada nama juga -->
            <div class="text-sm text-gray-900 <?= ($highlight_class) ? 'font-bold text-yellow-900' : '' ?>">
                <?= htmlspecialchars($order['user_name']) ?>
            </div>
            <div class="text-xs text-gray-500"><?= htmlspecialchars($order['phone_number']) ?></div>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= format_rupiah($order['total']) ?></td>
        <td class="px-6 py-4 whitespace-nowrap text-center">
            <span class="px-2.5 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full <?= get_status_class($order['status']) ?>">
                <?= $status_map[$order['status']] ?? ucfirst($order['status']) ?>
            </span>
            <?php if($order['status'] == 'cancelled' && !empty($order['cancel_reason'])): ?>
                <div class="text-xs text-gray-500 mt-1.5" title="Alasan: <?= htmlspecialchars($order['cancel_reason']) ?>">
                    <i class="fas fa-info-circle mr-1 text-gray-400"></i>
                    <?php
                    $reason_short = strlen($order['cancel_reason']) > 35 ? substr($order['cancel_reason'], 0, 35) . '...' : $order['cancel_reason'];
                    echo htmlspecialchars($reason_short);
                    ?>
                </div>
            <?php endif; ?>
        </td>
        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
            <div class="flex items-center justify-center gap-3">
                <button type="button" data-order-id="<?= $order['id'] ?>" title="Lihat Detail" class="btn-toggle-detail text-gray-500 hover:text-indigo-600 focus:outline-none">
                    <i class="fas fa-eye"></i>
                </button>
                <?php if($order['status'] == 'belum_dicetak'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_single_and_process&order_id=<?= $order['id'] ?>"
                       target="_blank" title="Cetak Resi & Proses"
                       onclick="return confirm('Anda yakin ingin mencetak resi ini?\nStatus pesanan akan diubah menjadi \'Diproses\'.');"
                       class="text-gray-500 hover:text-black focus:outline-none"><i class="fas fa-print"></i></a>
                <?php elseif($order['status'] == 'processed'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" title="Cetak Ulang Resi" class="text-gray-500 hover:text-black focus:outline-none"><i class="fas fa-print"></i></a>
                <?php endif; ?>
            </div>
        </td>

        <!-- --- Kolom Aksi Cepat --- -->
        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
             <div class="flex items-center justify-center gap-3">
                <?php switch($order['status']):
                    case 'waiting_approval': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="approve_payment" data-action-name="Setujui" title="Setujui Pembayaran" class="btn-update-status text-green-500 hover:text-green-700 focus:outline-none"><i class="fas fa-check-circle fa-fw"></i></button>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="reject_payment" data-action-name="Tolak" title="Tolak Pembayaran" class="btn-update-status text-red-500 hover:text-red-700 focus:outline-none"><i class="fas fa-times-circle fa-fw"></i></button>
                    <?php break; case 'belum_dicetak': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="process_order" data-action-name="Proses" title="Proses Pesanan" class="btn-update-status text-cyan-500 hover:text-cyan-700 focus:outline-none"><i class="fas fa-box fa-fw"></i></button>
                    <?php break; case 'processed': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="ship_order" data-action-name="Kirim" title="Kirim Pesanan" class="btn-update-status text-blue-500 hover:text-blue-700 focus:outline-none"><i class="fas fa-truck fa-fw"></i></button>
                    <?php break; case 'shipped': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="complete_order" data-action-name="Selesaikan" title="Selesaikan Pesanan" class="btn-update-status text-purple-500 hover:text-purple-700 focus:outline-none"><i class="fas fa-check-double fa-fw"></i></button>
                    <?php break;
                    default: ?>
                        <span class="text-gray-400 text-xs">-</span>
                <?php endswitch; ?>
            </div>
        </td>
        <!-- --- AKHIR Kolom Aksi Cepat --- -->

        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
             <button type="button"
                     data-order-id="<?= $order['id'] ?>"
                     data-current-status="<?= $order['status'] ?>"
                     title="Ubah Status Pesanan"
                     class="btn-flexible-update text-indigo-600 hover:text-indigo-900 focus:outline-none p-1 rounded-md hover:bg-indigo-50">
                 <i class="fas fa-edit fa-fw"></i> Ubah
             </button>
        </td>
    </tr>
    <!-- Baris Detail (Sesuaikan colspan) -->
    <?php
    // Hitung colspan baru: Header punya 8 kolom. Jika bulk, jadi 9.
    $colspan = 8; // Default (tanpa bulk)
    if ($bulk_action_options) {
        $colspan = 9; // Dengan bulk checkbox
    }
    ?>
    <tr id="details-<?= $order['id'] ?>" class="hidden bg-gray-50 <?= $highlight_class ?>">
        <td colspan="<?= $colspan ?>" class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <h4 class="font-semibold text-xs mb-2 text-gray-600 uppercase tracking-wider">Item Pesanan:</h4>
                    <div class="space-y-2">
                        <?php if (!empty($order['items'])): foreach($order['items'] as $item): ?>
                            <div class="flex items-center text-xs text-gray-700">
                                <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" class="w-8 h-8 rounded object-cover mr-3 border flex-shrink-0">
                                <span class="flex-grow mr-2"><?= htmlspecialchars($item['product_name']) ?></span>
                                <span class="font-medium">x <?= $item['quantity'] ?></span>
                            </div>
                        <?php endforeach; else: ?>
                            <p class="text-xs text-gray-500 italic">Tidak ada item</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if(!empty($order['payment_proof'])): ?>
                <div>
                    <h4 class="font-semibold text-xs mb-2 text-gray-600 uppercase tracking-wider">Bukti Pembayaran:</h4>
                    <a href="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" target="_blank" class="block w-24 h-24">
                        <img src="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" class="w-full h-full rounded border object-cover cursor-pointer hover:opacity-80 transition-opacity">
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </td>
    </tr>
<?php endforeach; else: ?>
     <?php
    // Hitung colspan error juga
    $error_colspan = 8; // Default (tanpa bulk)
    if ($bulk_action_options) {
        $error_colspan = 9; // Dengan bulk checkbox
    }
    ?>
    <tr><td colspan="<?= $error_colspan ?>" class="text-center py-10 text-gray-500">Tidak ada pesanan ditemukan.</td></tr>
<?php endif; ?>