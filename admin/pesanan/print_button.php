<?php
// File: admin/pesanan/print_button.php
// File ini HANYA berisi div untuk Tombol Cetak Massal
?>
<?php if ($status_filter === 'belum_dicetak' && !empty($orders)): ?>
    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_all_and_process" target="_blank" 
       onclick="return confirm('Anda yakin ingin mencetak semua resi?\nStatus SEMUA pesanan \'Belum Dicetak\' akan diubah menjadi \'Diproses\'.');"
       class="px-4 py-2 bg-gray-600 text-white text-sm font-semibold rounded-md hover:bg-gray-700 shadow">
        <i class="fas fa-print mr-2"></i>Cetak Semua Resi
    </a>
<?php elseif ($status_filter === 'processed' && !empty($orders)): ?>
    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?status=processed" target="_blank" 
       onclick="return confirm('Anda yakin ingin mencetak ulang semua resi \'Diproses\'?\n(Tindakan ini tidak akan mengubah status pesanan)');"
       class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 shadow">
        <i class="fas fa-print mr-2"></i>Cetak Semua Resi (Ulang)
    </a>
<?php endif; ?>