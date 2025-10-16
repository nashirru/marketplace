<?php
// File: checkout/checkout.php - Logika Pemrosesan Checkout

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

check_login();

$user_id = $_SESSION['user_id'];

// --- LOGIKA PEMROSESAN CHECKOUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. VALIDASI DAN SANITASI DATA
    $payment_method_id = (int)($_POST['payment_method'] ?? 0);
    $full_name = sanitize_input($_POST['full_name'] ?? '');
    $phone_number = sanitize_input($_POST['phone_number'] ?? '');
    $province = sanitize_input($_POST['province'] ?? '');
    $city = sanitize_input($_POST['city'] ?? '');
    $subdistrict = sanitize_input($_POST['subdistrict'] ?? '');
    $postal_code = sanitize_input($_POST['postal_code'] ?? '');
    $address_line_1 = sanitize_input($_POST['address_line_1'] ?? '');
    $address_line_2 = sanitize_input($_POST['address_line_2'] ?? '');
    $is_default = isset($_POST['is_default']) ? 1 : 0;

    // Cek apakah data yang dibutuhkan sudah lengkap
    if (empty($payment_method_id) || empty($full_name) || empty($phone_number) || 
        empty($province) || empty($city) || empty($address_line_1)) {
        set_flashdata('error', 'Semua kolom alamat wajib diisi (kecuali Detail Tambahan).');
        header("Location: " . BASE_URL . "/checkout/checkout.php");
        exit;
    }

    // 2. LOGIKA PENYIMPANAN/PEMBARUAN ALAMAT PENGGUNA
    try {
        $conn->begin_transaction();

        $address_id = null;
        // Cek apakah alamat sudah ada
        $stmt_check = $conn->prepare("
            SELECT id FROM user_addresses 
            WHERE user_id = ? AND full_name = ? AND phone_number = ? 
            AND province = ? AND city = ? AND subdistrict = ? 
            AND postal_code = ? AND address_line_1 = ? AND address_line_2 = ?
        ");
        $stmt_check->bind_param("issssssss", $user_id, $full_name, $phone_number, 
            $province, $city, $subdistrict, $postal_code, $address_line_1, $address_line_2);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $existing_address = $result_check->fetch_assoc();
            $address_id = $existing_address['id'];
        } else {
            // Insert alamat baru
            $stmt_insert = $conn->prepare("
                INSERT INTO user_addresses 
                (user_id, full_name, phone_number, province, city, subdistrict, 
                postal_code, address_line_1, address_line_2) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt_insert->bind_param("issssssss", $user_id, $full_name, $phone_number, 
                $province, $city, $subdistrict, $postal_code, $address_line_1, 
                $address_line_2);
            $stmt_insert->execute();
            $address_id = $conn->insert_id;
            $stmt_insert->close();
        }
        $stmt_check->close();
        
        // Handle is_default
        if ($is_default == 1) {
            $stmt_default_off = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ? AND id != ?");
            $stmt_default_off->bind_param("ii", $user_id, $address_id);
            $stmt_default_off->execute();
            $stmt_default_off->close();

            $stmt_default_on = $conn->prepare("UPDATE user_addresses SET is_default = 1 WHERE id = ?");
            $stmt_default_on->bind_param("i", $address_id);
            $stmt_default_on->execute();
            $stmt_default_on->close();
        }


        // 3. AMBIL ITEM KERANJANG DAN HITUNG TOTAL
        $cart_items = get_cart_items($conn, $user_id);

        if (empty($cart_items)) {
            set_flashdata('error', 'Keranjang Anda kosong. Tidak dapat memproses checkout.');
            $conn->rollback();
            header("Location: " . BASE_URL . "/cart/cart.php");
            exit;
        }

        $total = 0;
        foreach ($cart_items as $item) {
            // ✅ PERBAIKAN: Hitung subtotal secara manual di sini
            $total += $item['price'] * $item['quantity'];
        }

        // 4. GENERATE ORDER NUMBER DAN HASH
        $order_number = generate_order_number($conn);
        $order_hash = generate_order_hash();

        // 5. INSERT KE TABEL ORDERS
        $status = 'waiting_payment';
        $stmt_order = $conn->prepare("
            INSERT INTO orders 
            (order_hash, user_id, order_number, user_address_id, total, 
            payment_method_id, status, full_name, phone_number, province, 
            city, subdistrict, postal_code, address_line_1, address_line_2) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_order->bind_param("sisidisssssssss", 
            $order_hash, $user_id, $order_number, $address_id, $total, $payment_method_id, 
            $status, $full_name, $phone_number, $province, $city, $subdistrict, 
            $postal_code, $address_line_1, $address_line_2
        );
        $stmt_order->execute();
        $order_id = $conn->insert_id;
        $stmt_order->close();
        
        // 6. INSERT ORDER ITEMS DAN UPDATE STOK
        $stmt_item = $conn->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, price) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt_stock = $conn->prepare("
            UPDATE products 
            SET stock = stock - ? 
            WHERE id = ? AND stock >= ?
        ");
        
        // ✅ FITUR BARU: Menyiapkan query untuk mencatat pembelian user
        $stmt_purchase_record = $conn->prepare("
            INSERT INTO user_purchase_records (user_id, product_id, quantity_bought)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity_bought = quantity_bought + VALUES(quantity_bought)
        ");

        foreach ($cart_items as $item) {
            // Insert item
            $stmt_item->bind_param("iiid", $order_id, $item['product_id'], 
                $item['quantity'], $item['price']);
            $stmt_item->execute();
            
            // Update stok
            $stmt_stock->bind_param("iii", $item['quantity'], 
                $item['product_id'], $item['quantity']);
            $stmt_stock->execute();
            
            // Cek apakah stok cukup
            if ($stmt_stock->affected_rows === 0) {
                $conn->rollback();
                set_flashdata('error', 'Stok produk "' . htmlspecialchars($item['name']) . '" tidak mencukupi.');
                header("Location: " . BASE_URL . "/cart/cart.php");
                exit;
            }

            // ✅ FITUR BARU: Jalankan query untuk mencatat pembelian
            $stmt_purchase_record->bind_param("iii", $user_id, $item['product_id'], $item['quantity']);
            $stmt_purchase_record->execute();
        }
        $stmt_item->close();
        $stmt_stock->close();
        $stmt_purchase_record->close(); // Tutup statement

        // 7. KOSONGKAN KERANJANG
        $stmt_clear = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt_clear->bind_param("i", $user_id);
        $stmt_clear->execute();
        $stmt_clear->close();

        $conn->commit();

        set_flashdata('success', 'Pesanan berhasil dibuat. Silakan unggah bukti pembayaran.');
        header("Location: " . BASE_URL . "/checkout/upload.php?hash=" . $order_hash);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Checkout Error: " . $e->getMessage());
        set_flashdata('error', 'Terjadi kesalahan: ' . htmlspecialchars($e->getMessage()));
        header("Location: " . BASE_URL . "/checkout/checkout.php");
        exit;
    }
}

// --- PEMUATAN DATA UNTUK TAMPILAN ---
$cart_items = get_cart_items($conn, $user_id);
$subtotal = 0;
// ✅ PERBAIKAN: Hitung subtotal manual untuk tampilan juga
foreach ($cart_items as &$item) {
    $item['subtotal'] = $item['price'] * $item['quantity'];
    $subtotal += $item['subtotal'];
}
unset($item);

$payment_methods = get_payment_methods($conn);
$default_address = get_default_user_address($conn, $user_id);

if (!$default_address) {
    $default_address = get_first_user_address($conn, $user_id);
}

$address_data = [
    'full_name' => $default_address['full_name'] ?? '',
    'phone_number' => $default_address['phone_number'] ?? '',
    'province' => $default_address['province'] ?? '',
    'city' => $default_address['city'] ?? '',
    'subdistrict' => $default_address['subdistrict'] ?? '',
    'postal_code' => $default_address['postal_code'] ?? '',
    'address_line_1' => $default_address['address_line_1'] ?? '',
    'address_line_2' => $default_address['address_line_2'] ?? '',
    'is_default' => $default_address['is_default'] ?? 0,
];

$flash_message = get_flashdata('error') ?? get_flashdata('success');
$flash_type = isset($_SESSION['flashdata']['type']) ? $_SESSION['flashdata']['type'] : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <div class="container mx-auto p-4 md:p-8">
        <h1 class="text-3xl font-bold text-indigo-800 mb-6">Konfirmasi Pesanan Anda</h1>

        <?php flash_message(); ?>

        <?php if (empty($cart_items)): ?>
            <div class="bg-white p-8 rounded-lg shadow text-center">
                <p class="text-gray-500 text-lg">Keranjang Anda kosong. Tidak ada yang bisa di-checkout.</p>
                <a href="<?= BASE_URL ?>/index.php" class="mt-4 inline-block px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                    Mulai Belanja
                </a>
            </div>
        <?php else: ?>
            <form method="POST" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Kolom Kiri: Alamat & Pembayaran -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Alamat Pengiriman -->
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h2 class="text-xl font-semibold text-indigo-700 mb-4 flex items-center">
                            <i class="fas fa-shipping-fast mr-2"></i> Alamat Pengiriman
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nama Penerima *</label>
                                <input type="text" name="full_name" required value="<?= htmlspecialchars($address_data['full_name']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nomor Telepon *</label>
                                <input type="text" name="phone_number" required value="<?= htmlspecialchars($address_data['phone_number']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Provinsi *</label>
                                <input type="text" name="province" required value="<?= htmlspecialchars($address_data['province']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Kota / Kabupaten *</label>
                                <input type="text" name="city" required value="<?= htmlspecialchars($address_data['city']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Kecamatan *</label>
                                <input type="text" name="subdistrict" required value="<?= htmlspecialchars($address_data['subdistrict']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Kode Pos *</label>
                                <input type="text" name="postal_code" required value="<?= htmlspecialchars($address_data['postal_code']) ?>"
                                    class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_default" value="1" <?= $address_data['is_default'] ? 'checked' : '' ?>
                                        class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                    <span class="ml-2 text-sm text-gray-700">Jadikan Alamat Utama</span>
                                </label>
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Alamat Lengkap *</label>
                            <textarea name="address_line_1" rows="2" required
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"><?= htmlspecialchars($address_data['address_line_1']) ?></textarea>
                        </div>
                        <div class="mt-4">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Detail Tambahan (Opsional)</label>
                            <input type="text" name="address_line_2" value="<?= htmlspecialchars($address_data['address_line_2']) ?>"
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                    </div>

                    <!-- Metode Pembayaran -->
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h2 class="text-xl font-semibold text-indigo-700 mb-4 flex items-center">
                            <i class="fas fa-credit-card mr-2"></i> Metode Pembayaran
                        </h2>
                        <div class="space-y-3">
                            <?php foreach ($payment_methods as $method): ?>
                                <label class="flex items-start p-4 border border-gray-200 rounded-lg hover:bg-indigo-50 cursor-pointer transition">
                                    <input type="radio" name="payment_method" value="<?= $method['id'] ?>" required
                                        class="mt-1 h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                                    <div class="ml-3 flex-1">
                                        <?php if (!empty($method['logo'])): ?>
                                            <img src="<?= BASE_URL ?>/assets/images/payment/<?= htmlspecialchars($method['logo']) ?>" 
                                                alt="<?= htmlspecialchars($method['name']) ?>" class="h-8 mb-2">
                                        <?php endif; ?>
                                        <span class="block font-bold text-gray-900"><?= htmlspecialchars($method['name']) ?></span>
                                        <span class="block text-sm text-gray-600 mt-1"><?= nl2br(htmlspecialchars($method['details'])) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($payment_methods)): ?>
                                <p class="text-red-500">Tidak ada metode pembayaran tersedia. Hubungi Admin.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Rincian Pesanan -->
                <div class="lg:col-span-1">
                    <div class="bg-white p-6 rounded-lg shadow sticky top-4">
                        <h2 class="text-xl font-semibold text-indigo-700 mb-4 flex items-center">
                            <i class="fas fa-shopping-bag mr-2"></i> Rincian Pesanan
                        </h2>
                        
                        <div class="space-y-3 max-h-96 overflow-y-auto mb-4">
                            <?php foreach ($cart_items as $item): ?>
                                <div class="flex items-center space-x-3 pb-3 border-b">
                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" 
                                        alt="<?= htmlspecialchars($item['name']) ?>" class="w-12 h-12 object-cover rounded">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></p>
                                        <p class="text-xs text-gray-500">Qty: <?= $item['quantity'] ?></p>
                                    </div>
                                    <span class="text-sm font-semibold"><?= format_rupiah($item['subtotal']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="space-y-2 pt-4 border-t">
                            <div class="flex justify-between text-gray-700">
                                <span>Subtotal Produk</span>
                                <span><?= format_rupiah($subtotal) ?></span>
                            </div>
                            <div class="flex justify-between text-gray-700">
                                <span>Biaya Pengiriman</span>
                                <span>Gratis</span>
                            </div>
                            <div class="flex justify-between text-xl font-bold text-indigo-800 pt-2 border-t">
                                <span>TOTAL</span>
                                <span><?= format_rupiah($subtotal) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="mt-6 w-full px-4 py-3 bg-indigo-600 text-white font-bold rounded-lg hover:bg-indigo-700 transition">
                            Konfirmasi dan Bayar
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
    
    <?php footer($conn); ?>
</body>
</html>