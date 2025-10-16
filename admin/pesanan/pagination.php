<?php
// File: admin/pesanan/pagination.php
// Bagian ini hanya berisi markup untuk navigasi halaman.
// Tujuannya agar bisa dipanggil ulang oleh live_search.php
?>
<nav class="inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=pesanan&status=<?= $status_filter ?>&limit=<?= $limit ?>&q=<?= urlencode($search_query) ?>&p=<?= $i ?>"
           class="relative inline-flex items-center px-4 py-2 border text-sm font-medium 
                  <?= $i == $page ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50' ?>">
            <?= $i ?>
        </a>
    <?php endfor; ?>
</nav>