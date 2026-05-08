<?php
// File: admin/pesanan/print_button.php
// File ini HANYA berisi div untuk Tombol Cetak Massal
?>
<?php if ($status_filter === 'belum_dicetak' && !empty($orders)): ?>
    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi_process.php?action=print_all_and_process" target="_blank" 
       onclick="return confirm('Anda yakin ingin mencetak semua resi?\nStatus SEMUA pesanan \'Belum Dicetak\' akan diubah menjadi \'Diproses\'.');"
       class="px-4 py-2 bg-gray-600 text-white text-sm font-semibold rounded-md hover:bg-gray-700 shadow">
        <i class="fas fa-print mr-2"></i>Cetak Semua Resi
    </a>
<?php elseif ($status_filter === 'processed' && !empty($orders)): ?>
    <button type="button" id="print-selected-resi-btn"
       class="px-4 py-2 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 shadow">
        <i class="fas fa-print mr-2"></i>Cetak Resi Dipilih
    </button>
    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi_process.php?status=processed" target="_blank" 
       onclick="return confirm('Anda yakin ingin mencetak ulang semua resi \'Diproses\'?\n(Tindakan ini tidak akan mengubah status pesanan)');"
       class="ml-2 px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 shadow">
        <i class="fas fa-print mr-2"></i>Cetak Semua (Ulang)
    </a>
<?php endif; ?>
