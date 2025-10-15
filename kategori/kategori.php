<?php
// File: kategori/kategori.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

// Contoh data kategori, idealnya diambil dari database
$categories = [
    ['name' => 'Kerajinan', 'icon' => '🎨', 'desc' => 'Produk buatan tangan yang unik.'],
    ['name' => 'Kuliner', 'icon' => '🍲', 'desc' => 'Cita rasa otentik khas Ponorogo.'],
    ['name' => 'Fashion', 'icon' => '👕', 'desc' => 'Pakaian dengan sentuhan budaya lokal.'],
    ['name' => 'Kesenian', 'icon' => '🎭', 'desc' => 'Pernak-pernik dan alat kesenian Reog.'],
    ['name' => 'Pertanian', 'icon' => '🌱', 'desc' => 'Hasil bumi segar dari petani lokal.'],
    ['name' => 'Jasa', 'icon' => '💼', 'desc' => 'Layanan profesional dari warga lokal.']
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semua Kategori - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    <?php navbar(); ?>

    <main class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">Semua Kategori</h1>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($categories as $category): ?>
                <a href="#" class="group block bg-white p-6 rounded-lg shadow-md hover:shadow-xl hover:-translate-y-1 transition-transform duration-300 border border-gray-200">
                    <div class="flex items-center space-x-4">
                        <span class="text-4xl"><?= $category['icon'] ?></span>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800"><?= $category['name'] ?></h3>
                            <p class="text-sm text-gray-500 mt-1"><?= $category['desc'] ?></p>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <?php footer(); ?>
</body>
</html>