<?php
// File: help/help.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pusat Bantuan - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center">
            <h1 class="text-3xl font-bold text-gray-800">Pusat Bantuan</h1>
            <p class="mt-2 text-gray-600">Ada yang bisa kami bantu?</p>
        </div>

        <div class="mt-12 max-w-3xl mx-auto">
            <div class="space-y-4">
                <!-- FAQ Item 1 -->
                <details class="p-6 bg-white rounded-lg shadow-sm group">
                    <summary class="flex items-center justify-between cursor-pointer font-medium text-gray-800">
                        Bagaimana cara memesan produk?
                        <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </summary>
                    <p class="mt-4 text-gray-600">
                        Pilih produk yang Anda inginkan, klik tombol "Tambah ke Keranjang", lalu ikuti proses checkout dari halaman keranjang belanja.
                    </p>
                </details>
                <!-- FAQ Item 2 -->
                <details class="p-6 bg-white rounded-lg shadow-sm group">
                    <summary class="flex items-center justify-between cursor-pointer font-medium text-gray-800">
                        Apa saja metode pembayaran yang diterima?
                        <svg class="w-5 h-5 text-gray-500 group-open:rotate-180 transition-transform" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </summary>
                    <p class="mt-4 text-gray-600">
                        Saat ini kami hanya menerima metode pembayaran via transfer bank. Pastikan untuk mengupload bukti transfer setelah melakukan pembayaran.
                    </p>
                </details>
            </div>
        </div>
    </main>

    <?php footer(); ?>
</body>
</html>