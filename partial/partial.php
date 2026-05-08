<?php
// File: partial/partial.php
// Update V2.2: FAQ Footer, WhatsApp Button & Red Theme Consistency
// Tanggal Update: 2025-01-11
// Programmer: AI Assistant (IQ 180 Mode)

/**
 * Partial.php
 * --------------------------------------------------------------------------
 * File ini berisi kumpulan fungsi komponen UI yang bersifat reusable (dapat digunakan kembali).
 * Fokus utama update ini adalah "Flat Clean Design" dengan performa tinggi.
 * Menghilangkan elemen berat seperti shadow tebal dan gradient.
 * Menggunakan pendekatan "Mobile-First" dengan Tailwind CSS.
 */

if (!defined('BASE_URL')) {
    // Deteksi protokol (HTTP/HTTPS) dan host secara otomatis untuk mendefinisikan BASE_URL
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    define('BASE_URL', $protocol . $_SERVER['HTTP_HOST']);
}

/**
 * Menampilkan <head> HTML dengan optimasi SEO tingkat lanjut dan Resource Loading yang efisien.
 * * @param string $page_title Judul halaman yang akan ditampilkan di tab browser.
 * @param mysqli $conn Koneksi database untuk mengambil pengaturan toko.
 * @param string|null $seo_desc Deskripsi meta untuk SEO (Opsional).
 * @param string|null $seo_keywords Keyword meta untuk SEO (Opsional).
 * @param string|null $og_image URL gambar untuk Open Graph share (Opsional).
 */
function page_head($page_title, $conn, $seo_desc = null, $seo_keywords = null, $og_image = null) {
    // Mengambil pengaturan toko dari database dengan fallback value yang aman
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
    $store_description = get_setting($conn, 'store_description') ?? 'Pusat belanja layangan tradisional terbaik dan terpercaya.';
    $logo_name = get_setting($conn, 'store_logo');
    
    // Path gambar default
    $default_og_image = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    $favicon_path = $default_og_image;

    // Konstruksi Meta Data Final
    $final_title = htmlspecialchars($page_title) . ' | ' . htmlspecialchars($store_name);
    $final_desc = htmlspecialchars($seo_desc ?: $store_description);
    
    // Keyword default yang relevan dengan niche "Layangan"
    $default_keywords = "layangan, layangan tradisional, gapangan, sendaren, ponorogo, warok, hobi, mainan tradisional, kite marketplace";
    $final_keywords = htmlspecialchars($seo_keywords ? $seo_keywords . ', ' . $default_keywords : $default_keywords);
    $final_og_image = $og_image ?: $default_og_image;
    
    // URL Canonical untuk menghindari duplikat konten di mata Google
    $current_url = BASE_URL . $_SERVER['REQUEST_URI'];
?>
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta name="theme-color" content="#ffffff"> <!-- Ganti jadi putih agar menyatu dengan navbar -->
    
    <title><?= $final_title ?></title>

    <!-- SEO Meta Tags Standard -->
    <meta name="description" content="<?= $final_desc ?>">
    <meta name="keywords" content="<?= $final_keywords ?>">
    <meta name="author" content="<?= htmlspecialchars($store_name) ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?= htmlspecialchars($current_url) ?>" />

    <!-- Open Graph / Facebook Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
    <meta property="og:title" content="<?= $final_title ?>">
    <meta property="og:description" content="<?= $final_desc ?>">
    <meta property="og:image" content="<?= htmlspecialchars($final_og_image) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($store_name) ?>">
    <meta property="og:locale" content="id_ID">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:url" content="<?= htmlspecialchars($current_url) ?>">
    <meta name="twitter:title" content="<?= $final_title ?>">
    <meta name="twitter:description" content="<?= $final_desc ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($final_og_image) ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon_path) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon_path) ?>">

    <!-- ============================================================ -->
    <!-- RESOURCE LOADING DIOPTIMASI (menghilangkan render-blocking)   -->
    <!-- ============================================================ -->

    <!-- DNS Prefetch & Preconnect: koneksi dibuka lebih awal ke CDN eksternal -->
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="dns-prefetch" href="https://fonts.gstatic.com">
    <link rel="dns-prefetch" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!--
        TAILWIND CSS — Pakai file lokal yang sudah didownload.
        Lebih cepat & tidak ada SRI hash mismatch.
    -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tailwind.min.css">

    <!-- Google Fonts: Inter — display=swap agar teks langsung tampil, font load belakangan -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet" media="print" onload="this.media='all'">
    <noscript>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    </noscript>

    <!--
        Font Awesome — load tanpa integrity hash (langsung dari CDN).
        media="print" trick agar tidak render-blocking.
    -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"
          media="print" onload="this.media='all'"
          crossorigin="anonymous" referrerpolicy="no-referrer">
    <noscript>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    </noscript>

    <!-- Custom CSS untuk Override dan Utilitas Spesifik -->
    <style>
        /* Base Typography */
        body { 
            font-family: 'Inter', sans-serif; 
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            background-color: #ffffff;
            color: #1f2937;
        }
        
        /* Utility: Line Clamp */
        .line-clamp-1 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 1;
        }
        .line-clamp-2 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }

        /* ================================================================
           Aspect Ratio Utilities (tidak ada di Tailwind v2, ditambahkan manual)
           ================================================================ */
        .aspect-square {
            aspect-ratio: 1 / 1;
        }
        .aspect-video {
            aspect-ratio: 16 / 9;
        }

        /* Gambar di dalam container aspect-ratio SELALU 1:1, tidak gepeng/molor */
        .aspect-square img,
        .aspect-video img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        /* Fallback untuk browser lama yang belum support aspect-ratio */
        @supports not (aspect-ratio: 1) {
            .aspect-square {
                position: relative;
                padding-bottom: 100%; /* 1:1 */
                overflow: hidden;
            }
            .aspect-square > * {
                position: absolute;
                top: 0; left: 0;
                width: 100%; height: 100%;
            }
        } /* end @supports */

        /* Form Elements Reset */
        input[type="search"]::-webkit-search-decoration,
        input[type="search"]::-webkit-search-cancel-button,
        input[type="search"]::-webkit-search-results-button,
        input[type="search"]::-webkit-search-results-decoration { 
            display: none; 
        }

        /* Banner Carousel Transitions */
        .banner-slide {
            transition: opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            pointer-events: none;
        }
        .banner-slide.active {
            opacity: 1;
            z-index: 10;
            pointer-events: auto;
        }

        /* Responsive aspect ratio untuk banner */
        #banner-carousel {
            aspect-ratio: 16 / 9;
            min-height: 180px;
        }
        @media (min-width: 768px) {
            #banner-carousel {
                aspect-ratio: 21 / 8;
            }
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1; 
        }
        ::-webkit-scrollbar-thumb {
            background: #d1d5db; 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af; 
        }
        
        /* WhatsApp Floating Button Animation */
        .wa-float {
            animation: pulse-green 2s infinite;
        }
        @keyframes pulse-green {
            0% { box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(37, 211, 102, 0); }
        }
    </style>
    <!-- Google Analytics — async = tidak memblokir render halaman -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-H26R0QZVBE"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', 'G-H26R0QZVBE');
    </script>
</head>
<?php
}

/**
 * Navbar Minimalis (Red & White Theme) - CLEAN VERSION
 * Menampilkan logo, pencarian, dan menu navigasi dengan desain flat.
 * * @param mysqli $conn Koneksi database.
 */
function navbar($conn) {
    // Setup Data Navbar
    $logo_name = get_setting($conn, 'store_logo');
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
    $logo_path = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    
    // Cek status login
    $is_logged_in = isset($_SESSION['user_id']);
    $user_name = $_SESSION['user_name'] ?? 'Tamu';
    
    // Hitung jumlah keranjang belanja (Real-time count)
    $cart_count = 0;
    if ($is_logged_in) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT SUM(quantity) as total_items FROM cart WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $cart_result = $stmt->get_result();
            $cart_data = $cart_result->fetch_assoc();
            $cart_count = (int)($cart_data['total_items'] ?? 0);
            $stmt->close();
        }
    } else {
        if (!empty($_SESSION['cart'])) {
            $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
        }
    }
?>
<header class="bg-white border-b border-gray-200 sticky top-0 z-50 shadow-sm">
    <!-- Top Bar Dihapus sesuai permintaan user untuk tampilan lebih bersih -->

    <!-- Main Navbar Content -->
    <nav class="container mx-auto px-4 sm:px-6 lg:px-8 h-16 sm:h-20 flex justify-between items-center gap-4">
        
        <!-- Logo Section -->
        <div class="flex-shrink-0 flex items-center">
            <a href="<?= BASE_URL ?>/" class="group flex items-center gap-2.5" title="Kembali ke Beranda">
                <img src="<?= $logo_path ?>" alt="Logo <?= htmlspecialchars($store_name) ?>" 
                     class="h-8 sm:h-10 w-auto object-contain transition-transform duration-300 group-hover:scale-105" 
                     onerror="this.onerror=null;this.src='<?= BASE_URL ?>/assets/images/settings/default_logo.png';">
                <div class="hidden sm:flex flex-col justify-center h-full">
                    <!-- Teks Traditional Marketplace dihapus -->
                    <span class="text-xl font-bold text-red-700 leading-none tracking-tight">WAROK KITE</span>
                </div>
            </a>
        </div>

        <!-- Search Bar Section (Desktop) -->
        <div class="hidden md:flex flex-grow max-w-2xl mx-6">
            <form action="<?= BASE_URL ?>/index.php" method="GET" class="w-full relative group">
                <input type="search" name="s" 
                       placeholder="Cari layangan, benang, atau aksesoris..." 
                       class="w-full h-11 pl-5 pr-12 rounded-full border border-gray-300 bg-gray-50 text-sm text-gray-800 focus:outline-none focus:border-red-700 focus:bg-white focus:ring-1 focus:ring-red-700 transition-all duration-200 placeholder-gray-400 shadow-sm"
                       autocomplete="off">
                <button type="submit" class="absolute right-0 top-0 h-11 w-12 flex items-center justify-center text-gray-400 hover:text-red-700 transition-colors duration-200 rounded-r-full">
                    <i class="fas fa-search text-lg"></i>
                </button>
            </form>
        </div>

        <!-- Menu Icons Section -->
        <div class="flex items-center gap-2 sm:gap-4">
            
            <!-- Mobile Search Toggle -->
            <button id="mobile-search-toggle" type="button" class="md:hidden p-2 text-gray-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-all">
                <i class="fas fa-search text-xl"></i>
            </button>

            <!-- Cart Icon -->
            <a href="<?= BASE_URL ?>/cart/cart.php" class="relative p-2 text-gray-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-all group" aria-label="Lihat Keranjang">
                <i class="fas fa-shopping-cart text-xl transition-transform group-hover:-rotate-6"></i>
                <?php if ($cart_count > 0): ?>
                    <span class="absolute top-1 right-0 flex h-4 w-4 items-center justify-center rounded-full bg-red-600 text-[10px] font-bold text-white ring-2 ring-white">
                        <?= $cart_count > 99 ? '99+' : $cart_count ?>
                    </span>
                <?php endif; ?>
            </a>

            <!-- User Menu -->
            <?php if ($is_logged_in): ?>
                <a href="<?= BASE_URL ?>/profile/profile.php" class="flex items-center gap-2 pl-2 pr-1 py-1 sm:px-3 sm:py-1.5 rounded-lg hover:bg-gray-50 border border-transparent hover:border-gray-200 transition-all text-gray-700">
                    <div class="hidden sm:flex flex-col items-end mr-1">
                        <span class="text-xs font-semibold text-gray-900 leading-none">Akun Saya</span>
                        <span class="text-[10px] text-gray-500 leading-none mt-1 truncate max-w-[80px]"><?= htmlspecialchars(explode(' ', $user_name)[0]) ?></span>
                    </div>
                    <i class="fas fa-user-circle text-2xl sm:text-3xl text-gray-300 hover:text-red-700 transition-colors"></i>
                </a>
            <?php else: ?>
                <div class="h-6 w-px bg-gray-300 mx-1 sm:mx-2 hidden sm:block"></div>
                <a href="<?= BASE_URL ?>/login/login.php" class="text-sm font-semibold text-gray-700 hover:text-red-700 px-3 py-2 transition-colors">
                    Masuk
                </a>
                <a href="<?= BASE_URL ?>/register/register.php" class="hidden sm:inline-flex items-center justify-center px-5 py-2 text-sm font-bold text-white bg-red-700 rounded-full hover:bg-red-800 transition-all shadow-sm hover:shadow hover:-translate-y-0.5">
                    Daftar
                </a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Mobile Search Bar (Expandable) -->
    <div id="mobile-search-bar" class="hidden md:hidden border-t border-gray-100 bg-white px-4 py-3 shadow-inner">
        <form action="<?= BASE_URL ?>/index.php" method="GET" class="relative">
            <input type="search" name="s" placeholder="Cari produk..." class="w-full h-10 pl-4 pr-10 rounded-full border border-gray-300 bg-gray-50 text-sm focus:outline-none focus:border-red-700 focus:bg-white shadow-sm">
            <button type="submit" class="absolute right-0 top-0 h-10 w-10 text-gray-500 hover:text-red-700 rounded-r-full">
                <i class="fas fa-search"></i>
            </button>
        </form>
    </div>
</header>

<script>
    // Simple logic for Mobile Search Toggle
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('mobile-search-toggle');
        const bar = document.getElementById('mobile-search-bar');
        const input = bar?.querySelector('input');

        if (toggle && bar) {
            toggle.addEventListener('click', () => {
                const isHidden = bar.classList.contains('hidden');
                bar.classList.toggle('hidden');
                if (isHidden && input) {
                    setTimeout(() => input.focus(), 100);
                }
            });
        }
    });
</script>
<?php
}

/**
 * Category Card (Minimalist Pill Style)
 * Menampilkan kategori dalam bentuk kartu kecil/pill yang bersih.
 */
function category_card($category) {
?>
    <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($category['id'])) ?>" 
       class="group flex flex-col items-center justify-center p-4 bg-white border border-gray-200 rounded-xl hover:border-red-600 hover:shadow-md transition-all duration-300 text-center h-full transform hover:-translate-y-1">
        <!-- Icon Placeholder -->
        <div class="w-12 h-12 mb-3 rounded-full bg-red-50 text-red-600 flex items-center justify-center group-hover:bg-red-600 group-hover:text-white transition-colors duration-300 shadow-sm">
            <i class="fas fa-tag text-lg"></i>
        </div>
        <span class="text-sm font-semibold text-gray-700 group-hover:text-red-700 transition-colors line-clamp-1">
            <?= htmlspecialchars($category['name']) ?>
        </span>
    </a>
<?php
}

/**
 * Product Card V2 (No Add to Cart on Index)
 * Desain: Border simple, clean whitespace, fokus ke gambar.
 */
function product_card($product) {
    // Kalkulasi Status Stok
    $stock = (int)($product['stock'] ?? 0);
    $is_out_of_stock = $stock <= 0;
    $sold_count = (int)($product['total_sold'] ?? 0);
    
    // Format Harga
    $price_display = format_rupiah($product['price']);
    
    // URL Produk - Pastikan mengarah ke product.php dengan benar
    $product_url = BASE_URL . '/product/product.php?id=' . urlencode(encode_id($product['id']));
?>
    <div class="group relative bg-white border border-gray-200 rounded-xl overflow-hidden hover:border-red-400 hover:shadow-lg transition-all duration-300 flex flex-col h-full">
        
        <!-- Image Section -->
        <a href="<?= $product_url ?>" class="block relative aspect-square overflow-hidden bg-gray-100">
            <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                 alt="<?= htmlspecialchars($product['name']) ?>" 
                 loading="lazy"
                 class="w-full h-full object-cover object-center transition-transform duration-500 group-hover:scale-110 <?= $is_out_of_stock ? 'grayscale opacity-70' : '' ?>"
                 onerror="this.onerror=null;this.src='<?= BASE_URL ?>/assets/images/no-image.png';">
            
            <!-- Overlay Stok Habis -->
            <?php if ($is_out_of_stock): ?>
                <div class="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center backdrop-blur-[2px]">
                    <span class="bg-red-600 text-white text-[10px] font-bold px-3 py-1.5 rounded shadow-sm uppercase tracking-wide border border-white/20">
                        Stok Habis
                    </span>
                </div>
            <?php endif; ?>
        </a>

        <!-- Content Section -->
        <div class="p-4 flex flex-col flex-grow">
            <!-- Kategori -->
            <?php if (isset($product['category_name'])): ?>
                <span class="text-[10px] text-gray-400 uppercase tracking-wider font-bold mb-1.5"><?= htmlspecialchars($product['category_name']) ?></span>
            <?php endif; ?>

            <!-- Nama Produk -->
            <h3 class="text-sm sm:text-base font-semibold text-gray-800 line-clamp-2 mb-2 group-hover:text-red-700 transition-colors leading-snug" title="<?= htmlspecialchars($product['name']) ?>">
                <a href="<?= $product_url ?>">
                    <?= htmlspecialchars($product['name']) ?>
                </a>
            </h3>

            <!-- Harga & Terjual -->
            <div class="mt-auto">
                <div class="flex items-end justify-between mb-3">
                    <span class="text-base sm:text-lg font-bold text-red-700"><?= $price_display ?></span>
                    <?php if ($sold_count > 0): ?>
                        <span class="text-[10px] text-gray-500 font-medium bg-gray-100 px-1.5 py-0.5 rounded"><?= $sold_count ?> Terjual</span>
                    <?php endif; ?>
                </div>

                <!-- Action Button -->
                <?php if ($is_out_of_stock): ?>
                     <button disabled class="w-full py-2 bg-gray-50 text-gray-400 text-xs font-semibold rounded-lg border border-gray-200 cursor-not-allowed">
                        Tidak Tersedia
                    </button>
                <?php else: ?>
                    <a href="<?= $product_url ?>" class="block w-full text-center py-2 rounded-lg border border-red-600 text-red-600 text-xs sm:text-sm font-semibold hover:bg-red-600 hover:text-white transition-all duration-200 shadow-sm">
                        Lihat Detail
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php
}

/**
 * Banner Carousel (Responsive)
 */
function banner_slide($conn) {
    // Mengambil banner aktif
    $result = $conn->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY created_at DESC");
    if (!$result || $result->num_rows === 0) return;

    $banners = $result->fetch_all(MYSQLI_ASSOC);
    $total = count($banners);
    $path_base = BASE_URL . '/assets/images/banner/';
?>
<div class="relative w-full mb-8 sm:mb-12 group">
    <!-- Container Aspect Ratio -->
    <div id="banner-carousel" class="relative w-full overflow-hidden rounded-2xl bg-gray-200 shadow-md" style="aspect-ratio: 16/9; min-height: 180px;">
        <?php foreach ($banners as $idx => $banner): ?>
            <div class="banner-slide <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>">
                <a href="<?= htmlspecialchars($banner['link_url'] ?: '#') ?>" class="block w-full h-full">
                    <img src="<?= $path_base . htmlspecialchars($banner['image']) ?>" 
                         alt="<?= htmlspecialchars($banner['title']) ?>" 
                         class="w-full h-full object-cover"
                         loading="<?= $idx === 0 ? 'eager' : 'lazy' ?>">
                </a>
            </div>
        <?php endforeach; ?>
        
        <!-- Navigation Arrows -->
        <?php if ($total > 1): ?>
            <button onclick="changeSlide(-1)" class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 p-3 rounded-full shadow-lg opacity-0 group-hover:opacity-100 transition-all duration-300 focus:outline-none transform hover:scale-110" aria-label="Previous">
                <i class="fas fa-chevron-left text-sm"></i>
            </button>
            <button onclick="changeSlide(1)" class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/90 hover:bg-white text-gray-800 p-3 rounded-full shadow-lg opacity-0 group-hover:opacity-100 transition-all duration-300 focus:outline-none transform hover:scale-110" aria-label="Next">
                <i class="fas fa-chevron-right text-sm"></i>
            </button>
            
            <!-- Dots Indicators -->
            <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex space-x-2 bg-black/20 px-3 py-1.5 rounded-full backdrop-blur-sm">
                <?php for ($i = 0; $i < $total; $i++): ?>
                    <button onclick="goToSlide(<?= $i ?>)" 
                            class="w-2 h-2 rounded-full transition-all duration-300 dot-indicator <?= $i === 0 ? 'bg-white w-6' : 'bg-white/60 hover:bg-white' ?>" 
                            aria-label="Slide <?= $i + 1 ?>"></button>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- Logic JS Slide -->
<script>
    let currSlide = 0;
    const slides = document.querySelectorAll('.banner-slide');
    const dots = document.querySelectorAll('.dot-indicator');
    const total = <?= $total ?>;
    let interval;

    function updateUI(idx) {
        slides.forEach(s => s.classList.remove('active'));
        slides[idx].classList.add('active');
        
        dots.forEach((d, i) => {
            if (i === idx) {
                d.className = "w-6 h-2 rounded-full transition-all duration-300 dot-indicator bg-white";
            } else {
                d.className = "w-2 h-2 rounded-full transition-all duration-300 dot-indicator bg-white/60 hover:bg-white";
            }
        });
        currSlide = idx;
    }

    function changeSlide(dir) {
        let next = (currSlide + dir + total) % total;
        updateUI(next);
        resetTimer();
    }

    function goToSlide(idx) {
        updateUI(idx);
        resetTimer();
    }

    function autoPlay() {
        changeSlide(1);
    }

    function resetTimer() {
        clearInterval(interval);
        if (total > 1) interval = setInterval(autoPlay, 6000);
    }

    if (total > 1) interval = setInterval(autoPlay, 6000);
</script>
<?php
}

/**
 * Footer Minimalis Updated with FAQ & WhatsApp Float
 */
function footer($conn) {
    // Ambil data settings
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
    $store_desc = get_setting($conn, 'store_description');
    $store_addr = get_setting($conn, 'store_address');
    $store_phone = get_setting($conn, 'store_phone');
    $store_email = get_setting($conn, 'store_email');
    
    // Social Media Links
    $fb = get_setting($conn, 'store_facebook');
    $tk = get_setting($conn, 'store_tiktok');
    $ig = get_setting($conn, 'store_instagram');

    // WhatsApp Link Logic
    $wa_link = '#';
    if ($store_phone) {
        $wa_number = preg_replace('/[^0-9]/', '', $store_phone);
        if (substr($wa_number, 0, 1) === '0') {
            $wa_number = '62' . substr($wa_number, 1);
        }
        $wa_message = urlencode("Halo, saya ingin bertanya seputar produk.");
        $wa_link = "https://wa.me/{$wa_number}?text={$wa_message}";
    }
?>
<footer class="bg-white border-t border-gray-200 mt-16 pt-12 pb-8 text-gray-600 text-sm">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-12 mb-12">
            
            <!-- Brand Column -->
            <div class="space-y-4">
                <h3 class="text-lg font-extrabold text-red-700 uppercase tracking-wide"><?= htmlspecialchars($store_name) ?></h3>
                <p class="leading-relaxed text-gray-500">
                    <?= htmlspecialchars($store_desc) ?>
                </p>
                <div class="flex space-x-4 pt-2">
                    <?php if ($fb): ?><a href="<?= $fb ?>" class="text-gray-400 hover:text-red-700 transition transform hover:scale-110"><i class="fab fa-facebook fa-lg"></i></a><?php endif; ?>
                    <?php if ($ig): ?><a href="<?= $ig ?>" class="text-gray-400 hover:text-red-700 transition transform hover:scale-110"><i class="fab fa-instagram fa-lg"></i></a><?php endif; ?>
                    <?php if ($tk): ?><a href="<?= $tk ?>" class="text-gray-400 hover:text-red-700 transition transform hover:scale-110"><i class="fab fa-tiktok fa-lg"></i></a><?php endif; ?>
                </div>
            </div>

            <!-- Links Column -->
            <div>
                <h4 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider">Jelajahi</h4>
                <ul class="space-y-2.5">
                    <li><a href="<?= BASE_URL ?>/" class="hover:text-red-700 transition-colors">Beranda</a></li>
                    <li><a href="<?= BASE_URL ?>/product/index.php" class="hover:text-red-700 transition-colors">Semua Produk</a></li>
                    <li><a href="<?= BASE_URL ?>/kategori/kategori.php" class="hover:text-red-700 transition-colors">Kategori</a></li>
                    <li><a href="<?= BASE_URL ?>/promo/" class="hover:text-red-700 transition-colors">Promo Spesial</a></li>
                </ul>
            </div>

            <!-- FAQ Column (UPDATED) -->
            <div>
                <h4 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider">FAQ</h4>
                <div class="space-y-4 text-xs leading-relaxed">
                    <div>
                        <p class="font-semibold text-gray-800">Bagaimana cara memesan produk?</p>
                        <p class="text-gray-500 mt-1">Pilih produk, tambahkan ke keranjang, lalu checkout. Isi alamat dan pilih pembayaran.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800">Metode pembayaran apa saja?</p>
                        <p class="text-gray-500 mt-1">Kami menerima transfer bank. Detail rekening akan muncul setelah checkout.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-800">Berapa lama pengiriman?</p>
                        <p class="text-gray-500 mt-1">Estimasi 3-7 hari kerja setelah pembayaran dikonfirmasi, tergantung lokasi.</p>
                    </div>
                    <div class="pt-1">
                        <a href="<?= BASE_URL ?>/help/faq.php" class="text-red-600 font-bold hover:underline">Lihat Semua Bantuan &rarr;</a>
                    </div>
                </div>
            </div>

            <!-- Contact Column -->
            <div>
                <h4 class="font-bold text-gray-900 mb-4 uppercase text-xs tracking-wider">Kontak</h4>
                <ul class="space-y-3">
                    <?php if ($store_addr): ?>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-map-marker-alt mt-1 text-red-700"></i>
                        <span class="leading-snug"><?= nl2br(htmlspecialchars($store_addr)) ?></span>
                    </li>
                    <?php endif; ?>
                    
                    <?php if ($store_phone): ?>
                    <li class="flex items-center gap-3">
                        <i class="fab fa-whatsapp text-green-600 text-lg"></i>
                        <span class="font-medium hover:text-green-600 cursor-pointer"><?= htmlspecialchars($store_phone) ?></span>
                    </li>
                    <?php endif; ?>

                    <?php if ($store_email): ?>
                    <li class="flex items-center gap-3">
                        <i class="fas fa-envelope text-red-700"></i>
                        <span class="hover:text-red-700 cursor-pointer"><?= htmlspecialchars($store_email) ?></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <!-- Copyright / Bottom Bar -->
        <div class="border-t border-gray-100 pt-8 flex flex-col md:flex-row justify-between items-center gap-4">
            <p class="text-xs text-gray-400">
                &copy; <?= date('Y') ?> <strong><?= htmlspecialchars($store_name) ?></strong>. All Rights Reserved.
            </p>
            <div class="flex items-center gap-4 opacity-70 grayscale hover:grayscale-0 transition-all">
                <i class="fab fa-cc-visa text-2xl text-blue-900" title="Visa"></i>
                <i class="fab fa-cc-mastercard text-2xl text-red-600" title="Mastercard"></i>
                <i class="fas fa-money-bill-wave text-2xl text-green-600" title="Transfer Bank"></i>
            </div>
        </div>
    </div>
    
    <!-- Floating WhatsApp Button -->
    <?php if ($store_phone): ?>
    <a href="<?= $wa_link ?>" target="_blank" class="fixed bottom-6 right-6 z-50 flex items-center justify-center w-14 h-14 bg-green-500 rounded-full shadow-lg hover:bg-green-600 text-white transition-all transform hover:scale-110 wa-float" title="Chat dengan Admin">
        <i class="fab fa-whatsapp text-3xl"></i>
    </a>
    <?php endif; ?>
</footer>
<?php
}
?>