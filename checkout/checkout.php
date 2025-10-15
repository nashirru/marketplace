<?php
// File: checkout/checkout.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;

if (!$is_logged_in) {
    $_SESSION['redirect_to'] = BASE_URL . '/checkout/checkout.php';
    set_flash_message('info', 'Anda harus login terlebih dahulu untuk melanjutkan ke pembayaran.');
    redirect('/login/login.php');
    exit;
}

// --- LOGIKA PENGISIAN ALAMAT OTOMATIS ---
$last_address = [];
$stmt_addr = $conn->prepare("SELECT full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2 FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt_addr->bind_param("i", $user_id);
$stmt_addr->execute();
$result_addr = $stmt_addr->get_result();
if ($result_addr->num_rows > 0) {
    $last_address = $result_addr->fetch_assoc();
}
$stmt_addr->close();

$cart_items = [];
$total_price = 0;
$stmt_cart = $conn->prepare("SELECT p.id, p.name, p.price, p.image, p.stock, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
$stmt_cart->bind_param("i", $user_id);
$stmt_cart->execute();
$result_cart = $stmt_cart->get_result();
while ($row = $result_cart->fetch_assoc()) {
    $cart_items[] = $row;
    $total_price += $row['price'] * $row['quantity'];
}
$stmt_cart->close();

if (empty($cart_items)) {
    set_flash_message('info', 'Keranjang Anda kosong. Silakan tambahkan produk terlebih dahulu.');
    redirect('/cart/cart.php');
    exit;
}

$payment_methods = [];
$result_pm = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1");
while ($row_pm = $result_pm->fetch_assoc()) {
    $payment_methods[] = $row_pm;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    $conn->begin_transaction();
    try {
        $full_name = sanitize_input($_POST['full_name']);
        $phone_number = sanitize_input($_POST['phone_number']);
        $province = sanitize_input($_POST['province']);
        $city = sanitize_input($_POST['city']);
        $subdistrict = sanitize_input($_POST['subdistrict']);
        $postal_code = sanitize_input($_POST['postal_code']);
        $address1 = sanitize_input($_POST['address_line_1']);
        $address2 = sanitize_input($_POST['address_line_2']);
        $payment_method_id = sanitize_input($_POST['payment_method_id']);

        $stmt_order = $conn->prepare("INSERT INTO orders (user_id, total, status, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, payment_method_id) VALUES (?, ?, 'waiting_payment', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // --- PERBAIKAN FINAL ---
        // Query memiliki 11 placeholder '?', jadi tipe data di sini juga harus 11 karakter.
        // i = integer (user_id), d = double (total_price), s = string (8 alamat), i = integer (payment_method_id)
        $stmt_order->bind_param("idssssssssi", $user_id, $total_price, $full_name, $phone_number, $province, $city, $subdistrict, $postal_code, $address1, $address2, $payment_method_id);
        
        $stmt_order->execute();
        $order_id = $stmt_order->insert_id;

        $created_at_for_hash = date('Y-m-d H:i:s');
        $conn->query("UPDATE orders SET created_at = '{$created_at_for_hash}' WHERE id = {$order_id}");
        
        $order_hash = md5($order_id . $user_id . $created_at_for_hash);
        $conn->query("UPDATE orders SET order_hash = '$order_hash' WHERE id = $order_id");

        $stmt_items = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        foreach ($cart_items as $item) {
            $stmt_items->bind_param("iiid", $order_id, $item['id'], $item['quantity'], $item['price']);
            $stmt_items->execute();
            $conn->query("UPDATE products SET stock = stock - {$item['quantity']} WHERE id = {$item['id']}");
        }

        $conn->query("DELETE FROM cart WHERE user_id = $user_id");
        $conn->commit();

        redirect('/checkout/invoice.php?oh=' . $order_hash);
        exit;
    } catch (Exception $e) {
        $conn->rollback();
        set_flash_message('error', 'Terjadi kesalahan saat membuat pesanan: ' . $e->getMessage());
        redirect('/checkout/checkout.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?= navbar($conn) ?>
    
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Checkout</h1>
        <?= flash_message('error'); ?>
        <form action="" method="POST">
             <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold mb-4">Alamat Pengiriman</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                            <input type="text" name="full_name" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['full_name'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nomor Telepon</label>
                            <input type="tel" name="phone_number" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['phone_number'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Provinsi</label>
                            <input type="text" name="province" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['province'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Kota/Kabupaten</label>
                            <input type="text" name="city" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['city'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Kecamatan</label>
                            <input type="text" name="subdistrict" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['subdistrict'] ?? '') ?>" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Kode Pos</label>
                            <input type="text" name="postal_code" class="mt-1 block w-full border-gray-300 rounded-md" value="<?= htmlspecialchars($last_address['postal_code'] ?? '') ?>" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Nama Jalan/Gedung/No. Rumah</label>
                            <textarea name="address_line_1" rows="2" class="mt-1 block w-full border-gray-300 rounded-md" required><?= htmlspecialchars($last_address['address_line_1'] ?? '') ?></textarea>
                        </div>
                         <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Detail Lainnya (Cth: Blok/Unit No., Patokan)</label>
                            <textarea name="address_line_2" rows="2" class="mt-1 block w-full border-gray-300 rounded-md"><?= htmlspecialchars($last_address['address_line_2'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <h2 class="text-xl font-bold mt-8 mb-4">Metode Pembayaran</h2>
                    <div class="space-y-3">
                        <?php if(empty($payment_methods)): ?>
                            <p class="text-red-500">Metode pembayaran belum dikonfigurasi oleh admin.</p>
                        <?php else: ?>
                            <?php foreach($payment_methods as $pm): ?>
                                <label class="flex items-center p-4 border rounded-lg cursor-pointer">
                                    <input type="radio" name="payment_method_id" value="<?= $pm['id'] ?>" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" required>
                                    <div class="ml-3 text-sm">
                                        <p class="font-medium text-gray-900"><?= htmlspecialchars($pm['name']) ?></p>
                                        <p class="text-gray-500"><?= nl2br(htmlspecialchars($pm['details'])) ?></p>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Ringkasan Pesanan -->
                <div class="bg-white p-6 rounded-lg shadow-md h-fit">
                    <h2 class="text-xl font-bold border-b pb-4 mb-4">Ringkasan Pesanan</h2>
                    <?php foreach($cart_items as $item): ?>
                    <div class="flex justify-between items-center text-sm mb-2">
                        <span><?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>)</span>
                        <span class="font-medium"><?= format_rupiah($item['price'] * $item['quantity']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="flex justify-between font-bold text-lg border-t pt-4 mt-4">
                        <span>Total</span>
                        <span><?= format_rupiah($total_price) ?></span>
                    </div>
                    <button type="submit" name="place_order" class="mt-6 block w-full text-center px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700" <?= empty($payment_methods) ? 'disabled' : '' ?>>
                        Buat Pesanan
                    </button>
                </div>
            </div>
        </form>
    </main>

    <?= footer($conn) ?>
</body>
</html>