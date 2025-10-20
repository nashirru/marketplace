<?php
// File: cart/add_to_cart.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';

$user_id = $_SESSION['user_id'] ?? 0;
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity_to_add = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
$redirect_url = $_SERVER['HTTP_REFERER'] ?? '/index.php';

if ($product_id <= 0 || $quantity_to_add <= 0) {
    set_flashdata('error', 'Data produk tidak valid.');
    redirect($redirect_url);
}

// Ambil data produk (stok, limit, nama, dan cycle_id)
$stmt_prod = $conn->prepare("SELECT stock, purchase_limit, name, stock_cycle_id FROM products WHERE id = ?");
$stmt_prod->bind_param("i", $product_id);
$stmt_prod->execute();
$product = $stmt_prod->get_result()->fetch_assoc();
$stmt_prod->close();

if (!$product) {
    set_flashdata('error', 'Produk tidak ditemukan.');
    redirect('/index.php');
}

$quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product_id);

// Cek Stok Total
if (($quantity_in_cart + $quantity_to_add) > $product['stock']) {
    set_flashdata('error', "Stok untuk '" . htmlspecialchars($product['name']) . "' tidak mencukupi. Sisa stok: " . $product['stock']);
    redirect($redirect_url);
}

// Cek Limit Pembelian (hanya untuk user yang login)
if ($user_id > 0 && $product['purchase_limit'] > 0) {
    // PERBAIKAN: Kirim 4 argumen ke fungsi
    $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
    
    if (($already_bought + $quantity_in_cart + $quantity_to_add) > $product['purchase_limit']) {
        $sisa_kuota = max(0, $product['purchase_limit'] - ($already_bought + $quantity_in_cart));
        $message = "Batas pembelian untuk '" . htmlspecialchars($product['name']) . "' adalah " . $product['purchase_limit'] . " buah. ";
        $message .= $sisa_kuota > 0 ? "Anda hanya dapat menambahkan {$sisa_kuota} buah lagi." : "Anda telah mencapai batas pembelian.";
        set_flashdata('error', $message);
        redirect($redirect_url);
    }
}

// --- LOGIKA UTAMA ---
if ($user_id > 0) {
    $stmt = $conn->prepare("
        INSERT INTO cart (user_id, product_id, quantity) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->bind_param("iii", $user_id, $product_id, $quantity_to_add);
    $stmt->execute();
    $stmt->close();
} else { 
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['quantity'] += $quantity_to_add;
    } else {
        $_SESSION['cart'][$product_id] = ['quantity' => $quantity_to_add];
    }
}

set_flashdata('success', "'" . htmlspecialchars($product['name']) . "' berhasil ditambahkan ke keranjang.");
redirect($redirect_url);
?>