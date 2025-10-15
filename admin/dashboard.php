<?php
// File: admin/dashboard.php

// Pastikan file ini tidak diakses langsung
if (!defined('BASE_URL')) {
    die('Akses dilarang');
}

// Ambil data statistik
$total_pesanan = $conn->query("SELECT COUNT(id) as total FROM orders")->fetch_assoc()['total'];
$pesanan_baru = $conn->query("SELECT COUNT(id) as total FROM orders WHERE status = 'waiting_approval'")->fetch_assoc()['total'];
$total_pendapatan = $conn->query("SELECT SUM(total) as total FROM orders WHERE status = 'selesai'")->fetch_assoc()['total'];
$total_user = $conn->query("SELECT COUNT(id) as total FROM users WHERE role = 'user'")->fetch_assoc()['total'];

?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    <!-- Stat Card 1: Total Pesanan -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-sm font-medium text-gray-500">Total Pesanan</h3>
        <p class="text-3xl font-bold text-gray-800 mt-2"><?= $total_pesanan ?></p>
    </div>

    <!-- Stat Card 2: Pesanan Baru -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-sm font-medium text-gray-500">Pesanan Perlu Diproses</h3>
        <p class="text-3xl font-bold text-indigo-600 mt-2"><?= $pesanan_baru ?></p>
    </div>

    <!-- Stat Card 3: Total Pendapatan -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-sm font-medium text-gray-500">Total Pendapatan</h3>
        <p class="text-3xl font-bold text-green-600 mt-2"><?= format_rupiah($total_pendapatan) ?></p>
    </div>

    <!-- Stat Card 4: Total Pengguna -->
    <div class="bg-white p-6 rounded-lg shadow-md">
        <h3 class="text-sm font-medium text-gray-500">Total Pengguna</h3>
        <p class="text-3xl font-bold text-gray-800 mt-2"><?= $total_user ?></p>
    </div>
</div>

<div class="mt-8 bg-white p-6 rounded-lg shadow-md">
    <h3 class="text-lg font-semibold text-gray-800 mb-4">Pesanan Terbaru</h3>
    <!-- Tampilkan daftar pesanan terbaru di sini jika diperlukan -->
    <p class="text-gray-500">Fitur daftar pesanan terbaru akan ditambahkan di sini.</p>
</div>