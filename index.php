<?php
// File: index.php
include 'config/config.php';
include 'sistem/sistem.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warok Kite - Marketplace Khas Ponorogo</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?php include 'partial/partial.php'; ?>

    <?= navbar($conn) ?>

    <main class="container mx-auto px-4 py-8">
        
        <div class="mb-6">
            <?= flash_message('success') ?>
            <?= flash_message('error') ?>
            <?= flash_message('info') ?>
        </div>
        
        <?= banner_slide($conn) ?>

        <section id="kategori" class="my-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Kategori Pilihan</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-4 lg:grid-cols-4 gap-4 sm:gap-6">
                <?php
                $categories = $conn->query("SELECT * FROM categories LIMIT 4");
                while($category = $categories->fetch_assoc()):
                ?>
                <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= $category['id'] ?>" class="group block bg-white rounded-lg shadow-sm p-4 text-center hover:shadow-lg transition-shadow">
                    <img src="https://placehold.co/100x100/E2E8F0/4A5568?text=<?= htmlspecialchars($category['name']) ?>" alt="<?= htmlspecialchars($category['name']) ?>" class="mx-auto h-16 w-16 mb-2">
                    <h3 class="text-sm font-semibold text-gray-700 group-hover:text-indigo-600"><?= htmlspecialchars($category['name']) ?></h3>
                </a>
                <?php endwhile; ?>
            </div>
        </section>

        <section id="produk">
            <h2 class="text-2xl font-bold text-gray-800 mb-6">Produk Terbaru</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 sm:gap-6">
                 <?php
                $products = $conn->query("SELECT * FROM products ORDER BY created_at DESC LIMIT 10");
                while($product = $products->fetch_assoc()):
                ?>
                <a href="<?= BASE_URL ?>/product/product.php?id=<?= $product['id'] ?>" class="group block bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-xl transition-shadow duration-300">
                    <div class="w-full h-40 sm:h-48 overflow-hidden">
                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                             alt="<?= htmlspecialchars($product['name']) ?>" 
                             class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                             onerror="this.onerror=null;this.src='https://placehold.co/400x400/E2E8F0/4A5568?text=Gambar+Rusak';">
                    </div>
                    <div class="p-3 sm:p-4">
                        <h3 class="text-sm sm:text-base font-semibold text-gray-800 truncate"><?= htmlspecialchars($product['name']) ?></h3>
                        <p class="text-base sm:text-lg font-bold text-indigo-600 mt-1"><?= format_rupiah($product['price']) ?></p>
                    </div>
                </a>
                <?php endwhile; ?>
            </div>
        </section>

    </main>

    <?= footer() ?>
    
    <script>
        let currentSlide = 0;
        const slides = document.querySelectorAll("#slider .slide");
        const totalSlides = slides.length;

        function showSlide(index) {
            slides.forEach((slide) => {
                slide.classList.add('hidden');
            });
            if (slides[index]) {
                slides[index].classList.remove('hidden');
            }
        }

        function nextSlide() {
            currentSlide = (currentSlide + 1) % totalSlides;
            showSlide(currentSlide);
        }

        if (totalSlides > 1) {
            showSlide(currentSlide);
            setInterval(nextSlide, 3000);
        } else if (totalSlides === 1) {
            showSlide(0);
        }
    </script>
</body>
</html>