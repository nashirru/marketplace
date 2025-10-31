<?php
// File: notification/mark_all_read.php
// Menandai semua notifikasi user yang belum dibaca sebagai sudah dibaca

require_once '../config/config.php';
require_once '../sistem/sistem.php';

check_login();

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->close();

// Tidak perlu flash message, langsung redirect
redirect('/profile/profile.php?tab=notifications');