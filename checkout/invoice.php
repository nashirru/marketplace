<?php
// File: checkout/invoice.php - Halaman Tampilan Invoice

require_once '../config/config.php'; 
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

check_login();

// Memuat pengaturan toko ke cache
load_settings($conn);

$store_name = get_setting($conn, 'store_name') ?? 'Warok Kite';
$store_address = get_setting($conn, 'store_address') ?? 'Ponorogo, Jawa Timur';
$store_phone = get_setting($conn, 'store_phone') ?? '0812-3456-7890';
$store_logo = get_setting($conn, 'store_logo') ?? 'placeholder.png';

// ✅ PERBAIKAN: Mengambil 'hash' dari URL
$order_hash = $_GET['hash'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// Ambil Data Pesanan
$order_data = null;
$order_items = [];

if (!empty($order_hash)) {
    // ✅ PERBAIKAN: Query menggunakan 'order_hash' bukan 'order_uid'
    $stmt_order = $conn->prepare("
        SELECT 
            o.*, 
            pm.name AS payment_method_name
        FROM orders o
        LEFT JOIN payment_methods pm ON o.payment_method_id = pm.id
        WHERE o.order_hash = ? AND o.user_id = ?
    ");
    $stmt_order->bind_param("si", $order_hash, $user_id);
    $stmt_order->execute();
    $result_order = $stmt_order->get_result();

    if ($result_order && $result_order->num_rows > 0) {
        $order_data = $result_order->fetch_assoc();

        // Ambil item-item pesanan
        $stmt_items = $conn->prepare("
            SELECT oi.*, p.name AS product_name 
            FROM order_items oi
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ");
        $stmt_items->bind_param("i", $order_data['id']);
        $stmt_items->execute();
        $result_items = $stmt_items->get_result();
        while ($row = $result_items->fetch_assoc()) {
            $order_items[] = $row;
        }
        $stmt_items->close();
    }
    $stmt_order->close();
}

// Jika pesanan tidak ditemukan
if (!$order_data) {
    set_flashdata('error', 'Pesanan tidak ditemukan atau Anda tidak memiliki akses.');
    redirect('/profile/profile.php');
}

// Format status untuk ditampilkan
$status_label = [
    'waiting_payment' => 'Menunggu Pembayaran',
    'waiting_approval' => 'Menunggu Verifikasi',
    'belum_dicetak' => 'Belum Dicetak',
    'processed' => 'Diproses',
    'shipped' => 'Dikirim',
    'completed' => 'Selesai',
    'cancelled' => 'Dibatalkan'
];

$status_display = $status_label[$order_data['status']] ?? $order_data['status'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice #<?= htmlspecialchars($order_data['order_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background-color: white; }
            .invoice-box { box-shadow: none !important; border: none !important; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="no-print">
        <?php navbar($conn); ?>
    </div>
    
    <div class="py-12 px-4">
        <div class="invoice-box max-w-4xl mx-auto bg-white p-8 rounded-lg shadow-lg">
            <!-- Header Invoice -->
            <header class="flex justify-between items-start border-b pb-6 mb-6">
                <div class="logo-info">
                    <img src="<?= BASE_URL ?>/assets/images/settings/<?= htmlspecialchars($store_logo) ?>" 
                         style="width: 90px; max-width: 300px;" alt="<?= htmlspecialchars($store_name) ?>">
                    <h1 class="text-2xl font-bold text-indigo-800 mt-2"><?= htmlspecialchars($store_name) ?></h1>
                    <p class="text-xs text-gray-500"><?= htmlspecialchars($store_address) ?></p>
                    <p class="text-xs text-gray-500">Telp: <?= htmlspecialchars($store_phone) ?></p>
                </div>
                <div class="text-right">
                    <h2 class="text-3xl font-extrabold text-gray-800">INVOICE</h2>
                    <p class="text-base text-gray-600 font-semibold mt-1">#<?= htmlspecialchars($order_data['order_number']) ?></p>
                    <p class="text-sm text-gray-500">Tanggal: <?= date('d M Y', strtotime($order_data['created_at'])) ?></p>
                </div>
            </header>

            <!-- Informasi Pembeli dan Alamat -->
            <section class="grid grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="text-lg font-semibold text-indigo-700 mb-2">Informasi Pembeli</h3>
                    <p class="font-bold text-gray-800"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></p>
                    <p class="text-gray-600"><?= htmlspecialchars($order_data['email'] ?? 'Email tidak tersedia') ?></p>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-indigo-700 mb-2">Alamat Pengiriman</h3>
                    <!-- Data diambil langsung dari tabel orders -->
                    <p class="font-bold text-gray-800"><?= htmlspecialchars($order_data['full_name']) ?></p>
                    <p class="text-gray-600"><?= htmlspecialchars($order_data['address_line_1']) ?></p>
                    <?php if (!empty($order_data['address_line_2'])): ?>
                        <p class="text-gray-600"><?= htmlspecialchars($order_data['address_line_2']) ?></p>
                    <?php endif; ?>
                    <p class="text-gray-600">
                        <?= htmlspecialchars($order_data['subdistrict']) ?>, 
                        <?= htmlspecialchars($order_data['city']) ?>, 
                        <?= htmlspecialchars($order_data['province']) ?> 
                        <?= htmlspecialchars($order_data['postal_code']) ?>
                    </p>
                    <p class="text-gray-600">Telp: <?= htmlspecialchars($order_data['phone_number']) ?></p>
                </div>
            </section>

            <!-- Detail Item Pesanan -->
            <section class="mb-8">
                <h3 class="text-lg font-semibold text-indigo-700 mb-3">Detail Pesanan</h3>
                <div class="overflow-x-auto rounded-lg border border-gray-200 shadow-sm">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-indigo-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Produk</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Harga</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Qty</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $total_amount = 0;
                            foreach ($order_items as $item): 
                                $subtotal_item = $item['price'] * $item['quantity'];
                                $total_amount += $subtotal_item;
                            ?>
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900"><?= htmlspecialchars($item['product_name']) ?></td>
                                <td class="px-6 py-4 text-sm text-center text-gray-500"><?= format_rupiah($item['price']) ?></td>
                                <td class="px-6 py-4 text-sm text-center text-gray-500"><?= $item['quantity'] ?></td>
                                <td class="px-6 py-4 text-sm text-right font-semibold text-gray-700"><?= format_rupiah($subtotal_item) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Ringkasan Pembayaran dan Status -->
            <section class="grid grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="text-lg font-semibold text-indigo-700 mb-3">Ringkasan Pembayaran</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between text-gray-700">
                            <span>Subtotal Produk:</span>
                            <span class="font-semibold"><?= format_rupiah($total_amount) ?></span>
                        </div>
                        <div class="flex justify-between text-gray-700 border-b pb-2">
                            <span>Biaya Pengiriman:</span>
                            <span class="font-semibold">Gratis</span>
                        </div>
                        <div class="flex justify-between text-xl font-bold text-indigo-800 pt-2">
                            <span>Total Pembayaran:</span>
                            <span><?= format_rupiah($order_data['total']) ?></span>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <div class="mb-4">
                        <p class="text-sm font-semibold text-gray-700 mb-1">Status Pesanan:</p>
                        <span class="inline-block px-4 py-2 rounded-lg text-lg font-bold
                            <?php 
                                if (in_array($order_data['status'], ['waiting_payment', 'waiting_approval'])) 
                                    echo 'bg-yellow-100 text-yellow-700';
                                elseif (in_array($order_data['status'], ['completed'])) 
                                    echo 'bg-green-100 text-green-700';
                                elseif ($order_data['status'] == 'cancelled') 
                                    echo 'bg-red-100 text-red-700';
                                else 
                                    echo 'bg-blue-100 text-blue-700';
                            ?>
                        ">
                            <?= htmlspecialchars($status_display) ?>
                        </span>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-gray-700">Metode Pembayaran:</p>
                        <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($order_data['payment_method_name'] ?? 'Transfer Bank') ?></p>
                    </div>
                </div>
            </section>

            <!-- Bukti Pembayaran -->
            <?php if (!empty($order_data['payment_proof'])): ?>
            <section class="mb-8 border-t pt-6">
                <h3 class="text-lg font-semibold text-indigo-700 mb-3">Bukti Pembayaran</h3>
                <div class="flex justify-center">
                    <img src="<?= BASE_URL ?>/assets/images/proof/<?= htmlspecialchars($order_data['payment_proof']) ?>" 
                         alt="Bukti Pembayaran" class="max-w-md rounded-lg shadow-md border border-gray-200">
                </div>
            </section>
            <?php endif; ?>

            <!-- Aksi Cetak -->
            <div class="mt-8 text-center space-x-4 no-print border-t pt-6">
                <button onclick="window.print()" class="px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg shadow-lg hover:bg-indigo-700 transition">
                    <i class="fas fa-print mr-2"></i> Cetak Invoice
                </button>
                <a href="<?= BASE_URL ?>/profile/profile.php" class="inline-block px-6 py-3 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                    <i class="fas fa-arrow-left mr-2"></i> Kembali ke Riwayat
                </a>
            </div>
        </div>
    </div>
    
    <div class="no-print">
        <?php footer($conn); ?>
    </div>
</body>
</html>