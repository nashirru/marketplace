<?php
// File: checkout/checkout.php
include '../config/config.php';
include '../sistem/sistem.php';

$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;

// --- Logika redirect yang lebih baik ---
if (!$is_logged_in) {
    $_SESSION['redirect_to'] = BASE_URL . '/checkout/checkout.php';
    set_flash_message('info', 'Anda harus login terlebih dahulu untuk melanjutkan ke pembayaran.');
    redirect('/login/login.php');
    exit;
}

// Ambil item keranjang dari database
$cart_items = [];
$total_price = 0;
$stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.image, p.stock, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cart_items[] = $row;
    $total_price += $row['price'] * $row['quantity'];
}
$stmt->close();

// Jika keranjang kosong, kembalikan ke keranjang.
if (empty($cart_items)) {
    set_flash_message('info', 'Keranjang Anda kosong. Silakan tambahkan produk terlebih dahulu.');
    redirect('/cart/cart.php');
    exit;
}

// Ambil metode pembayaran yang aktif
$payment_methods = $conn->query("SELECT * FROM payment_methods WHERE is_active = 1");

// --- LOGIKA UTAMA: PROSES PESANAN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    
    $full_name = sanitize_input($_POST['full_name']);
    $phone_number = sanitize_input($_POST['phone_number']);
    $province = sanitize_input($_POST['province']);
    $city = sanitize_input($_POST['city']);
    $subdistrict = sanitize_input($_POST['subdistrict']);
    $postal_code = sanitize_input($_POST['postal_code']);
    $address_line_1 = sanitize_input($_POST['address_line_1']);
    $address_line_2 = sanitize_input($_POST['address_line_2'] ?? '');
    $payment_method_id = (int)sanitize_input($_POST['payment_method_id']);

    if (!isset($_FILES['payment_proof']) || $_FILES['payment_proof']['error'] != 0) {
        set_flash_message('error', 'Gagal memproses pesanan: Bukti pembayaran wajib diunggah.');
        redirect('/checkout/checkout.php');
    }
    
    $upload_dir = '../assets/images/proof/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $image_name = uniqid() . '-' . basename($_FILES["payment_proof"]["name"]);
    $target_file = $upload_dir . $image_name;
    
    if (!move_uploaded_file($_FILES["payment_proof"]["tmp_name"], $target_file)) {
        set_flash_message('error', 'Gagal mengunggah bukti pembayaran.');
        redirect('/checkout/checkout.php');
    }

    $conn->begin_transaction();
    try {
        $order_hash = md5(uniqid(rand(), true));
        $status = 'waiting_approval';

        $stmt_order = $conn->prepare("INSERT INTO orders 
            (user_id, total, payment_proof, status, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, payment_method_id, order_hash) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt_order->bind_param("idssssssssssis", 
            $user_id, $total_price, $image_name, $status, $full_name, $phone_number, $province, $city, $subdistrict, $postal_code, $address_line_1, $address_line_2, $payment_method_id, $order_hash
        );

        if (!$stmt_order->execute()) throw new Exception("Gagal menyimpan data pesanan: " . $stmt_order->error);
        
        $order_id = $conn->insert_id;
        $stmt_order->close();

        $stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmt_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");

        foreach ($cart_items as $item) {
            $stmt_item->bind_param("iiid", $order_id, $item['id'], $item['quantity'], $item['price']);
            if (!$stmt_item->execute()) throw new Exception("Gagal menyimpan item pesanan: " . $stmt_item->error);

            $stmt_stock->bind_param("ii", $item['quantity'], $item['id']);
            if (!$stmt_stock->execute()) throw new Exception("Gagal mengupdate stok produk: " . $stmt_stock->error);
        }
        $stmt_item->close();
        $stmt_stock->close();

        $stmt_clear_cart = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt_clear_cart->bind_param("i", $user_id);
        if (!$stmt_clear_cart->execute()) throw new Exception("Gagal mengosongkan keranjang: " . $stmt_clear_cart->error);
        $stmt_clear_cart->close();
        
        $message = "Pesanan baru #WK{$order_id} telah dibuat dan sedang menunggu konfirmasi pembayaran dari admin.";
        $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $stmt_notif->bind_param("is", $user_id, $message);
        $stmt_notif->execute();
        $stmt_notif->close();

        $conn->commit();
        
        set_flash_message('success', 'Pesanan Anda berhasil dibuat! Admin akan segera memverifikasi pembayaran Anda.');
        redirect('/profile/profile.php');

    } catch (Exception $e) {
        $conn->rollback();
        if (file_exists($target_file)) unlink($target_file);
        set_flash_message('error', "Terjadi kesalahan saat membuat pesanan: " . $e->getMessage());
        error_log("Checkout Error: " . $e->getMessage());
        redirect('/checkout/checkout.php');
    }
    exit;
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

    <?php include '../partial/partial.php'; ?>
    <?= navbar($conn) ?>
    
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Checkout</h1>
        
        <?= flash_message('error') ?>
        <?= flash_message('info') ?>

        <form action="<?= BASE_URL ?>/checkout/checkout.php" method="POST" enctype="multipart/form-data">
             <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold mb-4 border-b pb-3">1. Alamat Pengiriman</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label for="full_name" class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                            <input type="text" id="full_name" name="full_name" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="phone_number" class="block text-sm font-medium text-gray-700">Nomor Telepon</label>
                            <input type="tel" id="phone_number" name="phone_number" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="province" class="block text-sm font-medium text-gray-700">Provinsi</label>
                            <input type="text" id="province" name="province" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="city" class="block text-sm font-medium text-gray-700">Kota/Kabupaten</label>
                            <input type="text" id="city" name="city" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="subdistrict" class="block text-sm font-medium text-gray-700">Kecamatan</label>
                            <input type="text" id="subdistrict" name="subdistrict" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div>
                            <label for="postal_code" class="block text-sm font-medium text-gray-700">Kode Pos</label>
                            <input type="text" id="postal_code" name="postal_code" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" required>
                        </div>
                        <div class="md:col-span-2">
                            <label for="address_line_1" class="block text-sm font-medium text-gray-700">Alamat Lengkap</label>
                            <textarea id="address_line_1" name="address_line_1" rows="3" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="Contoh: Jl. Pahlawan No. 123, RT 01/RW 02" required></textarea>
                        </div>
                        <div class="md:col-span-2">
                            <label for="address_line_2" class="block text-sm font-medium text-gray-700">Detail Alamat (Opsional)</label>
                            <input type="text" id="address_line_2" name="address_line_2" class="mt-1 block w-full border-gray-300 rounded-md shadow-sm" placeholder="Contoh: Sebelah masjid, rumah warna hijau">
                        </div>
                    </div>

                    <h2 class="text-xl font-bold mt-8 mb-4 border-b pb-3">2. Metode Pembayaran</h2>
                    <div class="space-y-3 mt-4">
                        <?php while($method = $payment_methods->fetch_assoc()): ?>
                        <label class="flex items-start p-4 border rounded-lg cursor-pointer">
                            <input type="radio" name="payment_method_id" value="<?= $method['id'] ?>" class="mt-1" required>
                            <div class="ml-4">
                                <p class="font-semibold"><?= htmlspecialchars($method['name']) ?></p>
                                <p class="text-sm text-gray-600"><?= nl2br(htmlspecialchars($method['details'])) ?></p>
                            </div>
                        </label>
                        <?php endwhile; ?>
                    </div>
                    <div class="mt-6">
                        <label for="payment_proof" class="block text-sm font-medium text-gray-700">Upload Bukti Transfer</label>
                        <input type="file" id="payment_proof" name="payment_proof" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
                        <p class="text-xs text-gray-500 mt-1">Format: JPG, PNG. Maks: 2MB.</p>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow-md h-fit">
                    <h2 class="text-xl font-bold border-b pb-4 mb-4">Ringkasan Pesanan</h2>
                    <?php foreach($cart_items as $item): ?>
                    <div class="flex justify-between items-center text-sm mb-2">
                        <span class="truncate pr-2"><?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>)</span>
                        <span class="font-medium flex-shrink-0"><?= format_rupiah($item['price'] * $item['quantity']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="flex justify-between font-bold text-lg border-t pt-4 mt-4">
                        <span>Total</span>
                        <span><?= format_rupiah($total_price) ?></span>
                    </div>
                    <button type="submit" name="place_order" class="mt-6 block w-full text-center px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Konfirmasi dan Buat Pesanan
                    </button>
                </div>
            </div>
        </form>
    </main>

    <?= footer() ?>
</body>
</html>