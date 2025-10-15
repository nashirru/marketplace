<?php
// File: profile/profile.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

check_login();
$user_id = $_SESSION['user_id'];

// Query diupdate untuk mengambil order_hash
$stmt = $conn->prepare("SELECT id, total, status, created_at, order_hash FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?= navbar($conn) ?>

    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Profil Saya</h1>
        
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-xl font-bold mb-4">Riwayat Pesanan</h2>
            <?= flash_message('error') ?>
            <?php if ($orders->num_rows > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Order ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tanggal</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while($order = $orders->fetch_assoc()): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#WK<?= str_pad($order['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d M Y, H:i', strtotime($order['created_at'])) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($order['total']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?= str_replace('_', ' ', ucfirst($order['status'])) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php
                                    // Hanya tampilkan link jika order_hash ada
                                    if (!empty($order['order_hash'])) {
                                        $detail_url = BASE_URL . '/checkout/invoice.php?order_hash=' . htmlspecialchars($order['order_hash']);
                                        $link_text = ($order['status'] == 'waiting_payment') ? 'Bayar Sekarang' : 'Lihat Detail';
                                        echo '<a href="' . $detail_url . '" class="text-indigo-600 hover:text-indigo-900">' . $link_text . '</a>';
                                    } else {
                                        echo '<span class="text-gray-400">N/A</span>'; // Untuk pesanan lama
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-gray-600">Anda belum memiliki riwayat pesanan.</p>
            <?php endif; ?>
        </div>
    </main>

    <?= footer($conn) ?>

</body>
</html>