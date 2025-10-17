<?php
// File: partial/partial.php

function navbar($conn) {
    cancel_overdue_orders($conn);

    $logo_name = get_setting($conn, 'store_logo'); 
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite Marketplace';

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

    echo '<header class="bg-white shadow-md sticky top-0 z-20">';
    echo '  <nav class="container mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">';
    echo '      <div class="flex-shrink-0">';
    echo '          <a href="' . BASE_URL . '/" class="flex items-center space-x-2 text-xl md:text-2xl font-bold text-gray-900">';
    echo '              <img src="' . $logo_path . '" alt="Logo Toko" class="h-8 md:h-10 w-auto rounded-lg object-contain" onerror="this.onerror=null;this.src=\'' . BASE_URL . '/assets/images/settings/default_logo.png\';">';
    echo '              <span class="hidden sm:inline">' . htmlspecialchars($store_name) . '</span>';
    echo '          </a>';
    echo '      </div>';
    echo '      <div class="flex-grow max-w-xl mx-4 hidden md:block">';
    echo '          <form action="' . BASE_URL . '/index.php" method="GET" class="w-full">';
    echo '              <div class="relative">';
    echo '                  <input type="search" name="s" placeholder="Cari produk di Warok Kite..." class="w-full border-2 border-gray-200 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">';
    echo '                  <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600"><i class="fas fa-search"></i></button>';
    echo '              </div>';
    echo '          </form>';
    echo '      </div>';
    echo '      <div class="flex items-center space-x-2 sm:space-x-4 text-gray-700 font-medium">';
    echo '          <button id="mobile-search-toggle" type="button" class="md:hidden p-2 rounded-full hover:bg-gray-100 transition">';
    echo '              <i class="fas fa-search text-xl"></i>';
    echo '          </button>';
    echo '          <a href="' . BASE_URL . '/cart/cart.php" class="relative p-2 rounded-full hover:bg-gray-100 transition">';
    echo '              <i class="fas fa-shopping-cart text-xl"></i>';
    $badge_style = $cart_count > 0 ? 'display: inline-flex;' : 'display: none;';
    echo '              <span id="cart-count-badge" style="' . $badge_style . '" class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold w-5 h-5 rounded-full items-center justify-center">' . $cart_count . '</span>';
    echo '          </a>';
    if ($is_logged_in) {
        echo '      <a href="' . BASE_URL . '/profile/profile.php" class="flex items-center space-x-2 p-2 rounded-full hover:bg-gray-100 transition">';
        echo '          <span class="hidden sm:inline text-sm">Hai, ' . htmlspecialchars(explode(' ', $user_name)[0]) . '</span>';
        echo '          <i class="fas fa-user-circle text-2xl text-indigo-600"></i>';
        echo '      </a>';
    } else {
        echo '          <a href="' . BASE_URL . '/login/login.php" class="px-3 py-2 text-sm sm:text-base bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">Masuk</a>';
    }
    echo '      </div>';
    echo '  </nav>';
    echo '  <div id="mobile-search-bar" class="md:hidden px-4 pb-3 border-t hidden">';
    echo '      <form action="' . BASE_URL . '/index.php" method="GET" class="w-full">';
    echo '          <div class="relative">';
    echo '              <input type="search" name="s" placeholder="Cari produk..." class="w-full border-2 border-gray-200 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">';
    echo '              <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600"><i class="fas fa-search"></i></button>';
    echo '          </div>';
    echo '      </form>';
    echo '  </div>';
    echo '</header>';
    echo "<script>
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
    </script>";
}


/**
 * ✅ CARD KATEGORI DIKEMBALIKAN KE VERSI SIMPLE (TANPA GAMBAR)
 */
function category_card($category) {
    ?>
    <a href="<?= BASE_URL ?>/kategori/kategori.php?id=<?= urlencode(encode_id($category['id'])) ?>" 
       class="flex flex-col items-center justify-center text-center group">
        <div class="w-20 h-20 sm:w-24 sm:h-24 bg-white rounded-full shadow-md group-hover:shadow-lg group-hover:bg-indigo-50 border transition-all duration-300 flex flex-col items-center justify-center p-2">
            <h3 class="font-semibold text-xs sm:text-sm text-gray-800 leading-tight"><?= htmlspecialchars($category['name']) ?></h3>
        </div>
    </a>
    <?php
}

function product_card($product) {
    $sold_count = (int)($product['total_sold'] ?? 0);
    ?>
    <div class="bg-white rounded-lg shadow-md overflow-hidden group border hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col">
        <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($product['id'])) ?>" class="block overflow-hidden">
            <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($product['image']) ?>" 
                 alt="<?= htmlspecialchars($product['name']) ?>" 
                 class="h-36 sm:h-44 w-full object-cover group-hover:scale-105 transition-transform duration-300">
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
                 <form action="<?= BASE_URL ?>/cart/add_to_cart.php" method="POST" class="w-full">
                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="w-full flex items-center justify-center px-3 py-1.5 bg-indigo-50 text-indigo-700 rounded-lg hover:bg-indigo-500 hover:text-white transition-colors duration-200 text-sm font-semibold" title="Tambah ke Keranjang">
                        <i class="fas fa-cart-plus mr-2"></i> Tambah
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function footer($conn) {
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite Marketplace';
    $store_description = get_setting($conn, 'store_description');
    $store_facebook = get_setting($conn, 'store_facebook');
    $store_tiktok = get_setting($conn, 'store_tiktok');
    $store_email = get_setting($conn, 'store_email');
    $store_address = get_setting($conn, 'store_address');
    
    echo '<footer class="bg-gray-800 text-white mt-12">';
    echo '  <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10">';
    echo '      <div class="grid grid-cols-1 md:grid-cols-4 gap-8">';
    echo '          <div><h3 class="text-xl font-bold mb-4 text-indigo-400">' . htmlspecialchars($store_name) . '</h3><p class="text-gray-400 text-sm">' . htmlspecialchars($store_description) . '</p></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Tautan</h3><ul class="space-y-2 text-sm">';
    echo '              <li><a href="' . BASE_URL . '/" class="text-gray-400 hover:text-white transition">Beranda</a></li>';
    echo '              <li><a href="' . BASE_URL . '/product/" class="text-gray-400 hover:text-white transition">Semua Produk</a></li>';
    echo '              <li><a href="' . BASE_URL . '/kategori/kategori.php" class="text-gray-400 hover:text-white transition">Kategori</a></li>';
    echo '          </ul></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Hubungi Kami</h3><ul class="space-y-2 text-sm">';
    if ($store_email) echo '<li><p class="text-gray-400"><i class="fas fa-envelope mr-2"></i>' . htmlspecialchars($store_email) . '</p></li>';
    if ($store_address) echo '<li class="flex items-start"><i class="fas fa-map-marker-alt mr-2 mt-1"></i><p class="text-gray-400">' . nl2br(htmlspecialchars($store_address)) . '</p></li>';
    echo '          </ul></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Ikuti Kami</h3><div class="flex space-x-4">';
    if ($store_facebook) echo '<a href="' . htmlspecialchars($store_facebook) . '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-indigo-500 transition"><i class="fab fa-facebook fa-lg"></i></a>';
    if ($store_tiktok) echo '<a href="' . htmlspecialchars($store_tiktok) . '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-indigo-500 transition"><i class="fab fa-tiktok fa-lg"></i></a>';
    echo '          </div></div>';
    echo '      </div>';
    echo '      <div class="mt-8 pt-4 border-t border-gray-700 text-center"><p class="text-sm text-gray-400">&copy; ' . date("Y") . ' ' . htmlspecialchars($store_name) . '. All rights reserved.</p></div>';
    echo '  </div>';
    echo '</footer>';
}

function banner_slide($conn) {
    $result = $conn->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY created_at DESC");
    if (!$result || $result->num_rows === 0) {
        return;
    }
    $banners = $result->fetch_all(MYSQLI_ASSOC);
    $total_banners = count($banners);
    $banner_paths = BASE_URL . '/assets/images/banner/';
?>
<style>.banner-slide{transition:opacity .5s ease-in-out;position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;pointer-events:none}.banner-slide.active{opacity:1;z-index:10;pointer-events:auto}</style>
<div class="container mx-auto px-4 mt-4 sm:mt-6">
    <div id="banner-carousel" class="relative w-full overflow-hidden rounded-xl shadow-lg aspect-w-16 aspect-h-9 md:aspect-h-7 lg:aspect-h-6" style="height: 400px;">
        <?php foreach ($banners as $index => $banner): ?>
        <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
            <a href="<?= htmlspecialchars($banner['link_url'] ?: '#') ?>" class="block h-full">
                <img src="<?= $banner_paths . htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title']) ?>" class="w-full h-full object-cover">
            </a>
        </div>
        <?php endforeach; ?>
        <button type="button" onclick="changeSlide(-1)" class="absolute top-1/2 left-2 sm:left-4 z-10 -translate-y-1/2 p-2 sm:p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white"><i class="fas fa-chevron-left"></i></button>
        <button type="button" onclick="changeSlide(1)" class="absolute top-1/2 right-2 sm:right-4 z-10 -translate-y-1/2 p-2 sm:p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white"><i class="fas fa-chevron-right"></i></button>
        <div class="absolute bottom-4 left-0 right-0 z-10 flex justify-center space-x-2">
            <?php for ($i = 0; $i < $total_banners; $i++): ?><button class="w-3 h-3 rounded-full bg-white bg-opacity-50 hover:bg-opacity-100 dot-indicator <?= $i === 0 ? 'bg-opacity-100' : '' ?>" onclick="goToSlide(<?= $i ?>)"></button><?php endfor; ?>
        </div>
    </div>
</div>
<script>
let currentSlide=0;const slides=document.querySelectorAll('.banner-slide'),dots=document.querySelectorAll('.dot-indicator'),totalSlides=<?=$total_banners?>;let slideInterval;function showSlide(e){slides.forEach((t,i)=>{t.classList.remove('active');t.style.zIndex=i===e?10:0}),dots.forEach(t=>{t.classList.remove('bg-opacity-100'),t.classList.add('bg-opacity-50')}),slides[e].classList.add('active'),dots[e].classList.remove('bg-opacity-50'),dots[e].classList.add('bg-opacity-100'),currentSlide=e}function changeSlide(e){let t=(currentSlide+e+totalSlides)%totalSlides;showSlide(t);resetInterval()}function goToSlide(e){showSlide(e);resetInterval()}function autoSlide(){let e=(currentSlide+1)%totalSlides;showSlide(e)}function resetInterval(){clearInterval(slideInterval);slideInterval=setInterval(autoSlide,5e3)}totalSlides>1&&(slideInterval=setInterval(autoSlide,5e3));
</script>
<?php
}
?>