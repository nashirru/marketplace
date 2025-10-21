<?php
// File: profile/profile.php
// Versi Final - Menangani semua status callback dan menampilkan notifikasi

require_once '../config/config.php'; 
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';
require_once '../midtrans/config_midtrans.php';

check_login(); 

if (isset($_GET['status'])) {
    $status = $_GET['status'];
    $order_id = $_GET['order_id'] ?? null;
    $message = $_GET['message'] ?? '';

    switch ($status) {
        case 'success':
            set_flashdata('success', 'Pembayaran berhasil! Status pesanan Anda akan segera diperbarui oleh sistem.');
            break;
        case 'pending':
            set_flashdata('info', 'Pembayaran Anda sedang diproses. Mohon tunggu konfirmasi.');
            break;
        case 'error':
            set_flashdata('error', 'Pembayaran Gagal. ' . htmlspecialchars($message));
            break;
        case 'closed':
            set_flashdata('info', 'Anda dapat melanjutkan pembayaran pesanan ini kapan saja.');
            break;
    }
    
    redirect('/profile/profile.php?tab=orders');
}

$user_id = $_SESSION['user_id'];
$user_data = get_user_by_id($conn, $user_id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = sanitize_input($_POST['name']);
        if (!empty($name) && $user_id > 0) {
            $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $user_id);
            if ($stmt->execute()) { $_SESSION['user_name'] = $name; set_flashdata('success', 'Nama berhasil diperbarui.'); } 
            else { set_flashdata('error', 'Gagal memperbarui nama.'); }
            $stmt->close();
        }
        redirect('/profile/profile.php?tab=account');
    }
}


$orders = [];
$stmt_orders = $conn->prepare("SELECT id, order_number, total, status, created_at, order_hash, expiry_time FROM orders WHERE user_id = ? ORDER BY created_at DESC");
if ($user_id > 0) {
    $stmt_orders->bind_param("i", $user_id);
    $stmt_orders->execute();
    $result_orders = $stmt_orders->get_result();
    while($row = $result_orders->fetch_assoc()) {
        $order_items = get_order_items_with_details($conn, $row['id']);
        $row['items'] = $order_items;
        $orders[] = $row;
    }
    $stmt_orders->close();
}
$addresses = get_user_addresses($conn, $user_id);
$active_tab = $_GET['tab'] ?? 'orders';
$page_title = 'Profil Saya - ' . (get_setting($conn, 'store_name') ?? 'Warok Kite');
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title, $conn); ?>
<script type="text/javascript" src="https://app.sandbox.midtrans.com/snap/snap.js" data-client-key="<?= htmlspecialchars(\Midtrans\Config::$clientKey); ?>"></script>
<style>
    .tab-button.active { border-bottom-color: #4f46e5; color: #4f46e5; font-weight: 600; }
    .pay-button:disabled { background-color: #9ca3af; cursor: not-allowed; }
</style>
<body class="bg-gray-50">
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 py-8 sm:py-12 min-h-screen max-w-5xl">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-6 sm:mb-8">Halaman Saya</h1>
        
        <?php flash_message(); ?>

        <div class="border-b border-gray-200 mb-6 sm:mb-8">
            <nav class="flex space-x-2 sm:space-x-4 -mb-px">
                <a href="?tab=orders" class="tab-button text-sm sm:text-base <?= $active_tab == 'orders' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-3 sm:px-4 border-b-2 border-transparent transition"><i class="fas fa-receipt mr-2"></i>Pesanan Saya</a>
                <a href="?tab=addresses" class="tab-button text-sm sm:text-base <?= $active_tab == 'addresses' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-3 sm:px-4 border-b-2 border-transparent transition"><i class="fas fa-map-marker-alt mr-2"></i>Alamat Saya</a>
                <a href="?tab=account" class="tab-button text-sm sm:text-base <?= $active_tab == 'account' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-3 sm:px-4 border-b-2 border-transparent transition"><i class="fas fa-user-circle mr-2"></i>Akun Saya</a>
            </nav>
        </div>

        <div>
            <?php if ($active_tab == 'orders'): ?>
                <div class="space-y-6">
                    <h2 class="text-2xl font-semibold text-indigo-600 mb-4 border-b pb-2">Riwayat Pesanan</h2>
                    <?php if (!empty($orders)): ?>
                        <?php foreach ($orders as $order): ?>
                            <div class="bg-white p-4 sm:p-6 rounded-xl shadow-lg border">
                                <div class="flex flex-wrap justify-between items-start gap-3 mb-3 border-b pb-3">
                                    <div>
                                        <h3 class="text-base sm:text-lg font-bold text-gray-800">No. Pesanan: <?= htmlspecialchars($order['order_number']) ?></h3>
                                        <p class="text-xs text-gray-500">Tanggal: <?= date('d M Y, H:i', strtotime($order['created_at'])) ?></p>
                                    </div>
                                    <span class="text-xs font-medium px-3 py-1 rounded-full <?= get_admin_status_class($order['status']) ?>">
                                        <?php
                                            $status_text = 'Status Tidak Dikenal';
                                            switch ($order['status']) {
                                                case 'belum_dicetak':
                                                case 'processed':
                                                    $status_text = 'Diproses';
                                                    break;
                                                case 'waiting_payment':
                                                    $status_text = 'Menunggu Pembayaran';
                                                    break;
                                                default:
                                                    $status_text = ucwords(str_replace('_', ' ', $order['status']));
                                                    break;
                                            }
                                            echo $status_text;
                                        ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-3 mb-4">
                                <?php foreach ($order['items'] as $item): ?>
                                    <div class="flex items-center gap-3">
                                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" class="w-12 h-12 rounded-md object-cover border">
                                        <div class="flex-grow"><p class="text-sm font-medium text-gray-800"><?= htmlspecialchars($item['name']) ?></p><p class="text-xs text-gray-500">Jumlah: <?= $item['quantity'] ?></p></div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                
                                <?php if ($order['status'] == 'waiting_payment'): ?>
                                <div class="my-4 p-3 bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 text-sm rounded-r-lg">
                                    <p><i class="fas fa-info-circle mr-2"></i>
                                    <?php
                                        $expiry_text = "dalam 24 jam.";
                                        if (!empty($order['expiry_time'])) {
                                            $expiry_text = "sebelum <strong>" . date('d M Y, H:i', strtotime($order['expiry_time'])) . "</strong>.";
                                        }
                                        echo "Mohon selesaikan pembayaran " . $expiry_text;
                                    ?>
                                    </p>
                                </div>
                                <?php endif; ?>

                                <div class="flex flex-wrap justify-between items-center gap-3 pt-3 border-t">
                                    <div><p class="text-xs text-gray-600">Total Belanja</p><p class="text-base sm:text-lg font-bold text-indigo-700"><?= format_rupiah($order['total']) ?></p></div>
                                    <div class="flex items-center gap-2">
                                        <?php if ($order['status'] == 'waiting_payment'): ?>
                                            <button type="button" class="pay-button text-sm text-white bg-green-600 hover:bg-green-700 px-3 py-1.5 rounded-md transition shadow" data-order-id="<?= $order['id'] ?>">Lanjutkan Pembayaran</button>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" class="text-sm text-indigo-600 border border-indigo-200 hover:bg-indigo-50 px-3 py-1.5 rounded-md transition">Lihat Invoice</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 bg-white rounded-xl shadow-lg"><p class="text-gray-500">Anda belum memiliki riwayat pesanan.</p></div>
                    <?php endif; ?>
                </div>
            
            <?php elseif ($active_tab == 'addresses'): ?>
                 <!-- Konten Tab Alamat -->
            
            <?php elseif ($active_tab == 'account'): ?>
                <div class="bg-white p-8 rounded-xl shadow-lg max-w-lg mx-auto">
                    <h2 class="text-2xl font-semibold text-indigo-600 mb-6 border-b pb-2">Informasi Akun</h2>
                    <form method="POST" class="space-y-4">
                        <div><label class="block text-sm font-medium text-gray-700">Nama Lengkap</label><input type="text" name="name" value="<?= htmlspecialchars($user_data['name']) ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                        <div><label class="block text-sm font-medium text-gray-700">Email</label><input type="email" value="<?= htmlspecialchars($user_data['email']) ?>" disabled class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 bg-gray-100"></div>
                        <div class="flex items-center gap-4 pt-4 border-t"><button type="submit" name="update_profile" class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700">Simpan Perubahan</button><a href="<?= BASE_URL ?>/login/logout.php" class="text-gray-600 py-2">Keluar</a></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php footer($conn); ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const payButtons = document.querySelectorAll('.pay-button');
        const profileUrl = '<?= BASE_URL ?>/profile/profile.php?tab=orders';

        payButtons.forEach(button => {
            button.addEventListener('click', async function() {
                const orderId = this.dataset.orderId;
                if (!orderId) return;

                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';

                try {
                    const formData = new FormData();
                    formData.append('order_id', orderId);
                    
                    const response = await fetch('<?= BASE_URL ?>/checkout/get_snap_token.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success && result.snap_token) {
                        window.snap.pay(result.snap_token, {
                            onSuccess: function(res){ window.location.href = `${profileUrl}&status=success&order_id=${res.order_id}`; },
                            onPending: function(res){ window.location.href = `${profileUrl}&status=pending&order_id=${res.order_id}`; },
                            onError: function(res){ window.location.href = `${profileUrl}&status=error&order_id=${res.order_id}&message=${encodeURIComponent(res.status_message)}`; },
                            onClose: function(){ window.location.href = `${profileUrl}&status=closed&order_id=${result.order_id ?? orderId}`; }
                        });
                    } else {
                        throw new Error(result.message || 'Gagal memulai sesi pembayaran.');
                    }
                } catch (error) {
                    alert('Terjadi kesalahan: ' + error.message);
                } finally {
                    this.disabled = false;
                    this.innerHTML = 'Lanjutkan Pembayaran';
                }
            });
        });
    });
    </script>
</body>
</html>