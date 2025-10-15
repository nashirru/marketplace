<?php
// File: checkout/checkout.php
include '../config/config.php';
include '../sistem/sistem.php';

$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;

// --- PERBAIKAN: Logika redirect yang lebih baik ---
// 1. Cek apakah pengguna login. Jika tidak, simpan halaman ini sebagai tujuan dan redirect ke login.
if (!$is_logged_in) {
    $_SESSION['redirect_to'] = BASE_URL . '/checkout/checkout.php';
    set_flash_message('info', 'Anda harus login terlebih dahulu untuk melanjutkan ke pembayaran.');
    redirect('/login/login.php');
    exit;
}

// 2. Ambil item keranjang dari database (karena sudah pasti login)
$cart_items = [];
$total_price = 0;
$stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.image, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $cart_items[] = $row;
    $total_price += $row['price'] * $row['quantity'];
}

// 3. Jika keranjang kosong, jangan biarkan di halaman checkout, kembalikan ke keranjang.
if (empty($cart_items)) {
    set_flash_message('info', 'Keranjang Anda kosong. Silakan tambahkan produk terlebih dahulu.');
    redirect('/cart/cart.php');
    exit;
}

// Logika untuk memproses pesanan
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    // Di sini nanti logika untuk menyimpan order ke database, upload bukti bayar, dll.
    // ...
    // Setelah berhasil, kosongkan keranjang dan redirect ke halaman sukses
    $conn->query("DELETE FROM cart WHERE user_id = $user_id");
    redirect('/profile/profile.php'); // Arahkan ke riwayat pesanan
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
    <?= navbar() ?>
    
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Checkout</h1>
        <form action="" method="POST" enctype="multipart/form-data">
             <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold mb-4">Alamat Pengiriman</h2>
                    <!-- Form Alamat -->
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nama Lengkap</label>
                            <input type="text" name="address_name" class="mt-1 block w-full border-gray-300 rounded-md" required>
                        </div>
                         <div>
                            <label class="block text-sm font-medium text-gray-700">Alamat Lengkap</label>
                            <textarea name="address_detail" rows="3" class="mt-1 block w-full border-gray-300 rounded-md" required></textarea>
                        </div>
                    </div>

                    <h2 class="text-xl font-bold mt-8 mb-4">Metode Pembayaran</h2>
                    <p>Silakan transfer ke rekening BCA: <strong>123-456-7890</strong> a/n Warok Kite.</p>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Upload Bukti Transfer</label>
                        <input type="file" name="payment_proof" class="mt-1 block w-full" required>
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
                    <button type="submit" name="place_order" class="mt-6 block w-full text-center px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700">
                        Konfirmasi Pesanan
                    </button>
                </div>
            </div>
        </form>
    </main>

    <?= footer() ?>
</body>
</html>