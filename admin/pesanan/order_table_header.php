<?php
// File: admin/pesanan/order_table_header.php
// File ini HANYA berisi <tr> untuk <thead> agar dinamis
?>
<tr>
    <?php if ($bulk_action_options): ?>
    <th class="px-4 py-3"><input type="checkbox" onclick="toggleAll(this)"></th>
    <?php endif; ?>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pesanan</th>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pelanggan</th>
    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Detail/Cetak</th>
    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Update Status</th>
</tr>