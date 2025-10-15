<?php
// File: profile/profile.php
include '../config/config.php';
include '../sistem/sistem.php';
include '../partial/partial.php';

// Wajibkan login untuk mengakses halaman ini
check_login();

$user_id = $_SESSION['user_id'];

// Mengambil data pesanan
$stmt = $conn->prepare("SELECT id, total, status, created_at, order_hash FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$orders = $stmt->get_result();
$stmt->close();

// Fungsi untuk menerjemahkan status untuk user
function translate_status_for_user($status) {
    if ($status == 'belum_dicetak') {
        return 'Disetujui';
    }
    if ($status == 'waiting_approval') {
        return 'Menunggu Verifikasi';
    }
    return ucfirst(str_replace('_', ' ', $status));
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

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
                                                    // Gunakan warna berdasarkan status asli
                                                    switch($order['status']) {
                                                        case 'completed': echo 'bg-green-100 text-green-800'; break;
                                                        case 'shipped': echo 'bg-blue-100 text-blue-800'; break;
                                                        case 'processed': echo 'bg-purple-100 text-purple-800'; break;
                                                        case 'belum_dicetak': echo 'bg-yellow-100 text-yellow-800'; break; // Warna untuk disetujui
                                                        case 'cancelled': echo 'bg-red-100 text-red-800'; break;
                                                        default: echo 'bg-indigo-100 text-indigo-800';
                                                    }
                                                ?>">
                                                <?= translate_status_for_user($order['status']) ?>
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
            
            <!-- Sisa kode (Notifikasi, dll) tetap sama -->
            <div class="bg-white p-6 rounded-lg shadow-md">
                 <h2 class="text-xl font-bold mb-4">Notifikasi Terbaru</h2>
                 <!-- ... -->
            </div>
        </div>
    </main>

    <?= footer() ?>

</body>
</html>