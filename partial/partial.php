<?php
// File: partial/partial.php
// Berisi fungsi-fungsi untuk menampilkan bagian-bagian template yang berulang (reusable).

/**
 * Menampilkan <head> HTML dengan optimasi SEO.
 * @param string $page_title Judul halaman (Wajib).
 * @param mysqli $conn Koneksi database (Wajib).
 * @param string|null $seo_desc Deskripsi SEO khusus untuk halaman ini (Opsional).
 * @param string|null $seo_keywords Keywords SEO khusus (Opsional, pisahkan dengan koma).
 * @param string|null $og_image URL gambar khusus untuk Open Graph (Opsional).
 */
function page_head($page_title, $conn, $seo_desc = null, $seo_keywords = null, $og_image = null) {
    // Pastikan BASE_URL sudah terdefinisi
    if (!defined('BASE_URL')) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        define('BASE_URL', $protocol . $_SERVER['HTTP_HOST']);
    }

    // Ambil data dari settings untuk SEO default
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
    $store_description = get_setting($conn, 'store_description') ?? 'Marketplace terbaik untuk produk khas Ponorogo.';
    $logo_name = get_setting($conn, 'store_logo');
    $default_og_image = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    $favicon_path = $default_og_image; // Gunakan logo sebagai favicon

    // Tentukan nilai SEO final
    $final_title = htmlspecialchars($page_title) . ' | ' . htmlspecialchars($store_name);
    $final_desc = htmlspecialchars($seo_desc ?: $store_description);
    // Default keywords, bisa ditambahkan dari parameter
    $default_keywords = "warok kite, marketplace, ponorogo, toko online, produk lokal";
    $final_keywords = htmlspecialchars($seo_keywords ? $seo_keywords . ', ' . $default_keywords : $default_keywords);
    $final_og_image = $og_image ?: $default_og_image;
    // URL Halaman saat ini untuk canonical dan OG
    $current_url = BASE_URL . $_SERVER['REQUEST_URI'];

?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $final_title ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="<?= $final_desc ?>">
    <meta name="keywords" content="<?= $final_keywords ?>">
    <link rel="canonical" href="<?= htmlspecialchars($current_url) ?>" />

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars($current_url) ?>">
    <meta property="og:title" content="<?= $final_title ?>">
    <meta property="og:description" content="<?= $final_desc ?>">
    <meta property="og:image" content="<?= htmlspecialchars($final_og_image) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($store_name) ?>">

    <!-- Twitter -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="<?= htmlspecialchars($current_url) ?>">
    <meta property="twitter:title" content="<?= $final_title ?>">
    <meta property="twitter:description" content="<?= $final_desc ?>">
    <meta property="twitter:image" content="<?= htmlspecialchars($final_og_image) ?>">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon_path) ?>">

    <!-- Stylesheets and Scripts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <style>
        body { font-family: 'Inter', sans-serif; }
        .line-clamp-2 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
        }
    </style>
</head>
<?php
}

// Fungsi navbar tetap sama
function navbar($conn) {
    // Pastikan BASE_URL sudah terdefinisi
    if (!defined('BASE_URL')) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        define('BASE_URL', $protocol . $_SERVER['HTTP_HOST']);
    }

    $logo_name = get_setting($conn, 'store_logo');
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';

    $logo_path = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    $is_logged_in = isset($_SESSION['user_id']);
    $user_name = $_SESSION['user_name'] ?? 'Pengguna';
    $cart_count = 0;

    if ($is_logged_in) {
        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT SUM(quantity) as total_items FROM cart WHERE user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $cart_result = $stmt->get_result();
        $cart_data = $cart_result->fetch_assoc();
        $cart_count = (int)($cart_data['total_items'] ?? 0);
        $stmt->close();
    } else {
        if (!empty($_SESSION['cart'])) {
            $cart_count = array_sum(array_column($_SESSION['cart'], 'quantity'));
        }
    }
?>
<header class="bg-white shadow-md sticky top-0 z-20">
  <nav class="container mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">
      <div class="flex-shrink-0">
          <a href="<?= BASE_URL ?>/" class="flex items-center space-x-2 text-xl md:text-2xl font-bold text-gray-900">
              <img src="<?= $logo_path ?>" alt="Logo Toko" class="h-8 md:h-10 w-auto rounded-lg object-contain" onerror="this.onerror=null;this.src='<?= BASE_URL ?>/assets/images/settings/default_logo.png';">
              <span class="hidden sm:inline"><?= htmlspecialchars($store_name) ?></span>
          </a>
      </div>
      <div class="flex-grow max-w-xl mx-4 hidden md:block">
          <form action="<?= BASE_URL ?>/index.php" method="GET" class="w-full">
              <div class="relative">
                  <input type="search" name="s" placeholder="Cari produk di <?= htmlspecialchars($store_name) ?>..." class="w-full border-2 border-gray-200 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">
                  <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600"><i class="fas fa-search"></i></button>
              </div>
          </form>
      </div>
      <div class="flex items-center space-x-2 sm:space-x-4 text-gray-700 font-medium">
          <button id="mobile-search-toggle" type="button" class="md:hidden p-2 rounded-full hover:bg-gray-100 transition">
              <i class="fas fa-search text-xl"></i>
          </button>
          <a href="<?= BASE_URL ?>/cart/cart.php" class="relative p-2 rounded-full hover:bg-gray-100 transition">
              <i class="fas fa-shopping-cart text-xl"></i>
              <span id="cart-count-badge" style="<?= $cart_count > 0 ? 'display: inline-flex;' : 'display: none;' ?>" class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold w-5 h-5 rounded-full items-center justify-center"><?= $cart_count ?></span>
          </a>
          <?php if ($is_logged_in): ?>
          <a href="<?= BASE_URL ?>/profile/profile.php" class="flex items-center space-x-2 p-2 rounded-full hover:bg-gray-100 transition">
              <span class="hidden sm:inline text-sm">Hai, <?= htmlspecialchars(explode(' ', $user_name)[0]) ?></span>
              <i class="fas fa-user-circle text-2xl text-indigo-600"></i>
          </a>
          <?php else: ?>
          <a href="<?= BASE_URL ?>/login/login.php" class="px-3 py-2 text-sm sm:text-base bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">Masuk</a>
          <?php endif; ?>
      </div>
  </nav>
  <div id="mobile-search-bar" class="md:hidden px-4 pb-3 border-t hidden">
      <form action="<?= BASE_URL ?>/index.php" method="GET" class="w-full">
          <div class="relative">
              <input type="search" name="s" placeholder="Cari produk..." class="w-full border-2 border-gray-200 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">
              <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600"><i class="fas fa-search"></i></button>
          </div>
      </form>
  </div>
</header>
<script>
    // Script navbar tetap sama
    document.addEventListener('DOMContentLoaded', function() {
        const toggleButton = document.getElementById('mobile-search-toggle');
        const searchBar = document.getElementById('mobile-search-bar');
        if (toggleButton && searchBar) {
            toggleButton.addEventListener('click', function() {
                searchBar.classList.toggle('hidden');
                if (!searchBar.classList.contains('hidden')) {
                    const searchInput = searchBar.querySelector('input[type=search]');
                    if (searchInput) { searchInput.focus(); }
                }
            });
        }
    });
</script>
<?php
}

// Fungsi category_card tetap sama
function category_card($category) {
    ?>
    <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($category['id'])) ?>"
       class="block text-center group">
       <!-- Tombol dengan styling baru -->
        <div class="px-4 py-2 bg-gradient-to-r from-indigo-500 to-purple-500 text-white rounded-xl shadow-md group-hover:shadow-lg group-hover:from-indigo-600 group-hover:to-purple-600 transition-all duration-300 transform group-hover:-translate-y-1">
            <h3 class="font-semibold text-xs sm:text-sm leading-tight truncate">
                <?= htmlspecialchars($category['name']) ?>
            </h3>
             <!-- Opsi: Tampilkan jumlah terjual jika ada -->
            <?php /* if (isset($category['total_sold'])): ?>
                <span class="text-xs opacity-75 mt-0.5 block"><?= (int)$category['total_sold'] ?> terjual</span>
            <?php endif; */ ?>
        </div>
    </a>
    <?php
}

// --- ✅ PERUBAHAN: Fungsi product_card (Logika Stok Habis & Limit Guest) ---
function product_card($product) {
    $sold_count = (int)($product['total_sold'] ?? 0);
    $is_out_of_stock = (int)($product['stock'] ?? 0) <= 0;

    // ✅ PERBAIKAN: Logika Cek Limit Kuota untuk GUEST (User Belum Login)
    $is_limit_reached_guest = false;
    $user_id = $_SESSION['user_id'] ?? 0;
    $limit = (int)($product['purchase_limit'] ?? 0);

    // Cek limit HANYA jika user adalah GUEST, produk ADA STOK, dan punya LIMIT
    if ($user_id == 0 && !$is_out_of_stock && $limit > 0) {
        // Panggil fungsi get_quantity_in_cart
        // $conn (arg 1) diisi null karena tidak akan dipakai jika $user_id (arg 2) adalah 0
        $quantity_in_cart = get_quantity_in_cart(null, 0, $product['id']); 
        
        if ($quantity_in_cart >= $limit) {
            $is_limit_reached_guest = true;
        }
    }
    // Catatan: Pengecekan limit untuk user > 0 (login) tidak bisa dilakukan di sini
    // karena fungsi ini tidak memiliki akses ke $conn untuk cek riwayat order.
    // Pengecekan itu ada di `add_to_cart.php` dan `cart.php`.

    ?>
    <!-- Tambahkan 'opacity-60 grayscale' jika stok habis -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden group border hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col <?= $is_out_of_stock ? 'opacity-60 grayscale' : '' ?>">
        
        <!-- Tambahkan 'relative' untuk positioning badge stok -->
        <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" class="block overflow-hidden relative">
            <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>"
                 alt="<?= htmlspecialchars($product['name']) ?>"
                 class="h-36 sm:h-44 w-full object-cover group-hover:scale-105 transition-transform duration-300">
            
            <!-- Badge "Stok Habis" -->
            <?php if ($is_out_of_stock): ?>
                <span class="absolute top-2 right-2 bg-red-600 bg-opacity-80 text-white text-xs font-bold px-2 py-1 rounded-md shadow">
                    STOK HABIS
                </span>
            <?php endif; ?>
        </a>
        
        <div class="p-2 sm:p-3 flex-grow flex flex-col">
            <h3 class="text-sm font-semibold text-gray-800 line-clamp-2 flex-grow">
                <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" class="hover:text-indigo-600">
                    <?= htmlspecialchars($product['name']) ?>
                </a>
            </h3>
            <div class="mt-1.5">
                <p class="text-base font-bold text-indigo-600"><?= format_rupiah($product['price']) ?></p>
                <p class="text-xs text-gray-500 mt-1"><?= $sold_count ?> terjual</p>
            </div>
            <div class="mt-2">
                 <!-- ✅ Logika Tombol Berdasarkan Stok & Limit Guest -->
                <?php if ($is_out_of_stock): ?>
                    <!-- Tombol Stok Habis (Disabled) -->
                    <button type="button" disabled class="w-full flex items-center justify-center px-3 py-1.5 bg-gray-200 text-gray-500 rounded-lg text-sm font-semibold cursor-not-allowed">
                        <i class="fas fa-times-circle mr-2"></i> Stok Habis
                    </button>
                <?php elseif ($is_limit_reached_guest): ?>
                    <!-- ✅ Tombol Limit Guest Tercapai (Disabled) -->
                    <button type="button" disabled class="w-full flex items-center justify-center px-3 py-1.5 bg-yellow-100 text-yellow-700 rounded-lg text-sm font-semibold cursor-not-allowed" title="Anda sudah mencapai limit produk ini di keranjang">
                        <i class="fas fa-exclamation-triangle mr-2"></i> Kuota Penuh
                    </button>
                <?php else: ?>
                    <!-- Tombol Tambah Keranjang (Normal) -->
                    <form action="<?= BASE_URL ?>/cart/add_to_cart.php" method="POST" class="w-full">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <input type="hidden" name="quantity" value="1">
                        <button type="submit" class="w-full flex items-center justify-center px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-500 hover:text-white transition-colors duration-200 text-sm font-semibold" title="Tambah ke Keranjang">
                            <i class="fas fa-cart-plus mr-2"></i> Tambah
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// --- Fungsi footer (Logika Link WhatsApp) ---
function footer($conn) {
    // Pastikan BASE_URL sudah terdefinisi
    if (!defined('BASE_URL')) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        define('BASE_URL', $protocol . $_SERVER['HTTP_HOST']);
    }

    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
    $store_description = get_setting($conn, 'store_description');
    $store_facebook = get_setting($conn, 'store_facebook');
    $store_tiktok = get_setting($conn, 'store_tiktok');
    $store_email = get_setting($conn, 'store_email');
    $store_address = get_setting($conn, 'store_address');
    $store_phone = get_setting($conn, 'store_phone'); // Ambil nomor telepon

    // Siapkan link WhatsApp dengan template pesan
    $wa_link = '#'; // Default jika tidak ada nomor
    if ($store_phone) {
        $wa_number = preg_replace('/[^0-9]/', '', $store_phone);
        // Pastikan nomor diawali 62 jika itu format lokal (misal 08...)
        if (substr($wa_number, 0, 1) === '0') {
            $wa_number = '62' . substr($wa_number, 1);
        }
        $wa_message_template = "Halo " . htmlspecialchars($store_name) . ", saya tertarik dengan salah satu produk Anda. Bisakah saya bertanya?";
        $wa_message = urlencode($wa_message_template);
        $wa_link = "https://wa.me/{$wa_number}?text={$wa_message}";
    }

    // Data FAQ (diambil dari help.php)
    $faq_items = [
        [
            'q' => 'Bagaimana cara memesan produk?',
            'a' => 'Pilih produk, tambahkan ke keranjang, lalu checkout. Isi alamat dan pilih pembayaran.'
        ],
        [
            'q' => 'Metode pembayaran apa saja?',
            'a' => 'Kami menerima transfer bank. Detail rekening akan muncul setelah checkout.'
        ],
        [
            'q' => 'Berapa lama pengiriman?',
            'a' => 'Estimasi 3-7 hari kerja setelah pembayaran dikonfirmasi, tergantung lokasi.'
        ]
    ];
?>
<footer class="bg-gray-800 text-gray-300 mt-16">
  <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-12">
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
          <!-- Kolom 1: Tentang Toko -->
          <div>
              <h3 class="text-xl font-bold mb-4 text-indigo-400"><?= htmlspecialchars($store_name) ?></h3>
              <p class="text-sm leading-relaxed"><?= htmlspecialchars($store_description) ?></p>
               <!-- Sosmed dipindah ke sini -->
              <div class="flex space-x-4 mt-5">
                <?php if ($store_facebook): ?>
                  <a href="<?= htmlspecialchars($store_facebook) ?>" target="_blank" rel="noopener noreferrer" class=" hover:text-indigo-400 transition" aria-label="Facebook"><i class="fab fa-facebook fa-lg"></i></a>
                <?php endif; ?>
                <?php if ($store_tiktok): ?>
                  <a href="<?= htmlspecialchars($store_tiktok) ?>" target="_blank" rel="noopener noreferrer" class=" hover:text-indigo-400 transition" aria-label="Tiktok"><i class="fab fa-tiktok fa-lg"></i></a>
                <?php endif; ?>
                 <?php if ($store_email): ?>
                  <a href="mailto:<?= htmlspecialchars($store_email) ?>" class=" hover:text-indigo-400 transition" aria-label="Email"><i class="fas fa-envelope fa-lg"></i></a>
                <?php endif; ?>
                 <?php if ($store_phone): ?>
                  <!-- Terapkan $wa_link di sini -->
                  <a href="<?= $wa_link ?>" target="_blank" rel="noopener noreferrer" class=" hover:text-indigo-400 transition" aria-label="WhatsApp"><i class="fab fa-whatsapp fa-lg"></i></a>
                <?php endif; ?>
              </div>
          </div>

          <!-- Kolom 2: Tautan Cepat -->
          <div>
              <h3 class="text-lg font-semibold mb-4 text-indigo-400">Tautan Cepat</h3>
              <ul class="space-y-2 text-sm">
                  <li><a href="<?= BASE_URL ?>/" class="hover:text-white transition">Beranda</a></li>
                  <li><a href="<?= BASE_URL ?>/product/" class="hover:text-white transition">Semua Produk</a></li>
                  <li><a href="<?= BASE_URL ?>/kategori/" class="hover:text-white transition">Kategori</a></li>
                  <li><a href="<?= BASE_URL ?>/cart/cart.php" class="hover:text-white transition">Keranjang</a></li>
                   <li><a href="<?= BASE_URL ?>/profile/profile.php" class="hover:text-white transition">Profil Saya</a></li>
                   <li><a href="<?= BASE_URL ?>/help/help.php" class="hover:text-white transition">Pusat Bantuan</a></li>
              </ul>
          </div>

          <!-- Kolom 3: FAQ -->
          <div>
              <h3 class="text-lg font-semibold mb-4 text-indigo-400">FAQ</h3>
              <ul class="space-y-3 text-sm">
                <?php foreach ($faq_items as $item): ?>
                  <li>
                      <p class="font-medium text-gray-100"><?= htmlspecialchars($item['q']) ?></p>
                      <p class="text-xs mt-1"><?= htmlspecialchars($item['a']) ?></p>
                  </li>
                <?php endforeach; ?>
                 <li><a href="<?= BASE_URL ?>/help/help.php" class="text-xs text-indigo-400 hover:text-indigo-300 font-medium">Lihat Semua Bantuan &rarr;</a></li>
              </ul>
          </div>

          <!-- Kolom 4: Kontak Kami -->
          <div>
              <h3 class="text-lg font-semibold mb-4 text-indigo-400">Kontak Kami</h3>
              <ul class="space-y-2 text-sm">
                <?php if ($store_phone): ?>
                 <li class="flex items-start">
                     <i class="fas fa-phone-alt fa-fw mr-2 mt-1 text-indigo-400"></i>
                     <!-- Terapkan $wa_link di sini juga -->
                     <a href="<?= $wa_link ?>" target="_blank" rel="noopener noreferrer" class="hover:text-white transition"><?= htmlspecialchars($store_phone) ?></a>
                 </li>
                <?php endif; ?>
                <?php if ($store_email): ?>
                  <li class="flex items-start">
                      <i class="fas fa-envelope fa-fw mr-2 mt-1 text-indigo-400"></i>
                      <a href="mailto:<?= htmlspecialchars($store_email) ?>" class="hover:text-white transition"><?= htmlspecialchars($store_email) ?></a>
                  </li>
                <?php endif; ?>
                <?php if ($store_address): ?>
                  <li class="flex items-start">
                      <i class="fas fa-map-marker-alt fa-fw mr-2 mt-1 text-indigo-400"></i>
                      <span class="leading-relaxed"><?= nl2br(htmlspecialchars($store_address)) ?></span>
                  </li>
                <?php endif; ?>
              </ul>
          </div>

      </div>
      <div class="mt-10 pt-6 border-t border-gray-700 text-center">
          <p class="text-sm">&copy; <?= date("Y") ?> <?= htmlspecialchars($store_name) ?>. All rights reserved.</p>
      </div>
  </div>
</footer>
<?php
}

// Fungsi banner_slide tetap sama
function banner_slide($conn) {
    $result = $conn->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY created_at DESC");
    if (!$result || $result->num_rows === 0) {
        return; // Jangan tampilkan apa pun jika tidak ada banner
    }
    $banners = $result->fetch_all(MYSQLI_ASSOC);
    $total_banners = count($banners);
    $banner_paths = BASE_URL . '/assets/images/banner/';
?>
<style>.banner-slide{transition:opacity .5s ease-in-out;position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;pointer-events:none}.banner-slide.active{opacity:1;z-index:10;pointer-events:auto}</style>
<div class="container mx-auto px-4 mt-4 sm:mt-6">
    <div id="banner-carousel" class="relative w-full overflow-hidden rounded-xl shadow-lg aspect-[21/9] bg-gray-200"> {/* Tambah bg-gray-200 */}
        <?php foreach ($banners as $index => $banner): ?>
        <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
            <a href="<?= htmlspecialchars($banner['link_url'] ?: '#') ?>" class="block h-full">
                <img src="<?= $banner_paths . htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title']) ?>" class="w-full h-full object-cover">
            </a>
        </div>
        <?php endforeach; ?>
        <?php if ($total_banners > 1): ?>
        <button type="button" onclick="changeSlide(-1)" class="absolute top-1/2 left-2 sm:left-4 z-10 -translate-y-1/2 p-2 sm:p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white focus:outline-none"><i class="fas fa-chevron-left"></i></button>
        <button type="button" onclick="changeSlide(1)" class="absolute top-1/2 right-2 sm:right-4 z-10 -translate-y-1/2 p-2 sm:p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white focus:outline-none"><i class="fas fa-chevron-right"></i></button>
        <div class="absolute bottom-4 left-0 right-0 z-10 flex justify-center space-x-2">
            <?php for ($i = 0; $i < $total_banners; $i++): ?><button class="w-3 h-3 rounded-full bg-white bg-opacity-50 hover:bg-opacity-100 dot-indicator <?= $i === 0 ? 'bg-opacity-100' : '' ?> focus:outline-none" onclick="goToSlide(<?= $i ?>)"></button><?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
// Script banner slide tetap sama
let currentSlide=0;const slides=document.querySelectorAll('.banner-slide'),dots=document.querySelectorAll('.dot-indicator'),totalSlides=<?=$total_banners?>;let slideInterval;function showSlide(e){slides.forEach((t,i)=>{t.classList.remove('active');t.style.zIndex=i===e?10:0}),dots.forEach(t=>{t.classList.remove('bg-opacity-100'),t.classList.add('bg-opacity-50')}),slides[e].classList.add('active'),dots[e].classList.remove('bg-opacity-50'),dots[e].classList.add('bg-opacity-100'),currentSlide=e}function changeSlide(e){let t=(currentSlide+e+totalSlides)%totalSlides;showSlide(t);resetInterval()}function goToSlide(e){showSlide(e);resetInterval()}function autoSlide(){let e=(currentSlide+1)%totalSlides;showSlide(e)}function resetInterval(){clearInterval(slideInterval);if(totalSlides>1){slideInterval=setInterval(autoSlide,5e3)}}if(totalSlides>1){slideInterval=setInterval(autoSlide,5e3)};
</script>
<?php
}

?>