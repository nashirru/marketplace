<?php
// File: admin/pesanan/order_table_header.php
// File ini HANYA berisi <tr> untuk <thead> agar dinamis
?>
<tr>
    <?php if ($bulk_action_options): ?>
    <!-- ✅ PERBAIKAN: Hapus onclick dan tambahkan ID yang konsisten -->
    <th class="px-4 py-3"><input type="checkbox" id="select-all-checkbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"></th>
    <?php endif; ?>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pesanan</th>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pelanggan</th>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Detail/Cetak</th>
    <!-- --- TAMBAHAN BARU --- -->
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi Cepat</th>
    <!-- --- AKHIR TAMBAHAN BARU --- -->
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Ubah Status</th>
</tr>