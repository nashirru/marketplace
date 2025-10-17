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

// Ambil data produk (stok, limit, nama)
$stmt_prod = $conn->prepare("SELECT stock, purchase_limit, name FROM products WHERE id = ?");
$stmt_prod->bind_param("i", $product_id);
$stmt_prod->execute();
$product = $stmt_prod->get_result()->fetch_assoc();
$stmt_prod->close();

if (!$product) {
    set_flashdata('error', 'Produk tidak ditemukan.');
    redirect('/index.php');
}

// Cek Stok Total (termasuk yang sudah ada di keranjang)
$quantity_in_cart = 0;
if ($user_id > 0) {
    $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product_id);
} else {
    $quantity_in_cart = $_SESSION['cart'][$product_id]['quantity'] ?? 0;
}

if (($quantity_in_cart + $quantity_to_add) > $product['stock']) {
    set_flashdata('error', "Stok produk '" . htmlspecialchars($product['name']) . "' tidak mencukupi.");
    redirect($redirect_url);
}

// Cek Limit Pembelian (hanya untuk user yang login)
if ($user_id > 0 && !is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) {
    $already_bought = get_user_purchase_count($conn, $user_id, $product_id);
    
    $total_future_quantity = $already_bought + $quantity_in_cart + $quantity_to_add;

    if ($total_future_quantity > $product['purchase_limit']) {
        $sisa_kuota = max(0, $product['purchase_limit'] - ($already_bought + $quantity_in_cart));
        $message = $sisa_kuota > 0 ? "Anda hanya dapat menambahkan maksimal {$sisa_kuota} buah lagi." : "Anda telah mencapai batas pembelian untuk produk ini.";
        set_flashdata('error', $message);
        redirect($redirect_url);
    }
}

// --- LOGIKA UTAMA: TAMBAH ATAU UPDATE KUANTITAS ---
if ($user_id > 0) {
    // Pengguna sudah login, langsung ke database
    $stmt_check = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt_check->bind_param("ii", $user_id, $product_id);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    
    if ($result->num_rows > 0) {
        // Produk sudah ada, UPDATE kuantitas
        $existing_item = $result->fetch_assoc();
        $new_quantity = $existing_item['quantity'] + $quantity_to_add;
        $stmt_update = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmt_update->bind_param("iii", $new_quantity, $user_id, $product_id);
        $stmt_update->execute();
        $stmt_update->close();
    } else {
        // Produk belum ada, INSERT baru
        $stmt_insert = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("iii", $user_id, $product_id, $quantity_to_add);
        $stmt_insert->execute();
        $stmt_insert->close();
    }
    $stmt_check->close();
} else { 
    // Pengguna belum login, simpan ke session
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['quantity'] += $quantity_to_add;
    } else {
        $_SESSION['cart'][$product_id] = ['quantity' => $quantity_to_add];
    }
}

set_flashdata('success', "'" . htmlspecialchars($product['name']) . "' berhasil ditambahkan ke keranjang.");
redirect($redirect_url);
?>