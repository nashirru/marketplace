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
        $alert_type = (strpos($name, 'success') !== false) ? 'green' : ((strpos($name, 'info') !== false) ? 'blue' : 'red');
        return '<div class="p-4 mb-4 text-sm text-' . $alert_type . '-700 bg-' . $alert_type . '-100 rounded-lg" role="alert">' . $message . '</div>';
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

/**
 * Mengambil nilai pengaturan dari database dengan caching.
 * PERBAIKAN: Urutan parameter diubah menjadi ($key, $conn) agar konsisten.
 * @param string $key Kunci pengaturan.
 * @param mysqli $conn Objek koneksi database.
 * @return string|null Nilai pengaturan atau null.
 */
function get_setting($key, $conn) {
    // Inisialisasi variabel statis untuk cache agar query tidak berulang-ulang
    static $settings = null;

    // Jika pengaturan belum di-cache, ambil dari DB
    if ($settings === null) {
        $settings = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM settings");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
    }

    // Kembalikan nilai dari cache
    return $settings[$key] ?? null;
}