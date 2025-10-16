<?php
// File: partial/partial.php
// Berisi fungsi-fungsi untuk bagian-bagian halaman yang berulang (Navbar, Footer, dll.)

/**
 * Memuat dan menampilkan Navbar/Header di halaman depan.
 * Memerlukan koneksi $conn untuk mengambil data pengaturan.
 * * @param mysqli $conn Objek koneksi database.
 */
function navbar($conn) {
    // ✅ FITUR BARU: Panggil fungsi untuk membatalkan pesanan yang kadaluarsa
    cancel_overdue_orders($conn);

    // Panggil get_setting() DENGAN $conn sebagai parameter pertama
    $logo_name = get_setting($conn, 'store_logo'); 
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite Marketplace';

    $logo_path = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    $is_logged_in = isset($_SESSION['user_id']);
    $user_name = $_SESSION['user_name'] ?? 'Pengguna';
    $cart_count = 0;
    
    // Logika untuk menghitung item di keranjang
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
        // Hitung dari session jika guest
        if (!empty($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as $item) {
                $cart_count += $item['quantity'];
            }
        }
    }

    echo '<header class="bg-white shadow-md sticky top-0 z-10">';
    echo '  <nav class="container mx-auto px-4 sm:px-6 lg:px-8 py-3 flex justify-between items-center">';
    
    // 1. Logo/Brand (Left)
    echo '      <div class="flex items-center space-x-4">';
    echo '          <a href="' . BASE_URL . '/" class="text-2xl font-bold text-gray-900 flex items-center">';
    echo '              <img src="' . $logo_path . '" alt="Logo Toko" class="h-8 w-auto mr-2 rounded-lg object-contain" onerror="this.onerror=null;this.src=\'' . BASE_URL . '/assets/images/settings/default_logo.png\';">';
    echo '              ' . htmlspecialchars($store_name);
    echo '          </a>';
    echo '      </div>';
    
    // 2. Search Bar (DIKEMBALIKAN KE NAVBAR)
    echo '      <div class="flex-grow max-w-xl mx-4 hidden md:block">';
    echo '          <form action="' . BASE_URL . '/index.php" method="GET" class="w-full">'; // Action ke index.php
    echo '              <div class="relative">';
    echo '                  <input type="search" name="s" placeholder="Cari produk di Warok Kite..." class="w-full border-2 border-indigo-300 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">';
    echo '                  <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600">';
    echo '                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>';
    echo '                  </button>';
    echo '              </div>';
    echo '          </form>';
    echo '      </div>';

    // 3. Cart/Auth (Right)
    echo '      <div class="flex items-center space-x-4 text-gray-700 font-medium">';
    
    // Tombol Keranjang
    echo '          <a href="' . BASE_URL . '/cart/cart.php" class="relative p-2 rounded-full hover:bg-gray-100 transition">';
    echo '              <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>';
    
    $badge_style = $cart_count > 0 ? 'display: inline-block;' : 'display: none;';
    echo '              <span id="cart-count-badge" style="' . $badge_style . '" class="absolute top-0 right-0 bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full leading-none">' . $cart_count . '</span>';
    
    echo '          </a>';
    
    // Tombol Login/Profil
    if ($is_logged_in) {
        echo '          <a href="' . BASE_URL . '/profile/profile.php" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">Hai, ' . htmlspecialchars(explode(' ', $user_name)[0]) . '</a>';
    } else {
        echo '          <a href="' . BASE_URL . '/login/login.php" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition">Masuk / Daftar</a>';
    }

    echo '      </div>';
    
    echo '  </nav>';
    // Mobile Search Bar
    echo '  <div class="md:hidden px-4 pb-3">';
    echo '      <form action="' . BASE_URL . '/index.php" method="GET" class="w-full">';
    echo '          <div class="relative">';
    echo '              <input type="search" name="s" placeholder="Cari produk..." class="w-full border-2 border-indigo-300 bg-gray-50 h-10 px-5 pr-10 rounded-full text-sm focus:outline-none focus:border-indigo-500">';
    echo '              <button type="submit" class="absolute right-0 top-0 mt-2 mr-4 text-indigo-600">';
    echo '                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>';
    echo '              </button>';
    echo '          </div>';
    echo '      </form>';
    echo '  </div>';
    echo '</header>';
}

/**
 * Memuat dan menampilkan Footer di halaman depan.
 * * @param mysqli $conn Objek koneksi database.
 */
function footer($conn) {
    // ... (kode footer tidak berubah) ...
    $store_name = get_setting($conn, 'store_name') ?? 'Warok Kite Marketplace';
    $store_description = get_setting($conn, 'store_description') ?? 'Tempat jual beli produk UMKM unggulan Ponorogo.';
    $store_facebook = get_setting($conn, 'store_facebook');
    $store_tiktok = get_setting($conn, 'store_tiktok');
    $store_email = get_setting($conn, 'store_email');
    $store_phone = get_setting($conn, 'store_phone');

    echo '<footer class="bg-gray-800 text-white mt-12">';
    echo '  <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-10">';
    echo '      <div class="grid grid-cols-1 md:grid-cols-4 gap-8">';
    echo '          <div><h3 class="text-xl font-bold mb-4 text-indigo-400">' . htmlspecialchars($store_name) . '</h3><p class="text-gray-400 text-sm">' . htmlspecialchars($store_description) . '</p></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Tautan</h3><ul class="space-y-2 text-sm"><li><a href="' . BASE_URL . '/" class="text-gray-400 hover:text-white transition">Beranda</a></li><li><a href="' . BASE_URL . '/product/product.php" class="text-gray-400 hover:text-white transition">Semua Produk</a></li><li><a href="' . BASE_URL . '/kategori/kategori.php" class="text-gray-400 hover:text-white transition">Kategori</a></li><li><a href="' . BASE_URL . '/help/help.php" class="text-gray-400 hover:text-white transition">Pusat Bantuan</a></li></ul></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Hubungi Kami</h3><ul class="space-y-2 text-sm">';
    if ($store_phone) echo '<li><p class="text-gray-400">Telepon: ' . htmlspecialchars($store_phone) . '</p></li>';
    if ($store_email) echo '<li><p class="text-gray-400">Email: ' . htmlspecialchars($store_email) . '</p></li>';
    echo '</ul></div>';
    echo '          <div><h3 class="text-lg font-semibold mb-4 text-indigo-400">Ikuti Kami</h3><div class="flex space-x-4">';
    if ($store_facebook) echo '<a href="' . htmlspecialchars($store_facebook) . '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-indigo-500 transition"><svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path fill-rule="evenodd" d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z" clip-rule="evenodd" /></svg></a>';
    if ($store_tiktok) echo '<a href="' . htmlspecialchars($store_tiktok) . '" target="_blank" rel="noopener noreferrer" class="text-gray-400 hover:text-indigo-500 transition"><svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-2.43.03-4.83-.95-6.43-2.98-2.02-2.55-2.45-5.69-1.76-8.59.33-1.36.81-2.66 1.48-3.88.08-1.53.63-3.09 1.75-4.17 1.12-1.11 2.7-1.62 4.24-1.79v4.03c-1.44.05-2.89.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-2.43.03-4.83-.95-6.43-2.98-2.02-2.55-2.45-5.69-1.76-8.59.33-1.36.81-2.66 1.48-3.88.16-2.88 1.04-5.54 2.65-7.82C7.69.75 9.98-.03 12.525.02z"/></svg></a>';
    echo '</div></div></div>';
    echo '<div class="mt-8 pt-4 border-t border-gray-700 text-center"><p class="text-sm text-gray-400">&copy; ' . date("Y") . ' ' . htmlspecialchars($store_name) . '. All rights reserved.</p></div>';
    echo '</div></footer>';
}

/**
 * Menampilkan carousel banner yang aktif.
 * @param mysqli $conn Objek koneksi database.
 */
function banner_slide($conn) {
    // ... (kode banner_slide tidak berubah) ...
    $result = $conn->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY created_at DESC");
    if (!$result || $result->num_rows === 0) {
        echo '<div class="container mx-auto px-4 mt-6"><div class="h-64 bg-gray-200 rounded-xl flex items-center justify-center"><p class="text-gray-500">Tidak ada banner aktif.</p></div></div>';
        return;
    }
    $banners = [];
    while ($row = $result->fetch_assoc()) $banners[] = $row;
    $total_banners = count($banners);
    $banner_paths = BASE_URL . '/assets/images/banner/';
?>
<style>.banner-slide{transition:opacity .5s ease-in-out;position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;pointer-events:none}.banner-slide.active{opacity:1;z-index:10;pointer-events:auto}</style>
<div class="container mx-auto px-4 mt-6">
    <div id="banner-carousel" class="relative w-full overflow-hidden rounded-xl shadow-xl" style="height: 400px;">
        <?php foreach ($banners as $index => $banner): ?>
        <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>" data-index="<?= $index ?>">
            <a href="<?= htmlspecialchars($banner['link_url'] ?: '#') ?>" class="block h-full">
                <img src="<?= $banner_paths . htmlspecialchars($banner['image']) ?>" alt="<?= htmlspecialchars($banner['title']) ?>" class="w-full h-full object-cover">
            </a>
        </div>
        <?php endforeach; ?>
        <button type="button" onclick="changeSlide(-1)" class="absolute top-1/2 left-4 z-20 -translate-y-1/2 p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg></button>
        <button type="button" onclick="changeSlide(1)" class="absolute top-1/2 right-4 z-20 -translate-y-1/2 p-3 bg-black bg-opacity-30 rounded-full hover:bg-opacity-50 text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg></button>
        <div class="absolute bottom-4 left-0 right-0 z-20 flex justify-center space-x-2">
            <?php for ($i = 0; $i < $total_banners; $i++): ?><button class="w-3 h-3 rounded-full bg-white bg-opacity-50 hover:bg-opacity-100 dot-indicator <?= $i === 0 ? 'bg-opacity-100' : '' ?>" onclick="goToSlide(<?= $i ?>)"></button><?php endfor; ?>
        </div>
    </div>
</div>
<script>
let currentSlide=0;const slides=document.querySelectorAll('.banner-slide'),dots=document.querySelectorAll('.dot-indicator'),totalSlides=<?=$total_banners?>;let slideInterval;function showSlide(e){slides.forEach((t,i)=>{t.classList.remove('active'),t.style.zIndex=i===e?10:0}),dots.forEach(t=>{t.classList.remove('bg-opacity-100'),t.classList.add('bg-opacity-50')}),slides[e].classList.add('active'),dots[e].classList.remove('bg-opacity-50'),dots[e].classList.add('bg-opacity-100'),currentSlide=e}function changeSlide(e){let t=(currentSlide+e+totalSlides)%totalSlides;showSlide(t),resetInterval()}function goToSlide(e){showSlide(e),resetInterval()}function autoSlide(){let e=(currentSlide+1)%totalSlides;showSlide(e)}function resetInterval(){clearInterval(slideInterval),slideInterval=setInterval(autoSlide,5e3)}totalSlides>1&&(slideInterval=setInterval(autoSlide,5e3));
</script>
<?php
}
?>