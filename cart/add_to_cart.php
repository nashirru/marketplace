<?php
// File: cart/add_to_cart.php
// VERSI FIXED: Pengecekan limit yang benar (termasuk pending order DAN GUEST)

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
$purchase_limit = (int)$product['purchase_limit'];

// Pengecekan Stok Total
if (($quantity_in_cart + $quantity_to_add) > $product['stock']) {
    set_flashdata('error', "Stok untuk '" . htmlspecialchars($product['name']) . "' tidak mencukupi. Sisa stok: " . $product['stock']);
    redirect($redirect_url);
}

// ============================================================
// ✅ PERBAIKAN: CEK LIMIT PEMBELIAN (USER LOGIN & GUEST)
// ============================================================
if ($user_id > 0 && $purchase_limit > 0) {
    // --- LOGIKA UNTUK USER LOGIN ---
    $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
    $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
    
    $total_will_purchase = $already_bought + $pending_bought + $quantity_in_cart + $quantity_to_add;
    
    if ($total_will_purchase > $purchase_limit) {
        $total_committed = $already_bought + $pending_bought;
        $remaining_quota = max(0, $purchase_limit - $total_committed);
        $can_add_to_cart = max(0, $remaining_quota - $quantity_in_cart);
        
        $message = "Gagal! Batas pembelian untuk '" . htmlspecialchars($product['name']) . "' adalah " . $purchase_limit . " buah. ";
        if ($already_bought > 0) $message .= "Anda sudah membeli {$already_bought} buah. ";
        if ($pending_bought > 0) $message .= "Anda memiliki {$pending_bought} buah di pesanan yang menunggu pembayaran. ";
        if ($quantity_in_cart > 0) $message .= "Di keranjang: {$quantity_in_cart} buah. ";
        
        if ($can_add_to_cart > 0) {
            $message .= "Anda hanya dapat menambahkan {$can_add_to_cart} buah lagi.";
        } else {
            $message .= "Anda telah mencapai batas pembelian/pemesanan untuk cycle ini.";
        }
        
        set_flashdata('error', $message);
        redirect($redirect_url);
    }
    
} else if ($user_id == 0 && $purchase_limit > 0) {
    // --- ✅ LOGIKA BARU UNTUK GUEST ---
    // (get_quantity_in_cart sudah mengambil dari session jika user_id = 0)
    $total_will_purchase = $quantity_in_cart + $quantity_to_add;
    
    if ($total_will_purchase > $purchase_limit) {
        $can_add_to_cart = max(0, $purchase_limit - $quantity_in_cart);
        
        $message = "Gagal! Batas pembelian untuk '" . htmlspecialchars($product['name']) . "' adalah " . $purchase_limit . " buah. ";
        if ($quantity_in_cart > 0) $message .= "Anda sudah punya {$quantity_in_cart} buah di keranjang. ";
        
        if ($can_add_to_cart > 0) {
            $message .= "Anda hanya dapat menambahkan {$can_add_to_cart} buah lagi.";
        } else {
            $message .= "Anda telah mencapai batas pembelian di keranjang Anda.";
        }
        
        set_flashdata('error', $message);
        redirect($redirect_url);
    }
}

// --- LOGIKA UTAMA: Lolos Pengecekan ---
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
redirect('/cart/cart.php'); // Redirect ke cart agar user lihat hasilnya