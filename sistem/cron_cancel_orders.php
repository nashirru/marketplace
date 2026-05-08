<?php
/**
 * cron_cancel_orders.php
 * ---------------------------------------------------------------
 * Jalankan via cron job setiap 5 menit agar pesanan overdue
 * dibatalkan otomatis tanpa membebani setiap web request.
 *
 * Setup cron (hosting/VPS Linux):
 *   setiap 5 menit : /usr/bin/php /path/to/warok/sistem/cron_cancel_orders.php
 *
 * Setup Task Scheduler (Windows/XAMPP lokal):
 *   Program : C:\xampp\php\php.exe
 *   Argumen : C:\xampp\htdocs\warok\sistem\cron_cancel_orders.php
 *   Interval: setiap 5 menit
 * ---------------------------------------------------------------
 */

// Hanya boleh dijalankan via CLI, bukan browser
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only');
}

define('IS_CRON', true);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/sistem.php';

echo '[' . date('Y-m-d H:i:s') . '] Memulai pembatalan pesanan overdue...' . PHP_EOL;
cancel_overdue_orders($conn);
echo '[' . date('Y-m-d H:i:s') . '] Selesai.' . PHP_EOL;

$conn->close();
