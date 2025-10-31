<?php
// File: login/logout.php

// === PERBAIKAN: INCLUDE CONFIG & SISTEM AGAR BASE_URL TERDEFINISI ===
include '../config/config.php';
include '../sistem/sistem.php';
// ===================================================================

// Hancurkan semua data session
session_unset();
session_destroy();

// Arahkan ke halaman utama setelah logout
redirect('/');
?>