<?php
// File: profile/profile.php
include '../config/config.php';
include '../sistem/sistem.php';

// Wajibkan login untuk mengakses halaman ini
if (!isset($_SESSION['user_id'])) {
    redirect('/login/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Mengambil data pesanan, termasuk order_hash untuk link invoice
$stmt = $conn->prepare("SELECT id, total, status, created_at, order_hash FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

// Mengambil data notifikasi
$stmt_notif = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$stmt_notif->bind_param("i", $user_id);
$stmt_notif->execute();
$notifications = $stmt_notif->get_result();
$stmt_notif->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tambahkan Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?php include '../partial/partial.php'; ?>
    <?= navbar($conn) ?>

    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Profil Saya</h1>
        
        <?= flash_message('success') ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2 space-y-8">
                <!-- Riwayat Pesanan -->
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h2 class="text-xl font-bold mb-4">Riwayat Pesanan</h2>
                    <?php if ($orders->num_rows > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanggal</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php while($order = $orders->fetch_assoc()): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#WK<?= $order['id'] ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('d M Y, H:i', strtotime($order['created_at'])) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= format_rupiah($order['total']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                    switch($order['status']) {
                                                        case 'completed': echo 'bg-green-100 text-green-800'; break;
                                                        case 'shipped': echo 'bg-blue-100 text-blue-800'; break;
                                                        case 'processed': echo 'bg-yellow-100 text-yellow-800'; break;
                                                        case 'cancelled': echo 'bg-red-100 text-red-800'; break;
                                                        default: echo 'bg-indigo-100 text-indigo-800';
                                                    }
                                                ?>">
                                                <?= str_replace('_', ' ', ucfirst($order['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                            <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" target="_blank" class="text-gray-600 hover:text-indigo-800 transition-colors duration-200" title="Cetak Invoice">
                                                <i class="fas fa-print fa-lg"></i>
                                            </a>
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
            </div>
            
            <!-- Notifikasi -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                 <h2 class="text-xl font-bold mb-4">Notifikasi Terbaru</h2>
                 <ul class="divide-y divide-gray-200">
                    <?php if ($notifications->num_rows > 0): ?>
                        <?php while($notif = $notifications->fetch_assoc()): ?>
                            <li class="py-4">
                                <p class="text-sm text-gray-700"><?= htmlspecialchars($notif['message']) ?></p>
                                <p class="text-xs text-gray-500 mt-1"><?= date('d M Y, H:i', strtotime($notif['created_at'])) ?></p>
                            </li>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <li class="py-4 text-center text-gray-500">Tidak ada notifikasi.</li>
                    <?php endif; ?>
                 </ul>
            </div>
        </div>
    </main>

    <?= footer() ?>

</body>
</html>