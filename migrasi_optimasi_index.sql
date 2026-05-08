-- =============================================================================
-- MIGRASI OPTIMASI PERFORMA - Warok Kite
-- Versi   : 1.0
-- Tanggal : 2026-04-30
-- Tujuan  : Menambahkan index yang hilang, memperbaiki skema tabel, dan
--           menambahkan kolom yang dibutuhkan aplikasi agar tidak terjadi
--           HTTP 500 atau query lambat saat trafik tinggi.
--
-- CARA MENJALANKAN:
--   phpMyAdmin → Database publi → Tab "SQL" → Paste & Jalankan
--   ATAU via CLI: mysql -u root publi < migrasi_optimasi_index.sql
--
-- AMAN DIJALANKAN BERULANG: Semua statement menggunakan IF NOT EXISTS /
--   IF EXISTS sehingga tidak akan error jika dijalankan lebih dari sekali.
-- =============================================================================

USE `u111743367_warokkite`;

-- Nonaktifkan sementara pengecekan FK agar ALTER tidak konflik urutan
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- BAGIAN 1: KOLOM YANG HILANG (dibutuhkan oleh kode PHP tapi belum ada di DB)
-- =============================================================================

-- 1a. order_items.variation_name
--     Dipakai di sistem.php get_order_items_with_details() dan
--     get_orders_with_items_by_status() untuk snapshot nama variasi saat order.
--     Tanpa kolom ini query akan error saat ada variasi.
ALTER TABLE `order_items`
    ADD COLUMN IF NOT EXISTS `variation_name` varchar(100) DEFAULT NULL
        COMMENT 'Snapshot nama variasi saat order dibuat'
    AFTER `variation_id`;

-- 1b. order_items.stock_cycle_id
--     Dipakai di get_user_pending_purchase_count() untuk menghitung
--     pembelian pending berdasarkan siklus stok.
ALTER TABLE `order_items`
    ADD COLUMN IF NOT EXISTS `stock_cycle_id` int(11) DEFAULT 1
        COMMENT 'Siklus stok produk saat item dibeli'
    AFTER `variation_name`;

-- 1c. categories.image
--     Dipakai di partial.php untuk menampilkan gambar kategori.
ALTER TABLE `categories`
    ADD COLUMN IF NOT EXISTS `image` varchar(255) DEFAULT NULL
        COMMENT 'Nama file gambar kategori'
    AFTER `name`;


-- =============================================================================
-- BAGIAN 2: INDEX YANG HILANG PADA TABEL KRITIS
-- (Semua menggunakan sintaks IF NOT EXISTS — aman untuk DB yang sudah berjalan)
-- =============================================================================

-- -------------------------------------------------------
-- 2.1 TABEL: products
-- Query beranda: WHERE is_active=1 ORDER BY created_at DESC
-- Query admin  : WHERE is_active=1 AND category_id=?
-- -------------------------------------------------------

-- Composite index untuk query beranda (paling sering dipanggil)
-- Menggabungkan is_active + created_at agar MySQL tidak full-scan
ALTER TABLE `products`
    ADD INDEX IF NOT EXISTS `idx_active_created` (`is_active`, `created_at` DESC);

-- Composite untuk filter kategori + aktif
ALTER TABLE `products`
    ADD INDEX IF NOT EXISTS `idx_active_category` (`is_active`, `category_id`);

-- Index untuk kolom stock (query stok rendah di dashboard admin)
ALTER TABLE `products`
    ADD INDEX IF NOT EXISTS `idx_stock` (`stock`);


-- -------------------------------------------------------
-- 2.2 TABEL: orders
-- Index yang ada: user_id, payment_method_id, user_address_id, status (single)
-- Yang KURANG   : composite status+created_at, user_id+status, expiry_time
-- -------------------------------------------------------

-- Composite untuk query admin: filter status + urut tanggal (paling sering)
ALTER TABLE `orders`
    ADD INDEX IF NOT EXISTS `idx_status_created` (`status`, `created_at` DESC);

-- Composite untuk halaman riwayat user: WHERE user_id=? AND status=?
ALTER TABLE `orders`
    ADD INDEX IF NOT EXISTS `idx_user_status` (`user_id`, `status`);

-- Index untuk cancel_overdue_orders: WHERE status='waiting_payment' AND created_at < ?
-- Composite ini jauh lebih efisien dari single index `status` yang sudah ada
ALTER TABLE `orders`
    ADD INDEX IF NOT EXISTS `idx_status_expiry` (`status`, `expiry_time`);

-- Index untuk Midtrans webhook lookup
ALTER TABLE `orders`
    ADD INDEX IF NOT EXISTS `idx_midtrans_txid` (`midtrans_transaction_id`);


-- -------------------------------------------------------
-- 2.3 TABEL: order_items
-- Index yang ada: order_id (single), product_id (single)
-- Yang KURANG   : composite order_id+product_id untuk JOIN query
-- -------------------------------------------------------

ALTER TABLE `order_items`
    ADD INDEX IF NOT EXISTS `idx_order_product` (`order_id`, `product_id`);

-- Index untuk query cancel_overdue (UPDATE JOIN product via order_id)
ALTER TABLE `order_items`
    ADD INDEX IF NOT EXISTS `idx_product_order` (`product_id`, `order_id`);


-- -------------------------------------------------------
-- 2.4 TABEL: notifications
-- Index yang ada: user_id (single)
-- Yang KURANG   : composite user_id+is_read (query notif belum dibaca)
-- -------------------------------------------------------

ALTER TABLE `notifications`
    ADD INDEX IF NOT EXISTS `idx_user_read` (`user_id`, `is_read`);

-- Index untuk auto-cleanup notifikasi lama
ALTER TABLE `notifications`
    ADD INDEX IF NOT EXISTS `idx_created_at` (`created_at`);


-- -------------------------------------------------------
-- 2.5 TABEL: user_addresses
-- Index yang ada: user_id (single)
-- Yang KURANG   : composite user_id+is_default (query alamat default)
-- -------------------------------------------------------

ALTER TABLE `user_addresses`
    ADD INDEX IF NOT EXISTS `idx_user_default` (`user_id`, `is_default`);


-- -------------------------------------------------------
-- 2.6 TABEL: product_variations
-- Index yang ada: product_id (single)
-- Sudah cukup, tidak perlu tambahan.
-- -------------------------------------------------------


-- -------------------------------------------------------
-- 2.7 TABEL: banners
-- Index yang ada: PRIMARY KEY saja
-- Yang KURANG   : is_active (query banner aktif di beranda)
-- -------------------------------------------------------

ALTER TABLE `banners`
    ADD INDEX IF NOT EXISTS `idx_active` (`is_active`);


-- -------------------------------------------------------
-- 2.8 TABEL: payment_attempts
-- Index yang ada: order_id, attempt_order_number (unique)
-- Yang KURANG   : status+created_at untuk cleanup token kadaluarsa
-- -------------------------------------------------------

ALTER TABLE `payment_attempts`
    ADD INDEX IF NOT EXISTS `idx_status_created` (`status`, `created_at`);


-- -------------------------------------------------------
-- 2.9 TABEL: user_purchase_records
-- Index yang ada: user_id, product_id, unique(user,product,cycle)
-- Sudah baik. Tidak perlu tambahan.
-- -------------------------------------------------------


-- =============================================================================
-- BAGIAN 3: PERBAIKAN SKEMA (TYPE / COLLATION / CONSTRAINT)
-- =============================================================================

-- 3a. Pastikan settings.setting_key menggunakan collation yang case-sensitive
--     agar lookup 'store_name' dan 'Store_Name' tidak ambigu
--     (MariaDB 10.4 default: utf8mb4_general_ci — case-insensitive, sudah OK)
--     Tidak perlu diubah, cukup dokumentasi.

-- 3b. Tambahkan index pada faq.sort_order + is_active untuk query tampilan FAQ
ALTER TABLE `faq`
    ADD INDEX IF NOT EXISTS `idx_active_sort` (`is_active`, `sort_order`);


-- =============================================================================
-- BAGIAN 4: TABEL BARU (jika belum ada)
-- =============================================================================

-- 4a. Tabel php_sessions — opsional, untuk pindahkan session dari filesystem ke DB
--     Aktifkan jika kamu membutuhkan session berbasis database (high concurrency).
--     Jika tidak digunakan, biarkan saja (tidak mengganggu apapun).
CREATE TABLE IF NOT EXISTS `php_sessions` (
    `session_id`   varchar(128) NOT NULL,
    `session_data` mediumtext   DEFAULT NULL,
    `last_accessed` timestamp   NOT NULL DEFAULT current_timestamp()
                                ON UPDATE current_timestamp(),
    PRIMARY KEY (`session_id`),
    INDEX `idx_last_accessed` (`last_accessed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Opsional: penyimpanan session PHP berbasis database';


-- =============================================================================
-- SELESAI
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;

-- Verifikasi: tampilkan ringkasan index pada tabel kritis
-- (Uncomment baris di bawah untuk melihat hasil setelah migrasi)
-- SHOW INDEX FROM `products`;
-- SHOW INDEX FROM `orders`;
-- SHOW INDEX FROM `order_items`;
-- SHOW INDEX FROM `notifications`;
