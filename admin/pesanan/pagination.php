<?php
// File: admin/pesanan/pagination.php
// Bagian ini hanya berisi markup untuk navigasi halaman.
// Variabel $current_page, $total_pages, $status_filter, $limit, $search_query
// semua disediakan oleh live_search.php
?>
<nav class="flex justify-center md:justify-end items-center" aria-label="Pagination">
    <?php
    // Tentukan berapa banyak tombol nomor yang akan ditampilkan di sekitar halaman saat ini
    $range = 2;
    $start_index = max(1, $current_page - $range);
    $end_index = min($total_pages, $current_page + $range);

    // Jika total halaman terlalu banyak, sesuaikan rentang agar paginasi terlihat rapi
    if ($end_index - $start_index < (2 * $range) && $total_pages > (2 * $range) + 1) {
        if ($current_page <= $range + 1) {
            $end_index = min($total_pages, (2 * $range) + 1);
        } elseif ($current_page >= $total_pages - $range) {
            $start_index = max(1, $total_pages - (2 * $range));
        }
    }
    ?>

    <div class="flex items-center space-x-1">
        <!-- Tombol Sebelumnya -->
        <?php if ($current_page > 1): ?>
            <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=<?= $current_page - 1 ?>"
               class="px-3 py-1 text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                <i class="fas fa-chevron-left text-xs"></i>
            </a>
        <?php else: ?>
            <span class="px-3 py-1 text-sm font-medium rounded-full text-gray-400 bg-gray-50 border border-gray-200 cursor-not-allowed">
                <i class="fas fa-chevron-left text-xs"></i>
            </span>
        <?php endif; ?>
        
        <!-- Paginasi Awal (Halaman 1) -->
        <?php if ($start_index > 1): ?>
            <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=1"
               class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                1
            </a>
            <?php if ($start_index > 2): ?>
                <span class="text-sm px-2 py-1 text-gray-500">...</span>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Tombol Nomor Halaman -->
        <?php for ($i = $start_index; $i <= $end_index; $i++): ?>
            <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=<?= $i ?>"
               class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full transition-colors 
                      <?= $i == $current_page 
                          ? 'bg-indigo-600 text-white shadow-md border border-indigo-600' 
                          : 'bg-white text-gray-700 border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>

        <!-- Paginasi Akhir (Total Halaman) -->
        <?php if ($end_index < $total_pages): ?>
            <?php if ($end_index < $total_pages - 1): ?>
                <span class="text-sm px-2 py-1 text-gray-500">...</span>
            <?php endif; ?>
            <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=<?= $total_pages ?>"
               class="w-8 h-8 flex items-center justify-center text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                <?= $total_pages ?>
            </a>
        <?php endif; ?>

        <!-- Tombol Selanjutnya -->
        <?php if ($current_page < $total_pages): ?>
            <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=<?= $current_page + 1 ?>"
               class="px-3 py-1 text-sm font-medium rounded-full text-gray-700 bg-white border border-gray-300 hover:bg-indigo-50 hover:text-indigo-600 transition-colors">
                <i class="fas fa-chevron-right text-xs"></i>
            </a>
        <?php else: ?>
            <span class="px-3 py-1 text-sm font-medium rounded-full text-gray-400 bg-gray-50 border border-gray-200 cursor-not-allowed">
                <i class="fas fa-chevron-right text-xs"></i>
            </span>
        <?php endif; ?>
    </div>
</nav>