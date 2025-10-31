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

// Pindahkan status_map ke global $status_map di sistem.php jika belum ada
// Untuk saat ini, kita duplikat agar file ini mandiri
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
// Definisikan juga SEMUA status yang valid untuk dropdown modal
$all_valid_statuses = [
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Perlu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];


// --- PERBAIKAN DI SINI ---
// Tambahkan 'waiting_payment' ke daftar status yang punya bulk action
$bulk_action_options = in_array($status_filter, ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped']);
// --- AKHIR PERBAIKAN ---

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

<!-- Modal Konfirmasi Kustom (Lama - untuk Bulk Action & Aksi Cepat) -->
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

<!-- Modal Baru untuk Update Status Fleksibel -->
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
                <textarea id="flexible-modal-cancel-reason" name="cancel_reason" rows="2" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Contoh: Ditolak oleh admin karena..."></textarea>
                <p class="text-xs text-gray-500 mt-1">Jika dibiarkan kosong, akan diisi "Dibatalkan oleh Admin".</p>
            </div>

            <div class="flex justify-end gap-3 mt-6">
                <button id="flexible-modal-btn-cancel" type="button" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md text-sm hover:bg-gray-300">Batal</button>
                <button id="flexible-modal-btn-submit" type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">Simpan Perubahan</button>
            </div>
        </form>
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
        <div class="relative">
            <input type="text" name="search" id="search-input" value="<?= htmlspecialchars($search_query) ?>" placeholder="Cari No. Pesanan, Nama..." class="pl-10 pr-4 py-2 border rounded-lg w-full md:w-80">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
        </div>

        <div class="flex items-center gap-4">
             <div id="dynamic-print-button-container">
                <?php // Konten diisi oleh AJAX ?>
             </div>
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
            <a href="?page=pesanan&status=<?= $key ?>&limit=<?= $limit ?>"
               data-status="<?= $key ?>"
               class="status-tab px-3 py-1.5 text-sm font-medium rounded-md transition-colors cursor-pointer <?= $status_filter == $key ? 'bg-indigo-600 text-white shadow' : 'text-gray-600 hover:bg-gray-200' ?>">
                <?= $value ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Form Aksi Massal -->
    <form method="POST" action="<?= BASE_URL ?>/admin/admin.php" id="bulk-action-form">
        <input type="hidden" name="active_query_string" id="active-query-string" value="<?= http_build_query($_GET) ?>">

        <!-- Konten Aksi Massal diisi oleh AJAX -->
        <div id="bulk-action-container">
             <?php // Konten diisi oleh AJAX ?>
        </div>

        <!-- Tabel Pesanan -->
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
    // ✅ PERBAIKAN: Ambil form-nya
    const bulkActionForm = document.getElementById('bulk-action-form');
    const printButtonContainer = document.getElementById('dynamic-print-button-container');
    const loadingOverlay = document.getElementById('loading-overlay');
    const activeQueryString = document.getElementById('active-query-string');

    // Modal Konfirmasi (Lama)
    const modal = document.getElementById('confirmation-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalBody = document.getElementById('modal-body');
    const modalBtnConfirm = document.getElementById('modal-btn-confirm');
    const modalBtnCancel = document.getElementById('modal-btn-cancel');
    let modalConfirmCallback = null;

    // Elemen Modal Fleksibel
    const flexibleModal = document.getElementById('flexible-update-modal');
    const flexibleModalTitle = document.getElementById('flexible-modal-title');
    const flexibleModalForm = document.getElementById('flexible-update-form');
    const flexibleModalOrderId = document.getElementById('flexible-modal-order-id');
    const flexibleModalStatusSelect = document.getElementById('flexible-modal-new-status');
    const flexibleModalCancelGroup = document.getElementById('flexible-modal-cancel-reason-group');
    const flexibleModalCancelReason = document.getElementById('flexible-modal-cancel-reason');
    const flexibleModalBtnClose = document.getElementById('flexible-modal-btn-close');
    const flexibleModalBtnCancel = document.getElementById('flexible-modal-btn-cancel');
    const flexibleModalQueryString = document.getElementById('flexible-modal-query-string');

    // --- Helper Functions ---
    const showLoading = () => loadingOverlay.classList.remove('hidden');
    const hideLoading = () => loadingOverlay.classList.add('hidden');

    // Modal Lama
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

    // Fungsi Modal Fleksibel
    const showFlexibleModal = (orderId, currentStatus) => {
        flexibleModalOrderId.value = orderId;
        flexibleModalStatusSelect.value = currentStatus; // Set nilai default select
        flexibleModalCancelReason.value = '';
        flexibleModalQueryString.value = activeQueryString.value;

        // Tampilkan/sembunyikan input alasan pembatalan
        if (flexibleModalStatusSelect.value === 'cancelled') {
             flexibleModalCancelGroup.classList.remove('hidden');
        } else {
             flexibleModalCancelGroup.classList.add('hidden');
        }

        flexibleModal.classList.remove('hidden');
    };

    const hideFlexibleModal = () => {
        flexibleModal.classList.add('hidden');
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
            // --- ✅ PERBAIKAN 3: Update colspan jika terjadi error ---
            const currentBulkOptions = ['waiting_payment', 'waiting_approval', 'belum_dicetak', 'processed', 'shipped'].includes(currentState.status);
            // 9 kolom jika ada bulk, 8 jika tidak
            const errorColspan = currentBulkOptions ? 9 : 8; 
            tableBody.innerHTML = `<tr><td colspan="${errorColspan}" class="text-center py-10 text-red-500">Gagal memuat data. Silakan coba lagi.</td></tr>`;
            // --- AKHIR PERBAIKAN 3 ---
        } finally {
            hideLoading();
        }
    };


    // --- TAMBAHAN BARU: Fungsi handleStatusUpdate untuk tombol Aksi Cepat ---
     const handleStatusUpdate = async (orderId, action, actionName) => {
        showModal(
            `Konfirmasi: ${actionName}`,
            `Anda yakin ingin ${actionName.toLowerCase()} pesanan #${orderId} ini?`, // Tampilkan ID order
            `Ya, ${actionName}`,
            async () => {
                hideModal();
                showLoading();

                const formData = new FormData();
                formData.append('order_id', orderId); // Mengirim ID tunggal
                formData.append('action', action); // Mengirim aksi spesifik (approve_payment, dll)
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
                        // Mungkin tambahkan notifikasi sukses kecil di sudut layar
                    } else {
                        // Gagal, tampilkan error
                        alert('Error: ' + result.message); // Ganti dengan modal notifikasi
                    }
                } catch (error) {
                    console.error('Update status cepat error:', error);
                    alert('Terjadi kesalahan saat mengupdate status.');
                } finally {
                    hideLoading();
                }
            }
        );
    };
    // --- AKHIR TAMBAHAN BARU ---

    // Fungsi untuk submit modal fleksibel
    const handleFlexibleSubmit = async (e) => {
        e.preventDefault();
        showLoading();

        const formData = new FormData(flexibleModalForm);

        try {
            const response = await fetch(adminUrl, {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                hideFlexibleModal();
                await fetchOrderData(); // Sukses, refresh tabel
                 // Mungkin tambahkan notifikasi sukses kecil di sudut layar
            } else {
                // Gagal, tampilkan error
                // ✅ PERBAIKAN KESALAHAN SYNTAX DI SINI
                alert('Error: ' + result.message); // Ganti dengan modal notifikasi
            }
        } catch (error) {
            console.error('Flexible update error:', error);
            alert('Terjadi kesalahan saat mengupdate status.');
        } finally {
            hideLoading();
        }
    };


    // --- Event Listeners ---

    // 1. Pencarian (Tidak berubah)
    searchInput.addEventListener('input', () => {
        debounce(() => {
            currentState.search = searchInput.value;
            currentState.page = 1;
            fetchOrderData();
        }, 350);
    });

    // 2. Ganti Limit (Tidak berubah)
    limitSelect.addEventListener('change', () => {
        currentState.limit = limitSelect.value;
        currentState.page = 1;
        fetchOrderData();
    });

    // 3. Ganti Tab Status (Tidak berubah)
    statusTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const newStatus = tab.getAttribute('data-status');
            if (newStatus === currentState.status) return;

            statusTabs.forEach(t => t.classList.remove('bg-indigo-600', 'text-white', 'shadow'));
            statusTabs.forEach(t => t.classList.add('text-gray-600', 'hover:bg-gray-200'));
            tab.classList.add('bg-indigo-600', 'text-white', 'shadow');
            tab.classList.remove('text-gray-600', 'hover:bg-gray-200');

            currentState.status = newStatus;
            currentState.page = 1;
            fetchOrderData();
        });
    });

    // 4. Klik Paginasi (Tidak berubah)
    paginationContainer.addEventListener('click', (e) => {
        e.preventDefault();
        const link = e.target.closest('a');
        if (!link) return;
        const url = new URL(link.href);
        const newPage = url.searchParams.get('p') || 1;
        currentState.page = parseInt(newPage, 10);
        fetchOrderData();
    });

    // 5. --- PERUBAHAN: Klik Tombol di Tabel (Event Delegation) ---
    tableBody.addEventListener('click', (e) => {

        // --- TAMBAHAN BARU: Handle tombol Aksi Cepat (btn-update-status) ---
        const quickActionButton = e.target.closest('button.btn-update-status');
        if (quickActionButton) {
            e.preventDefault();
            const orderId = quickActionButton.getAttribute('data-order-id');
            const action = quickActionButton.getAttribute('data-action');
            const actionName = quickActionButton.getAttribute('data-action-name');
            handleStatusUpdate(orderId, action, actionName); // Panggil fungsi yang lama
        }
        // --- AKHIR TAMBAHAN BARU ---

        // Tombol Ubah Status Fleksibel (btn-flexible-update)
        const flexibleButton = e.target.closest('button.btn-flexible-update');
        if (flexibleButton) {
            e.preventDefault();
            const orderId = flexibleButton.getAttribute('data-order-id');
            const currentStatus = flexibleButton.getAttribute('data-current-status');
            showFlexibleModal(orderId, currentStatus);
        }

        // Handle toggle detail (Lama, masih relevan)
        const detailButton = e.target.closest('button.btn-toggle-detail');
        if(detailButton) {
            const orderId = detailButton.getAttribute('data-order-id');
            // Pastikan elemen detail ada sebelum mencoba toggle
            const detailRow = document.getElementById('details-' + orderId);
            if (detailRow) {
                 detailRow.classList.toggle('hidden');
            }
        }
    });
    // --- AKHIR PERUBAHAN ---

    // 6. Modal Buttons (Lama - untuk Bulk & Aksi Cepat)
    modalBtnConfirm.addEventListener('click', () => {
        if (modalConfirmCallback) {
            modalConfirmCallback();
        }
    });
    modalBtnCancel.addEventListener('click', hideModal);

    // Event Listener Modal Fleksibel
    flexibleModalForm.addEventListener('submit', handleFlexibleSubmit);
    flexibleModalBtnClose.addEventListener('click', hideFlexibleModal);
    flexibleModalBtnCancel.addEventListener('click', hideFlexibleModal);

    // Tampilkan/sembunyikan input alasan saat status di modal diganti
    flexibleModalStatusSelect.addEventListener('change', (e) => {
        if (e.target.value === 'cancelled') {
            flexibleModalCancelGroup.classList.remove('hidden');
        } else {
            flexibleModalCancelGroup.classList.add('hidden');
        }
    });

    
    // ✅ ===============================================================
    // ✅ PERBAIKAN 1: LOGIKA "SELECT ALL"
    // ✅ ===============================================================
    // Kita gunakan event delegation pada tableHead
    tableHead.addEventListener('click', (e) => {
        // Cek apakah yang diklik adalah checkbox "select all"
        if (e.target.id === 'select-all-checkbox') {
            const isChecked = e.target.checked;
            // Temukan semua checkbox di dalam body
            const rowCheckboxes = tableBody.querySelectorAll('input.order-checkbox');
            rowCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
        }
    });

    // ✅ ===============================================================
    // ✅ PERBAIKAN 2: LOGIKA "BULK SUBMIT" VIA AJAX
    // ✅ ===============================================================
    bulkActionForm.addEventListener('submit', async (e) => {
        e.preventDefault(); // Mencegah form submit dan redirect
        
        // Dapatkan action dari tombol yang diklik
        const submitButton = e.submitter;
        if (!submitButton || !submitButton.name || submitButton.name !== 'action') {
            return; // Bukan submit dari tombol aksi
        }
        const action = submitButton.value;
        const actionName = submitButton.textContent.trim();

        // Dapatkan semua order ID yang tercentang
        const checkedBoxes = tableBody.querySelectorAll('input.order-checkbox:checked');
        if (checkedBoxes.length === 0) {
            alert('Silakan pilih setidaknya satu pesanan.');
            return;
        }

        // Tampilkan modal konfirmasi
        showModal(
            `Konfirmasi Aksi Massal: ${actionName}`,
            `Anda yakin ingin ${actionName.toLowerCase()} pada ${checkedBoxes.length} pesanan terpilih?`,
            `Ya, ${actionName}`,
            async () => {
                hideModal();
                showLoading();

                // Buat FormData baru yang bersih
                const formData = new FormData();
                
                // Tambahkan semua ID yang tercentang
                checkedBoxes.forEach(box => {
                    formData.append('selected_orders[]', box.value);
                });
                
                // Tambahkan data lain yang diperlukan
                formData.append('action', action); // Misal: 'cancel_order'
                formData.append('is_ajax', 1);
                formData.append('active_query_string', activeQueryString.value);

                try {
                    const response = await fetch(adminUrl, {
                        method: 'POST',
                        body: formData
                    });
                    
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.statusText}`);
                    }

                    // Coba parse JSON
                    const result = await response.json();

                    if (result.success) {
                        await fetchOrderData(); // Sukses, refresh tabel
                        // Hilangkan centang "select all"
                        const selectAll = document.getElementById('select-all-checkbox');
                        if (selectAll) selectAll.checked = false;
                    } else {
                        alert('Error: ' + (result.message || 'Terjadi kesalahan'));
                    }
                } catch (error) {
                    console.error('Bulk action error:', error);
                    // Tangkap error jika response bukan JSON
                    alert('Terjadi kesalahan saat memproses aksi massal. Pastikan server merespon dengan JSON yang valid.');
                } finally {
                    hideLoading();
                }
            }
        );
    });

    // --- Initial Load ---
    fetchOrderData();
});
</script>