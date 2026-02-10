<?php
// File: admin/pesanan/bulk_actions.php
// File ini HANYA berisi div untuk Aksi Massal
?>
<?php if ($bulk_action_options): ?>
<div class="mb-4 p-2 bg-gray-50 rounded-lg flex items-center gap-4 flex-wrap">
    <span class="text-sm font-medium text-gray-700">Aksi untuk item terpilih:</span>
    
    <?php // --- PERBAIKAN BARU ---
    if($status_filter == 'waiting_payment'): ?>
        <button type="submit" name="action" value="cancel_order" class="px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600">Batalkan Pesanan</button>
    
    <?php elseif($status_filter == 'waiting_approval'): ?>
        <button type="submit" name="action" value="approve_payment" class="px-3 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600">Setujui</button>
        <button type="submit" name="action" value="reject_payment" class="px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600">Tolak</button>
    
    <?php elseif($status_filter == 'belum_dicetak'): ?>
        <button type="submit" name="action" value="process_order" class="px-3 py-1 text-xs bg-cyan-500 text-white rounded hover:bg-cyan-600">Proses Pesanan</button>
    
    <?php elseif($status_filter == 'processed'): ?>
        <button type="submit" name="action" value="ship_order" class="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600">Kirim Pesanan</button>
    
    <?php elseif($status_filter == 'shipped'): ?>
        <button type="submit" name="action" value="complete_order" class="px-3 py-1 text-xs bg-purple-500 text-white rounded hover:bg-purple-600">Selesaikan Pesanan</button>
    
    <?php endif; ?>
    <?php // --- AKHIR PERBAIKAN --- ?>
</div>
<?php endif; ?>