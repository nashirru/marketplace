<?php
// File: checkout/invoice.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

check_login();
$user_id = $_SESSION['user_id'];

if (!isset($_GET['order_hash']) || empty($_GET['order_hash'])) {
    redirect('/profile/profile.php');
}

$order_hash = sanitize_input($_GET['order_hash']);

$stmt = $conn->prepare("
    SELECT o.*, pm.name as payment_method_name, pm.details as payment_method_details
    FROM orders o
    LEFT JOIN payment_methods pm ON o.payment_method_id = pm.id
    WHERE o.order_hash = ? AND o.user_id = ?
");
$stmt->bind_param("si", $order_hash, $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    set_flash_message('error', 'Invoice tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('/profile/profile.php');
}
$order = $result->fetch_assoc();
$order_id = $order['id'];

$items_stmt = $conn->prepare("SELECT oi.*, p.name as product_name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$order_items = $items_stmt->get_result();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #WK<?= htmlspecialchars($order['id']) ?> - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100">
    <?= navbar($conn); ?>

    <main class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto bg-white p-6 sm:p-8 rounded-lg shadow-md">
            
            <?= flash_message('success') ?>
            <?= flash_message('error') ?>
            
            <div class="flex justify-between items-start mb-6 pb-4 border-b">
                <div>
                    <?php
                    $logo_filename = get_setting($conn, 'store_logo');
                    if ($logo_filename && file_exists('../assets/images/settings/' . $logo_filename)) {
                        $logo_url = BASE_URL . '/assets/images/settings/' . $logo_filename;
                        echo "<img src='{$logo_url}' alt='Logo Toko' class='h-12 w-auto'>";
                    } else {
                        echo '<h1 class="text-2xl font-bold text-indigo-600">Warok<span class="text-gray-800">Kite</span></h1>';
                    }
                    ?>
                </div>
                <div class="text-right">
                    <h2 class="text-3xl font-bold text-gray-800">Invoice</h2>
                    <p class="text-gray-500">#WK<?= htmlspecialchars($order['id']) ?></p>
                </div>
            </div>

            <div class="grid md:grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="font-semibold text-gray-800 mb-2">Ditagihkan Kepada:</h3>
                    <p class="text-gray-600">
                        <?= htmlspecialchars($order['full_name']) ?><br>
                        <?= htmlspecialchars($order['address_line_1']) ?><br>
                        <?php if(!empty($order['address_line_2'])) echo htmlspecialchars($order['address_line_2']) . '<br>'; ?>
                        <?= htmlspecialchars($order['subdistrict']) ?>, <?= htmlspecialchars($order['city']) ?><br>
                        <?= htmlspecialchars($order['province']) ?>, <?= htmlspecialchars($order['postal_code']) ?><br>
                        Telp: <?= htmlspecialchars($order['phone_number']) ?>
                    </p>
                </div>
                <div class="text-right">
                    <h3 class="font-semibold text-gray-800 mb-2">Detail Pesanan:</h3>
                    <p class="text-gray-600">
                        Tanggal Pesanan: <?= date('d F Y', strtotime($order['created_at'])) ?><br>
                        Status: <span class="font-semibold text-indigo-600"><?= str_replace('_', ' ', ucfirst($order['status'])) ?></span>
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto mb-8">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produk</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Jumlah</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Harga Satuan</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while($item = $order_items->fetch_assoc()): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?= $item['quantity'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= format_rupiah($item['price']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 text-right font-medium"><?= format_rupiah($item['price'] * $item['quantity']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end mb-8">
                <div class="w-full max-w-xs">
                    <div class="flex justify-between py-2 border-b">
                        <span class="text-gray-600">Subtotal</span>
                        <span class="text-gray-800"><?= format_rupiah($order['total']) ?></span>
                    </div>
                    <div class="flex justify-between py-2 font-bold text-lg">
                        <span>Total</span>
                        <span><?= format_rupiah($order['total']) ?></span>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 p-6 rounded-lg border">
                <h3 class="font-semibold text-gray-800 mb-3">Informasi Pembayaran</h3>
                <div class="prose prose-sm max-w-none text-gray-600">
                    <p>Silakan lakukan pembayaran ke rekening berikut:</p>
                    <p><strong><?= htmlspecialchars($order['payment_method_name']) ?></strong></p>
                    <p><?= nl2br(htmlspecialchars($order['payment_method_details'])) ?></p>
                    <p>Total yang harus dibayar: <strong><?= format_rupiah($order['total']) ?></strong></p>
                </div>

                <?php if ($order['status'] == 'waiting_payment' && empty($order['payment_proof'])): ?>
                    <div class="mt-6 border-t pt-6">
                        <h4 class="font-semibold text-gray-800 mb-3">Konfirmasi Pembayaran Anda</h4>
                        <form action="<?= BASE_URL ?>/checkout/upload.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="order_hash" value="<?= htmlspecialchars($order['order_hash']) ?>">
                            <div>
                                <label for="payment_proof" class="block text-sm font-medium text-gray-700">Upload Bukti Transfer</label>
                                <input type="file" name="payment_proof" id="payment_proof" required class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                            </div>
                            <button type="submit" class="mt-4 w-full px-6 py-2 bg-indigo-600 text-white font-semibold rounded-md hover:bg-indigo-700">
                                Unggah dan Konfirmasi
                            </button>
                        </form>
                    </div>
                <?php elseif (!empty($order['payment_proof'])): ?>
                    <div class="mt-6 border-t pt-6 text-center">
                        <p class="text-green-600 font-medium">Bukti pembayaran Anda telah diunggah. Pesanan akan segera kami proses.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?= footer($conn); ?>
</body>
</html>