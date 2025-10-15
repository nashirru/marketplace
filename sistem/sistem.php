<?php
// File: sistem/sistem.php

// Pastikan session sudah dimulai
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Mengarahkan pengguna ke halaman lain dengan aman menggunakan BASE_URL.
 * Ini adalah perbaikan utama untuk masalah redirect.
 * @param string $url URL tujuan (misal: '/login/login.php' atau '/')
 */
function redirect($url) {
    // Pastikan BASE_URL sudah didefinisikan di config.php
    if (!defined('BASE_URL')) {
        die("Kesalahan Kritis: BASE_URL tidak terdefinisi. Periksa file config/config.php");
    }
    
    // Pastikan URL tujuan diawali dengan slash
    if (substr($url, 0, 1) !== '/') {
        $url = '/' . $url;
    }

    $location = BASE_URL . $url;
    header("Location: " . $location);
    exit(); // Wajib untuk menghentikan eksekusi skrip setelah redirect
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
        $alert_type = (strpos($name, 'success') !== false) ? 'green' : 'red';
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
    check_login(); // Pastikan sudah login dulu
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        set_flash_message('auth_error', 'Anda tidak memiliki hak akses ke halaman admin.');
        redirect('/');
    }
}

?>