<?php
// File: cart/add_to_cart.php

require_once '../config/config.php';
require_once '../sistem/sistem.php';

$user_id = $_SESSION['user_id'] ?? 0;
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$quantity_to_add = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;
$action = $_POST['action'] ?? 'add';
$redirect_url = $_SERVER['HTTP_REFERER'] ?? '/index.php';

if ($product_id <= 0 || $quantity_to_add <= 0) {
    set_flashdata('error', 'Data tidak valid.');
    redirect($redirect_url);
}

// Ambil data produk (stok dan limit)
// Note: purchase_limit 0 atau NULL berarti unlimited
$stmt_prod = $conn->prepare("SELECT stock, purchase_limit, name FROM products WHERE id = ?");
$stmt_prod->bind_param("i", $product_id);
$stmt_prod->execute();
$product = $stmt_prod->get_result()->fetch_assoc();
$stmt_prod->close();

if (!$product) {
    set_flashdata('error', 'Produk tidak ditemukan.');
    redirect('/index.php');
}

// Cek Stok
if ($quantity_to_add > $product['stock']) {
    set_flashdata('error', "Stok produk '" . htmlspecialchars($product['name']) . "' tidak mencukupi.");
    redirect($redirect_url);
}

// Cek Limit Pembelian (menggunakan fungsi yang sudah diperbarui di sistem.php)
if ($user_id > 0 && !is_null($product['purchase_limit']) && $product['purchase_limit'] > 0) {
    // get_user_purchase_count kini hanya menghitung pembelian sejak last_stock_reset
    $already_bought = get_user_purchase_count($conn, $user_id, $product_id);
    $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product_id);
    
    // Perhitungan total yang akan dimiliki jika item ini ditambahkan
    $total_future_quantity = $already_bought + $quantity_in_cart + $quantity_to_add;

    if ($total_future_quantity > $product['purchase_limit']) {
        // Hitung kuota yang tersisa untuk ditambahkan ke keranjang
        $sisa_kuota_add = $product['purchase_limit'] - ($already_bought + $quantity_in_cart);
        $sisa_kuota_add = max(0, $sisa_kuota_add); // Pastikan tidak negatif

        // Jika kuota add_to_cart adalah 0, berikan pesan error
        if ($sisa_kuota_add === 0) {
             set_flashdata('error', "Anda telah mencapai batas pembelian {$product['purchase_limit']} untuk produk ini.");
        } else {
             // Jika kuota add_to_cart tidak 0, tapi kuantitas yang diminta > kuota
             set_flashdata('error', "Anda hanya dapat menambahkan maksimal {$sisa_kuota_add} buah lagi (Batas Pembelian: {$product['purchase_limit']} buah).");
        }
        
        redirect($redirect_url);
    }
}

// Jika user login, simpan ke database
if ($user_id > 0) {
    $quantity_in_cart = get_quantity_in_cart($conn, $user_id, $product_id);

    if ($quantity_in_cart > 0) {
        $new_quantity = $quantity_in_cart + $quantity_to_add;
        $stmt_update = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmt_update->bind_param("iii", $new_quantity, $user_id, $product_id);
        $stmt_update->execute();
        $stmt_update->close();
    } else {
        $stmt_insert = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("iii", $user_id, $product_id, $quantity_to_add);
        $stmt_insert->execute();
        $stmt_insert->close();
    }
} else { // Jika guest, simpan ke session
    // Logika limit tidak berlaku untuk guest/session cart
    if (isset($_SESSION['cart'][$product_id])) {
        $_SESSION['cart'][$product_id]['quantity'] += $quantity_to_add;
    } else {
        $_SESSION['cart'][$product_id] = [
            'quantity' => $quantity_to_add
        ];
    }
}

set_flashdata('success', "'" . htmlspecialchars($product['name']) . "' berhasil ditambahkan ke keranjang.");
redirect($redirect_url);

?>