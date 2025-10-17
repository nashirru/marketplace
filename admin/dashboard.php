<?php
// File: admin/dashboard.php

if (!defined('IS_ADMIN_PAGE')) die('Akses dilarang');

// --- PENGAMBILAN DATA STATISTIK ---

// 1. Statistik Utama
$total_pesanan = $conn->query("SELECT COUNT(id) as total FROM orders WHERE status != 'cancelled'")->fetch_assoc()['total'];
$pesanan_baru = $conn->query("SELECT COUNT(id) as total FROM orders WHERE status = 'belum_dicetak' OR status = 'waiting_approval'")->fetch_assoc()['total'];
$pendapatan_total = $conn->query("SELECT SUM(total) as total FROM orders WHERE status = 'completed'")->fetch_assoc()['total'];
$total_user = $conn->query("SELECT COUNT(id) as total FROM users WHERE role = 'user'")->fetch_assoc()['total'];

// 2. Rekap Harian (Hari Ini)
$today = date('Y-m-d');
$rekap_harian = $conn->query("
    SELECT 
        COUNT(id) as total_orders, 
        SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as total_sales
    FROM orders 
    WHERE DATE(created_at) = '$today' AND status != 'cancelled'
")->fetch_assoc();

// 3. Rekap Bulanan (Bulan Ini)
$this_month = date('Y-m');
$rekap_bulanan = $conn->query("
    SELECT 
        COUNT(id) as total_orders, 
        SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END) as total_sales
    FROM orders 
    WHERE DATE_FORMAT(created_at, '%Y-%m') = '$this_month' AND status != 'cancelled'
")->fetch_assoc();

// 4. Data Chart Penjualan 7 Hari Terakhir
$chart_data = [];
$chart_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('d M', strtotime($date));
    $query = $conn->query("
        SELECT SUM(total) as daily_sales 
        FROM orders 
        WHERE DATE(created_at) = '$date' AND status = 'completed'
    ");
    $result = $query->fetch_assoc();
    $chart_data[] = (float)($result['daily_sales'] ?? 0);
}

// 5. Tiga Pesanan Terbaru
$latest_orders = [];
$result_latest = $conn->query("
    SELECT o.id, o.order_number, o.total, o.status, u.name as user_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 3
");
if ($result_latest) {
    while($row = $result_latest->fetch_assoc()) {
        $latest_orders[] = $row;
    }
}
?>

<!-- Pustaka Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="space-y-6">
    <!-- Grid Statistik Utama -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center space-x-4">
            <div class="bg-blue-100 p-3 rounded-full"><i class="fas fa-box-open text-xl text-blue-500"></i></div>
            <div>
                <p class="text-sm text-gray-500">Total Pesanan</p>
                <p class="text-2xl font-bold text-gray-800"><?= $total_pesanan ?></p>
            </div>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center space-x-4">
            <div class="bg-indigo-100 p-3 rounded-full"><i class="fas fa-concierge-bell text-xl text-indigo-500"></i></div>
            <div>
                <p class="text-sm text-gray-500">Pesanan Baru</p>
                <p class="text-2xl font-bold text-gray-800"><?= $pesanan_baru ?></p>
            </div>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center space-x-4">
            <div class="bg-green-100 p-3 rounded-full"><i class="fas fa-wallet text-xl text-green-500"></i></div>
            <div>
                <p class="text-sm text-gray-500">Pendapatan</p>
                <p class="text-2xl font-bold text-gray-800"><?= format_rupiah($pendapatan_total) ?></p>
            </div>
        </div>
        <div class="bg-white p-5 rounded-xl shadow-md flex items-center space-x-4">
            <div class="bg-gray-100 p-3 rounded-full"><i class="fas fa-users text-xl text-gray-500"></i></div>
            <div>
                <p class="text-sm text-gray-500">Total Pengguna</p>
                <p class="text-2xl font-bold text-gray-800"><?= $total_user ?></p>
            </div>
        </div>
    </div>

    <!-- Grid Rekap Harian & Bulanan -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700">Rekap Hari Ini (<?= date('d M Y') ?>)</h3>
            <div class="mt-4 flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">Penjualan</p>
                    <p class="text-2xl font-bold text-indigo-600"><?= format_rupiah($rekap_harian['total_sales']) ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Transaksi</p>
                    <p class="text-2xl font-bold text-gray-800"><?= $rekap_harian['total_orders'] ?> Pesanan</p>
                </div>
            </div>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700">Rekap Bulan Ini (<?= date('F Y') ?>)</h3>
            <div class="mt-4 flex justify-between items-center">
                <div>
                    <p class="text-sm text-gray-500">Penjualan</p>
                    <p class="text-2xl font-bold text-green-600"><?= format_rupiah($rekap_bulanan['total_sales']) ?></p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Transaksi</p>
                    <p class="text-2xl font-bold text-gray-800"><?= $rekap_bulanan['total_orders'] ?> Pesanan</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Grid Chart & Pesanan Terbaru -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 bg-white p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700 mb-4">Grafik Penjualan (7 Hari Terakhir)</h3>
            <canvas id="salesChart"></canvas>
        </div>
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h3 class="font-semibold text-gray-700 mb-4">Pesanan Terbaru</h3>
            <div class="space-y-4">
                <?php if (!empty($latest_orders)): ?>
                    <?php foreach ($latest_orders as $order): ?>
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($order['user_name']) ?></p>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($order['order_number']) ?></p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold text-indigo-600"><?= format_rupiah($order['total']) ?></p>
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full 
                                    <?= strpos($order['status'], 'completed') !== false ? 'bg-green-100 text-green-800' : (strpos($order['status'], 'cancelled') !== false ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800') ?>">
                                    <?= ucfirst(str_replace('_', ' ', $order['status'])) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-sm text-gray-500">Belum ada pesanan.</p>
                <?php endif; ?>
                <div class="border-t pt-2 text-center">
                    <a href="?page=pesanan" class="text-sm font-semibold text-indigo-600 hover:underline">Lihat Semua Pesanan &rarr;</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('salesChart').getContext('2d');
    const salesChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chart_labels) ?>,
            datasets: [{
                label: 'Penjualan',
                data: <?= json_encode($chart_data) ?>,
                backgroundColor: 'rgba(79, 70, 229, 0.7)',
                borderColor: 'rgba(79, 70, 229, 1)',
                borderWidth: 1,
                borderRadius: 5,
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value, index, values) {
                            return 'Rp ' + value.toLocaleString('id-ID');
                        }
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += 'Rp ' + context.parsed.y.toLocaleString('id-ID');
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
});
</script>