<?php
// File: admin/dashboard.php

if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// --- PENGAMBILAN DATA STATISTIK ---
$status_pendapatan_list = "('belum_dicetak', 'processed', 'shipped', 'completed')";

// --- 1. Statistik Utama (Box Atas) ---
$total_pesanan_all = $conn->query("SELECT COUNT(id) as total FROM orders")->fetch_assoc()['total'];
$pesanan_baru = $conn->query("SELECT COUNT(id) as total FROM orders WHERE status = 'belum_dicetak' OR status = 'waiting_approval'")->fetch_assoc()['total'];
$pendapatan_total_all = $conn->query("SELECT SUM(total) as total FROM orders WHERE status IN $status_pendapatan_list")->fetch_assoc()['total'];
$total_user = $conn->query("SELECT COUNT(id) as total FROM users WHERE role = 'user'")->fetch_assoc()['total'];

// --- 2. Ringkasan Status Pesanan ---
$status_counts_query = $conn->query("SELECT status, COUNT(id) as count FROM orders GROUP BY status");
$status_counts = [];
while ($row = $status_counts_query->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
}
// Definisikan detail status (termasuk warna Tailwind)
$status_map = [
    'waiting_payment' => ['label' => 'Menunggu Pembayaran', 'icon' => 'fa-clock', 'color' => 'orange', 'text' => 'text-orange-600', 'bg' => 'bg-orange-100'],
    'waiting_approval' => ['label' => 'Perlu Verifikasi', 'icon' => 'fa-exclamation-circle', 'color' => 'yellow', 'text' => 'text-yellow-600', 'bg' => 'bg-yellow-100'],
    'belum_dicetak' => ['label' => 'Belum Dicetak', 'icon' => 'fa-print', 'color' => 'purple', 'text' => 'text-purple-600', 'bg' => 'bg-purple-100'],
    'processed' => ['label' => 'Diproses', 'icon' => 'fa-box', 'color' => 'cyan', 'text' => 'text-cyan-600', 'bg' => 'bg-cyan-100'],
    'shipped' => ['label' => 'Dikirim', 'icon' => 'fa-truck', 'color' => 'blue', 'text' => 'text-blue-600', 'bg' => 'bg-blue-100'],
    'completed' => ['label' => 'Selesai', 'icon' => 'fa-check-circle', 'color' => 'green', 'text' => 'text-green-600', 'bg' => 'bg-green-100'],
    'cancelled' => ['label' => 'Dibatalkan', 'icon' => 'fa-times-circle', 'color' => 'red', 'text' => 'text-red-600', 'bg' => 'bg-red-100'],
];


// --- 3. Produk Terlaris (Berdasarkan jumlah terjual di order_items yg statusnya valid) ---
$top_products_query = $conn->query("
    SELECT p.id, p.name, p.image, SUM(oi.quantity) as total_sold
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status IN $status_pendapatan_list
    GROUP BY p.id, p.name, p.image
    ORDER BY total_sold DESC
    LIMIT 5
");
$top_products = $top_products_query->fetch_all(MYSQLI_ASSOC);

// --- 4. Kategori Terlaris (Berdasarkan jumlah produk terjual di kategori tsb) ---
$top_categories_query = $conn->query("
    SELECT c.id, c.name, SUM(oi.quantity) as total_items_sold
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status IN $status_pendapatan_list
    GROUP BY c.id, c.name
    ORDER BY total_items_sold DESC
    LIMIT 5
");
$top_categories = $top_categories_query->fetch_all(MYSQLI_ASSOC);


// --- 5. Data Chart Awal (7 Hari Terakhir) ---
// Ini akan diupdate oleh AJAX nanti
$chart_data_initial = [];
$chart_labels_initial = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels_initial[] = date('d M', strtotime($date));
    $query = $conn->query("
        SELECT SUM(total) as daily_sales
        FROM orders
        WHERE DATE(created_at) = '$date' AND status IN $status_pendapatan_list
    ");
    $result = $query->fetch_assoc();
    $chart_data_initial[] = (float)($result['daily_sales'] ?? 0);
}

// 6. Pesanan Terbaru (digunakan di widget)
$latest_orders = [];
$result_latest = $conn->query("
    SELECT o.id, o.order_number, o.total, o.status, u.name as user_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 5
");
if ($result_latest) {
    while($row = $result_latest->fetch_assoc()) {
        $latest_orders[] = $row;
    }
}
?>

<!-- Pustaka Chart.js dan Flatpickr (untuk date range picker) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/id.js"></script> <!-- Bahasa Indonesia untuk Flatpickr -->

<div class="space-y-6">
    <!-- Grid Statistik Utama -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6">
        <!-- Card Total Pesanan -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 text-white p-4 md:p-5 rounded-xl shadow-lg flex items-center justify-between transition-transform transform hover:scale-105">
            <div>
                <p class="text-sm font-medium opacity-80">Total Pesanan</p>
                <p class="text-2xl md:text-3xl font-bold"><?= number_format($total_pesanan_all) ?></p>
            </div>
            <div class="bg-white bg-opacity-20 p-2 md:p-3 rounded-full">
                <i class="fas fa-boxes text-lg md:text-2xl"></i>
            </div>
        </div>
        <!-- Card Pesanan Baru -->
        <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 text-white p-4 md:p-5 rounded-xl shadow-lg flex items-center justify-between transition-transform transform hover:scale-105">
            <div>
                <p class="text-sm font-medium opacity-80">Pesanan Baru</p>
                <p class="text-2xl md:text-3xl font-bold"><?= number_format($pesanan_baru) ?></p>
                <a href="?page=pesanan&status=belum_dicetak" class="text-xs opacity-70 hover:opacity-100 mt-1 inline-block">Lihat Detail &rarr;</a>
            </div>
            <div class="bg-white bg-opacity-20 p-2 md:p-3 rounded-full">
                <i class="fas fa-concierge-bell text-lg md:text-2xl"></i>
            </div>
        </div>
        <!-- Card Pendapatan Total -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 text-white p-4 md:p-5 rounded-xl shadow-lg flex items-center justify-between transition-transform transform hover:scale-105 overflow-hidden"> <!-- Tambah overflow-hidden -->
            <div class="min-w-0 flex-1"> <!-- Tambahkan flex-1 agar div ini mengambil sisa ruang -->
                <p class="text-sm font-medium opacity-80">Total Pendapatan</p>
                <!-- PERBAIKAN DI SINI: Ukuran font lebih fleksibel + break-words -->
                <p class="text-lg sm:text-xl lg:text-2xl font-bold break-words"><?= format_rupiah($pendapatan_total_all) ?></p>
                 <!-- `truncate` dihapus, diganti `break-words` -->
            </div>
            <div class="bg-white bg-opacity-20 p-2 md:p-3 rounded-full flex-shrink-0 ml-2"> <!-- Pastikan icon tidak mengecil + beri margin kiri -->
                <i class="fas fa-wallet text-lg md:text-2xl"></i>
            </div>
        </div>
        <!-- Card Total Pengguna -->
        <div class="bg-gradient-to-br from-gray-700 to-gray-800 text-white p-4 md:p-5 rounded-xl shadow-lg flex items-center justify-between transition-transform transform hover:scale-105">
            <div>
                <p class="text-sm font-medium opacity-80">Total Pengguna</p>
                <p class="text-2xl md:text-3xl font-bold"><?= number_format($total_user) ?></p>
                <!-- Link "Lihat Detail" dihapus -->
            </div>
            <div class="bg-white bg-opacity-20 p-2 md:p-3 rounded-full">
                <i class="fas fa-users text-lg md:text-2xl"></i>
            </div>
        </div>
    </div>

    <!-- Grafik Penjualan Interaktif -->
    <div class="bg-white p-4 md:p-6 rounded-xl shadow-md">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
            <h3 class="font-semibold text-gray-700 text-lg">Grafik Pendapatan</h3>
            <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
                <select id="chart-range" class="border rounded-lg text-sm p-2 bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full sm:w-auto">
                    <option value="7d" selected>7 Hari Terakhir</option>
                    <option value="30d">30 Hari Terakhir</option>
                    <option value="this_month">Bulan Ini</option>
                    <option value="this_year">Tahun Ini</option>
                    <option value="custom">Rentang Kustom</option>
                </select>
                <input type="text" id="custom-date-range" class="border rounded-lg text-sm p-2 bg-gray-50 hidden flatpickr-input w-full sm:w-auto" placeholder="Pilih Tanggal">
                <button id="update-chart-btn" class="px-3 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-md hover:bg-indigo-700 hidden w-full sm:w-auto">
                    <i class="fas fa-sync-alt sm:mr-1"></i> <span class="hidden sm:inline">Update</span>
                </button>
            </div>
        </div>
        <div class="relative h-64 md:h-96">
            <canvas id="salesChart"></canvas>
             <!-- Loading indicator untuk chart -->
            <div id="chart-loading" class="absolute inset-0 bg-white bg-opacity-75 flex items-center justify-center hidden z-10">
                <i class="fas fa-spinner fa-spin text-indigo-600 text-3xl"></i>
            </div>
        </div>
    </div>

    <!-- Ringkasan Status Pesanan & Produk/Kategori Terlaris -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Kolom Kiri: Ringkasan Status -->
        <div class="bg-white p-4 md:p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700 mb-4 border-b pb-2">Ringkasan Status Pesanan</h3>
            <div class="space-y-3">
                <?php foreach ($status_map as $key => $details):
                    $count = $status_counts[$key] ?? 0;
                    // Tampilkan hanya jika ada count atau status penting
                    if ($count > 0 || in_array($key, ['waiting_payment', 'waiting_approval', 'belum_dicetak'])): ?>
                    <a href="?page=pesanan&status=<?= $key ?>" class="flex items-center justify-between p-2 rounded-md hover:bg-gray-100 transition-colors">
                        <div class="flex items-center gap-3">
                            <span class="w-8 h-8 flex items-center justify-center rounded-full <?= $details['bg'] ?> <?= $details['text'] ?>">
                                <i class="fas <?= $details['icon'] ?>"></i>
                            </span>
                            <span class="text-sm font-medium text-gray-700"><?= $details['label'] ?></span>
                        </div>
                        <span class="text-sm font-bold text-gray-800 bg-gray-100 px-2 py-0.5 rounded-md"><?= $count ?></span>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                 <div class="border-t pt-3 text-center">
                    <a href="?page=pesanan" class="text-sm font-semibold text-indigo-600 hover:underline">Lihat Semua Pesanan &rarr;</a>
                </div>
            </div>
        </div>

        <!-- Kolom Tengah: Produk Terlaris -->
        <div class="bg-white p-4 md:p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700 mb-4 border-b pb-2">Produk Terlaris</h3>
            <?php if (!empty($top_products)): ?>
                <div class="space-y-3">
                    <?php foreach ($top_products as $product): ?>
                        <div class="flex items-center gap-3 p-2 rounded-md hover:bg-gray-50">
                            <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-10 h-10 rounded object-cover border flex-shrink-0">
                            <div class="flex-grow min-w-0">
                                <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" target="_blank" class="text-sm font-medium text-gray-800 hover:text-indigo-600 truncate block" title="<?= htmlspecialchars($product['name']) ?>">
                                    <?= htmlspecialchars($product['name']) ?>
                                </a>
                            </div>
                            <span class="text-sm font-bold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-md flex-shrink-0"><?= number_format($product['total_sold']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500 italic text-center py-5">Belum ada data produk terjual.</p>
            <?php endif; ?>
        </div>

        <!-- Kolom Kanan: Kategori Terlaris & Pesanan Terbaru -->
        <div class="space-y-6">
             <!-- Kategori Terlaris -->
            <div class="bg-white p-4 md:p-6 rounded-xl shadow-md">
                <h3 class="font-semibold text-gray-700 mb-4 border-b pb-2">Kategori Terlaris</h3>
                <?php if (!empty($top_categories)): ?>
                    <div class="space-y-3">
                        <?php foreach ($top_categories as $category): ?>
                            <div class="flex items-center justify-between p-2 rounded-md hover:bg-gray-50">
                                <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($category['id'])) ?>" target="_blank" class="text-sm font-medium text-gray-800 hover:text-indigo-600">
                                    <?= htmlspecialchars($category['name']) ?>
                                </a>
                                <span class="text-sm font-bold text-green-600 bg-green-50 px-2 py-0.5 rounded-md"><?= number_format($category['total_items_sold']) ?> item</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500 italic text-center py-5">Belum ada data kategori.</p>
                <?php endif; ?>
            </div>
             <!-- Pesanan Terbaru (dari data yg sudah diambil) -->
            <div class="bg-white p-4 md:p-6 rounded-xl shadow-md">
                <h3 class="font-semibold text-gray-700 mb-4 border-b pb-2">Pesanan Terbaru</h3>
                <div class="space-y-4">
                    <?php if (!empty($latest_orders)): ?>
                        <?php foreach ($latest_orders as $order):
                             // Gunakan $status_map yang sudah ada
                            $status_detail = $status_map[$order['status']] ?? ['label' => ucfirst($order['status']), 'color' => 'gray', 'text' => 'text-gray-600', 'bg' => 'bg-gray-100'];
                        ?>
                            <div class="flex items-center justify-between p-1 rounded-md hover:bg-gray-50">
                                <div>
                                    <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($order['user_name']) ?></p>
                                    <a href="?page=pesanan&search=<?= htmlspecialchars($order['order_number']) ?>" class="text-xs text-indigo-600 hover:underline"><?= htmlspecialchars($order['order_number']) ?></a>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <p class="text-sm font-semibold text-gray-700"><?= format_rupiah($order['total']) ?></p>
                                     <!-- Terapkan class warna dari $status_detail -->
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $status_detail['bg'] ?> <?= $status_detail['text'] ?>">
                                        <?= $status_detail['label'] ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 italic text-center py-5">Belum ada pesanan.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('salesChart').getContext('2d');
    let salesChart; // Variabel global untuk chart

    const chartRangeSelect = document.getElementById('chart-range');
    const customDateRangeInput = document.getElementById('custom-date-range');
    const updateChartBtn = document.getElementById('update-chart-btn');
    const chartLoading = document.getElementById('chart-loading');
    const ajaxChartUrl = '<?= BASE_URL ?>/admin/ajax_get_chart_data.php'; // Pastikan path benar

    // Inisialisasi Flatpickr
    const fp = flatpickr(customDateRangeInput, {
        mode: "range",
        dateFormat: "Y-m-d",
        locale: "id", // Bahasa Indonesia
        onChange: function(selectedDates, dateStr, instance) {
            // Tampilkan tombol update hanya jika rentang kustom dipilih dan valid
            updateChartBtn.classList.toggle('hidden', selectedDates.length < 2);
        }
    });

    // Fungsi untuk membuat/update chart
    function createOrUpdateChart(labels, data) {
        const chartConfig = {
            type: 'line', // Tetap line chart
            data: {
                labels: labels,
                datasets: [{
                    label: 'Pendapatan',
                    data: data,
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: 'rgba(79, 70, 229, 1)',
                    pointBorderColor: '#fff',
                    pointHoverBackgroundColor: '#fff',
                    pointHoverBorderColor: 'rgba(79, 70, 229, 1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                // Format rupiah simpel untuk Y-axis
                                if (value >= 1000000) {
                                    return 'Rp ' + (value / 1000000) + ' Jt';
                                } else if (value >= 1000) {
                                    return 'Rp ' + (value / 1000) + ' Rb';
                                }
                                return 'Rp ' + value;
                            }
                        }
                    },
                    x: {
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) label += ': ';
                                if (context.parsed.y !== null) {
                                    // Tooltip tetap format lengkap
                                    label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                                }
                                return label;
                            }
                        }
                    }
                },
                animation: {
                    duration: 400
                },
                interaction: {
                    intersect: false,
                    mode: 'index',
                },
                 elements: {
                    point:{
                        radius: 3,
                        hoverRadius: 5
                    }
                }
            }
        };

        if (salesChart) {
            salesChart.data.labels = labels;
            salesChart.data.datasets[0].data = data;
            salesChart.update();
        } else {
            salesChart = new Chart(ctx, chartConfig);
        }
    }

    // Fungsi untuk fetch data chart via AJAX
    async function fetchChartData(range, startDate = null, endDate = null) {
        chartLoading.classList.remove('hidden'); // Tampilkan loading
        try {
            const params = new URLSearchParams({ range: range });
            if (startDate && endDate) {
                params.append('start_date', startDate);
                params.append('end_date', endDate);
            }
            // Tambahkan cache buster
            params.append('_', new Date().getTime());

            const response = await fetch(`${ajaxChartUrl}?${params.toString()}`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            const result = await response.json();

            if (result.success) {
                createOrUpdateChart(result.labels, result.data);
            } else {
                console.error("Error fetching chart data:", result.message);
                 // Tampilkan pesan error di canvas jika fetch gagal
                 if (salesChart) salesChart.destroy(); // Hancurkan chart lama jika ada
                 ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                 ctx.fillStyle = '#ef4444'; // Warna merah
                 ctx.textAlign = 'center';
                 ctx.font = '14px Inter';
                 ctx.fillText('Gagal memuat data: ' + (result.message || 'Error tidak diketahui'), ctx.canvas.width / 2, ctx.canvas.height / 2);
            }
        } catch (error) {
            console.error("AJAX error:", error);
             // Tampilkan pesan error di canvas jika AJAX error
             if (salesChart) salesChart.destroy();
             ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
             ctx.fillStyle = '#ef4444';
             ctx.textAlign = 'center';
             ctx.font = '14px Inter';
             ctx.fillText('Terjadi kesalahan jaringan saat mengambil data grafik.', ctx.canvas.width / 2, ctx.canvas.height / 2);
        } finally {
             chartLoading.classList.add('hidden'); // Sembunyikan loading
        }
    }

    // Event listener untuk select range
    chartRangeSelect.addEventListener('change', function() {
        const selectedRange = this.value;
        const isCustom = selectedRange === 'custom';

        customDateRangeInput.classList.toggle('hidden', !isCustom);
         // Sembunyikan tombol update jika bukan custom ATAU jika custom tapi belum pilih tanggal
        updateChartBtn.classList.add('hidden');

        if (!isCustom) {
            fp.clear(); // Hapus tanggal kustom jika range non-kustom dipilih
            fetchChartData(selectedRange);
        } else {
             // Jika custom, tampilkan tombol update HANYA jika sudah ada tanggal terpilih
            updateChartBtn.classList.toggle('hidden', fp.selectedDates.length < 2);
        }
    });

    // Event listener untuk tombol update
    updateChartBtn.addEventListener('click', function() {
        if (fp.selectedDates.length === 2) {
            const startDate = fp.formatDate(fp.selectedDates[0], "Y-m-d");
            const endDate = fp.formatDate(fp.selectedDates[1], "Y-m-d");
            fetchChartData('custom', startDate, endDate);
        } else {
            // Seharusnya tidak terjadi karena tombol disembunyikan, tapi sebagai pengaman
            alert('Silakan pilih rentang tanggal kustom terlebih dahulu.');
        }
    });

    // --- Initial Chart Load ---
    createOrUpdateChart(<?= json_encode($chart_labels_initial) ?>, <?= json_encode($chart_data_initial) ?>);

});
</script>