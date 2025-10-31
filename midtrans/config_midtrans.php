<?php
// File: midtrans/config_midtrans.php
// File untuk konfigurasi API Key dan instalasi SDK Midtrans.

// Pastikan Anda sudah menjalankan 'composer require midtrans/midtrans-php'
// Atau jika Anda mengunduh manual, sesuaikan path ke autoload.
require_once __DIR__ . '/../vendor/autoload.php';

// Konfigurasi API Key Midtrans Anda
// Ganti dengan kunci asli dari dashboard Midtrans Anda
// https://dashboard.midtrans.com/settings/api_key
\Midtrans\Config::$serverKey = 'Mid-server-kffWildQQR-Om3k9yEVVrZhu';
\Midtrans\Config::$clientKey = 'Mid-client-y-kgYuCB11792GFk';

// Set ke true jika Anda sudah di lingkungan produksi
\Midtrans\Config::$isProduction = false;

// Aktifkan sanitasi untuk keamanan (menghindari serangan XSS)
\Midtrans\Config::$isSanitized = true;

// Aktifkan 3D Secure untuk transaksi kartu kredit jika diperlukan
\Midtrans\Config::$is3ds = true;