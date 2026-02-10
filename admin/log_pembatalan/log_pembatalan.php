<?php
// File: admin/log_pembatalan/log_pembatalan.php
// Halaman untuk menampilkan log dari tabel admin_cancel_logs

if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang');
}

// Ambil filter awal dari URL
$limit = max(1, (int)($_GET['limit'] ?? 25)); 
$current_page = max(1, (int)($_GET['p'] ?? 1)); 
$search_query = $_GET['search'] ?? '';
$period_filter = $_GET['period'] ?? 'week'; 
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

?>

<!-- Loading Overlay -->
<div id="loading-overlay" class="absolute inset-0 bg-white bg-opacity-75 z-30 flex items-center justify-center hidden">
    <div class="flex items-center gap-2 text-gray-600">
        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>Memuat data log...</span>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow-md relative"> 

    <!-- Header Kontrol Filter -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 mb-4">
        <div class="flex flex-wrap items-center gap-4">
            <div class="relative">
                <input type="text" name="search" id="search-input" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari Order ID, Admin..." class="pl-10 pr-4 py-2 border rounded-lg w-full sm:w-64">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </div>
            <div>
                <select id="period-select" class="border rounded-lg text-sm p-2 shadow-sm">
                    <option value="week" <?= $period_filter == 'week' ? 'selected' : '' ?>>1 Minggu Terakhir</option>
                    <option value="month" <?= $period_filter == 'month' ? 'selected' : '' ?>>1 Bulan Terakhir</option>
                    <option value="all" <?= $period_filter == 'all' ? 'selected' : '' ?>>Semua Waktu</option>
                    <option value="custom" <?= $period_filter == 'custom' ? 'selected' : '' ?>>Tanggal Kustom</option>
                </select>
            </div>
            <div id="custom-date-range-container" class="<?= $period_filter == 'custom' ? '' : 'hidden' ?>">
                 <input type="text" id="custom-date-range" placeholder="Pilih rentang tanggal..." 
                        class="border rounded-lg text-sm p-2 w-64 shadow-sm"
                        value="<?= !empty($start_date_filter) ? $start_date_filter . ' to ' . $end_date_filter : '' ?>">
            </div>
        </div>
        <div class="flex items-center gap-4">
            <select id="limit-select" class="border rounded-lg text-sm p-2 shadow-sm">
                <?php foreach ([10, 25, 50, 100] as $l): ?>
                    <option value="<?= $l ?>" <?= $limit == $l ? 'selected' : '' ?>><?= $l ?>/halaman</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Tabel Log -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50" id="log-table-head">
                <!-- Header diisi oleh AJAX -->
            </thead>
            <tbody class="bg-white divide-y divide-gray-200" id="log-table-body">
                <!-- Konten Baris diisi oleh AJAX -->
            </tbody>
        </table>
    </div>

    <!-- Paginasi -->
    <div class="flex items-center justify-between border-t border-gray-200 px-4 py-3 mt-4">
        <p id="results-count" class="text-sm text-gray-700">Memuat...</p>
        <div id="pagination-container">
            <!-- Konten Paginasi diisi oleh AJAX -->
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // --- State Management ---
    let currentState = {
        search: '<?= $search_query ?>',
        limit: <?= $limit ?>,
        page: <?= $current_page ?>,
        period: '<?= $period_filter ?>',
        startDate: '<?= $start_date_filter ?>',
        endDate: '<?= $end_date_filter ?>'
    };
    
    // URL AJAX baru
    let ajaxUrl = '<?= BASE_URL ?>/admin/log_pembatalan/log_pembatalan_ajax.php'; 
    let debounceTimer;
    let isFetching = false; 

    // --- Elemen DOM ---
    const searchInput = document.getElementById('search-input');
    const limitSelect = document.getElementById('limit-select');
    const tableHead = document.getElementById('log-table-head');
    const tableBody = document.getElementById('log-table-body');
    const paginationContainer = document.getElementById('pagination-container');
    const resultsCount = document.getElementById('results-count');
    const loadingOverlay = document.getElementById('loading-overlay');
    const periodSelect = document.getElementById('period-select');
    const customDateContainer = document.getElementById('custom-date-range-container');
    const customDateInput = document.getElementById('custom-date-range');
    let flatpickrInstance = null; 

    // --- Helper Functions ---
    const showLoading = () => loadingOverlay.classList.remove('hidden');
    const hideLoading = () => loadingOverlay.classList.add('hidden');
    
    const debounce = (func, delay) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(func, delay);
    };

    // --- Fungsi Inti: Fetch Data (GET) ---
    const fetchLogData = async () => {
        if (isFetching) return; 
        isFetching = true;
        showLoading();
        
        const fetchParams = new URLSearchParams({
            q: currentState.search,
            limit: currentState.limit,
            p: currentState.page,
            period: currentState.period,
            start_date: currentState.startDate,
            end_date: currentState.endDate
        });
        const fetchUrl = `${ajaxUrl}?${fetchParams.toString()}`;

        // URL untuk browser
        const params = new URLSearchParams({
            page: 'log_pembatalan', 
            search: currentState.search,
            limit: currentState.limit,
            p: currentState.page,
            period: currentState.period
        });
        if (currentState.period === 'custom') {
            params.append('start_date', currentState.startDate);
            params.append('end_date', currentState.endDate);
        }
        const browserUrl = `?${params.toString()}`;

        try {
            const response = await fetch(fetchUrl);
            if (!response.ok) throw new Error('Network response was not ok');
            const data = await response.json();

            tableHead.innerHTML = data.header;
            tableBody.innerHTML = data.rows;
            paginationContainer.innerHTML = data.pagination;
            resultsCount.textContent = `Menampilkan ${data.start_index} - ${data.end_index} dari ${data.total_results} hasil`;

            window.history.pushState(currentState, '', browserUrl);

        } catch (error) {
            console.error('Fetch error:', error);
            tableBody.innerHTML = `<tr><td colspan="7" class="text-center py-10 text-red-500">Gagal memuat data log. Silakan coba lagi.</td></tr>`;
        } finally {
            isFetching = false;
            hideLoading();
        }
    };

    // Inisialisasi Flatpickr
    flatpickrInstance = flatpickr(customDateInput, {
        mode: "range", dateFormat: "Y-m-d",
        defaultDate: (currentState.startDate && currentState.endDate) ? [currentState.startDate, currentState.endDate] : [],
        onClose: function(selectedDates) {
            if (selectedDates.length === 2) {
                currentState.startDate = selectedDates[0].toISOString().split('T')[0];
                currentState.endDate = selectedDates[1].toISOString().split('T')[0];
                currentState.page = 1;
                fetchLogData();
            }
        }
    });

    // --- Event Listeners (Filter) ---
    
    periodSelect.addEventListener('change', () => {
        currentState.period = periodSelect.value;
        currentState.page = 1;
        if (currentState.period === 'custom') {
            customDateContainer.classList.remove('hidden');
            if (!currentState.startDate || !currentState.endDate) {
                 return;
            }
            fetchLogData();
        } else {
            customDateContainer.classList.add('hidden');
            currentState.startDate = '';
            currentState.endDate = '';
            flatpickrInstance.clear(); 
            fetchLogData();
        }
    });

    searchInput.addEventListener('input', () => {
        debounce(() => {
            currentState.search = searchInput.value;
            currentState.page = 1;
            fetchLogData();
        }, 350);
    });

    limitSelect.addEventListener('change', () => {
        currentState.limit = limitSelect.value;
        currentState.page = 1;
        fetchLogData();
    });

    paginationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        const link = e.target.closest('a');
        if (!link) return;
        const url = new URL(link.href);
        const newPage = url.searchParams.get('p') || 1;
        currentState.page = parseInt(newPage, 10);
        fetchLogData();
    });

    // --- Initial Load ---
    fetchLogData();

});
</script>