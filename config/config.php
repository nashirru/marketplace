<?php
// File: config/config.php

// --- Pengaturan Aplikasi ---
// Ubah '/warok' sesuai dengan nama folder proyek Anda di localhost.
// Jika proyek ada di root (http://localhost/), cukup ubah menjadi ''.
define('BASE_URL', 'https://uncompiled-thriftless-semaj.ngrok-free.dev/warok');

// --- Pengaturan Koneksi Database ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', '1');

// --- Buat Koneksi ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// --- Cek Koneksi ---
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

// --- Atur Karakter Set ---
$conn->set_charset("utf8mb4");

// --- Mulai Session ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


/*
====================================================================
 STRUKTUR SQL LENGKAP UNTUK DATABASE (jalankan di phpMyAdmin)
====================================================================

-- Buat database jika belum ada
CREATE DATABASE IF NOT EXISTS warokkite_db CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE warokkite_db;

-- Tabel 1: users
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 2: categories
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 3: products
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `stock` int(11) NOT NULL,
  `image` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 4: cart
CREATE TABLE `cart` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 5: orders
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `payment_proof` varchar(255) DEFAULT NULL,
  `status` enum('waiting_approval','approved','cetak_resi','proses_pengemasan','dikirim','selesai') NOT NULL DEFAULT 'waiting_approval',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 6: order_items
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 7: notifications
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabel 8: promotions
CREATE TABLE `promotions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `discount_percent` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =================================================================
-- CONTOH DATA AWAL (SAMPLE DATA)
-- =================================================================

-- 1. User Admin
-- (Password: admin123)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin Warok Kite', 'admin@warokkite.com', '$2y$10$3zR1b.Z0qY.N/uZ3n8mCfe7sCMO4.G6c/nTMJ5/uL7aD8hDq/yBka', 'admin', '2025-10-15 13:30:00');

-- 2. Kategori Produk
INSERT INTO `categories` (`id`, `name`, `image`) VALUES
(1, 'Kesenian', 'kesenian.png'),
(2, 'Kuliner', 'kuliner.png'),
(3, 'Fashion', 'fashion.png'),
(4, 'Kerajinan', 'kerajinan.png');

-- 3. Produk
INSERT INTO `products` (`id`, `category_id`, `name`, `description`, `price`, `stock`, `image`, `created_at`) VALUES
(1, 1, 'Topeng Bujang Ganong', 'Topeng kayu asli buatan tangan pengrajin lokal Ponorogo. Dibuat dari kayu pule yang ringan dan kuat.', 150000.00, 15, 'https://placehold.co/400x400/E2E8F0/4A5568?text=Topeng', '2025-10-15 13:35:00'),
(2, 2, 'Sate Ayam Ponorogo (Frozen)', 'Paket sate ayam 20 tusuk (frozen) dengan bumbu kacang khas. Siap dibakar kapan saja.', 45000.00, 50, 'https://placehold.co/400x400/FEE2E2/B91C1C?text=Sate', '2025-10-15 13:36:00'),
(3, 3, 'Batik Tulis Motif Reog', 'Kain batik tulis asli dari Ponorogo dengan motif Reog yang ikonik. Ukuran 2x1.5 meter.', 250000.00, 20, 'https://placehold.co/400x400/DBEAFE/1E40AF?text=Batik', '2025-10-15 13:37:00'),
(4, 4, 'Miniatur Dadak Merak', 'Kerajinan miniatur Dadak Merak Reog Ponorogo, cocok untuk hiasan dinding atau koleksi.', 350000.00, 10, 'https://placehold.co/400x400/D1FAE5/065F46?text=Miniatur', '2025-10-15 13:38:00');

*/
?>