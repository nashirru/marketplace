<?php
// File: admin/pesanan/pesanan.php
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang');
}

// Ambil parameter HANYA untuk status awal. Data akan dimuat oleh AJAX.
$status_filter = $_GET['status'] ?? 'semua';
$search_query = $_GET['search'] ?? '';
$limit = max(1, (int)($_GET['limit'] ?? 10)); // Minimal 1
$current_page = max(1, (int)($_GET['p'] ?? 1)); // Menggunakan 'p' untuk paginasi

$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

$status_map = [
    'semua' => 'Semua Pesanan', 'waiting_payment' => 'Menunggu Pembayaran', 'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak', 'processed' => 'Diproses', 'shipped' => 'Dikirim',
    'completed' => 'Selesai', 'cancelled' => 'Dibatalkan'
];

// Opsi bulk action SAMA seperti sebelumnya
$bulk_action_options = in_array($status_filter, ['waiting_approval', 'processed', 'shipped']);

// Fungsi get_status_class (dipindahkan ke live_search.php jika perlu, tapi biarkan di sini untuk template)
function get_status_class($status) {
    $classes = [
        'completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800',
        'processed' => 'bg-cyan-100 text-cyan-800', 'belum_dicetak' => 'bg-purple-100 text-purple-800',
        'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800',
        'cancelled' => 'bg-red-100 text-red-800',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-800';
}
?>

<!-- Modal Konfirmasi Kustom -->
<div id="confirmation-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 z-40 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-sm">
        <h3 class="text-lg font-medium text-gray-900 mb-4" id="modal-title">Konfirmasi Tindakan</h3>
        <p class="text-sm text-gray-600 mb-6" id="modal-body">Apakah Anda yakin?</p>
        <div class="flex justify-end gap-3">
            <button id="modal-btn-cancel" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md text-sm hover:bg-gray-300">Batal</button>
            <button id="modal-btn-confirm" class="px-4 py-2 bg-red-600 text-white rounded-md text-sm hover:bg-red-700">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="loading-overlay" class="absolute inset-0 bg-white bg-opacity-75 z-30 flex items-center justify-center hidden">
    <div class="flex items-center gap-2 text-gray-600">
        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>Memuat data...</span>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow-md relative"> <!-- Tambahkan relative untuk loading overlay -->
    
    <!-- Header Kontrol -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 mb-4">
        <!-- PERUBAHAN: Form dihilangkan, ID ditambahkan -->
        <div class="relative">
            <input type="text" name="search" id="search-input" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari No. Pesanan, Nama..." class="pl-10 pr-4 py-2 border rounded-lg w-full md:w-80">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        </div>
        
        <div class="flex items-center gap-4">
            <!-- PERUBAHAN: Konten ini akan di-render oleh AJAX -->
             <div id="dynamic-print-button-container">
                <?php if ($status_filter === 'belum_dicetak'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?action=print_all_and_process" target="_blank" 
                       onclick="return confirm('Anda yakin ingin mencetak semua resi?\nStatus SEMUA pesanan \'Belum Dicetak\' akan diubah menjadi \'Diproses\'.');"
                       class="px-4 py-2 bg-gray-600 text-white text-sm font-semibold rounded-md hover:bg-gray-700 shadow">
                        <i class="fas fa-print mr-2"></i>Cetak Semua Resi
                    </a>
                <?php elseif ($status_filter === 'processed'): ?>
                    <a href="<?= BASE_URL ?>/admin/pesanan/cetak_resi.php?status=processed" target="_blank" 
                       onclick="return confirm('Anda yakin ingin mencetak ulang semua resi \'Diproses\'?\n(Tindakan ini tidak akan mengubah status pesanan)');"
                       class="px-4 py-2 bg-blue-600 text-white text-sm font-semibold rounded-md hover:bg-blue-700 shadow">
                        <i class="fas fa-print mr-2"></i>Cetak Semua Resi (Ulang)
                    </a>
                <?php endif; ?>
             </div>
             
            <!-- PERUBAHAN: Select limit, tambahkan ID -->
            <select id="limit-select" class="border rounded-lg text-sm p-2">
                <?php foreach ([10, 25, 50] as $l): ?>
                    <option value="<?= $l ?>" <?= $limit == $l ? 'selected' : '' ?>><?= $l ?>/halaman</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Navigasi Tab Status -->
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <?php foreach ($status_map as $key => $value): ?>
            <!-- PERUBAHAN: Link diganti menjadi data-attribute untuk JS -->
            <a href="?page=pesanan&status=<?= $key ?>&limit=<?= $limit ?>" 
               data-status="<?= $key ?>"
               class="status-tab px-3 py-1.5 text-sm font-medium rounded-md transition-colors cursor-pointer <?= $status_filter == $key ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-200' ?>">
                <?= $value ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- PERUBAHAN: Form Aksi Massal, tambahkan ID -->
    <form method="POST" action="<?= BASE_URL ?>/admin/admin.php" id="bulk-action-form">
        <input type="hidden" name="active_query_string" id="active-query-string" value="<?= http_build_query($_GET) ?>">

        <!-- PERUBAHAN: Konten ini akan di-render oleh AJAX -->
        <div id="bulk-action-container">
            <?php if ($bulk_action_options): ?>
            <div class="mb-4 p-2 bg-gray-50 rounded-lg flex items-center gap-4">
                <span class="text-sm font-medium text-gray-700">Aksi untuk item terpilih:</span>
                <?php if($status_filter == 'waiting_approval'): ?>
                    <button type="submit" name="action" value="approve_payment" class="px-3 py-1 text-xs bg-green-500 text-white rounded hover:bg-green-600">Setujui</button>
                    <button type="submit" name="action" value="reject_payment" class="px-3 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600">Tolak</button>
                <?php elseif($status_filter == 'processed'): ?>
                    <button type="submit" name="action" value="ship_order" class="px-3 py-1 text-xs bg-blue-500 text-white rounded hover:bg-blue-600">Kirim Pesanan</button>
                <?php elseif($status_filter == 'shipped'): ?>
                    <button type="submit" name="action" value="complete_order" class="px-3 py-1 text-xs bg-purple-500 text-white rounded hover:bg-purple-600">Selesaikan Pesanan</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tabel Pesanan -->
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50" id="order-table-head">
                    <!-- Konten Header akan di-render oleh AJAX -->
                </thead>
                <!-- PERUBAHAN: ID ditambahkan, konten PHP dihapus -->
                <tbody class="bg-white divide-y divide-gray-200" id="order-table-body">
                    <!-- Konten Baris akan di-render oleh AJAX -->
                </tbody>
            </table>
        </div>
    </form>
    
    <!-- PERUBAHAN: Form individual dihapus, akan ditangani oleh JS -->

    <!-- Paginasi -->
    <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 mt-4">
        <!-- PERUBAHAN: ID ditambahkan, konten PHP dihapus -->
        <p id="results-count" class="text-sm text-gray-700">Memuat...</p>
        <!-- PERUBAHAN: ID ditambahkan, konten PHP dihapus -->
        <div id="pagination-container">
            <!-- Konten Paginasi akan di-render oleh AJAX -->
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- State Management ---
    let currentState = {
        status: '<?= $status_filter ?>',
        search: '<?= $search_query ?>',
        limit: <?= $limit ?>,
        page: <?= $current_page ?>
    };
    let ajaxUrl = '<?= BASE_URL ?>/admin/pesanan/live_search.php';
    let adminUrl = '<?= BASE_URL ?>/admin/admin.php';
    let baseUrl = '<?= BASE_URL ?>';
    let debounceTimer;

    // --- Elemen DOM ---
    const searchInput = document.getElementById('search-input');
    const limitSelect = document.getElementById('limit-select');
    const tableHead = document.getElementById('order-table-head');
    const tableBody = document.getElementById('order-table-body');
    const paginationContainer = document.getElementById('pagination-container');
    const resultsCount = document.getElementById('results-count');
    const statusTabs = document.querySelectorAll('.status-tab');
    const bulkActionContainer = document.getElementById('bulk-action-container');
    const printButtonContainer = document.getElementById('dynamic-print-button-container');
    const loadingOverlay = document.getElementById('loading-overlay');
    const activeQueryString = document.getElementById('active-query-string');
    
    // Modal
    const modal = document.getElementById('confirmation-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalBody = document.getElementById('modal-body');
    const modalBtnConfirm = document.getElementById('modal-btn-confirm');
    const modalBtnCancel = document.getElementById('modal-btn-cancel');
    let modalConfirmCallback = null;

    // --- Helper Functions ---
    const showLoading = () => loadingOverlay.classList.remove('hidden');
    const hideLoading = () => loadingOverlay.classList.add('hidden');

    const showModal = (title, body, confirmText = 'Ya, Lanjutkan', onConfirm) => {
        modalTitle.textContent = title;
        modalBody.textContent = body;
        modalBtnConfirm.textContent = confirmText;
        modalConfirmCallback = onConfirm;
        modal.classList.remove('hidden');
    };
    
    const hideModal = () => {
        modal.classList.add('hidden');
        modalConfirmCallback = null;
    };

    const debounce = (func, delay) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(func, delay);
    };

    // --- Fungsi Inti: Fetch Data ---
    const fetchOrderData = async () => {
        showLoading();
        
        // 1. Bangun URL
        const params = new URLSearchParams({
            page: 'pesanan', // Untuk URL di browser
            status: currentState.status,
            search: currentState.search,
            limit: currentState.limit,
            p: currentState.page
        });
        const fetchParams = new URLSearchParams({
            status: currentState.status,
            q: currentState.search,
            limit: currentState.limit,
            p: currentState.page
        });
        
        const fetchUrl = `${ajaxUrl}?${fetchParams.toString()}`;
        const browserUrl = `?${params.toString()}`;

        try {
            const response = await fetch(fetchUrl);
            if (!response.ok) throw new Error('Network response was not ok');
            const data = await response.json();

            // 2. Render HTML
            tableHead.innerHTML = data.header;
            tableBody.innerHTML = data.rows;
            paginationContainer.innerHTML = data.pagination;
            resultsCount.textContent = `Menampilkan ${data.start_index} - ${data.end_index} dari ${data.total_results} hasil`;
            bulkActionContainer.innerHTML = data.bulk_actions;
            printButtonContainer.innerHTML = data.print_button;
            
            // 3. Update URL Browser
            window.history.pushState(currentState, '', browserUrl);
            activeQueryString.value = params.toString();

        } catch (error) {
            console.error('Fetch error:', error);
            tableBody.innerHTML = `<tr><td colspan="7" class="text-center py-10 text-red-500">Gagal memuat data. Silakan coba lagi.</td></tr>`;
        } finally {
            hideLoading();
        }
    };

    // --- Fungsi Inti: Update Status ---
    const handleStatusUpdate = async (orderId, action, actionName) => {
        showModal(
            `Konfirmasi: ${actionName}`,
            `Anda yakin ingin ${actionName.toLowerCase()} pesanan ini?`,
            `Ya, ${actionName}`,
            async () => {
                hideModal();
                showLoading();
                
                const formData = new FormData();
                formData.append('order_id', orderId);
                formData.append('action', action);
                formData.append('is_ajax', 1);
                formData.append('active_query_string', activeQueryString.value);

                try {
                    const response = await fetch(adminUrl, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        // Sukses, refresh tabel
                        await fetchOrderData(); 
                    } else {
                        // Gagal, tampilkan error (mungkin di modal baru)
                        alert('Error: ' + result.message); // Ganti dengan modal notifikasi
                    }
                } catch (error) {
                    console.error('Update status error:', error);
                    alert('Terjadi kesalahan saat mengupdate status.');
                } finally {
                    hideLoading();
                }
            }
        );
    };

    // --- Event Listeners ---

    // 1. Pencarian
    searchInput.addEventListener('input', () => {
        debounce(() => {
            currentState.search = searchInput.value;
            currentState.page = 1; // Reset ke halaman 1
            fetchOrderData();
        }, 350); // Delay 350ms
    });

    // 2. Ganti Limit
    limitSelect.addEventListener('change', () => {
        currentState.limit = limitSelect.value;
        currentState.page = 1; // Reset ke halaman 1
        fetchOrderData();
    });

    // 3. Ganti Tab Status
    statusTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const newStatus = tab.getAttribute('data-status');
            if (newStatus === currentState.status) return; // Jangan fetch jika status sama

            // Update UI Tab
            statusTabs.forEach(t => t.classList.remove('bg-indigo-600', 'text-white', 'shadow'));
            statusTabs.forEach(t => t.classList.add('text-gray-600', 'hover:bg-gray-200'));
            tab.classList.add('bg-indigo-600', 'text-white', 'shadow');
            tab.classList.remove('text-gray-600', 'hover:bg-gray-200');

            // Update State
            currentState.status = newStatus;
            currentState.page = 1; // Reset ke halaman 1
            // currentState.search = ''; // Opsional: reset pencarian
            // searchInput.value = ''; 
            
            fetchOrderData();
        });
    });

    // 4. Klik Paginasi (Event Delegation)
    paginationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        const link = e.target.closest('a');
        if (!link) return;

        const url = new URL(link.href);
        const newPage = url.searchParams.get('p') || 1;
        
        currentState.page = parseInt(newPage, 10);
        fetchOrderData();
    });

    // 5. Klik Tombol Update Status (Event Delegation)
    tableBody.addEventListener('click', (e) => {
        const button = e.target.closest('button.btn-update-status');
        if (button) {
            e.preventDefault();
            const orderId = button.getAttribute('data-order-id');
            const action = button.getAttribute('data-action');
            const actionName = button.getAttribute('data-action-name');
            handleStatusUpdate(orderId, action, actionName);
        }
        
        // Handle toggle detail
        const detailButton = e.target.closest('button.btn-toggle-detail');
        if(detailButton) {
            const orderId = detailButton.getAttribute('data-order-id');
            document.getElementById('details-' + orderId).classList.toggle('hidden');
        }
    });
    
    // 6. Modal Buttons
    modalBtnConfirm.addEventListener('click', () => {
        if (modalConfirmCallback) {
            modalConfirmCallback();
        }
    });
    modalBtnCancel.addEventListener('click', hideModal);

    // --- Initial Load ---
    fetchOrderData();
});
</script>