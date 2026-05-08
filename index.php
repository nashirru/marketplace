<?php
/**
 * orders_v2.php (Technically index.php v2 - STABILIZED)
 * --------------------------------------------------------------------------
 * Halaman Utama Marketplace "Warok Kite"
 * Desain: Red-Themed Minimalist
 * UPDATE V3.3: Menambahkan Unified Session Config untuk mencegah konflik dengan Admin Subdomain.
 * --------------------------------------------------------------------------
 */

// --- BLOCK 1: UNIFIED SESSION CONFIGURATION (WAJIB ADA DI SEMUA FILE UTAMA) ---
// Ini mencegah "Cookie War" antara warokkite.com dan admin.warokkite.com
if (session_status() == PHP_SESSION_NONE) {
    $host = $_SERVER['HTTP_HOST'];
    
    // Konfigurasi ini HARUS SAMA PERSIS dengan di api_helper.php
    $cookie_params = [
        'lifetime' => 86400 * 30, // 30 Hari
        'path' => '/',            
        'domain' => '',           
        'secure' => true,
        'httponly' => false,      
        'samesite' => 'Lax'       
    ];

    // Deteksi apakah request melewati HTTPS proxy (ngrok, dll)
    $is_https_proxy = (
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );

    // Deteksi Environment (Sama seperti Admin)
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $cookie_params['secure'] = false;
        $cookie_params['samesite'] = 'Lax';
        $cookie_params['domain'] = ''; 
    } else if (strpos($host, 'ngrok') !== false || strpos($host, 'ngrok-free') !== false) {
        $cookie_params['domain'] = ''; 
        $cookie_params['secure'] = $is_https_proxy; // true jika ngrok pakai HTTPS
        $cookie_params['samesite'] = $is_https_proxy ? 'None' : 'Lax';
    } else {
        // Production: Force Wildcard Domain (.warokkite.com)
        if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $host, $regs)) {
            $cookie_params['domain'] = '.' . $regs['domain'];
        }
        $cookie_params['secure'] = $is_https_proxy || isset($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443;
    }

    // Terapkan Aturan Cookie
    session_set_cookie_params([
        'lifetime' => $cookie_params['lifetime'],
        'path' => $cookie_params['path'],
        'domain' => $cookie_params['domain'],
        'secure' => $cookie_params['secure'],
        'httponly' => $cookie_params['httponly'],
        'samesite' => $cookie_params['samesite']
    ]);
    
    // OPSI: Gunakan nama sesi yang berbeda untuk User vs Admin agar login tidak campur
    // Tapi cookie domainnya tetap harus harmonis.
    session_name('WAROK_MAIN_SESSION'); 
    session_start();
}
// --- END SESSION CONFIG ---

// 1. Memuat Dependensi Inti
require_once 'config/config.php';
require_once 'sistem/sistem.php';
require_once 'partial/partial.php'; // Menggunakan partial V2.2

// 2. Load Pengaturan Toko ke Memory
load_settings($conn);

// 3. Logika Pencarian & Filter
$search_query = isset($_GET['s']) ? trim(sanitize_input($_GET['s'])) : '';
$is_searching = !empty($search_query);

// 4. Persiapan Data Produk (Query Utama)
$products = [];
// Query dasar join dengan order_items untuk menghitung 'total_sold' secara dinamis
$base_product_sql = "
    SELECT 
        p.id, 
        p.name, 
        p.price, 
        p.image, 
        p.stock, 
        p.created_at,
        c.name as category_name,
        COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id AND o.status IN ('completed', 'shipped', 'processed')
";

// Modifikasi Query berdasarkan kondisi Search vs Normal
if ($is_searching) {
    // Mode Pencarian: Filter berdasarkan nama
    $sql = $base_product_sql . " 
        WHERE p.name LIKE ? AND p.is_active = 1 
        GROUP BY p.id 
        ORDER BY (p.stock > 0) DESC, total_sold DESC, p.created_at DESC";
        // Logic Order: Stok Ada dulu -> Paling Laris -> Paling Baru
    
    $stmt = $conn->prepare($sql);
    $param_search = "%{$search_query}%";
    $stmt->bind_param("s", $param_search);
} else {
    // Mode Default (Beranda): Tampilkan produk terbaru/terpopuler
    // Limit 15 produk agar grid terlihat penuh (5 kolom x 3 baris)
    $sql = $base_product_sql . " 
        WHERE p.is_active = 1 
        GROUP BY p.id 
        ORDER BY (p.stock > 0) DESC, p.created_at DESC 
        LIMIT 15";
    
    $stmt = $conn->prepare($sql);
}

// Eksekusi Query Produk
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    $stmt->close();
}

// 5. Persiapan Data Kategori (Hanya jika tidak sedang mencari)
$categories = [];
if (!$is_searching) {
    // Ambil kategori yang memiliki produk aktif saja
    $cat_sql = "
        SELECT c.*, COUNT(p.id) as product_count
        FROM categories c
        JOIN products p ON c.id = p.category_id AND p.is_active = 1
        GROUP BY c.id
        ORDER BY c.name ASC
        LIMIT 6
    ";
    $cat_result = $conn->query($cat_sql);
    if ($cat_result) {
        while ($row = $cat_result->fetch_assoc()) {
            $categories[] = $row;
        }
    }
}

// 6. Setup Meta Data Halaman
$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
if ($is_searching) {
    $page_title = 'Hasil Pencarian: "' . htmlspecialchars($search_query) . '"';
} else {
    $page_title = 'Beranda - Pusat Layangan Tradisional';
}
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
    
    <!-- Memanggil Fungsi Head dari Partial V2.2 -->
    <?php page_head($page_title, $conn); ?>

    <body class="bg-white flex flex-col min-h-screen text-gray-800 antialiased">

        <!-- Navbar Section -->
        <?php navbar($conn); ?>
        
        <!-- Flash Message Notification (Untuk feedback user) -->
        <div class="container mx-auto px-4 mt-4">
            <?php flash_message(); ?>
        </div>

        <!-- Main Content Area -->
        <main class="flex-grow w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            
            <!-- A. Banner Section (Hanya tampil di Beranda) -->
            <?php if (!$is_searching): ?>
                <?php banner_slide($conn); ?>
                
                <!-- B. Kategori Section -->
                <?php if (!empty($categories)): ?>
                <section class="mb-14">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-900 tracking-tight">
                            Kategori Pilihan
                        </h2>
                        <!-- Link diperbaiki ke kategori.php -->
                        <a href="<?= BASE_URL ?>/kategori/kategori.php" class="text-sm font-medium text-red-600 hover:text-red-800 transition flex items-center group">
                            Lihat Semua <i class="fas fa-arrow-right ml-1 text-xs transform group-hover:translate-x-1 transition-transform"></i>
                        </a>
                    </div>
                    
                    <!-- Grid Kategori: Responsive 2 -> 3 -> 6 -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-4">
                        <?php foreach ($categories as $category): ?>
                            <?php category_card($category); ?>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>
            <?php endif; ?>

            <!-- C. Produk Section (Hasil Cari atau Terbaru) -->
            <section class="mb-12">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-8 gap-4">
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-900 tracking-tight relative pl-4 border-l-4 border-red-600">
                        <?= $is_searching ? 'Menampilkan Hasil Pencarian' : 'Rekomendasi Terbaru' ?>
                    </h2>
                    
                    <?php if ($is_searching): ?>
                        <a href="<?= BASE_URL ?>/" class="text-sm text-gray-500 hover:text-red-600 transition">
                            <i class="fas fa-times-circle mr-1"></i> Reset Pencarian
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Grid Produk: Mobile First (2 kolom) -> Tablet (3) -> Desktop (4/5) -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6 lg:gap-8">
                    
                    <?php if (!empty($products)): ?>
                        <?php foreach ($products as $product): ?>
                            <!-- Memanggil product_card dari Partial V2 -->
                            <?php product_card($product); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Empty State Design -->
                        <div class="col-span-full py-16 text-center border-2 border-dashed border-gray-200 rounded-xl bg-gray-50">
                            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-gray-100 mb-4 text-gray-400">
                                <i class="fas fa-box-open text-4xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900">Produk tidak ditemukan</h3>
                            <p class="text-gray-500 mt-2 max-w-md mx-auto">
                                Maaf, kami tidak menemukan produk yang cocok dengan kata kunci 
                                <span class="font-bold text-red-600">"<?= htmlspecialchars($search_query) ?>"</span>.
                            </p>
                            <a href="<?= BASE_URL ?>/" class="inline-block mt-6 px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition shadow-sm font-medium">
                                Kembali ke Beranda
                            </a>
                        </div>
                    <?php endif; ?>

                </div>

                <!-- Load More Button -->
                <?php if (!$is_searching && count($products) >= 15): ?>
                <div class="mt-14 text-center">
                    <a href="<?= BASE_URL ?>/product/index.php" class="inline-flex items-center justify-center px-8 py-3 border border-gray-300 shadow-sm text-sm font-bold rounded-full text-gray-700 bg-white hover:bg-gray-50 hover:text-red-600 hover:border-red-300 transition-all duration-300">
                        Jelajahi Produk Lainnya
                    </a>
                </div>
                <?php endif; ?>
            </section>
            
        </main>

        <!-- Footer Section -->
        <?php footer($conn); ?>

    </body>
</html>