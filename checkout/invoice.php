<?php
// File: checkout/invoice.php
include '../config/config.php';
include '../sistem/sistem.php';
load_settings($conn);

// Periksa apakah hash pesanan ada
if (!isset($_GET['hash'])) {
    die("Error: Invoice tidak ditemukan.");
}

$order_hash = sanitize_input($_GET['hash']);

// Ambil data pesanan utama
$stmt = $conn->prepare("SELECT * FROM orders WHERE order_hash = ?");
$stmt->bind_param("s", $order_hash);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows === 0) {
    die("Error: Invoice tidak valid.");
}
$order = $result->fetch_assoc();
$stmt->close();

// Ambil item pesanan
$stmt_items = $conn->prepare("
    SELECT oi.quantity, oi.price, p.name 
    FROM order_items oi 
    JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$stmt_items->bind_param("i", $order['id']);
$stmt_items->execute();
$order_items = $stmt_items->get_result();
$stmt_items->close();

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #WK<?= $order['id'] ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            .no-print { display: none; }
            body { -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto max-w-4xl p-4 sm:p-8">
        <div class="bg-white p-8 rounded-lg shadow-lg">
            <!-- Header -->
            <div class="flex justify-between items-start mb-8">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">INVOICE</h1>
                    <p class="text-gray-500">Invoice #: <span class="font-semibold">WK<?= $order['id'] ?></span></p>
                    <p class="text-gray-500">Tanggal: <span class="font-semibold"><?= date('d F Y', strtotime($order['created_at'])) ?></span></p>
                </div>
                <div class="text-right">
                    <?php 
                        $logo_filename = get_setting('store_logo');
                        $logo_url = $logo_filename ? BASE_URL . '/assets/images/settings/' . $logo_filename : "https://placehold.co/150x40/374151/FFFFFF?text=WarokKite";
                    ?>
                    <img src="<?= $logo_url ?>" alt="Logo Toko" class="h-12 object-contain">
                </div>
            </div>

            <!-- Detail Alamat -->
            <div class="grid sm:grid-cols-2 gap-4 mb-8">
                <div>
                    <h2 class="font-semibold text-gray-700 mb-1">Ditagihkan Kepada:</h2>
                    <p class="text-gray-600"><?= htmlspecialchars($order['full_name']) ?></p>
                    <p class="text-gray-600"><?= nl2br(htmlspecialchars($order['address_line_1'])) ?></p>
                    <?php if (!empty($order['address_line_2'])): ?>
                        <p class="text-gray-600"><?= htmlspecialchars($order['address_line_2']) ?></p>
                    <?php endif; ?>
                    <p class="text-gray-600"><?= htmlspecialchars($order['subdistrict']) ?>, <?= htmlspecialchars($order['city']) ?></p>
                    <p class="text-gray-600"><?= htmlspecialchars($order['province']) ?>, <?= htmlspecialchars($order['postal_code']) ?></p>
                    <p class="text-gray-600">Telp: <?= htmlspecialchars($order['phone_number']) ?></p>
                </div>
            </div>

            <!-- Tabel Item -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produk</th>
                            <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Jumlah</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Harga Satuan</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while ($item = $order_items->fetch_assoc()): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800"><?= htmlspecialchars($item['name']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?= $item['quantity'] ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right"><?= format_rupiah($item['price']) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-800 text-right font-semibold"><?= format_rupiah($item['price'] * $item['quantity']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

            <!-- Total -->
            <div class="flex justify-end mt-6">
                <div class="w-full max-w-xs">
                    <div class="flex justify-between text-gray-600">
                        <span>Subtotal</span>
                        <span><?= format_rupiah($order['total']) ?></span>
                    </div>
                     <div class="flex justify-between text-gray-600 mt-2">
                        <span>Biaya Pengiriman</span>
                        <span>-</span>
                    </div>
                    <div class="flex justify-between font-bold text-gray-800 text-lg border-t pt-2 mt-2">
                        <span>Total</span>
                        <span><?= format_rupiah($order['total']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Footer & Tombol Cetak -->
            <div class="border-t mt-8 pt-6">
                <p class="text-center text-sm text-gray-500">Terima kasih telah berbelanja di Warok Kite!</p>
                <div class="flex justify-center mt-4 no-print">
                    <button onclick="window.print()" class="px-6 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
                        Cetak Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>