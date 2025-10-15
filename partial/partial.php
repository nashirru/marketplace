<?php
// File: partial/partial.php

function navbar($conn) {
    $cart_count = 0;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $result = $conn->query("SELECT COUNT(id) as count FROM cart WHERE user_id = $user_id");
        $cart_count = $result->fetch_assoc()['count'];
    } elseif (isset($_SESSION['cart'])) {
        $cart_count = count($_SESSION['cart']);
    }

    $notification_count = 0;
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $res = $conn->query("SELECT COUNT(id) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
        if ($res) {
            $notification_count = $res->fetch_assoc()['count'];
        }
    }
    
    $notification_badge = '';
    if ($notification_count > 0) {
        $notification_badge = "<span id=\"notification-count\" class=\"absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center\">{$notification_count}</span>";
    }

    $logo_img = '';
    $logo_filename = get_setting('store_logo', $conn);
    if ($logo_filename) {
        // PERBAIKAN: Gunakan path absolut dari root proyek untuk pengecekan file
        $logo_path_on_server = $_SERVER['DOCUMENT_ROOT'] . BASE_URL . '/assets/images/settings/' . $logo_filename;
        if (file_exists($logo_path_on_server)) {
            $logo_url = BASE_URL . '/assets/images/settings/' . $logo_filename;
            $logo_img = "<img src='{$logo_url}' alt='Logo Toko' class='h-8 w-auto mr-2'>";
        }
    }

    $home_url = BASE_URL . '/';
    $cart_url = BASE_URL . '/cart/cart.php';
    $notification_url = BASE_URL . '/notification/notification.php';

    // Ambil link navigasi user dari session
    $user_nav_links = $_SESSION['user_nav_links'] ?? '';

    return <<<HTML
    <header class="bg-white shadow-md sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="{$home_url}" class="flex items-center text-2xl font-bold text-indigo-600">
                    {$logo_img}
                    Warok<span class="text-gray-800">Kite</span>
                </a>
                <!-- Search Bar -->
                <div class="hidden md:flex flex-1 max-w-lg mx-4">
                    <input type="text" placeholder="Cari produk khas Ponorogo..." class="w-full px-4 py-2 border border-gray-300 rounded-l-md focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <button class="bg-indigo-600 text-white px-4 rounded-r-md hover:bg-indigo-700">Cari</button>
                </div>
                <!-- Ikon Navigasi -->
                <div class="flex items-center space-x-4">
                    <a href="{$cart_url}" class="relative text-gray-600 hover:text-indigo-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span id="cart-count" class="absolute -top-2 -right-2 bg-red-500 text-white text-xs rounded-full h-5 w-5 flex items-center justify-center">{$cart_count}</span>
                    </a>
                    <a href="{$notification_url}" class="relative text-gray-600 hover:text-indigo-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                        {$notification_badge}
                    </a>
                    <!-- Tombol Login/Register atau Profil Pengguna -->
                    <div class="relative">
                        <button id="profile-menu-button" class="text-gray-600 hover:text-indigo-600 flex items-center">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        </button>
                        <div id="profile-menu-dropdown" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 hidden">
                            {$user_nav_links}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const profileButton = document.getElementById('profile-menu-button');
            const profileDropdown = document.getElementById('profile-menu-dropdown');

            if (profileButton && profileDropdown) {
                profileButton.addEventListener('click', function(event) {
                    event.stopPropagation(); // Mencegah event 'click' menyebar ke window
                    profileDropdown.classList.toggle('hidden');
                });

                // Menutup dropdown jika klik di luar area menu
                window.addEventListener('click', function(event) {
                    if (!profileDropdown.contains(event.target) && !profileButton.contains(event.target)) {
                        profileDropdown.classList.add('hidden');
                    }
                });
            }
        });
    </script>
HTML;
}

function banner_slide($conn) {
    $banners = [];
    $result = $conn->query("SELECT * FROM banners WHERE is_active = 1 ORDER BY created_at DESC");
    while($row = $result->fetch_assoc()) {
        $banners[] = $row;
    }

    $slides_html = '';
    if (empty($banners)) {
        $slides_html = '<div class="slide"><img src="https://placehold.co/1200x400/374151/FFFFFF?text=Warok+Kite" alt="Banner Default" class="w-full h-full object-cover"></div>';
    } else {
        foreach ($banners as $banner) {
            $image_url = BASE_URL . '/assets/images/banner/' . htmlspecialchars($banner['image']);
            $link_url = !empty($banner['link_url']) ? htmlspecialchars($banner['link_url']) : '#';
            $title = htmlspecialchars($banner['title']);
            
            $slides_html .= <<<HTML
            <div class="slide hidden">
                <a href="{$link_url}">
                    <img src="{$image_url}" alt="{$title}" class="w-full h-full object-cover"
                         onerror="this.onerror=null;this.src='https://placehold.co/1200x400/E2E8F0/4A5568?text=Gambar+Rusak';">
                </a>
            </div>
            HTML;
        }
    }

    return <<<HTML
    <section id="banner" class="mb-12">
        <div id="slider" class="relative w-full h-56 sm:h-72 md:h-96 rounded-lg overflow-hidden shadow-lg">
           {$slides_html}
        </div>
    </section>
    HTML;
}

function footer($conn) {
    $help_url = BASE_URL . '/help/help.php';
    $store_description = htmlspecialchars(get_setting('store_description', $conn));
    $facebook_url = htmlspecialchars(get_setting('facebook_url', $conn));
    $instagram_url = htmlspecialchars(get_setting('instagram_url', $conn));

    return <<<HTML
    <footer class="bg-gray-800 text-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div>
                    <h3 class="font-bold mb-2">Warok Kite</h3>
                    <p class="text-gray-400 text-sm">{$store_description}</p>
                </div>
                <div>
                    <h3 class="font-bold mb-2">Jelajahi</h3>
                    <ul class="space-y-1 text-sm">
                        <li><a href="#" class="text-gray-400 hover:text-white">Kategori</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white">Produk Terbaru</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold mb-2">Bantuan</h3>
                     <ul class="space-y-1 text-sm">
                        <li><a href="{$help_url}" class="text-gray-400 hover:text-white">Pusat Bantuan</a></li>
                        <li><a href="#" class="text-gray-400 hover:text-white">Hubungi Kami</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-bold mb-2">Ikuti Kami</h3>
                    <div class="flex space-x-4">
                        <a href="{$facebook_url}" target="_blank" class="text-gray-400 hover:text-white">Facebook</a>
                        <a href="{$instagram_url}" target="_blank" class="text-gray-400 hover:text-white">Instagram</a>
                    </div>
                </div>
            </div>
            <div class="mt-8 pt-4 border-t border-gray-700 text-center text-sm text-gray-500">
                &copy; 2025 Warok Kite. All Rights Reserved.
            </div>
        </div>
    </footer>
    HTML;
}

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Menentukan link navigasi user berdasarkan status login
if (isset($_SESSION['user_id'])) {
    $admin_link = ($_SESSION['role'] == 'admin') ? '<a href="'.BASE_URL.'/admin/admin.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Dashboard Admin</a>' : '';
    $_SESSION['user_nav_links'] = '
        <a href="'.BASE_URL.'/profile/profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profil Saya</a>
        '.$admin_link.'
        <a href="'.BASE_URL.'/login/logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Logout</a>
    ';
} else {
    $_SESSION['user_nav_links'] = '
        <a href="'.BASE_URL.'/login/login.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Login</a>
        <a href="'.BASE_URL.'/register/register.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Register</a>
    ';
}
?>