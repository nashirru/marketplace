<?php
// File: admin/import/match_manual.php
// Halaman BARU untuk menampilkan HANYA resi yang BELUM MATCH
// DIPERBARUI: Menampilkan biaya ongkir

// Keamanan: Pastikan file ini tidak diakses langsung
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang!');
}

// --- Logika untuk Menampilkan Data ---

// Filter Pencarian
$search_query = sanitize_input($_GET['q'] ?? '');

// Pagination
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Bangun query WHERE
$where_clauses = [];
$params = [];
$types = "";

if (!empty($search_query)) {
    $search_term = "%" . $search_query . "%";
    $where_clauses[] = "(i.recipient_name LIKE ? OR i.tracking_number LIKE ? OR i.recipient_address LIKE ?)";
    $params = array_fill(0, 3, $search_term);
    $types = "sss";
}

// LEFT JOIN untuk cek status
$join_sql = " LEFT JOIN orders o ON o.tracking_number LIKE CONCAT('%', i.tracking_number, '%') ";

// KONDISI UTAMA: HANYA TAMPILKAN YANG UNMATCHED
$where_clauses[] = "o.id IS NULL";

$where_sql = " WHERE " . implode(" AND ", $where_clauses);

// Query Data
// DIPERBARUI: Select 'i.shipping_cost'
$sql_data = "SELECT i.id, i.tracking_number, i.recipient_name, i.recipient_address, 
             i.shipment_date, i.imported_at, i.shipping_cost
             FROM imported_shipments i
             $join_sql 
             $where_sql 
             ORDER BY i.imported_at DESC 
             LIMIT ? OFFSET ?";
$types .= "ii";
$params[] = $limit;
$params[] = $offset;

$stmt_data = $conn->prepare($sql_data);
if (!empty($params)) {
    $stmt_data->bind_param($types, ...$params);
}
$stmt_data->execute();
$result_data = $stmt_data->get_result();

// Query Count (untuk pagination)
$sql_count = "SELECT COUNT(i.id) as total 
              FROM imported_shipments i 
              $join_sql 
              $where_sql";
$stmt_count = $conn->prepare($sql_count);
// Hapus params LIMIT & OFFSET
$params_count = array_slice($params, 0, -2);
$types_count = substr($types, 0, -2);
if (!empty($params_count)) {
    $stmt_count->bind_param($types_count, ...$params_count);
}
$stmt_count->execute();
$total_rows = $stmt_count->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

?>

<div class="bg-white shadow-md rounded-lg p-6">

    <h2 class="text-2xl font-semibold mb-4 text-gray-800">Match Manual Resi (Unmatched)</h2>
    <p class="mb-4 text-sm text-gray-600">Halaman ini hanya menampilkan resi yang <b>belum</b> berhasil dicocokkan ke pesanan manapun. Total data unmatched: <b><?= $total_rows ?></b></p>
    
    <!-- Filter Form -->
    <form method="GET" action="admin.php" class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
        <input type="hidden" name="page" value="match_manual">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-3">
                <label for="q" class="block text-sm font-medium text-gray-700">Cari (Nama, Resi, Alamat)</label>
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($search_query) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm sm:text-sm" placeholder="Ketik pencarian...">
            </div>
        </div>
        <div class="text-right mt-4">
            <a href="admin.php?page=match_manual" class="text-sm text-gray-600 hover:text-gray-800 mr-4">Reset Filter</a>
            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                <i class="fas fa-search mr-2"></i> Cari
            </button>
        </div>
    </form>

    <!-- Tabel Data -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Penerima</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No. Resi / Biaya</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tgl Import</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result_data->num_rows > 0): ?>
                    <?php while ($row = $result_data->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($row['recipient_name']) ?></div>
                                <div class="text-xs text-gray-500" style="max-width: 250px; white-space: normal;"><?= htmlspecialchars($row['recipient_address']) ?></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($row['tracking_number']) ?></div>
                                <!-- DIPERBARUI: Tampilkan Biaya -->
                                <div class="text-xs text-indigo-600 font-medium">
                                    Rp <?= number_format($row['shipping_cost'], 0, ',', '.') ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= date('d M Y, H:i', strtotime($row['imported_at'])) ?>
                            </td>
                             <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <button onclick="openManualMatchModal(
                                    '<?= htmlspecialchars($row['tracking_number']) ?>',
                                    '<?= htmlspecialchars(addslashes($row['recipient_name'])) ?>',
                                    '<?= htmlspecialchars(addslashes($row['recipient_address'])) ?>'
                                )" class="text-indigo-600 hover:text-indigo-900">
                                    <i class="fas fa-link mr-1"></i> Match Manual
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                            Luar biasa! Tidak ada resi 'unmatched' ditemukan <?= !empty($search_query) ? 'untuk pencarian "' . htmlspecialchars($search_query) . '"' : '' ?>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php 
    $base_url = "?page=match_manual&q=" . urlencode($search_query);
    if ($total_pages > 1) {
        echo '<nav class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6 mt-4">';
        // ... (Logika pagination lengkap tidak perlu diubah) ...
        echo '<div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">';
        echo '<div><p class="text-sm text-gray-700">Menampilkan <span class="font-medium">'.($offset + 1).'</span> sampai <span class="font-medium">'.min($offset + $limit, $total_rows).'</span> dari <span class="font-medium">'.$total_rows.'</span> hasil</p></div>';
        echo '<div><nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">';
        
        $max_pages_to_show = 5;
        $start_page = max(1, $page - floor($max_pages_to_show / 2));
        $end_page = min($total_pages, $start_page + $max_pages_to_show - 1);
        if ($end_page - $start_page + 1 < $max_pages_to_show) {
            $start_page = max(1, $end_page - $max_pages_to_show + 1);
        }

        if ($page > 1) {
            echo '<a href="'.$base_url.'&p='.($page-1).'" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"><span class="sr-only">Previous</span><i class="fas fa-chevron-left h-5 w-5"></i></a>';
        }
        for ($i = $start_page; $i <= $end_page; $i++) {
            $is_current = ($i == $page);
            echo '<a href="'.$base_url.'&p='.$i.'" aria-current="'.($is_current ? 'page' : 'false').'" class="relative inline-flex items-center px-4 py-2 border '.($is_current ? 'z-10 bg-indigo-50 border-indigo-500 text-indigo-600' : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50').' text-sm font-medium"> '.$i.' </a>';
        }
        if ($page < $total_pages) {
            echo '<a href="'.$base_url.'&p='.($page+1).'" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50"><span class="sr-only">Next</span><i class="fas fa-chevron-right h-5 w-5"></i></a>';
        }
        echo '</nav></div>';
        echo '</div>';
        echo '</nav>';
    }
    ?>

</div>

<!-- Modal Match Manual (Tidak ada perubahan di sini) -->
<div id="manualMatchModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50 hidden" onclick="closeManualMatchModal()">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 p-6" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-800">Match Resi Manual</h3>
            <button onclick="closeManualMatchModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        
        <!-- Info Resi -->
        <div>
            <p class="text-sm text-gray-600">Anda akan memasukkan resi ini:</p>
            <p id="modalResi" class="text-lg font-bold font-mono text-indigo-700 bg-indigo-50 p-2 rounded-md my-2"></p>
            <p class="text-xs text-gray-500">Nama: <span id="modalNama" class="font-medium"></span></p>
            <p class="text-xs text-gray-500">Alamat: <span id="modalAlamat" class="font-medium"></span></p>
        </div>
        
        <hr class="my-4">

        <!-- Form Pencarian -->
        <div class="mb-4">
            <label for="orderSearchInput" class="block text-sm font-medium text-gray-700">Cari Pesanan (No. Order / Nama / HP)</label>
            <div class="flex mt-1">
                <input type="text" id="orderSearchInput" class="flex-grow p-2 border border-gray-300 rounded-l-md focus:ring-indigo-500 focus:border-indigo-500">
                <button id="orderSearchButton" class="bg-indigo-600 text-white px-4 py-2 rounded-r-md hover:bg-indigo-700">
                    <i class="fas fa-search"></i>
                </button>
            </div>
        </div>

        <!-- Hasil Pencarian -->
        <div id="orderSearchResults" class="max-h-64 overflow-y-auto border rounded-md bg-gray-50 p-2">
            <p class="text-center text-sm text-gray-500">Silakan cari pesanan...</p>
        </div>

        <!-- Form Submit Tersembunyi -->
        <form id="manualMatchForm" action="admin.php?page=match_manual" method="POST" class="mt-6 text-right">
            <input type="hidden" name="action" value="manual_match">
            <input type="hidden" id="hiddenResi" name="tracking_number">
            <input type="hidden" id="hiddenOrderId" name="order_id">
            
            <button type="button" onclick="closeManualMatchModal()" class="text-gray-700 bg-gray-200 font-semibold py-2 px-5 rounded-md hover:bg-gray-300 transition mr-2">Batal</button>
            <button id="submitMatchButton" type="submit" class="bg-green-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-green-700 transition shadow-sm disabled:bg-gray-400" disabled>
                <i class="fas fa-link mr-1"></i> Konfirmasi Match
            </button>
        </form>
    </div>
</div>

<script>
// ... (Script JS untuk modal tidak ada perubahan) ...
const modal = document.getElementById('manualMatchModal');
const modalResi = document.getElementById('modalResi');
const modalNama = document.getElementById('modalNama');
const modalAlamat = document.getElementById('modalAlamat');
const searchInput = document.getElementById('orderSearchInput');
const searchButton = document.getElementById('orderSearchButton');
const searchResults = document.getElementById('orderSearchResults');
const hiddenResi = document.getElementById('hiddenResi');
const hiddenOrderId = document.getElementById('hiddenOrderId');
const submitMatchButton = document.getElementById('submitMatchButton');

function openManualMatchModal(resi, nama, alamat) {
    modalResi.textContent = resi;
    modalNama.textContent = nama;
    modalAlamat.textContent = alamat;
    hiddenResi.value = resi;
    hiddenOrderId.value = '';
    submitMatchButton.disabled = true;
    searchResults.innerHTML = '<p class="text-center text-sm text-gray-500">Silakan cari pesanan...</p>';
    modal.classList.remove('hidden');
}

function closeManualMatchModal() {
    modal.classList.add('hidden');
}

searchButton.addEventListener('click', async (e) => {
    e.preventDefault();
    const searchTerm = searchInput.value;
    if (searchTerm.length < 3) {
        searchResults.innerHTML = '<p class="text-center text-sm text-red-500">Ketik minimal 3 karakter</p>';
        return;
    }
    
    searchResults.innerHTML = '<p class="text-center text-sm text-gray-500"><i class="fas fa-spinner fa-spin mr-2"></i>Mencari...</p>';
    
    try {
        const formData = new FormData();
        formData.append('search_term', searchTerm);
        
        const response = await fetch('import/ajax_search_orders.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        
        const orders = await response.json();
        
        if (orders.length > 0) {
            searchResults.innerHTML = ''; // Kosongkan hasil
            orders.forEach(order => {
                const isShipped = order.status === 'shipped';
                const statusColor = isShipped ? 'text-blue-600' : 'text-purple-600';
                const statusText = isShipped ? '(Sudah Dikirim)' : '(Status: ' + order.status + ')';

                const orderDiv = document.createElement('div');
                orderDiv.className = 'p-3 my-1 border bg-white rounded-md cursor-pointer hover:bg-indigo-50 border-gray-300 flex justify-between items-center';
                orderDiv.dataset.orderId = order.id;
                orderDiv.innerHTML = `
                    <div>
                        <p class="font-semibold text-gray-800">${order.order_number} - ${order.full_name}</p>
                        <p class="text-xs text-gray-500">${order.address_line_1}</p>
                        <p class="text-xs ${statusColor} font-medium">${statusText}</p>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                `;
                
                orderDiv.addEventListener('click', () => {
                    document.querySelectorAll('#orderSearchResults div').forEach(el => el.classList.remove('ring-2', 'ring-indigo-500', 'border-indigo-500'));
                    orderDiv.classList.add('ring-2', 'ring-indigo-500', 'border-indigo-500');
                    hiddenOrderId.value = order.id;
                    submitMatchButton.disabled = false;
                });
                
                searchResults.appendChild(orderDiv);
            });
        } else {
            searchResults.innerHTML = '<p class="text-center text-sm text-gray-500">Tidak ada pesanan ditemukan.</p>';
        }
        
    } catch (error) {
        searchResults.innerHTML = '<p class="text-center text-sm text-red-500">Gagal mengambil data. Coba lagi.</p>';
    }
});

modal.addEventListener('click', (e) => {
    if (e.target === modal) {
        closeManualMatchModal();
    }
});
</script>