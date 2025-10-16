<?php
// File: help/help.php - Halaman Pusat Bantuan

// Pemuatan File Inti
// Koneksi $conn dan BASE_URL tersedia setelah ini
require_once '../config/config.php'; 
require_once '../sistem/sistem.php';
require_once '../partial/partial.php'; // Memuat fungsi navbar, footer

// Memuat pengaturan toko ke cache (penting untuk navbar dan footer)
load_settings($conn);

// Judul halaman
$store_name = get_setting($conn, 'store_name') ?? 'Marketplace';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bantuan - <?= htmlspecialchars($store_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }</style>
</head>
<body>

    <!-- Memuat Navbar DENGAN $conn -->
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 py-12 min-h-screen max-w-4xl">
        <h1 class="text-4xl font-extrabold text-gray-900 mb-8 border-b pb-4">Pusat Bantuan (FAQ)</h1>
        
        <div class="space-y-6">
            <!-- FAQ 1 -->
            <div class="bg-white p-6 rounded-xl shadow-lg">
                <h2 class="text-xl font-semibold text-indigo-600 mb-3">Bagaimana cara memesan produk?</h2>
                <p class="text-gray-700">Pilih produk yang Anda inginkan, tambahkan ke keranjang, dan lanjutkan ke proses *checkout*. Pastikan Anda sudah mengisi alamat pengiriman dengan benar dan memilih metode pembayaran yang tersedia.</p>
            </div>

            <!-- FAQ 2 -->
            <div class="bg-white p-6 rounded-xl shadow-lg">
                <h2 class="text-xl font-semibold text-indigo-600 mb-3">Apa saja metode pembayaran yang diterima?</h2>
                <p class="text-gray-700">Kami menerima transfer bank melalui beberapa bank lokal. Detail rekening akan ditampilkan setelah Anda menyelesaikan proses *checkout*.</p>
            </div>

            <!-- FAQ 3 -->
            <div class="bg-white p-6 rounded-xl shadow-lg">
                <h2 class="text-xl font-semibold text-indigo-600 mb-3">Berapa lama waktu pengiriman?</h2>
                <p class="text-gray-700">Waktu pengiriman bervariasi tergantung lokasi Anda dan jenis layanan kurir yang dipilih. Rata-rata, pengiriman memakan waktu $3$-$7$ hari kerja setelah pembayaran dikonfirmasi.</p>
            </div>
        </div>

        <div class="mt-12 text-center p-6 bg-indigo-50 rounded-xl">
            <h2 class="text-2xl font-bold text-indigo-700">Tidak menemukan jawaban Anda?</h2>
            <p class="mt-2 text-indigo-600">Silakan hubungi customer service kami melalui kontak di bagian bawah halaman (footer).</p>
        </div>
    </main>

    <!-- Memuat Footer DENGAN $conn -->
    <?php footer($conn); ?>

</body>
</html>