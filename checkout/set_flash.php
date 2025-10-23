<?php
// File: checkout/set_flash.php
// FILE BARU: Tugasnya hanya 1, set notifikasi dan redirect.
// Ini adalah "jembatan" antara JavaScript dan PHP Session.

require_once '../config/config.php';
require_once '../sistem/sistem.php'; // (set_flashdata ada di sini)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 1. Ambil data dari URL
$type = sanitize_input($_GET['type'] ?? 'info');
$message = sanitize_input($_GET['message'] ?? '...');
// Ambil URL tujuan dari parameter
$redirect_to = sanitize_input($_GET['redirect_to'] ?? '/profile/profile.php?tab=orders');

// 2. Set notifikasi flashdata
set_flashdata($type, $message);

// 3. Validasi redirect_to agar aman (hanya boleh redirect internal)
if (strpos($redirect_to, '/') !== 0 || strpos($redirect_to, '//') !== false) {
    $redirect_to = '/profile/profile.php?tab=orders';
}

// 4. Redirect ke halaman profil (atau tujuan lain)
redirect($redirect_to); // Fungsi redirect dari sistem.php
?>