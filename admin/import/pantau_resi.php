<?php
// File: admin/import/pantau_resi.php
// Halaman untuk memantau resi yang sudah di-assign ke orders
// DIPERBARUI: LOGIKA V12 - Menambahkan total biaya ongkir

// Keamanan: Pastikan file ini tidak diakses langsung
if (!defined('IS_ADMIN_PAGE')) {
    die('Akses dilarang!');
}

// --- Logika untuk Menampilkan Data ---

// Filter Pencarian
$search_query = sanitize_input($_GET['q'] ?? '');
$start_date = sanitize_input($_GET['start_date'] ?? ''); 
$end_date = sanitize_input($_GET['end_date'] ?? '');   

// Pagination
$page = (int)($_GET['p'] ?? 1);
if ($page < 1) $page = 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Bangun query WHERE
$where_clauses = [];
$params = [];
$types = "";

// Kondisi WAJIB: Hanya tampilkan order yang punya resi
$where_clauses[] = "(o.tracking_number IS NOT NULL AND o.tracking_number != '')";

// Filter Pencarian
if (!empty($search_query)) {
    $search_term = "%" . $search_query . "%";
    // Cari berdasarkan nama, no hp, atau nomor resi
    $where_clauses[] = "(o.full_name LIKE ? OR o.phone_number LIKE ? OR o.tracking_number LIKE ?)";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= "sss";
}

// Filter Tanggal (berdasarkan tanggal pesanan dibuat)
if (!empty($start_date) && !empty($end_date)) {
    $where_clauses[] = "DATE(o.created_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

$where_sql = " WHERE " . implode(" AND ", $where_clauses);

// =======================================================
// PERUBAHAN LOGIKA QUERY (V12)
// =======================================================

// Query Data: DIGABUNG BERDASARKAN user_id
// DIPERBARUI: Tambahkan SUM(o.shipping_fee_actual)
$sql_data = "SELECT 
                o.user_id, 
                o.full_name, 
                o.phone_number,
                GROUP_CONCAT(DISTINCT o.tracking_number SEPARATOR ',') as all_tracking_numbers,
                MAX(o.created_at) as last_order_date,
                SUM(o.shipping_fee_actual) as total_shipping_cost
             FROM orders o
             $where_sql 
             GROUP BY o.user_id, o.full_name, o.phone_number
             ORDER BY last_order_date DESC 
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
// Ini harus menggunakan subquery untuk menghitung group unik
$sql_count = "SELECT COUNT(*) as total FROM (
                SELECT o.user_id 
                FROM orders o 
                $where_sql 
                GROUP BY o.user_id, o.full_name, o.phone_number
             ) as user_groups";
             
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

    <h2 class="text-2xl font-semibold mb-4 text-gray-800">Pantau Resi Customer (Per Pelanggan)</h2>
    <p class="mb-4 text-sm text-gray-600">Halaman ini menampilkan daftar pelanggan yang sudah memiliki resi, digabung per pelanggan.</p>
    
    <!-- Filter Form -->
    <form method="GET" action="admin.php" class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200">
        <input type="hidden" name="page" value="pantau_resi">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="md:col-span-2">
                <label for="q" class="block text-sm font-medium text-gray-700">Cari (Nama, HP, Resi)</label>
                <input type="text" name="q" id="q" value="<?= htmlspecialchars($search_query) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm sm:text-sm" placeholder="Ketik pencarian...">
            </div>
            <div>
                <label for="start_date" class="block text-sm font-medium text-gray-700">Tgl Pesanan (Mulai)</label>
                <input type="text" name="start_date" id="start_date" value="<?= htmlspecialchars($start_date) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm sm:text-sm" placeholder="Pilih tanggal">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-gray-700">Tgl Pesanan (Selesai)</label>
                <input type="text" name="end_date" id="end_date" value="<?= htmlspecialchars($end_date) ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm sm:text-sm" placeholder="Pilih tanggal">
            </div>
        </div>
        <div class="text-right mt-4">
            <a href="admin.php?page=pantau_resi" class="text-sm text-gray-600 hover:text-gray-800 mr-4">Reset Filter</a>
            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-indigo-600 hover:bg-indigo-700">
                <i class="fas fa-search mr-2"></i> Cari
            </button>
        </div>
    </form>

    <!-- Tabel Data (DIUBAH) -->
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Penerima</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kontak</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Daftar Nomor Resi</th>
                    <!-- BARU: Kolom Biaya Ongkir -->
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Ongkir</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Update Terakhir</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if ($result_data->num_rows > 0): ?>
                    <?php while ($row = $result_data->fetch_assoc()): ?>
                        <tr class="hover:bg-gray-50">
                            
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($row['full_name']) ?></div>
                                <div class="text-xs text-gray-500">User ID: <?= htmlspecialchars($row['user_id']) ?></div>
                            </td>
                            
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?= htmlspecialchars($row['phone_number']) ?></div>
                            </td>
                            
                            <td class="px-6 py-4">
                                <?php
                                // Gabungkan semua resi dari string yang digabung, buat unik
                                $all_resi_strings = $row['all_tracking_numbers'] ?? '';
                                $temp_resi_list = [];
                                $resi_groups = explode(',', $all_resi_strings);
                                foreach ($resi_groups as $resi) {
                                    $cleaned_resi = trim($resi);
                                    if (!empty($cleaned_resi)) {
                                        $temp_resi_list[] = $cleaned_resi;
                                    }
                                }
                                $resis = array_unique($temp_resi_list);
                                $resi_count = count($resis);
                                
                                if ($resi_count > 1) {
                                    echo '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 mb-2">'. $resi_count .' Resi Unik</span>';
                                    echo '<ul class="list-disc list-inside space-y-1" style="max-width: 400px; white-space: normal;">';
                                    foreach ($resis as $resi) {
                                        echo '<li class="text-sm font-semibold text-gray-900 break-all">' . htmlspecialchars($resi) . '</li>';
                                    }
                                    echo '</ul>';
                                } elseif ($resi_count === 1) {
                                    echo '<span class="text-sm font-semibold text-gray-900">' . htmlspecialchars(reset($resis)) . '</span>';
                                } else {
                                    echo '<span class="text-xs text-gray-400 italic">Error: Resi kosong</span>';
                                }
                                ?>
                            </td>
                            
                            <!-- BARU: Menampilkan Total Ongkir -->
                             <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 font-semibold">
                                Rp <?= number_format($row['total_shipping_cost'], 0, ',', '.') ?>
                            </td>

                             <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?= date('d M Y, H:i', strtotime($row['last_order_date'])) ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                            Tidak ada data pelanggan (dengan resi) ditemukan <?= !empty($search_query) ? 'untuk pencarian "' . htmlspecialchars($search_query) . '"' : '' ?>.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination (Logika URL sudah benar) -->
    <?php 
    $base_url = "?page=pantau_resi&q=" . urlencode($search_query) . "&start_date=" . urlencode($start_date) . "&end_date=" . urlencode($end_date);
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

<script>
// Inisialisasi Flatpickr untuk filter tanggal
document.addEventListener('DOMContentLoaded', function() {
    flatpickr("#start_date", {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "d M Y",
    });
    flatpickr("#end_date", {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "d M Y",
    });
});
</script>