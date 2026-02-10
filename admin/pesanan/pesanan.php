<?php
// File: admin/pesanan/pesanan.php

if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang');
}

$status_filter = $_GET['status'] ?? 'semua';
$search_query = $_GET['search'] ?? '';
// MENGUBAH MAX LIMIT DARI 50 MENJADI 100
$limit = max(1, (int)($_GET['limit'] ?? 10)); 
// Pastikan $limit tidak melebihi batas (misalnya, jika user mengetik 1000 di URL)
$allowed_limits = [10, 25, 50, 100]; 
if (!in_array($limit, $allowed_limits)) {
    // Default ke 10 jika nilai tidak valid, atau nilai terdekat jika perlu
    $limit = 10;
}
$current_page = max(1, (int)($_GET['p'] ?? 1)); 

$period_filter = $_GET['period'] ?? 'week'; 
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

$allowed_statuses = ['semua', 'waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped', 'completed', 'cancelled'];
if (!in_array($status_filter, $allowed_statuses)) {
    $status_filter = 'semua';
}

$status_map = [
    'semua' => 'Semua Pesanan',
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];
$all_valid_statuses = [
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

$bulk_action_options = in_array($status_filter, ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped']);

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

<div id="flexible-update-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 z-40 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-md">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-medium text-gray-900" id="flexible-modal-title">Ubah Status Pesanan</h3>
            <button id="flexible-modal-btn-close" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>

        <form id="flexible-update-form">
            <input type="hidden" id="flexible-modal-order-id" name="order_id">
            <input type="hidden" name="action" value="flexible_update_status">
            <input type="hidden" name="is_ajax" value="1">
            <input type="hidden" id="flexible-modal-query-string" name="active_query_string">

            <div class="mb-4">
                <label for="flexible-modal-new-status" class="block text-sm font-medium text-gray-700 mb-1">Status Baru</label>
                <select id="flexible-modal-new-status" name="new_status" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                    <?php foreach ($all_valid_statuses as $key => $value): ?>
                        <option value="<?= $key ?>"><?= $value ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="flexible-modal-cancel-reason-group" class="mb-4 hidden">
                <label for="flexible-modal-cancel-reason" class="block text-sm font-medium text-gray-700 mb-1">Alasan Pembatalan (Opsional)</label>
                <textarea id="flexible-modal-cancel-reason" name="cancel_reason" rows="2" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Contoh: Stok habis, permintaan user, dll."></textarea>
                <p class="text-xs text-gray-500 mt-1">Jika dibiarkan kosong, akan diisi "Dibatalkan oleh Admin".</p>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <button id="flexible-modal-btn-cancel" type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md text-sm hover:bg-gray-300">Batal</button>
                <button id="flexible-modal-btn-submit" type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal "Checking Status" -->
<div id="checking-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl p-6 w-full max-w-sm text-center">
        <div class="flex justify-center items-center mb-4">
             <svg class="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
        </div>
        <h3 class="text-lg font-medium text-gray-900 mb-2" id="checking-modal-title">Harap Tunggu...</h3>
        <p class="text-sm text-gray-600" id="checking-modal-body">Menghubungi server Midtrans untuk verifikasi status pembayaran...</p>
    </div>
</div>

<!-- ========================================================== -->
<!-- --- MODAL BARU: Custom Alert (Pengganti alert()) --- -->
<!-- ========================================================== -->
<div id="custom-alert-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 z-50 flex items-center justify-center hidden">
    <!-- Box modal, diatur oleh JS untuk transisi -->
    <div id="custom-alert-box" class="bg-white rounded-lg shadow-xl p-6 w-full max-w-lg transform transition-all scale-95 opacity-0">
        <div class="flex items-start">
            <!-- Ikon (diisi oleh JS) -->
            <div id="custom-alert-icon-wrapper" class="flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-100">
                <span id="custom-alert-icon">
                    <!-- SVG icon (success, error, warning) akan dimasukkan di sini oleh JS -->
                </span>
            </div>
            <div class="ml-4 text-left w-full">
                <h3 class="text-lg font-medium text-gray-900" id="custom-alert-title">Judul Alert</h3>
                <div class="mt-2">
                    <!-- Pesan mendukung HTML (untuk \n menjadi <br>) -->
                    <div class="text-sm text-gray-600" id="custom-alert-body" style="max-height: 200px; overflow-y: auto;">Isi pesan alert.</div>
                </div>
            </div>
        </div>
        <div class="mt-5 sm:mt-6">
            <button id="custom-alert-btn-close" type="button" class="inline-flex justify-center w-full rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:text-sm">
                Tutup
            </button>
        </div>
    </div>
</div>
<!-- ========================================================== -->
<!-- --- AKHIR MODAL Custom Alert --- -->
<!-- ========================================================== -->


<!-- Loading Overlay (Lama) -->
<div id="loading-overlay" class="absolute inset-0 bg-white bg-opacity-75 z-30 flex items-center justify-center hidden">
    <div class="flex items-center gap-2 text-gray-600">
        <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span>Memuat data...</span>
    </div>
</div>

<div class="bg-white p-6 rounded-lg shadow-md relative"> 

    <!-- Header Kontrol -->
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 pb-4 mb-4">
        <div class="flex flex-wrap items-center gap-4">
            <div class="relative">
                <input type="text" name="search" id="search-input" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari No. Pesanan, Nama..." class="pl-10 pr-4 py-2 border rounded-lg w-full sm:w-64">
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
             <div id="dynamic-print-button-container">
                <?php // Konten diisi oleh AJAX ?>
             </div>
            <select id="limit-select" class="border rounded-lg text-sm p-2 shadow-sm">
                <?php foreach ([10, 25, 50, 100] as $l): // DITAMBAH OPSI 100 ?>
                    <option value="<?= $l ?>" <?= $limit == $l ? 'selected' : '' ?>><?= $l ?>/halaman</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Navigasi Tab Status -->
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <?php foreach ($status_map as $key => $value): ?>
            <a href="?page=pesanan&status=<?= $key ?>&limit=<?= $limit ?>"
               data-status="<?= $key ?>"
               class="status-tab px-3 py-1.5 text-sm font-medium rounded-md transition-colors cursor-pointer <?= $status_filter == $key ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-200' ?>">
                <?= $value ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Form Aksi Massal -->
    <form method="POST" action="<?= BASE_URL ?>/admin/pesanan/admin_order_actions.php" id="bulk-action-form">
        <input type="hidden" name="active_query_string" id="active-query-string" value="<?= http_build_query($_GET) ?>">
        <div id="bulk-action-container">
             <?php // Konten diisi oleh AJAX ?>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50" id="order-table-head">
                    <!-- Konten Header diisi oleh AJAX -->
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="order-table-body">
                    <!-- Konten Baris diisi oleh AJAX -->
                </tbody>
            </table>
        </div>
    </form>

    <!-- Paginasi -->
    <!-- Flexbox diubah menjadi justify-between untuk menempatkan count di kiri dan paginasi di kanan -->
    <div class="flex flex-wrap items-center justify-between border-t border-gray-200 px-4 py-3 mt-4 gap-4">
        <p id="results-count" class="text-sm text-gray-700">Memuat...</p>
        <div id="pagination-container">
            <!-- Konten Paginasi diisi oleh AJAX -->
        </div>
    </div>
</div>

<script>
// <!--
// ================================================================
// --- JAVASCRIPT "SUPER DEBUGGING" ---
// ================================================================
// -->
document.addEventListener('DOMContentLoaded', function() {

    // --- State Management ---
    let currentState = {
        status: '<?= $status_filter ?>',
        search: '<?= $search_query ?>',
        limit: <?= $limit ?>, // MENGGUNAKAN NILAI BARU (10, 25, 50, 100)
        page: <?= $current_page ?>,
        period: '<?= $period_filter ?>',
        startDate: '<?= $start_date_filter ?>',
        endDate: '<?= $end_date_filter ?>'
    };
    
    let ajaxUrl = '<?= BASE_URL ?>/admin/pesanan/live_search.php';
    let adminActionUrl = '<?= BASE_URL ?>/admin/pesanan/admin_order_actions.php'; 
    let baseUrl = '<?= BASE_URL ?>';
    let debounceTimer;
    let autoRefreshTimer = null; 
    let isFetching = false; 

    // --- Elemen DOM ---
    const searchInput = document.getElementById('search-input');
    const limitSelect = document.getElementById('limit-select');
    const tableHead = document.getElementById('order-table-head');
    const tableBody = document.getElementById('order-table-body');
    const paginationContainer = document.getElementById('pagination-container');
    const resultsCount = document.getElementById('results-count');
    const statusTabs = document.querySelectorAll('.status-tab');
    const bulkActionContainer = document.getElementById('bulk-action-container');
    const bulkActionForm = document.getElementById('bulk-action-form');
    const printButtonContainer = document.getElementById('dynamic-print-button-container');
    const loadingOverlay = document.getElementById('loading-overlay');
    const activeQueryString = document.getElementById('active-query-string');
    const periodSelect = document.getElementById('period-select');
    const customDateContainer = document.getElementById('custom-date-range-container');
    const customDateInput = document.getElementById('custom-date-range');
    let flatpickrInstance = null; 

    // Modal Konfirmasi (Lama)
    const modal = document.getElementById('confirmation-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalBody = document.getElementById('modal-body');
    const modalBtnConfirm = document.getElementById('modal-btn-confirm');
    const modalBtnCancel = document.getElementById('modal-btn-cancel');
    let modalConfirmCallback = null;

    // Elemen Modal Fleksibel
    const flexibleModal = document.getElementById('flexible-update-modal');
    const flexibleModalForm = document.getElementById('flexible-update-form');
    const flexibleModalOrderId = document.getElementById('flexible-modal-order-id');
    const flexibleModalStatusSelect = document.getElementById('flexible-modal-new-status');
    const flexibleModalCancelGroup = document.getElementById('flexible-modal-cancel-reason-group');
    const flexibleModalCancelReason = document.getElementById('flexible-modal-cancel-reason');
    const flexibleModalBtnClose = document.getElementById('flexible-modal-btn-close');
    const flexibleModalBtnCancel = document.getElementById('flexible-modal-btn-cancel');
    const flexibleModalQueryString = document.getElementById('flexible-modal-query-string');

    // Modal "Checking"
    const checkingModal = document.getElementById('checking-modal');
    const checkingModalTitle = document.getElementById('checking-modal-title');
    const checkingModalBody = document.getElementById('checking-modal-body');

    // --- Elemen DOM Custom Alert ---
    const customAlertModal = document.getElementById('custom-alert-modal');
    const customAlertBox = document.getElementById('custom-alert-box');
    const customAlertIconWrapper = document.getElementById('custom-alert-icon-wrapper');
    const customAlertIcon = document.getElementById('custom-alert-icon');
    const customAlertTitle = document.getElementById('custom-alert-title');
    const customAlertBody = document.getElementById('custom-alert-body');
    const customAlertBtnClose = document.getElementById('custom-alert-btn-close');

    // --- Ikon SVG untuk Custom Alert ---
    const successIcon = `<svg class="h-6 w-6 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>`;
    const errorIcon = `<svg class="h-6 w-6 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>`;
    const warningIcon = `<svg class="h-6 w-6 text-yellow-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>`;


    // --- Helper Functions ---
    const showLoading = (isAutoRefresh = false) => {
        if (!isAutoRefresh) {
            loadingOverlay.classList.remove('hidden');
        }
    };
    const hideLoading = () => loadingOverlay.classList.add('hidden');

    const showCheckingModal = (title = 'Harap Tunggu...', body = 'Memverifikasi status pembayaran...') => {
        stopAutoRefresh();
        checkingModalTitle.textContent = title;
        checkingModalBody.textContent = body;
        checkingModal.classList.remove('hidden');
    };
    const hideCheckingModal = () => {
        checkingModal.classList.add('hidden');
    };
    
    const showModal = (title, body, confirmText = 'Ya, Lanjutkan', onConfirm) => {
        stopAutoRefresh();
        modalTitle.textContent = title;
        modalBody.textContent = body;
        modalBtnConfirm.textContent = confirmText;
        modalConfirmCallback = onConfirm;
        modal.classList.remove('hidden');
    };
    const hideModal = () => {
        modal.classList.add('hidden');
        modalConfirmCallback = null;
        startAutoRefresh();
    };

    const showFlexibleModal = (orderId, currentStatus) => {
        stopAutoRefresh(); 
        flexibleModalOrderId.value = orderId;
        flexibleModalStatusSelect.value = currentStatus; 
        flexibleModalCancelReason.value = '';
        flexibleModalQueryString.value = activeQueryString.value;

        if (flexibleModalStatusSelect.value === 'cancelled') {
             flexibleModalCancelGroup.classList.remove('hidden');
        } else {
             flexibleModalCancelGroup.classList.add('hidden');
        }
        flexibleModal.classList.remove('hidden');
    };
    const hideFlexibleModal = () => {
        flexibleModal.classList.add('hidden');
        startAutoRefresh(); 
    };

    // --- Fungsi Custom Alert ---
    const showCustomAlert = (title, body, type = 'success') => {
        stopAutoRefresh();
        
        customAlertTitle.textContent = title;
        // Ganti \n (newline) dengan <br> agar terbaca di HTML
        customAlertBody.innerHTML = body.replace(/\n/g, '<br>');

        // Hapus kelas warna lama
        customAlertIconWrapper.classList.remove('bg-green-100', 'bg-red-100', 'bg-yellow-100');
        customAlertIcon.innerHTML = '';

        if (type === 'success') {
            customAlertIconWrapper.classList.add('bg-green-100');
            customAlertIcon.innerHTML = successIcon;
        } else if (type === 'error') {
            customAlertIconWrapper.classList.add('bg-red-100');
            customAlertIcon.innerHTML = errorIcon;
        } else if (type === 'warning') {
            customAlertIconWrapper.classList.add('bg-yellow-100');
            customAlertIcon.innerHTML = warningIcon;
        }

        customAlertModal.classList.remove('hidden');
        // Trigger transisi
        setTimeout(() => {
            customAlertBox.classList.remove('scale-95', 'opacity-0');
            customAlertBox.classList.add('scale-100', 'opacity-100');
        }, 10);
    };

    const hideCustomAlert = () => {
        customAlertBox.classList.remove('scale-100', 'opacity-100');
        customAlertBox.classList.add('scale-95', 'opacity-0');
        // Tunggu transisi selesai sebelum menyembunyikan
        setTimeout(() => {
            customAlertModal.classList.add('hidden');
            startAutoRefresh(); // Mulai lagi refresh
        }, 200); // Durasi harus cocok dengan transisi CSS (approx)
    };
    
    const debounce = (func, delay) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(func, delay);
    };

    // --- Fungsi Inti: Fetch Data (GET) ---
    const fetchOrderData = async (isAutoRefresh = false) => {
        if (isFetching) return; 
        isFetching = true;
        showLoading(isAutoRefresh);
        
        const fetchParams = new URLSearchParams({
            status: currentState.status,
            q: currentState.search,
            limit: currentState.limit,
            p: currentState.page,
            period: currentState.period,
            start_date: currentState.startDate,
            end_date: currentState.endDate
        });
        const fetchUrl = `${ajaxUrl}?${fetchParams.toString()}`;

        const params = new URLSearchParams({
            page: 'pesanan', 
            status: currentState.status,
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
            bulkActionContainer.innerHTML = data.bulk_actions;
            printButtonContainer.innerHTML = data.print_button;

            if (!isAutoRefresh) {
                window.history.pushState(currentState, '', browserUrl);
            }
            activeQueryString.value = params.toString();

        } catch (error) {
            console.error('Fetch error:', error);
            const currentBulkOptions = ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped'].includes(currentState.status);
            // Hitung colspan: 8 kolom dasar + 1 jika bulk
            const errorColspan = currentBulkOptions ? 9 : 8; 
            tableBody.innerHTML = `<tr><td colspan="${errorColspan}" class="text-center py-10 text-red-500">Gagal memuat data. Silakan coba lagi.</td></tr>`;
            stopAutoRefresh();
        } finally {
            isFetching = false;
            hideLoading();
        }
    };

    // --- Fungsi Auto-Refresh ---
    const startAutoRefresh = () => {
        if (autoRefreshTimer) return; 
        autoRefreshTimer = setInterval(() => {
            fetchOrderData(true);
        }, 30000); 
    };
    const stopAutoRefresh = () => {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    };
    
    // Inisialisasi Flatpickr
    flatpickrInstance = flatpickr(customDateInput, {
        mode: "range", dateFormat: "Y-m-d",
        defaultDate: (currentState.startDate && currentState.endDate) ? [currentState.startDate, currentState.endDate] : [],
        onClose: function(selectedDates) {
            if (selectedDates.length === 2) {
                stopAutoRefresh();
                currentState.startDate = selectedDates[0].toISOString().split('T')[0];
                currentState.endDate = selectedDates[1].toISOString().split('T')[0];
                currentState.page = 1;
                fetchOrderData().then(startAutoRefresh);
            }
        }
    });

    // --- FUNGSI "SUPER DEBUGGING" UNTUK FETCH (POST) ---
    /**
     * @param {string} url
     * @param {FormData} formData
     * @returns {Promise<object>} - Hasil JSON yang sudah di-parse
     * @throws {Error} - Error kustom yang berisi responseBody
     */
    const fetchWithSuperDebugging = async (url, formData) => {
        let responseText = '';
        try {
            const response = await fetch(url, {
                method: 'POST',
                body: formData
            });

            // 1. Ambil respons sebagai TEKS, apapun yang terjadi
            responseText = await response.text();

            if (!response.ok) {
                // Server error (404, 500, dll)
                // Buat error baru, tapi tambahkan responseText
                const error = new Error(`HTTP error! status: ${response.status}`);
                error.responseBody = responseText; // Simpan body Teks
                throw error;
            }

            let jsonData;
            try {
                // 2. Coba parse Teks sebagai JSON
                jsonData = JSON.parse(responseText);
            } catch (e) {
                // Gagal parse (artinya responsnya adalah PHP Warning/Notice, bukan JSON murni)
                const error = new Error('Respons server bukan JSON yang valid.');
                error.responseBody = responseText; // Simpan body Teks
                throw error;
            }

            // 3. Sukses, kembalikan JSON
            return jsonData;

        } catch (networkError) {
            // Ini adalah error network (misal, tidak ada koneksi) atau error yang kita lempar di atas
            if (networkError.responseBody) {
                // Ini adalah error kita, lempar lagi ke atas
                throw networkError;
            }
            // Ini error network sungguhan
            const error = new Error(`Kesalahan Jaringan: ${networkError.message}`);
            error.responseBody = "Tidak dapat terhubung ke server.";
            throw error;
        }
    };
    // --- AKHIR FUNGSI "SUPER DEBUGGING" ---


    // --- Fungsi handleStatusUpdate (Aksi Cepat - POST) ---
    const handleStatusUpdate = async (orderId, action, actionName) => {
        showModal(
            `Konfirmasi: ${actionName}`,
            `Anda yakin ingin ${actionName.toLowerCase()} pesanan #${orderId} ini?`, 
            `Ya, ${actionName}`,
            async () => {
                hideModal();
                
                const isSafeCancelAction = (action === 'reject_payment');
                if (isSafeCancelAction) {
                    showCheckingModal('Memverifikasi Status...', `Mengecek status pesanan #${orderId} di Midtrans sebelum menolak...`);
                } else {
                    showLoading();
                }

                const formData = new FormData();
                formData.append('order_id', orderId); 
                formData.append('action', action); 
                formData.append('is_ajax', 1);
                formData.append('active_query_string', activeQueryString.value);

                try {
                    // [PERBAIKAN] Gunakan fetch "Super Debugging"
                    const result = await fetchWithSuperDebugging(adminActionUrl, formData);

                    showCustomAlert(result.success ? 'Sukses' : 'Gagal', result.message, result.success ? 'success' : 'error');
                    
                    await fetchOrderData();

                } catch (error) {
                    // [PERBAIKAN] Tampilkan error PHP yang asli
                    console.error('Update status cepat error:', error);
                    let responseText = error.responseBody || error.message || 'Error tidak diketahui.';
                    // Bersihkan tag HTML
                    const cleanText = responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    showCustomAlert(
                        'Error Fatal', 
                        `Gagal mengupdate status. Server merespons:<br><br><pre class="text-left text-xs bg-gray-100 p-2 rounded">${cleanText || 'Tidak ada respons error.'}</pre>`, 
                        'error'
                    );
                } finally {
                    if (isSafeCancelAction) {
                        hideCheckingModal();
                    } else {
                        hideLoading();
                    }
                }
            }
        );
    };

    // --- Fungsi submit modal fleksibel (POST) ---
    const handleFlexibleSubmit = async (e) => {
        e.preventDefault();
        
        const formData = new FormData(flexibleModalForm);
        const newStatus = formData.get('new_status');
        const isCancelAction = (newStatus === 'cancelled');
        
        hideFlexibleModal(); 

        if (isCancelAction) {
            showCheckingModal('Memverifikasi Status...', `Mengecek status pesanan di Midtrans sebelum membatalkan...`);
        } else {
            showLoading();
        }

        try {
            // [PERBAIKAN] Gunakan fetch "Super Debugging"
            const result = await fetchWithSuperDebugging(adminActionUrl, formData);

            showCustomAlert(result.success ? 'Sukses' : 'Gagal', result.message, result.success ? 'success' : 'error');
            
            await fetchOrderData();

        } catch (error) {
            // [PERBAIKAN] Tampilkan error PHP yang asli
            console.error('Flexible update error:', error);
            let responseText = error.responseBody || error.message || 'Error tidak diketahui.';
            // Bersihkan tag HTML
            const cleanText = responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
            showCustomAlert(
                'Error Fatal', 
                `Gagal mengupdate status. Server merespons:<br><br><pre class="text-left text-xs bg-gray-100 p-2 rounded">${cleanText || 'Tidak ada respons error.'}</pre>`, 
                'error'
            );
        } finally {
            if (isCancelAction) {
                hideCheckingModal();
            } else {
                hideLoading();
            }
        }
    };


    // --- Event Listeners (Filter) ---
    
    periodSelect.addEventListener('change', () => {
        stopAutoRefresh();
        currentState.period = periodSelect.value;
        currentState.page = 1;
        if (currentState.period === 'custom') {
            customDateContainer.classList.remove('hidden');
            if (!currentState.startDate || !currentState.endDate) {
                 startAutoRefresh(); 
                 return;
            }
            fetchOrderData().then(startAutoRefresh);
        } else {
            customDateContainer.classList.add('hidden');
            currentState.startDate = '';
            currentState.endDate = '';
            flatpickrInstance.clear(); 
            fetchOrderData().then(startAutoRefresh);
        }
    });

    searchInput.addEventListener('input', () => {
        stopAutoRefresh(); 
        debounce(() => {
            currentState.search = searchInput.value;
            currentState.page = 1;
            fetchOrderData().then(startAutoRefresh);
        }, 350);
    });

    limitSelect.addEventListener('change', () => {
        stopAutoRefresh();
        // MEMASTIKAN NILAI LIMIT BARU DIPROSES
        currentState.limit = parseInt(limitSelect.value, 10);
        currentState.page = 1;
        fetchOrderData().then(startAutoRefresh);
    });

    statusTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            stopAutoRefresh();
            const newStatus = tab.getAttribute('data-status');
            if (newStatus === currentState.status) {
                startAutoRefresh(); 
                return;
            }
            statusTabs.forEach(t => t.classList.remove('bg-indigo-600', 'text-white', 'shadow'));
            statusTabs.forEach(t => t.classList.add('text-gray-600', 'hover:bg-gray-200'));
            tab.classList.add('bg-indigo-600', 'text-white', 'shadow');
            tab.classList.remove('text-gray-600', 'hover:bg-gray-200');
            currentState.status = newStatus;
            currentState.page = 1;
            fetchOrderData().then(startAutoRefresh);
        });
    });

    paginationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        const link = e.target.closest('a');
        if (!link) return;
        stopAutoRefresh();
        const url = new URL(link.href);
        const newPage = url.searchParams.get('p') || 1;
        currentState.page = parseInt(newPage, 10);
        fetchOrderData().then(startAutoRefresh);
    });

    // --- Event Listeners (Aksi & Modal) ---

    tableBody.addEventListener('click', (e) => {
        // Aksi Cepat
        const quickActionButton = e.target.closest('button.btn-update-status');
        if (quickActionButton) {
            e.preventDefault();
            const orderId = quickActionButton.getAttribute('data-order-id');
            const action = quickActionButton.getAttribute('data-action');
            const actionName = quickActionButton.getAttribute('data-action-name');
            handleStatusUpdate(orderId, action, actionName); 
        }
        // Modal Fleksibel
        const flexibleButton = e.target.closest('button.btn-flexible-update');
        if (flexibleButton) {
            e.preventDefault();
            const orderId = flexibleButton.getAttribute('data-order-id');
            const currentStatus = flexibleButton.getAttribute('data-current-status');
            showFlexibleModal(orderId, currentStatus);
        }
        // Toggle Detail
        const detailButton = e.target.closest('button.btn-toggle-detail');
        if(detailButton) {
            const orderId = detailButton.getAttribute('data-order-id');
            const detailRow = document.getElementById('details-' + orderId);
            if (detailRow) {
                 detailRow.classList.toggle('hidden');
            }
        }
    });

    // Tombol Modal Konfirmasi
    modalBtnConfirm.addEventListener('click', () => {
        if (modalConfirmCallback) {
            modalConfirmCallback();
        }
    });
    modalBtnCancel.addEventListener('click', hideModal);

    // Tombol Modal Fleksibel
    flexibleModalForm.addEventListener('submit', handleFlexibleSubmit);
    flexibleModalBtnClose.addEventListener('click', hideFlexibleModal);
    flexibleModalBtnCancel.addEventListener('click', hideFlexibleModal);
    flexibleModalStatusSelect.addEventListener('change', (e) => {
        if (e.target.value === 'cancelled') {
            flexibleModalCancelGroup.classList.remove('hidden');
        } else {
            flexibleModalCancelGroup.classList.add('hidden');
        }
    });

    // Event listener untuk Tombol Tutup Custom Alert
    customAlertBtnClose.addEventListener('click', hideCustomAlert);

    // Checkbox Select All
    tableHead.addEventListener('click', (e) => {
        if (e.target.id === 'select-all-checkbox') {
            const isChecked = e.target.checked;
            const rowCheckboxes = tableBody.querySelectorAll('input.order-checkbox');
            rowCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        }
    });


    // --- LOGIKA "BULK SUBMIT" VIA AJAX (POST) ---
    bulkActionForm.addEventListener('submit', async (e) => {
        e.preventDefault(); 
        
        const submitButton = e.submitter;
        if (!submitButton || !submitButton.name || submitButton.name !== 'action') {
            return; 
        }
        const action = submitButton.value;
        const actionName = submitButton.textContent.trim();

        const checkedBoxes = tableBody.querySelectorAll('input.order-checkbox:checked');
        if (checkedBoxes.length === 0) {
            showCustomAlert('Perhatian', 'Silakan pilih setidaknya satu pesanan.', 'warning');
            return;
        }

        showModal(
            `Konfirmasi Aksi Massal: ${actionName}`,
            `Anda yakin ingin ${actionName.toLowerCase()} pada ${checkedBoxes.length} pesanan terpilih?`,
            `Ya, ${actionName}`,
            async () => {
                hideModal();
                
                const isSafeCancelAction = (action === 'cancel_order');
                if (isSafeCancelAction) {
                    showCheckingModal('Memverifikasi Status...', `Mengecek ${checkedBoxes.length} pesanan di Midtrans... Ini mungkin perlu waktu.`);
                } else {
                    showLoading();
                }

                const formData = new FormData(bulkActionForm); 
                formData.set('action', action);
                formData.append('is_ajax', 1);

                try {
                    // [PERBAIKAN] Gunakan fetch "Super Debugging"
                    const result = await fetchWithSuperDebugging(adminActionUrl, formData);
                    
                    showCustomAlert(result.success ? 'Hasil Aksi Massal' : 'Gagal', result.message, result.success ? 'success' : 'error');

                    await fetchOrderData(); 
                    const selectAll = document.getElementById('select-all-checkbox');
                    if (selectAll) selectAll.checked = false;
                        
                } catch (error) {
                    // [PERBAIKAN] Tampilkan error PHP yang asli
                    console.error('Bulk action error:', error);
                    let responseText = error.responseBody || error.message || 'Error tidak diketahui.';
                    // Bersihkan tag HTML
                    const cleanText = responseText.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
                    showCustomAlert(
                        'Error Fatal', 
                        `Gagal memproses aksi massal. Server merespons:<br><br><pre class="text-left text-xs bg-gray-100 p-2 rounded">${cleanText || 'Tidak ada respons error.'}</pre>`, 
                        'error'
                    );
                } finally {
                    if (isSafeCancelAction) {
                        hideCheckingModal();
                    } else {
                        hideLoading();
                    }
                }
            }
        );
    });

    // --- Initial Load ---
    fetchOrderData().then(() => {
        startAutoRefresh(); 
    });

    // --- Jeda refresh saat tab tidak aktif ---
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopAutoRefresh();
        } else {
            fetchOrderData(true).then(startAutoRefresh);
        }
    });
});
</script>