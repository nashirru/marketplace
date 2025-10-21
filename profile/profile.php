<?php
// File: profile/profile.php
// Versi Final dengan Detail Produk dan Fitur Pembatalan Pesanan

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';
require_once '../midtrans/config_midtrans.php';

check_login();

$user_id = $_SESSION['user_id'];
$user_data = get_user_by_id($conn, $user_id);
$active_tab = $_GET['tab'] ?? 'orders';

// [FITUR BARU] Logika untuk membatalkan pesanan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);

    if ($order_id_to_cancel > 0) {
        $conn->begin_transaction();
        try {
            // Ambil item pesanan untuk mengembalikan stok
            $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_items->bind_param("i", $order_id_to_cancel);
            $stmt_items->execute();
            $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_items->close();

            // Kembalikan stok untuk setiap produk
            $stmt_stock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            foreach ($items as $item) {
                $stmt_stock->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt_stock->execute();
            }
            $stmt_stock->close();

            // Update status pesanan menjadi 'cancelled'
            // Hanya batalkan jika pesanan milik user dan statusnya 'waiting_payment'
            $stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'waiting_payment'");
            $stmt_cancel->bind_param("ii", $order_id_to_cancel, $user_id);
            $stmt_cancel->execute();
            
            if ($stmt_cancel->affected_rows > 0) {
                set_flashdata('success', 'Pesanan berhasil dibatalkan.');
                create_notification($conn, $user_id, "Pesanan #" . get_order_number_by_id($conn, $order_id_to_cancel) . " telah Anda batalkan.");
            } else {
                set_flashdata('error', 'Gagal membatalkan pesanan. Mungkin sudah dibayar atau statusnya telah berubah.');
            }
            $stmt_cancel->close();

            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            set_flashdata('error', 'Terjadi kesalahan saat membatalkan pesanan.');
            // Opsional: catat error $e->getMessage() ke file log
        }
    }
    redirect('/profile/profile.php?tab=orders');
}


// Logika untuk menangani redirect dari halaman pembayaran
if (isset($_GET['status'])) {
    $status = $_GET['status'];
    $message = $_GET['message'] ?? 'Status pesanan Anda akan segera diperbarui.';

    switch ($status) {
        case 'success': set_flashdata('success', $message); break;
        case 'pending': set_flashdata('info', $message); break;
        case 'error': case 'cancelled': set_flashdata('error', $message); break;
        default: set_flashdata('info', 'Anda dapat melanjutkan pembayaran dari halaman ini.'); break;
    }
    
    redirect('/profile/profile.php?tab=orders');
}

// Mengambil data pesanan
$orders = [];
$stmt_orders = $conn->prepare("SELECT id, order_number, total, status, created_at, order_hash, expiry_time FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt_orders->bind_param("i", $user_id);
$stmt_orders->execute();
$result_orders = $stmt_orders->get_result();
while($row = $result_orders->fetch_assoc()) {
    $orders[] = $row;
}
$stmt_orders->close();

// [FITUR BARU] Ambil semua item dari semua pesanan dalam satu query untuk efisiensi
$order_items_map = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    
    $stmt_all_items = $conn->prepare("
        SELECT oi.order_id, oi.quantity, oi.price, p.name as product_name, p.image as product_image
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
    ");
    $stmt_all_items->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
    $stmt_all_items->execute();
    $result_items = $stmt_all_items->get_result();
    while ($item = $result_items->fetch_assoc()) {
        $order_items_map[$item['order_id']][] = $item;
    }
    $stmt_all_items->close();
}


$page_title = 'Profil Saya - ' . (get_setting($conn, 'store_name') ?? 'Warok Kite');

// Fungsi untuk badge status
function get_status_badge($status) {
    $status_config = [
        'waiting_payment' => ['label' => 'Menunggu Pembayaran', 'class' => 'bg-yellow-100 text-yellow-800', 'icon' => 'fa-clock'],
        'belum_dicetak'   => ['label' => 'Diproses', 'class' => 'bg-blue-100 text-blue-800', 'icon' => 'fa-cog'],
        'processed'       => ['label' => 'Diproses', 'class' => 'bg-blue-100 text-blue-800', 'icon' => 'fa-cog'],
        'shipped'         => ['label' => 'Dikirim', 'class' => 'bg-purple-100 text-purple-800', 'icon' => 'fa-shipping-fast'],
        'completed'       => ['label' => 'Selesai', 'class' => 'bg-green-100 text-green-800', 'icon' => 'fa-check-circle'],
        'cancelled'       => ['label' => 'Dibatalkan', 'class' => 'bg-red-100 text-red-800', 'icon' => 'fa-times-circle']
    ];
    $config = $status_config[$status] ?? ['label' => 'Status Lain', 'class' => 'bg-gray-100 text-gray-800', 'icon' => 'fa-question-circle'];
    return sprintf(
        '<span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold %s"><i class="fas %s mr-2"></i>%s</span>',
        $config['class'], $config['icon'], $config['label']
    );
}
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
                <a href="?tab=account" class="tab-button text-sm sm:text-base <?= $active_tab == 'account' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-3 sm:px-4 border-b-2 border-transparent transition"><i class="fas fa-user-circle mr-2"></i>Akun Saya</a>
            </nav>
        </div>

        <div>
            <?php if ($active_tab == 'orders'): ?>
                <div class="space-y-6">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Riwayat Pesanan</h2>
                    <?php if (!empty($orders)): ?>
                        <?php foreach ($orders as $order): ?>
                            <div id="order-card-<?= $order['id'] ?>" class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                                <div class="p-4 sm:p-6 bg-gray-50 border-b">
                                    <div class="flex flex-wrap justify-between items-start gap-3">
                                        <div>
                                            <h3 class="text-base sm:text-lg font-bold text-gray-800">No. Pesanan: <?= htmlspecialchars($order['order_number']) ?></h3>
                                            <p class="text-xs text-gray-500">Tanggal: <?= date('d M Y, H:i', strtotime($order['created_at'])) ?></p>
                                        </div>
                                        <div id="status-badge-<?= $order['id'] ?>">
                                            <?= get_status_badge($order['status']) ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- [FITUR BARU] Tampilan Detail Produk yang Dipesan -->
                                <div class="p-4 sm:p-6">
                                    <h4 class="text-sm font-semibold text-gray-600 mb-3">Detail Produk:</h4>
                                    <div class="space-y-4">
                                        <?php if (isset($order_items_map[$order['id']])): ?>
                                            <?php foreach ($order_items_map[$order['id']] as $item): ?>
                                                <div class="flex items-center gap-4">
                                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="w-16 h-16 object-cover rounded-md border">
                                                    <div class="flex-grow">
                                                        <p class="text-sm font-medium text-gray-800"><?= htmlspecialchars($item['product_name']) ?></p>
                                                        <p class="text-xs text-gray-500"><?= $item['quantity'] ?> x <?= format_rupiah($item['price']) ?></p>
                                                    </div>
                                                    <p class="text-sm font-semibold text-gray-700"><?= format_rupiah($item['quantity'] * $item['price']) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="p-4 sm:p-6 bg-gray-50 border-t">
                                    <div class="flex flex-wrap justify-between items-center gap-4">
                                        <div>
                                            <p class="text-xs text-gray-600">Total Belanja</p>
                                            <p class="text-base sm:text-lg font-bold text-indigo-700"><?= format_rupiah($order['total']) ?></p>
                                        </div>
                                        <div class="flex items-center flex-wrap gap-2">
                                            <?php if ($order['status'] == 'waiting_payment'): ?>
                                                <!-- [FITUR BARU] Tombol Batal Pesanan -->
                                                <form method="POST" onsubmit="return confirm('Anda yakin ingin membatalkan pesanan ini? Stok akan dikembalikan.');">
                                                    <input type="hidden" name="action" value="cancel_order">
                                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                                    <button type="submit" class="text-sm text-red-600 border border-red-200 hover:bg-red-50 px-4 py-2 rounded-md transition">Batalkan</button>
                                                </form>
                                                <button type="button" id="pay-button-<?= $order['id'] ?>" class="pay-button text-sm text-white bg-green-600 hover:bg-green-700 px-4 py-2 rounded-md transition shadow" onclick="continuePayment(<?= $order['id'] ?>)"><i class="fas fa-credit-card mr-1"></i>Bayar</button>
                                            <?php endif; ?>
                                            <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" class="text-sm text-indigo-600 border border-indigo-200 hover:bg-indigo-50 px-4 py-2 rounded-md transition">Invoice</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 bg-white rounded-xl shadow-lg"><p class="text-gray-500">Anda belum memiliki riwayat pesanan.</p></div>
                    <?php endif; ?>
                </div>
            <?php elseif ($active_tab == 'account'): ?>
                <!-- Konten untuk tab akun akan ditambahkan di sini -->
                 <div class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-700 mb-4">Informasi Akun</h2>
                    <p><strong>Nama:</strong> <?= htmlspecialchars($user_data['name']) ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($user_data['email']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="payment-loading-overlay" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-75 z-[9999] flex justify-center items-center text-white text-xl transition-opacity duration-300 opacity-0 pointer-events-none">
        <p><i class="fas fa-spinner fa-spin mr-3"></i>Memuat pembayaran...</p>
    </div>

    <?php footer($conn); ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- LOGIKA PENGECEKAN STATUS OTOMATIS ---
        const ordersToCheck = document.querySelectorAll('[id^="order-card-"]');
        ordersToCheck.forEach(card => {
            const orderId = card.id.split('-')[2];
            const statusBadgeContainer = document.getElementById(`status-badge-${orderId}`);
            if (statusBadgeContainer && statusBadgeContainer.innerText.includes('Menunggu Pembayaran')) {
                checkOrderStatus(orderId);
            }
        });

        async function checkOrderStatus(orderId) {
            try {
                const formData = new FormData();
                formData.append('order_id', orderId);
                const response = await fetch('<?= BASE_URL ?>/checkout/check_payment_status.php', { method: 'POST', body: formData });
                if (!response.ok) return;
                const result = await response.json();
                if (result.success && result.order_status === 'belum_dicetak') {
                    const statusBadgeContainer = document.getElementById(`status-badge-${orderId}`);
                    const payButton = document.getElementById(`pay-button-${orderId}`);
                    if(statusBadgeContainer) statusBadgeContainer.innerHTML = `<?= get_status_badge('belum_dicetak') ?>`;
                    if(payButton) payButton.parentElement.innerHTML = `<a href="<?= BASE_URL ?>/checkout/invoice.php?hash=${payButton.dataset.hash}" class="text-sm text-indigo-600 border border-indigo-200 hover:bg-indigo-50 px-4 py-2 rounded-md transition">Invoice</a>`;
                }
            } catch (error) {
                console.error(`Gagal memeriksa status pesanan #${orderId}:`, error);
            }
        }

        // --- LOGIKA UNTUK TOMBOL "BAYAR SEKARANG" ---
        window.continuePayment = async function(orderId) {
            const loadingOverlay = document.getElementById('payment-loading-overlay');
            loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
            const payButton = document.getElementById(`pay-button-${orderId}`);
            if(payButton) payButton.disabled = true;
            try {
                const formData = new FormData();
                formData.append('order_id', orderId);
                const response = await fetch('<?= BASE_URL ?>/checkout/get_snap_token.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success && result.snap_token) {
                    loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                    window.snap.pay(result.snap_token, {
                        onSuccess: function(res){ window.location.href = `<?= BASE_URL ?>/profile/profile.php?tab=orders&status=success&message=Pembayaran berhasil!`; },
                        onPending: function(res){ window.location.href = `<?= BASE_URL ?>/profile/profile.php?tab=orders&status=pending&message=Pembayaran Anda sedang diproses.`; },
                        onError: function(res){ window.location.href = `<?= BASE_URL ?>/profile/profile.php?tab=orders&status=error&message=Pembayaran gagal.`; },
                        onClose: function(){ if(payButton) payButton.disabled = false; }
                    });
                } else { throw new Error(result.message || 'Gagal memulai sesi pembayaran.'); }
            } catch (error) {
                loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                if(payButton) payButton.disabled = false;
                alert('Terjadi kesalahan: ' + error.message);
            }
        }
    });
    </script>
</body>
</html>