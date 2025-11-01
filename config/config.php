<?php
// File: config/config.php

// --- Pengaturan Aplikasi ---
// Ubah '/warok' sesuai dengan nama folder proyek Anda di localhost.
// Jika proyek ada di root (http://localhost/), cukup ubah menjadi ''.
define('BASE_URL', 'https://uncompiled-thriftless-semaj.ngrok-free.dev/warok');

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'publi');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>