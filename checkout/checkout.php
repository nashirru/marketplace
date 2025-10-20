<?php
// File: checkout/checkout.php - Logika Pemrosesan Checkout

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

check_login();

$user_id = $_SESSION['user_id'];

// --- LOGIKA PEMROSESAN CHECKOUT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. VALIDASI DAN SANITASI DATA ALAMAT
    $address_data = [
        'full_name'      => sanitize_input($_POST['full_name'] ?? ''),
        'phone_number'   => sanitize_input($_POST['phone_number'] ?? ''),
        'province'       => sanitize_input($_POST['province'] ?? ''),
        'city'           => sanitize_input($_POST['city'] ?? ''),
        'subdistrict'    => sanitize_input($_POST['subdistrict'] ?? ''),
        'postal_code'    => sanitize_input($_POST['postal_code'] ?? ''),
        'address_line_1' => sanitize_input($_POST['address_line_1'] ?? ''),
        'address_line_2' => sanitize_input($_POST['address_line_2'] ?? '')
    ];
    $payment_method_id = (int)($_POST['payment_method'] ?? 0);
    
    if (empty($payment_method_id) || empty($address_data['full_name']) || empty($address_data['phone_number']) || empty($address_data['province']) || empty($address_data['city']) || empty($address_data['address_line_1'])) {
        set_flashdata('error', 'Semua kolom alamat yang bertanda bintang (*) wajib diisi.');
        redirect("/checkout/checkout.php");
    }

    try {
        $conn->begin_transaction();

        // 2. AMBIL ITEM KERANJANG (sekarang dengan stock_cycle_id)
        $cart_items = get_cart_items($conn, $user_id);
        if (empty($cart_items)) {
            throw new Exception('Keranjang Anda kosong.');
        }

        // 3. PENGECEKAN FINAL STOK & LIMIT (DENGAN CYCLE ID)
        foreach ($cart_items as $item) {
            $stmt_prod = $conn->prepare("SELECT name, stock, purchase_limit, stock_cycle_id FROM products WHERE id = ? FOR UPDATE");
            $stmt_prod->bind_param("i", $item['product_id']);
            $stmt_prod->execute();
            $product = $stmt_prod->get_result()->fetch_assoc();
            $stmt_prod->close();

            if (!$product || $product['stock'] < $item['quantity']) {
                throw new Exception("Stok untuk '" . htmlspecialchars($item['name']) . "' tidak mencukupi. Sisa stok: " . ($product['stock'] ?? 0));
            }

            if ($product['purchase_limit'] > 0) {
                // PERBAIKAN: Memanggil fungsi dengan 4 argumen
                $already_bought = get_user_purchase_count($conn, $user_id, $item['product_id'], $product['stock_cycle_id']);
                if (($already_bought + $item['quantity']) > $product['purchase_limit']) {
                    $sisa_kuota = max(0, $product['purchase_limit'] - $already_bought);
                    $message = "Pembelian '" . htmlspecialchars($item['name']) . "' melebihi batas. Limit: {$product['purchase_limit']}. Anda sudah membeli: {$already_bought}. Sisa kuota Anda: {$sisa_kuota}.";
                    throw new Exception($message);
                }
            }
        }
        
        // 4. SIMPAN ALAMAT
        $address_id = save_or_get_user_address($conn, $user_id, $address_data);
        if (isset($_POST['is_default'])) {
            set_default_address($conn, $user_id, $address_id);
        }

        // 5. BUAT PESANAN
        $total = array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cart_items));
        $order_number = generate_order_number($conn);
        $order_hash = generate_order_hash();
        $status = 'waiting_payment'; // Siapkan variabel status

        // PERBAIKAN: Menggunakan placeholder '?' untuk status
        $stmt_order = $conn->prepare("INSERT INTO orders (order_hash, user_id, order_number, user_address_id, total, payment_method_id, status, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // PERBAIKAN: Menambahkan variabel $status ke bind_param, total 15 variabel
        $stmt_order->bind_param("sisidisssssssss", $order_hash, $user_id, $order_number, $address_id, $total, $payment_method_id, $status, $address_data['full_name'], $address_data['phone_number'], $address_data['province'], $address_data['city'], $address_data['subdistrict'], $address_data['postal_code'], $address_data['address_line_1'], $address_data['address_line_2']);
        $stmt_order->execute();
        $order_id = $conn->insert_id;
        $stmt_order->close();
        
        // 6. PINDAHKAN ITEM, UPDATE STOK, DAN CATAT RIWAYAT PEMBELIAN
        $stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
        $stmt_record = $conn->prepare("INSERT INTO user_purchase_records (user_id, product_id, stock_cycle_id, quantity_purchased) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE quantity_purchased = quantity_purchased + VALUES(quantity_purchased)");

        foreach ($cart_items as $item) {
            $stmt_item->bind_param("iiid", $order_id, $item['product_id'], $item['quantity'], $item['price']);
            $stmt_item->execute();
            
            $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
            $stmt_stock->execute();
            
            $stmt_record->bind_param("iiii", $user_id, $item['product_id'], $item['stock_cycle_id'], $item['quantity']);
            $stmt_record->execute();
        }
        $stmt_item->close();
        $stmt_stock->close();
        $stmt_record->close();

        // 7. KOSONGKAN KERANJANG
        $stmt_clear = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt_clear->bind_param("i", $user_id);
        $stmt_clear->execute();
        $stmt_clear->close();

        $conn->commit();

        create_notification($conn, $user_id, "Pesanan baru #{$order_number} telah dibuat. Segera lakukan pembayaran.");
        redirect("/checkout/upload.php?hash=" . $order_hash);

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Checkout Error: " . $e->getMessage());
        set_flashdata('error', $e->getMessage());
        redirect("/cart/cart.php");
    }
}

// --- TAMPILAN HALAMAN ---
$cart_items = get_cart_items_for_display($conn, $user_id);
$subtotal = 0;
foreach ($cart_items as &$item) {
    $item['subtotal'] = $item['price'] * $item['quantity'];
    $subtotal += $item['subtotal'];
}
unset($item);

$payment_methods = get_payment_methods($conn);
$default_address = get_default_user_address($conn, $user_id) ?: get_first_user_address($conn, $user_id);

$page_title = "Checkout";
?>
<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . get_setting($conn, 'store_name'), $conn); ?>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <div class="container mx-auto p-4 md:p-8">
        <h1 class="text-3xl font-bold text-indigo-800 mb-6">Konfirmasi Pesanan Anda</h1>

        <?php flash_message(); ?>

        <?php if (empty($cart_items)): ?>
            <div class="bg-white p-8 rounded-lg shadow text-center">
                <p class="text-gray-500 text-lg">Keranjang Anda kosong. Tidak ada yang bisa di-checkout.</p>
                <a href="<?= BASE_URL ?>/" class="mt-4 inline-block px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
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
                                <input type="text" name="full_name" required value="<?= htmlspecialchars($default_address['full_name'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nomor Telepon *</label>
                                <input type="text" name="phone_number" required value="<?= htmlspecialchars($default_address['phone_number'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Provinsi *</label><input type="text" name="province" required value="<?= htmlspecialchars($default_address['province'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Kota / Kabupaten *</label><input type="text" name="city" required value="<?= htmlspecialchars($default_address['city'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Kecamatan *</label><input type="text" name="subdistrict" required value="<?= htmlspecialchars($default_address['subdistrict'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Kode Pos *</label><input type="text" name="postal_code" required value="<?= htmlspecialchars($default_address['postal_code'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg"></div>
                        </div>

                        <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Alamat Lengkap *</label><textarea name="address_line_1" rows="2" required class="w-full p-3 border border-gray-300 rounded-lg"><?= htmlspecialchars($default_address['address_line_1'] ?? '') ?></textarea></div>
                        <div class="mt-4"><label class="block text-sm font-medium text-gray-700 mb-1">Detail Tambahan (Opsional)</label><input type="text" name="address_line_2" value="<?= htmlspecialchars($default_address['address_line_2'] ?? '') ?>" class="w-full p-3 border border-gray-300 rounded-lg"></div>
                        <div class="mt-4"><label class="flex items-center"><input type="checkbox" name="is_default" value="1" class="h-4 w-4 text-indigo-600 border-gray-300 rounded"><span class="ml-2 text-sm text-gray-700">Simpan sebagai alamat baru & jadikan utama</span></label></div>
                    </div>

                    <!-- Metode Pembayaran -->
                    <div class="bg-white p-6 rounded-lg shadow">
                        <h2 class="text-xl font-semibold text-indigo-700 mb-4 flex items-center">
                            <i class="fas fa-credit-card mr-2"></i> Metode Pembayaran
                        </h2>
                        <div class="space-y-3">
                            <?php foreach ($payment_methods as $method): ?>
                                <label class="flex items-start p-4 border border-gray-200 rounded-lg hover:bg-indigo-50 cursor-pointer transition">
                                    <input type="radio" name="payment_method" value="<?= $method['id'] ?>" required class="mt-1 h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500">
                                    <div class="ml-3 flex-1">
                                        <span class="block font-bold text-gray-900"><?= htmlspecialchars($method['name']) ?></span>
                                        <span class="block text-sm text-gray-600 mt-1"><?= nl2br(htmlspecialchars($method['details'])) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
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
                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-12 h-12 object-cover rounded">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['name']) ?></p>
                                        <p class="text-xs text-gray-500">Qty: <?= $item['quantity'] ?></p>
                                    </div>
                                    <span class="text-sm font-semibold"><?= format_rupiah($item['subtotal']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="space-y-2 pt-4 border-t">
                            <div class="flex justify-between text-gray-700"><span>Subtotal Produk</span><span><?= format_rupiah($subtotal) ?></span></div>
                            <div class="flex justify-between text-gray-700"><span>Biaya Pengiriman</span><span>Gratis</span></div>
                            <div class="flex justify-between text-xl font-bold text-indigo-800 pt-2 border-t"><span>TOTAL</span><span><?= format_rupiah($subtotal) ?></span></div>
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