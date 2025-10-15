<?php
// File: sistem/sistem.php

// Pastikan session sudah dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mengarahkan pengguna ke halaman lain dengan aman menggunakan BASE_URL.
 * @param string $url URL tujuan (misal: '/login/login.php' atau '/')
 */
function redirect($url) {
    if (!defined('BASE_URL')) {
        die("Kesalahan Kritis: BASE_URL tidak terdefinisi. Periksa file config/config.php");
    }
    if (substr($url, 0, 1) !== '/') {
        $url = '/' . $url;
    }
    $location = BASE_URL . $url;
    header("Location: " . $location);
    exit();
}

/**
 * Membersihkan input dari user untuk mencegah XSS.
 * @param string $data Input dari user.
 * @return string Input yang sudah dibersihkan.
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Mengatur pesan flash (notifikasi sekali pakai).
 * @param string $name Nama kunci pesan.
 * @param string $message Isi pesan.
 */
function set_flash_message($name, $message) {
    $_SESSION['flash_' . $name] = $message;
}

/**
 * Menampilkan dan menghapus pesan flash.
 * @param string $name Nama kunci pesan.
 * @return string HTML pesan atau string kosong.
 */
function flash_message($name) {
    if (isset($_SESSION['flash_' . $name])) {
        $message = $_SESSION['flash_' . $name];
        unset($_SESSION['flash_' . $name]);
        
        $alert_type = 'indigo'; // Default
        if (strpos($name, 'success') !== false) $alert_type = 'green';
        elseif (strpos($name, 'error') !== false) $alert_type = 'red';
        elseif (strpos($name, 'info') !== false) $alert_type = 'blue';

        return '<div class="p-4 mb-4 text-sm text-' . $alert_type . '-800 bg-' . $alert_type . '-100 rounded-lg" role="alert">' . htmlspecialchars($message) . '</div>';
    }
    return '';
}

/**
 * Memformat angka menjadi format Rupiah.
 * @param float $number Angka yang akan diformat.
 * @return string Angka dalam format Rupiah.
 */
function format_rupiah($number) {
    return 'Rp ' . number_format($number, 0, ',', '.');
}

/**
 * Memeriksa apakah user sudah login, jika belum, redirect ke halaman login.
 */
function check_login() {
    if (!isset($_SESSION['user_id'])) {
        set_flash_message('auth_error', 'Anda harus login untuk mengakses halaman ini.');
        redirect('/login/login.php');
    }
}

/**
 * Memeriksa apakah user adalah admin, jika bukan, redirect ke halaman utama.
 */
function check_admin() {
    check_login();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        set_flash_message('auth_error', 'Anda tidak memiliki hak akses ke halaman admin.');
        redirect('/');
    }
}

// --- FUNGSI BARU UNTUK PENGATURAN ---

/**
 * @var array Cache untuk menyimpan pengaturan dari database.
 */
$settings_cache = [];

/**
 * Memuat semua pengaturan dari database ke dalam cache.
 * @param mysqli $conn Objek koneksi database.
 */
function load_settings($conn) {
    global $settings_cache;
    if (empty($settings_cache)) {
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings_cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    }
}

/**
 * Mengambil nilai pengaturan dari cache.
 * @param string $key Kunci pengaturan (contoh: 'store_logo').
 * @param mixed $default Nilai default jika kunci tidak ditemukan.
 * @return mixed Nilai pengaturan.
 */
function get_setting($key, $default = null) {
    global $settings_cache;
    return $settings_cache[$key] ?? $default;
}
?>