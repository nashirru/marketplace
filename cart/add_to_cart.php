<?php
// File: cart/add_to_cart.php
// VERSI SECURITY UPDATE FINAL: Strict Variation, Integrity Check & Global Product Limit
// Programmer IQ 180 Edition: Multi-Layered Validation System

require_once '../config/config.php';
require_once '../sistem/sistem.php';

$user_id = $_SESSION['user_id'] ?? 0;

// Validasi Input Dasar
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
// Tangkap Variation ID (Bisa null jika produk tidak ada variasi)
$variation_id = isset($_POST['variation_id']) && $_POST['variation_id'] !== '' && $_POST['variation_id'] !== '0' ? (int)$_POST['variation_id'] : null;
$quantity_to_add = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 0;

$redirect_url = $_SERVER['HTTP_REFERER'] ?? '/index.php';

if ($product_id <= 0 || $quantity_to_add <= 0) {
    set_flashdata('error', 'Data produk tidak valid.');
    redirect($redirect_url);
}

// =================================================================================
// LAYER 1: STRICT SYSTEM VALIDATION (PENGECEKAN BERLAPIS - ANTI MANIPULASI)
// =================================================================================

// 1. Ambil Info Dasar Produk (Status & Flag Variasi)
$stmt_check = $conn->prepare("SELECT name, has_variation, is_active FROM products WHERE id = ?");
$stmt_check->bind_param("i", $product_id);
$stmt_check->execute();
$prod_basic = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();

// Check Keberadaan Produk
if (!$prod_basic) {
    set_flashdata('error', 'Produk tidak ditemukan dalam database.');
    redirect($redirect_url);
}

// Check Status Aktif
if ($prod_basic['is_active'] == 0) {
    set_flashdata('error', 'Maaf, produk ini sedang tidak aktif dan tidak dapat dibeli.');
    redirect($redirect_url);
}

// 2. Logic Pengecekan Wajib Variasi (CRITICAL POINT)
if ($prod_basic['has_variation'] == 1) {
    // LAYER 1.1: Cek keberadaan ID Variasi dari Input
    if (empty($variation_id)) {
        set_flashdata('error', 'SECURITY ALERT: Produk ini memiliki variasi. Anda WAJIB memilih variasi (Warna/Ukuran) sebelum menambahkan ke keranjang.');
        redirect($redirect_url);
    }

    // LAYER 1.2: Validasi Integritas Data (Anti-Hack)
    // Memastikan ID Variasi benar-benar milik ID Produk tersebut di Database
    $stmt_integrity = $conn->prepare("SELECT id, stock FROM product_variations WHERE id = ? AND product_id = ?");
    $stmt_integrity->bind_param("ii", $variation_id, $product_id);
    $stmt_integrity->execute();
    $var_integrity = $stmt_integrity->get_result()->fetch_assoc();
    $stmt_integrity->close();

    if (!$var_integrity) {
        set_flashdata('error', 'SECURITY ALERT: Data variasi tidak valid atau tidak cocok dengan produk ini. Permintaan ditolak.');
        redirect($redirect_url);
    }
    
    // LAYER 1.3: Cek Stok Variasi Spesifik
    if ($var_integrity['stock'] < $quantity_to_add) {
        set_flashdata('error', 'Stok variasi yang dipilih tidak mencukupi.');
        redirect($redirect_url);
    }
    
} else {
    // Jika produk TIDAK punya variasi, paksa reset variation_id jadi null untuk konsistensi DB
    $variation_id = null;
}

// =================================================================================
// END VALIDATION LAYER - LANJUT KE LOGIKA BISNIS
// =================================================================================


// 1. AMBIL DATA PRODUK & VARIASI LENGKAP (UNTUK DISPLAY & FINAL CHECK)
if ($variation_id) {
    // Ambil stok dari tabel variasi
    $stmt_prod = $conn->prepare("
        SELECT p.name, p.purchase_limit, p.stock_cycle_id, 
               pv.stock AS variation_stock, pv.name AS variation_name 
        FROM products p 
        JOIN product_variations pv ON p.id = pv.product_id 
        WHERE p.id = ? AND pv.id = ?
    ");
    $stmt_prod->bind_param("ii", $product_id, $variation_id);
    $stmt_prod->execute();
    $product = $stmt_prod->get_result()->fetch_assoc();
    $stmt_prod->close();

    if ($product) {
        $real_stock = (int)$product['variation_stock'];
        $product_name_display = $product['name'] . ' (' . $product['variation_name'] . ')';
    }
} else {
    // Ambil dari tabel produk biasa
    $stmt_prod = $conn->prepare("SELECT stock, purchase_limit, name, stock_cycle_id FROM products WHERE id = ?");
    $stmt_prod->bind_param("i", $product_id);
    $stmt_prod->execute();
    $product = $stmt_prod->get_result()->fetch_assoc();
    $stmt_prod->close();
    
    if ($product) {
        $real_stock = (int)$product['stock'];
        $product_name_display = $product['name'];
    }
}

if (!$product) {
    set_flashdata('error', 'Terjadi kesalahan sistem saat mengambil detail produk.');
    redirect('/index.php');
}


// 2. HITUNG JUMLAH DI CART (METODE CERDAS: PISAH ANTARA VARIASI & GLOBAL)

// A. Hitung Quantity Spesifik Variasi (Untuk Cek STOK Variasi)
$qty_in_cart_specific = 0; 
// B. Hitung Total Quantity Produk ini di Cart (Untuk Cek LIMIT Pembelian Global)
$qty_in_cart_global = 0; 

if ($user_id > 0) {
    // Query Database untuk User Login
    $stmt_check = $conn->prepare("SELECT variation_id, quantity FROM cart WHERE user_id = ? AND product_id = ?");
    $stmt_check->bind_param("ii", $user_id, $product_id);
    $stmt_check->execute();
    $res_check = $stmt_check->get_result();
    
    while ($row = $res_check->fetch_assoc()) {
        $qty_in_cart_global += (int)$row['quantity']; // Tambahkan ke global
        
        // Cek apakah ini variasi yang sedang ditambahkan?
        if ($variation_id) {
            if ($row['variation_id'] == $variation_id) $qty_in_cart_specific += (int)$row['quantity'];
        } else {
            if (empty($row['variation_id'])) $qty_in_cart_specific += (int)$row['quantity'];
        }
    }
    $stmt_check->close();

} else {
    // Cek Session Cart (Guest)
    if (isset($_SESSION['cart'][$product_id])) {
        // PERHATIKAN: Logic Session Cart harus mendukung multi-dimensi atau struktur yang konsisten
        // Di sini kita menangani kasus simpel (overwrite) atau kompleks tergantung implementasi session cart Anda.
        // Asumsi: Session menyimpan array quantity dan variation_id.
        
        // Cek apakah session menyimpan ID ini sebagai array tunggal atau list
        $sess_item = $_SESSION['cart'][$product_id];
        
        if (isset($sess_item['quantity'])) {
            // Logic Lama / Single Item
            $sess_qty = (int)$sess_item['quantity'];
            $sess_var = $sess_item['variation_id'] ?? null;
            
            $qty_in_cart_global = $sess_qty;
            if ($sess_var == $variation_id) {
                $qty_in_cart_specific = $sess_qty;
            }
        }
    }
}


// 3. PENGECEKAN STOK FINAL (Gunakan qty_in_cart_specific)
// Kita cek apakah (jumlah variasi ini di keranjang + yg mau ditambah) > stok variasi ini?
if (($qty_in_cart_specific + $quantity_to_add) > $real_stock) {
    set_flashdata('error', "Stok untuk '" . htmlspecialchars($product_name_display) . "' tidak mencukupi. Sisa stok: " . $real_stock);
    redirect($redirect_url);
}


// 4. PENGECEKAN LIMIT PEMBELIAN (Gunakan qty_in_cart_global)
// Kita cek apakah (total semua variasi produk ini di keranjang + yg mau ditambah) > Limit Produk?
$purchase_limit = (int)$product['purchase_limit'];

if ($purchase_limit > 0) {
    // Hitung total attempt global
    $total_attempt_global = $qty_in_cart_global + $quantity_to_add;

    if ($user_id > 0) {
        // --- LOGIKA UNTUK USER LOGIN ---
        // Ambil riwayat pembelian SUKSES & PENDING dari database
        // (Database purchase record sudah menghitung per Product ID, jadi sudah Global)
        $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
        $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
        
        $total_history = $already_bought + $pending_bought;
        
        if (($total_history + $total_attempt_global) > $purchase_limit) {
            $remaining_quota = max(0, $purchase_limit - $total_history);
            // $can_add = max(0, $remaining_quota - $qty_in_cart_global);
            
            $message = "Gagal! Batas pembelian total untuk produk ini (semua variasi) adalah " . $purchase_limit . " buah. ";
            if ($total_history > 0) $message .= "Riwayat (Beli+Pending): {$total_history}. ";
            if ($qty_in_cart_global > 0) $message .= "Di keranjang: {$qty_in_cart_global}. ";
            $message .= "Sisa kuota Anda: " . max(0, $remaining_quota - $qty_in_cart_global);
            
            set_flashdata('error', $message);
            redirect($redirect_url);
        }
        
    } else {
        // --- LOGIKA UNTUK GUEST ---
        if ($total_attempt_global > $purchase_limit) {
            $message = "Gagal! Batas pembelian produk ini adalah {$purchase_limit}. Anda sudah punya {$qty_in_cart_global} item produk ini di keranjang.";
            set_flashdata('error', $message);
            redirect($redirect_url);
        }
    }
}


// 5. INSERT KE DATABASE / SESSION (EXECUTION LAYER)
if ($user_id > 0) {
    // A. LOGIKA USER LOGIN (Database)
    // Menggunakan parameter yang lebih aman untuk cek duplikat
    $sql_exist = "SELECT id FROM cart WHERE user_id = ? AND product_id = ?";
    if ($variation_id) {
        $sql_exist .= " AND variation_id = " . $variation_id;
    } else {
        $sql_exist .= " AND (variation_id IS NULL OR variation_id = 0)";
    }
    
    $stmt_exist = $conn->prepare($sql_exist);
    $stmt_exist->bind_param("ii", $user_id, $product_id);
    $stmt_exist->execute();
    $existing_item = $stmt_exist->get_result()->fetch_assoc();
    $stmt_exist->close();

    if ($existing_item) {
        // UPDATE: Tambah quantity
        $stmt_up = $conn->prepare("UPDATE cart SET quantity = quantity + ? WHERE id = ?");
        $stmt_up->bind_param("ii", $quantity_to_add, $existing_item['id']);
        $stmt_up->execute();
        $stmt_up->close();
    } else {
        // INSERT BARU
        $stmt_in = $conn->prepare("INSERT INTO cart (user_id, product_id, variation_id, quantity) VALUES (?, ?, ?, ?)");
        $stmt_in->bind_param("iiii", $user_id, $product_id, $variation_id, $quantity_to_add);
        $stmt_in->execute();
        $stmt_in->close();
    }

} else { 
    // B. LOGIKA GUEST SESSION
    // Kita simpan di session dengan format yang mendukung variasi
    
    // Opsi: Jika ingin support multi variasi di Guest, logic ini harus diubah.
    // Tapi untuk menjaga kompatibilitas dengan sisa kode Anda yang pakai key product_id:
    // Kita hanya akan menyimpan SATU variasi per produk ID untuk Guest.
    // (Jika Guest tambah variasi lain, akan menimpa yang lama -> Ini batasan session sederhana)
    
    if (isset($_SESSION['cart'][$product_id])) {
        $current_var = $_SESSION['cart'][$product_id]['variation_id'] ?? null;
        
        if ($current_var == $variation_id) {
            $_SESSION['cart'][$product_id]['quantity'] += $quantity_to_add;
        } else {
            // Replace item jika variasi beda (karena session key pakai product_id)
            $_SESSION['cart'][$product_id] = [
                'quantity' => $quantity_to_add,
                'variation_id' => $variation_id
            ];
        }
    } else {
        $_SESSION['cart'][$product_id] = [
            'quantity' => $quantity_to_add,
            'variation_id' => $variation_id
        ];
    }
}

set_flashdata('success', "'" . htmlspecialchars($product_name_display) . "' berhasil ditambahkan ke keranjang.");
redirect('/cart/cart.php');
?>