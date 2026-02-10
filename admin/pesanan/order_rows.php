<?php
// File: admin/pesanan/order_rows.php
// VERSI CLEAN FINAL IQ 180
// PERBAIKAN: Konsistensi Pengecekan Variabel Variasi

// FIX: Setting zona waktu PHP ke WIB untuk konsistensi
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Jakarta');
}

if (!function_exists('format_indonesian_datetime')) {
    function format_indonesian_datetime($timestamp) {
        try {
            $dateTime = new DateTime($timestamp);
            return $dateTime->format('d M Y, H:i') . ' WIB';
        } catch (Exception $e) {
            return date('d M Y, H:i', strtotime($timestamp));
        }
    }
}

if (!empty($orders)): 
    foreach ($orders as $index => $order): 
    
    // Logika Highlight Duplikat (User Name)
    $highlight_class = '';
    $sortable_statuses = ['belum_dicetak', 'processed', 'shipped'];
    if (in_array($status_filter, $sortable_statuses)) {
        $current_name = strtolower($order['user_name']);
        $prev_name = isset($orders[$index - 1]) ? strtolower($orders[$index - 1]['user_name']) : null;
        $next_name = isset($orders[$index + 1]) ? strtolower($orders[$index + 1]['user_name']) : null;
        if (($current_name === $prev_name) || ($current_name === $next_name)) {
            $highlight_class = 'bg-yellow-50 hover:bg-yellow-100';
        }
    }
?>
    <tr class="<?= $highlight_class ?> hover:bg-gray-50 transition-colors border-b border-gray-100">
        <?php if ($bulk_action_options): ?>
        <td class="px-4 py-4 whitespace-nowrap">
            <input type="checkbox" name="selected_orders[]" value="<?= $order['id'] ?>" class="order-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 cursor-pointer">
        </td>
        <?php endif; ?>
        
        <!-- Info Order -->
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="font-bold text-sm text-indigo-600">
                #<?= htmlspecialchars($order['order_number']) ?>
            </div>
            <div class="text-xs text-gray-500 mt-1">
                <i class="far fa-clock mr-1"></i><?= format_indonesian_datetime($order['created_at']) ?>
            </div>
            <?php if(!empty($order['tracking_number'])): ?>
                <div class="text-xs text-green-600 mt-1 font-mono bg-green-50 px-2 py-0.5 rounded inline-block">
                    Resi: <?= htmlspecialchars($order['tracking_number']) ?>
                </div>
            <?php endif; ?>
        </td>

        <!-- Info Pelanggan -->
        <td class="px-6 py-4 whitespace-nowrap">
            <div class="flex items-center">
                <div class="h-8 w-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-xs mr-3">
                    <?= strtoupper(substr($order['user_name'], 0, 2)) ?>
                </div>
                <div>
                    <div class="text-sm font-medium text-gray-900 <?= ($highlight_class) ? 'font-bold text-yellow-900' : '' ?>">
                        <?= htmlspecialchars($order['user_name']) ?>
                    </div>
                    <div class="text-xs text-gray-500">
                        <i class="fas fa-phone-alt mr-1 text-gray-400"></i><?= htmlspecialchars($order['phone_number']) ?>
                    </div>
                </div>
            </div>
        </td>

        <!-- Total -->
        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
            <?= format_rupiah($order['total']) ?>
        </td>

        <!-- Status -->
        <td class="px-6 py-4 whitespace-nowrap text-center">
            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full shadow-sm <?= get_status_class($order['status']) ?>">
                <?= $status_map[$order['status']] ?? ucfirst($order['status']) ?>
            </span>
            <?php if($order['status'] == 'cancelled' && !empty($order['cancel_reason'])): ?>
                <div class="text-xs text-gray-500 mt-2 max-w-[150px] truncate mx-auto cursor-help" title="Alasan: <?= htmlspecialchars($order['cancel_reason']) ?>">
                    <i class="fas fa-info-circle mr-1 text-red-400"></i>
                    <?= htmlspecialchars($order['cancel_reason']) ?>
                </div>
            <?php endif; ?>
        </td>

        <!-- Aksi Detail & Print -->
        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
            <div class="flex items-center justify-center gap-2">
                <button type="button" data-order-id="<?= $order['id'] ?>" title="Lihat Detail Lengkap" class="btn-toggle-detail text-gray-500 hover:text-indigo-600 bg-white border border-gray-200 hover:border-indigo-300 p-2 rounded-lg shadow-sm transition-all focus:outline-none">
                    <i class="fas fa-eye"></i>
                </button>
                <?php if($order['status'] == 'belum_dicetak'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_single_and_process&order_id=<?= $order['id'] ?>"
                       target="_blank" title="Cetak Resi & Proses"
                       onclick="return confirm('Anda yakin ingin mencetak resi ini?\nStatus pesanan akan diubah menjadi \'Diproses\'.');"
                       class="text-white bg-gray-600 hover:bg-gray-700 p-2 rounded-lg shadow-sm transition-all focus:outline-none">
                       <i class="fas fa-print"></i>
                    </a>
                <?php elseif($order['status'] == 'processed'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?order_id=<?= $order['id'] ?>" target="_blank" title="Cetak Ulang Resi" class="text-blue-600 bg-blue-50 hover:bg-blue-100 p-2 rounded-lg transition-all focus:outline-none">
                        <i class="fas fa-print"></i>
                    </a>
                <?php endif; ?>
            </div>
        </td>

        <!-- Aksi Cepat (Quick Actions) -->
        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
             <div class="flex items-center justify-center gap-2">
                <?php switch($order['status']):
                    case 'waiting_approval': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="approve_payment" data-action-name="Setujui" title="Setujui Pembayaran" class="btn-update-status text-green-600 bg-green-50 hover:bg-green-100 p-1.5 rounded-md"><i class="fas fa-check"></i></button>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="reject_payment" data-action-name="Tolak" title="Tolak Pembayaran" class="btn-update-status text-red-600 bg-red-50 hover:bg-red-100 p-1.5 rounded-md"><i class="fas fa-times"></i></button>
                    <?php break; case 'belum_dicetak': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="process_order" data-action-name="Proses" title="Proses Pesanan (Tanpa Cetak)" class="btn-update-status text-cyan-600 bg-cyan-50 hover:bg-cyan-100 p-1.5 rounded-md"><i class="fas fa-box-open"></i></button>
                    <?php break; case 'processed': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="ship_order" data-action-name="Kirim" title="Kirim Pesanan" class="btn-update-status text-blue-600 bg-blue-50 hover:bg-blue-100 p-1.5 rounded-md"><i class="fas fa-shipping-fast"></i></button>
                    <?php break; case 'shipped': ?>
                        <button type="button" data-order-id="<?= $order['id'] ?>" data-action="complete_order" data-action-name="Selesaikan" title="Selesaikan Pesanan" class="btn-update-status text-purple-600 bg-purple-50 hover:bg-purple-100 p-1.5 rounded-md"><i class="fas fa-check-double"></i></button>
                    <?php break;
                    default: ?>
                        <span class="text-gray-300 text-xs">-</span>
                <?php endswitch; ?>
            </div>
        </td>

        <!-- Flexible Update -->
        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
             <button type="button"
                     data-order-id="<?= $order['id'] ?>"
                     data-current-status="<?= $order['status'] ?>"
                     title="Ubah Status Manual"
                     class="btn-flexible-update text-gray-500 hover:text-indigo-600 focus:outline-none p-1">
                 <i class="fas fa-edit"></i>
             </button>
        </td>
    </tr>

    <!-- ======================================================= -->
    <!-- BARIS DETAIL (HIDDEN) -->
    <!-- ======================================================= -->
    <?php
    $colspan = 8;
    if ($bulk_action_options) {
        $colspan = 9;
    }
    ?>
    <tr id="details-<?= $order['id'] ?>" class="hidden bg-gray-50 shadow-inner">
        <td colspan="<?= $colspan ?>" class="p-6">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <!-- KOLOM 1: ITEM PESANAN (DENGAN VARIASI) -->
                <div class="lg:col-span-1 border-r border-gray-200 pr-6">
                    <h4 class="font-bold text-xs mb-3 text-gray-500 uppercase tracking-wider flex items-center">
                        <i class="fas fa-shopping-bag mr-2"></i> Item Pesanan
                    </h4>
                    <div class="space-y-3">
                        <?php if (!empty($order['items'])): foreach($order['items'] as $item): ?>
                            <div class="flex items-start text-sm text-gray-700 bg-white p-2 rounded border border-gray-200">
                                <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" class="w-10 h-10 rounded object-cover mr-3 border flex-shrink-0 bg-gray-100">
                                <div class="flex-grow">
                                    <div class="font-medium text-gray-800"><?= htmlspecialchars($item['product_name']) ?></div>
                                    
                                    <!-- DISPLAY VARIASI DISINI (Dipastikan Tampil) -->
                                    <?php 
                                    // Pengecekan ekstra untuk variasi (Cek variation_name dan final_variation_name)
                                    $var_name = $item['variation_name'] ?? $item['final_variation_name'] ?? null;
                                    if(!empty($var_name)): 
                                    ?>
                                        <div class="text-xs text-indigo-600 bg-indigo-50 inline-block px-1.5 py-0.5 rounded mt-0.5 border border-indigo-100 font-semibold">
                                            Variasi: <?= htmlspecialchars($var_name) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= format_rupiah($item['price']) ?> x <span class="font-bold"><?= $item['quantity'] ?></span>
                                    </div>
                                </div>
                                <div class="font-bold text-gray-800 ml-2">
                                    <?= format_rupiah($item['price'] * $item['quantity']) ?>
                                </div>
                            </div>
                        <?php endforeach; else: ?>
                            <p class="text-xs text-gray-500 italic">Tidak ada item</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-200 flex justify-between items-center">
                        <span class="text-sm font-bold text-gray-600">Total Belanja:</span>
                        <span class="text-lg font-bold text-indigo-700"><?= format_rupiah($order['total']) ?></span>
                    </div>
                </div>

                <!-- KOLOM 2: ALAMAT PENGIRIMAN -->
                <div class="lg:col-span-1 border-r border-gray-200 pr-6">
                    <h4 class="font-bold text-xs mb-3 text-gray-500 uppercase tracking-wider flex items-center">
                        <i class="fas fa-map-marker-alt mr-2"></i> Alamat Pengiriman
                    </h4>
                    <div class="bg-white p-3 rounded border border-gray-200 text-sm text-gray-700 h-full">
                        <div class="font-bold text-gray-900 mb-1 flex items-center">
                            <?= htmlspecialchars($order['full_name']) ?>
                            <span class="ml-2 text-xs font-normal text-gray-500">(Penerima)</span>
                        </div>
                        <div class="mb-2 text-indigo-600 font-mono text-xs">
                            <i class="fas fa-phone mr-1"></i> <?= htmlspecialchars($order['phone_number']) ?>
                        </div>
                        <hr class="border-gray-100 my-2">
                        <div class="leading-relaxed text-gray-600">
                            <?= htmlspecialchars($order['address_line_1']) ?><br>
                            <?php if(!empty($order['address_line_2'])): ?>
                                <span class="text-gray-500"><?= htmlspecialchars($order['address_line_2']) ?></span><br>
                            <?php endif; ?>
                            <?= htmlspecialchars($order['subdistrict']) ?>, <?= htmlspecialchars($order['city']) ?><br>
                            <?= htmlspecialchars($order['province']) ?> - <strong><?= htmlspecialchars($order['postal_code']) ?></strong>
                        </div>
                    </div>
                </div>

                <!-- KOLOM 3: INFO LAIN (SANGAT BERSIH UNTUK MANUAL) -->
                <div class="lg:col-span-1">
                    <?php 
                    $has_proof = !empty($order['payment_proof']);
                    $is_midtrans = !empty($order['midtrans_payment_type']);
                    
                    // HEADER "INFO PEMBAYARAN" HANYA MUNCUL JIKA ADA DATA
                    if($is_midtrans || $has_proof):
                    ?>
                    <h4 class="font-bold text-xs mb-3 text-gray-500 uppercase tracking-wider flex items-center">
                        <i class="fas fa-receipt mr-2"></i> Info Pembayaran
                    </h4>
                    <?php endif; ?>

                    <!-- BUKTI UPLOAD (Jika Ada) -->
                    <?php if($has_proof): ?>
                        <a href="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" target="_blank" class="block group relative w-full h-40 bg-gray-100 rounded-lg overflow-hidden border border-gray-200 mb-3">
                            <img src="<?= BASE_URL ?>/assets/images/proof/<?= $order['payment_proof'] ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition-all flex items-center justify-center">
                                <span class="text-white opacity-0 group-hover:opacity-100 font-bold text-sm bg-black bg-opacity-50 px-3 py-1 rounded">Lihat Bukti</span>
                            </div>
                        </a>
                    <?php endif; ?>
                    
                    <!-- INFO MIDTRANS (Jika Ada) -->
                    <?php if($is_midtrans): ?>
                    <div class="mt-2 text-xs text-gray-500 space-y-1">
                        <div class="flex justify-between border-b border-gray-100 pb-1">
                            <span class="font-semibold text-gray-600">Metode:</span> 
                            <span><?= strtoupper(str_replace('_', ' ', $order['midtrans_payment_type'])) ?></span>
                        </div>
                        <div class="flex justify-between pt-1">
                            <span class="font-semibold text-gray-600">Midtrans:</span> 
                            <span class="<?= ($order['midtrans_status'] == 'settlement') ? 'text-green-600 font-bold' : 'text-gray-800' ?>">
                                <?= !empty($order['midtrans_status']) ? $order['midtrans_status'] : '-' ?>
                            </span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- JIKA MANUAL DAN TIDAK ADA BUKTI, AREA INI KOSONG (BERSIH) -->
                </div>

            </div>
        </td>
    </tr>
<?php endforeach; else: ?>
     <?php
    $error_colspan = 8; 
    if ($bulk_action_options) {
        $error_colspan = 9; 
    }
    ?>
    <tr><td colspan="<?= $error_colspan ?>" class="text-center py-10 text-gray-500">Tidak ada pesanan ditemukan.</td></tr>
<?php endif; ?>