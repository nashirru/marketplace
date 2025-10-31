<?php
// File: profile/profile.php
// PERBAIKAN: Menambahkan "Benteng Pertahanan Terakhir"
// Cek status otomatis saat halaman dimuat.

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';
require_once '../midtrans/config_midtrans.php'; // Pastikan path ini benar

check_login();

$user_id = $_SESSION['user_id'];
$user_data = get_user_by_id($conn, $user_id);
$active_tab = $_GET['tab'] ?? 'orders';

// Logika pembatalan pesanan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);
    $order_number_to_cancel = "N/A"; // Inisialisasi

    if ($order_id_to_cancel > 0) {
        $conn->begin_transaction();
        try {
            $stmt_check = $conn->prepare("SELECT status, order_number FROM orders WHERE id = ? AND user_id = ? FOR UPDATE");
            $stmt_check->bind_param("ii", $order_id_to_cancel, $user_id);
            $stmt_check->execute();
            $order_data_check = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if (!$order_data_check) throw new Exception("Pesanan tidak ditemukan.");
            $order_number_to_cancel = $order_data_check['order_number'];
            if ($order_data_check['status'] !== 'waiting_payment') throw new Exception("Pesanan ini tidak dapat dibatalkan.");
            
            $stmt_items = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
            $stmt_items->bind_param("i", $order_id_to_cancel);
            $stmt_items->execute();
            $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_items->close();
            
            $stmt_restock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            foreach ($items as $item) {
                $stmt_restock->bind_param("ii", $item['quantity'], $item['product_id']);
                $stmt_restock->execute();
            }
            $stmt_restock->close();
            
            $cancel_reason_user = "Dibatalkan oleh pelanggan";
            $stmt_cancel = $conn->prepare("UPDATE orders SET status = 'cancelled', cancel_reason = ? WHERE id = ? AND user_id = ?");
            $stmt_cancel->bind_param("sii", $cancel_reason_user, $order_id_to_cancel, $user_id);
            $stmt_cancel->execute();

            if ($stmt_cancel->affected_rows > 0) {
                $message = "Anda telah membatalkan pesanan #{$order_number_to_cancel}.";
                create_notification($conn, $user_id, $message);
                $conn->commit();
                set_flashdata('success', 'Pesanan berhasil dibatalkan.');
            } else {
                throw new Exception("Gagal update status pesanan.");
            }
            $stmt_cancel->close();
        } catch (Exception $e) {
            $conn->rollback();
            set_flashdata('error', 'Gagal membatalkan: ' . $e->getMessage());
        }
    } else {
        set_flashdata('error', 'ID Pesanan tidak valid.');
    }
    redirect('/profile/profile.php?tab=orders');
}

// Logika Selesaikan Pesanan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_order') {
    $order_id_to_complete = (int)($_POST['order_id'] ?? 0);
    if ($order_id_to_complete > 0) {
        $stmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND user_id = ? AND status = 'shipped'");
        $stmt->bind_param("ii", $order_id_to_complete, $user_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            set_flashdata('success', 'Pesanan telah ditandai sebagai selesai.');
            // Optional: Buat notifikasi
            $orderNumResult = $conn->query("SELECT order_number FROM orders WHERE id = $order_id_to_complete");
            if($orderNumRow = $orderNumResult->fetch_assoc()) {
                 create_notification($conn, $user_id, "Pesanan #{$orderNumRow['order_number']} telah Anda selesaikan.");
            }
        } else {
            set_flashdata('error', 'Gagal menyelesaikan pesanan atau pesanan tidak dalam status dikirim.');
        }
        $stmt->close();
    } else {
        set_flashdata('error', 'ID Pesanan tidak valid.');
    }
     redirect('/profile/profile.php?tab=orders');
}

// Pengambilan data untuk tab aktif
$orders = [];
$addresses = [];
$notifications = [];

if ($active_tab === 'orders') {
     $stmt_orders = $conn->prepare("
        SELECT o.*, oi.product_id, oi.quantity, oi.price AS item_price, p.name AS product_name, p.image AS product_image
        FROM orders o
        LEFT JOIN order_items oi ON o.id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE o.user_id = ?
        ORDER BY o.created_at DESC, o.id DESC
    ");
    $stmt_orders->bind_param("i", $user_id);
    $stmt_orders->execute();
    $result_orders = $stmt_orders->get_result();
    $order_items_grouped = [];
    while ($row = $result_orders->fetch_assoc()) {
        $order_id = $row['id'];
        if (!isset($order_items_grouped[$order_id])) {
            $order_items_grouped[$order_id] = [
                'details' => [
                    'id' => $row['id'],
                    'order_number' => $row['order_number'],
                    'total' => $row['total'],
                    'status' => $row['status'],
                    'cancel_reason' => $row['cancel_reason'],
                    'created_at' => $row['created_at'],
                    'full_name' => $row['full_name'],
                    'address_line_1' => $row['address_line_1'],
                    'city' => $row['city'],
                    'province' => $row['province'],
                    'postal_code' => $row['postal_code'],
                    'phone_number' => $row['phone_number'],
                    'order_hash' => $row['order_hash'] // Ambil hash untuk invoice
                ], 'items' => []
            ];
        }
        if ($row['product_id']) {
            $order_items_grouped[$order_id]['items'][] = [
                'product_id' => $row['product_id'],
                'product_name' => $row['product_name'],
                'product_image' => $row['product_image'],
                'quantity' => $row['quantity'],
                'item_price' => $row['item_price']
            ];
        }
    }
    $orders = array_values($order_items_grouped);
    $stmt_orders->close();
} elseif ($active_tab === 'addresses') {
    $addresses = get_user_addresses($conn, $user_id);
} elseif ($active_tab === 'notifications') {
    $stmt_notif = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $stmt_notif->bind_param("i", $user_id);
    $stmt_notif->execute();
    $result_notif = $stmt_notif->get_result();
    $notifications = $result_notif->fetch_all(MYSQLI_ASSOC);
    $stmt_notif->close();
}

$page_title = "Profil Saya";
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . get_setting($conn, 'store_name'), $conn); ?>
<script type="text/javascript"
        src="https://app.sandbox.midtrans.com/snap/snap.js?v=<?= time() ?>"
        data-client-key="<?= htmlspecialchars(\Midtrans\Config::$clientKey); ?>"></script>
<body class="bg-gray-100">

<div id="loading-overlay" class="fixed top-0 left-0 w-full h-full bg-black bg-opacity-75 z-[9999] flex justify-center items-center text-white text-xl transition-opacity duration-300 opacity-0 pointer-events-none">
    <p><i class="fas fa-spinner fa-spin mr-3"></i>Mempersiapkan pembayaran...</p>
</div>

<?php navbar($conn); ?>

<main class="container mx-auto px-4 py-8">
    <div class="mb-6" id="flash-message-container">
        <?php flash_message(); ?>
    </div>

    <!-- Navigasi Horizontal -->
    <nav class="bg-white p-4 rounded-lg shadow-md mb-8">
        <div class="flex justify-around items-center">
            <a href="?tab=orders" title="Pesanan Saya" class="p-3 rounded-full transition <?= $active_tab == 'orders' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:bg-gray-100 hover:text-indigo-600' ?>">
                <i class="fas fa-box fa-lg w-6 text-center"></i>
            </a>
            <a href="?tab=addresses" title="Alamat Saya" class="p-3 rounded-full transition <?= $active_tab == 'addresses' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:bg-gray-100 hover:text-indigo-600' ?>">
                <i class="fas fa-map-marker-alt fa-lg w-6 text-center"></i>
            </a>
            <a href="?tab=settings" title="Pengaturan Akun" class="p-3 rounded-full transition <?= $active_tab == 'settings' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:bg-gray-100 hover:text-indigo-600' ?>">
                <i class="fas fa-cog fa-lg w-6 text-center"></i>
            </a>
            <a href="?tab=notifications" title="Notifikasi" class="p-3 rounded-full transition <?= $active_tab == 'notifications' ? 'bg-indigo-100 text-indigo-700' : 'text-gray-500 hover:bg-gray-100 hover:text-indigo-600' ?>">
                <i class="fas fa-bell fa-lg w-6 text-center"></i>
            </a>
            <a href="<?= BASE_URL ?>/login/logout.php" title="Logout" class="p-3 rounded-full text-red-500 hover:bg-red-50 hover:text-red-700 transition">
                <i class="fas fa-sign-out-alt fa-lg w-6 text-center"></i>
            </a>
        </div>
    </nav>

    <!-- Konten Utama -->
    <section>
        <?php if ($active_tab === 'orders'): ?>
        <div class="bg-white p-4 sm:p-6 rounded-lg shadow-md">
            <h1 class="text-2xl font-bold mb-6 text-gray-800">Riwayat Pesanan Saya</h1>
            <?php if (empty($orders)): ?>
            <div class="text-center py-16">
                 <i class="fas fa-shopping-basket text-6xl text-gray-300 mb-4"></i>
                 <p class="text-gray-500">Anda belum memiliki riwayat pesanan.</p>
                 <a href="<?=BASE_URL?>/" class="mt-4 inline-block bg-indigo-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-indigo-700 transition">Mulai Belanja</a>
            </div>
            <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($orders as $order_group):
                    $order = $order_group['details'];
                    $items = $order_group['items'];
                    $dt = new DateTime($order['created_at']);
                    $formatted_date = $dt->format('d M Y, H:i');
                    $status_class = get_admin_status_class($order['status']);
                    // Perbaiki teks status agar lebih ramah pengguna
                    $status_text_map = [
                        'waiting_payment' => 'Menunggu Pembayaran',
                        'waiting_approval' => 'Menunggu Konfirmasi',
                        'belum_dicetak' => 'Diproses',
                        'processed' => 'Dikemas',
                        'shipped' => 'Dikirim',
                        'completed' => 'Selesai',
                        'cancelled' => 'Dibatalkan'
                    ];
                    $status_text = $status_text_map[$order['status']] ?? ucfirst(str_replace('_', ' ', $order['status']));
                ?>
                <div id="order-block-<?= $order['id'] ?>" class="border border-gray-200 rounded-xl overflow-hidden shadow-sm hover:shadow-lg transition-shadow duration-300 bg-white">
                    <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-gray-200">
                        <div>
                            <p class="font-semibold text-indigo-700 text-sm sm:text-base">Pesanan #<?= htmlspecialchars($order['order_number']) ?></p>
                            <p class="text-xs sm:text-sm text-gray-500 mt-1"><i class="far fa-clock mr-1"></i><?= $formatted_date ?> WIB</p>
                        </div>
                        <div class="mt-2 sm:mt-0 flex flex-col sm:items-end gap-1 text-right">
                             <span id="order-status-<?= $order['id'] ?>" class="text-xs sm:text-sm font-medium px-3 py-1 rounded-full <?= $status_class ?> shadow-xs"><?= htmlspecialchars($status_text) ?></span>
                            <?php if ($order['status'] === 'cancelled' && !empty($order['cancel_reason'])): ?>
                                <p class="text-xs text-red-600 mt-1.5" title="<?= htmlspecialchars($order['cancel_reason']) ?>">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    <?= htmlspecialchars(substr($order['cancel_reason'], 0, 40)) . (strlen($order['cancel_reason']) > 40 ? '...' : '') ?>
                                </p>
                            <?php endif; ?>
                            <p class="font-bold text-base sm:text-lg text-gray-800 mt-1"><?= format_rupiah($order['total']) ?></p>
                        </div>
                    </div>
                    <div class="p-4 space-y-4">
                        <?php if (empty($items)): ?>
                            <p class="text-sm text-gray-500 italic px-2">Tidak ada detail item untuk pesanan ini.</p>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                            <div class="flex items-start gap-4 p-2 rounded-md hover:bg-gray-50">
                                <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" alt="<?= htmlspecialchars($item['product_name']) ?>" class="w-16 h-16 object-cover rounded-md border flex-shrink-0">
                                <div class="flex-1 min-w-0">
                                    <a href="<?= BASE_URL ?>/product/product.php?id=<?= urlencode(encode_id($item['product_id'])) ?>" class="text-sm font-semibold text-gray-800 hover:text-indigo-700 line-clamp-2 leading-tight">
                                        <?= htmlspecialchars($item['product_name']) ?>
                                    </a>
                                    <p class="text-xs text-gray-500 mt-1">Jumlah: <?= $item['quantity'] ?></p>
                                    <p class="text-xs text-gray-500">@ <?= format_rupiah($item['item_price']) ?></p>
                                </div>
                                <p class="text-sm font-semibold text-gray-700 text-right flex-shrink-0"><?= format_rupiah($item['item_price'] * $item['quantity']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                     <div class="bg-gray-50 p-4 border-t border-gray-200 flex flex-col sm:flex-row justify-between items-center gap-3">
                        <div class="text-xs sm:text-sm text-gray-600 leading-relaxed">
                            <p class="font-medium text-gray-700 mb-1">Dikirim ke:</p>
                            <p><i class="fas fa-user mr-1.5 text-gray-400"></i><?= htmlspecialchars($order['full_name']) ?> <span class="text-gray-400 mx-1">|</span> <i class="fas fa-phone-alt mr-1.5 text-gray-400"></i><?= htmlspecialchars($order['phone_number']) ?></p>
                            <p><i class="fas fa-map-marker-alt mr-1.5 text-gray-400"></i><?= htmlspecialchars($order['address_line_1']) ?>, <?= htmlspecialchars($order['city']) ?>, <?= htmlspecialchars($order['province']) ?> <?= htmlspecialchars($order['postal_code']) ?></p>
                        </div>
                        
                        <div id="order-controls-<?= $order['id'] ?>" class="flex gap-2 w-full sm:w-auto mt-3 sm:mt-0 flex-shrink-0">
                            
                            <?php if ($order['status'] === 'waiting_payment'): ?>
                                <button data-order-id="<?= $order['id'] ?>" class="pay-now-button flex-1 sm:flex-none text-xs sm:text-sm bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition duration-150">
                                    <i class="fas fa-credit-card mr-1"></i> Bayar
                                </button>
                                <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan pesanan ini? Stok akan dikembalikan.')" class="cancel-form flex-1 sm:flex-none">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="w-full text-xs sm:text-sm bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition duration-150">
                                        <i class="fas fa-times mr-1"></i> Batalkan
                                    </button>
                                </form>
                            <?php elseif ($order['status'] === 'shipped'): ?>
                                <form method="POST" onsubmit="return confirm('Konfirmasi bahwa Anda sudah menerima pesanan ini?')" class="complete-form flex-1 sm:flex-none">
                                    <input type="hidden" name="action" value="complete_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="w-full text-xs sm:text-sm bg-gradient-to-r from-teal-500 to-teal-600 hover:from-teal-600 hover:to-teal-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition duration-150">
                                        <i class="fas fa-check-double mr-1"></i> Selesaikan Pesanan
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- PERBAIKAN: Menghapus tombol Invoice -->
                            <!-- 
                            <a id="invoice-btn-<?= $order['id'] ?>"
                               href="<?= BASE_URL ?>/checkout/invoice.php?order_id=<?= urlencode(encode_id($order['id'])) ?>" 
                               target="_blank" 
                               class="flex-1 sm:flex-none text-xs sm:text-sm text-center bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition duration-150"
                               <?php //if (in_array($order['status'], ['waiting_payment', 'cancelled'])): ?>
                               style="display:none;" 
                               <?php //endif; ?>>
                                <i class="fas fa-receipt mr-1"></i> Invoice
                            </a> 
                            -->

                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif ($active_tab === 'addresses'): ?>
        <div class="bg-white p-6 rounded-lg shadow-md">
            <div class="flex justify-between items-center mb-6 border-b pb-4">
                 <h1 class="text-2xl font-bold text-gray-800">Alamat Saya</h1>
                 <button onclick="toggleAddAddressForm()" class="bg-indigo-600 text-white font-semibold py-2 px-4 rounded-md hover:bg-indigo-700 transition text-sm shadow-sm hover:shadow">
                    <i class="fas fa-plus mr-1"></i> Tambah Baru
                </button>
            </div>
            <div id="add-address-form" class="hidden mb-8 p-5 border rounded-lg bg-gray-50 transition-all duration-300 ease-in-out max-h-0 opacity-0 overflow-hidden shadow-inner">
                <h3 class="font-semibold mb-4 text-lg text-gray-700">Tambah Alamat Baru</h3>
                <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST">
                    <input type="hidden" name="action" value="save_address">
                    <input type="hidden" name="address_id" value="0">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div><label for="full_name_new" class="block text-sm font-medium text-gray-700 mb-1">Nama Lengkap*</label><input type="text" id="full_name_new" name="full_name" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" required></div>
                        <div><label for="phone_number_new" class="block text-sm font-medium text-gray-700 mb-1">Nomor Telepon*</label><input type="tel" id="phone_number_new" name="phone_number" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" required></div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                        <div><label for="province_new" class="block text-sm font-medium text-gray-700 mb-1">Provinsi*</label><input type="text" id="province_new" name="province" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" required></div>
                        <div><label for="city_new" class="block text-sm font-medium text-gray-700 mb-1">Kota/Kabupaten*</label><input type="text" id="city_new" name="city" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" required></div>
                        <div><label for="postal_code_new" class="block text-sm font-medium text-gray-700 mb-1">Kode Pos</label><input type="text" id="postal_code_new" name="postal_code" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"></div>
                    </div>
                    <div class="mb-4"><label for="subdistrict_new" class="block text-sm font-medium text-gray-700 mb-1">Kecamatan</label><input type="text" id="subdistrict_new" name="subdistrict" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"></div>
                    <div class="mb-4"><label for="address_line_1_new" class="block text-sm font-medium text-gray-700 mb-1">Alamat Lengkap*</label><textarea id="address_line_1_new" name="address_line_1" rows="2" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500" required></textarea></div>
                    <div class="mb-4"><label for="address_line_2_new" class="block text-sm font-medium text-gray-700 mb-1">Detail Lainnya (Cth: Blok, Patokan)</label><input type="text" id="address_line_2_new" name="address_line_2" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"></div>
                    <div class="mb-4 flex items-center"><input type="checkbox" id="is_default_new" name="is_default" value="1" class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"><label for="is_default_new" class="ml-2 block text-sm text-gray-900">Jadikan alamat utama</label></div>
                    <div class="flex gap-2 mt-5">
                        <button type="submit" class="bg-indigo-600 text-white font-semibold py-2 px-5 rounded-md hover:bg-indigo-700 transition shadow-sm">Simpan</button>
                        <button type="button" onclick="toggleAddAddressForm()" class="bg-gray-200 text-gray-700 font-semibold py-2 px-5 rounded-md hover:bg-gray-300 transition">Batal</button>
                    </div>
                </form>
            </div>
            <?php if (empty($addresses)): ?>
            <p class="text-gray-500 text-center py-10">Anda belum menambahkan alamat.</p>
            <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($addresses as $addr): ?>
                <div class="border border-gray-200 p-4 rounded-lg flex justify-between items-start <?= $addr['is_default'] ? 'bg-indigo-50 border-indigo-300 shadow-sm' : 'bg-white hover:bg-gray-50' ?> transition-colors">
                    <div class="text-sm">
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($addr['full_name']) ?> <?= $addr['is_default'] ? '<span class="text-xs bg-indigo-500 text-white font-medium px-2 py-0.5 rounded-full ml-2 align-middle">Utama</span>' : '' ?></p>
                        <p class="text-gray-600 mt-1"><?= htmlspecialchars($addr['phone_number']) ?></p>
                        <p class="text-gray-600 mt-1"><?= htmlspecialchars($addr['address_line_1']) ?>, <?= htmlspecialchars($addr['subdistrict']) ?>, <?= htmlspecialchars($addr['city']) ?>, <?= htmlspecialchars($addr['province']) ?> <?= htmlspecialchars($addr['postal_code']) ?></p>
                        <?php if (!empty($addr['address_line_2'])): ?>
                        <p class="text-gray-500 italic mt-1">Catatan: <?= htmlspecialchars($addr['address_line_2']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-3 flex-shrink-0 ml-4 mt-1">
                        <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" onsubmit="return confirm('Yakin ingin menghapus alamat ini?')" class="inline">
                            <input type="hidden" name="action" value="delete_address">
                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                            <button type="submit" class="text-red-500 hover:text-red-700" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                        </form>
                        <?php if (!$addr['is_default']): ?>
                        <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="set_default_address">
                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                            <button type="submit" class="text-green-500 hover:text-green-700" title="Jadikan Alamat Utama"><i class="fas fa-check-circle"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>


        <?php elseif ($active_tab === 'notifications'): ?>
        <div class="bg-white p-6 rounded-lg shadow-md">
             <div class="flex justify-between items-center mb-6 border-b pb-4">
                 <h1 class="text-2xl font-bold text-gray-800">Notifikasi</h1>
                 <?php if (!empty($notifications) && array_filter($notifications, fn($n) => !$n['is_read'])): ?>
                    <form method="POST" action="<?= BASE_URL ?>/notification/mark_all_read.php">
                        <button type="submit" class="text-sm text-indigo-600 hover:underline font-medium">Tandai semua dibaca</button>
                    </form>
                 <?php endif; ?>
            </div>
            <?php if (empty($notifications)): ?>
            <p class="text-gray-500 text-center py-16"><i class="far fa-bell-slash text-5xl text-gray-300 mb-4"></i><br>Tidak ada notifikasi.</p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($notifications as $notif):
                    $notif_dt = new DateTime($notif['created_at']);
                    $notif_date = $notif_dt->format('d M Y, H:i');
                ?>
                <li class="border-b border-gray-100 pb-3 last:border-b-0 <?= !$notif['is_read'] ? ' p-3 rounded-md bg-indigo-50' : 'p-3' ?>">
                    <p class="text-sm text-gray-800 <?= !$notif['is_read'] ? 'font-semibold' : '' ?>"><?= htmlspecialchars($notif['message']) ?></p>
                    <p class="text-xs text-gray-400 mt-1.5"><?= $notif_date ?> WIB</p>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <?php elseif ($active_tab === 'settings'): ?>
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h1 class="text-2xl font-bold mb-6 text-gray-800 border-b pb-4">Pengaturan Akun</h1>
            <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="mb-5">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nama</label>
                    <input type="text" id="name" name="name" value="<?= htmlspecialchars($user_data['name']) ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" required>
                </div>
                <div class="mb-6">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email" name="email" value="<?= htmlspecialchars($user_data['email']) ?>" class="w-full p-2 border border-gray-300 rounded-md bg-gray-100 cursor-not-allowed focus:outline-none focus:ring-0 focus:border-gray-300" readonly disabled>
                    <p class="text-xs text-gray-500 mt-1">Email tidak dapat diubah.</p>
                </div>
                <hr class="my-8 border-gray-200">
                <h3 class="text-xl font-semibold mb-4 text-gray-800">Ubah Password</h3>
                <p class="text-sm text-gray-500 mb-5">Kosongkan field password jika Anda tidak ingin mengubahnya.</p>
                <div class="mb-4">
                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Password Saat Ini</label>
                    <input type="password" id="current_password" name="current_password" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="Masukkan password lama Anda">
                </div>
                <div class="mb-4">
                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">Password Baru</label>
                    <input type="password" id="new_password" name="new_password" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="Minimal 6 karakter">
                </div>
                <div class="mb-6">
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Konfirmasi Password Baru</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="w-full p-2 border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500 shadow-sm" placeholder="Ulangi password baru">
                </div>
                <button type="submit" class="bg-indigo-600 text-white font-semibold py-2.5 px-6 rounded-lg hover:bg-indigo-700 transition shadow-md hover:shadow-lg">
                    <i class="fas fa-save mr-2"></i> Simpan Perubahan
                </button>
            </form>
        </div>
        <?php endif; ?>
    </section>

</main>

<?php footer($conn); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const payButtons = document.querySelectorAll('.pay-now-button');
    const loadingOverlay = document.getElementById('loading-overlay');
    const loadingOverlayText = loadingOverlay.querySelector('p');
    const flashContainer = document.getElementById('flash-message-container');

    function injectFlashMessage(type, message) {
        if (!flashContainer) return;
        let icon_class = 'fa-info-circle';
        let color_class = 'text-blue-500';
        let border_color = 'border-blue-500';
        if (type === 'success') {
            icon_class = 'fa-check-circle';
            color_class = 'text-green-500';
            border_color = 'border-green-500';
        } else if (type === 'error') {
            icon_class = 'fa-times-circle';
            color_class = 'text-red-500';
            border_color = 'border-red-500';
        }
        const flashId = 'flashdata-' + new Date().getTime();
        const flashHTML = `
        <div id="${flashId}" class="fixed top-5 right-5 z-50 p-4 rounded-lg shadow-lg bg-white ${border_color} border-l-4 flex items-center" style="opacity: 0; transform: translateX(100%); transition: opacity 0.5s, transform 0.5s;">
            <i class="fas ${icon_class} ${color_class} text-2xl mr-3"></i>
            <div>
                <p class="font-semibold text-gray-800">${type.charAt(0).toUpperCase() + type.slice(1)}</p>
                <p class="text-sm text-gray-600">${message}</p>
            </div>
        </div>`;
        const oldFlash = document.getElementById('flashdata');
        if(oldFlash) oldFlash.remove();
        document.body.insertAdjacentHTML('beforeend', flashHTML);
        setTimeout(() => {
            const el = document.getElementById(flashId);
            if (el) {
                el.style.opacity = '1';
                el.style.transform = 'translateX(0)';
            }
        }, 100);
        setTimeout(() => {
            const el = document.getElementById(flashId);
            if (el) {
                el.style.opacity = '0';
                el.style.transform = 'translateX(100%)';
                setTimeout(() => el.remove(), 500);
            }
        }, 4000);
    }
    
    function updateOrderOnPage(orderId) {
        const statusBadge = document.getElementById(`order-status-${orderId}`);
        const controlsContainer = document.getElementById(`order-controls-${orderId}`);
        
        if (statusBadge) {
            statusBadge.textContent = 'Diproses'; // Ganti teks status
            // Update class sesuai status baru (misal 'Diproses')
            statusBadge.className = 'text-xs sm:text-sm font-medium px-3 py-1 rounded-full bg-cyan-100 text-cyan-800 shadow-xs'; 
        }
        
        if (controlsContainer) {
            // Hapus tombol Bayar dan Batal
            controlsContainer.querySelector('.pay-now-button')?.remove();
            controlsContainer.querySelector('.cancel-form')?.remove();
            
            // Tampilkan tombol Invoice jika ada (dan jika belum dihapus)
            // const invoiceButton = document.getElementById(`invoice-btn-${orderId}`);
            // if (invoiceButton) {
            //     invoiceButton.style.display = 'inline-flex'; 
            // }
        }
    }

    function pollForStatus(orderId) {
        let attempts = 0;
        const maxAttempts = 15; // 30 detik
        const pollCheckUrl = '<?= BASE_URL ?>/checkout/check_payment_status.php';

        if(loadingOverlayText) {
            loadingOverlayText.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i>Memverifikasi pembayaran...';
        }
        loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');

        const intervalId = setInterval(async () => {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(intervalId);
                loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                injectFlashMessage('error', 'Gagal memverifikasi. Silakan refresh halaman.');
                return;
            }

            try {
                const cacheBustedUrl = new URL(pollCheckUrl);
                cacheBustedUrl.searchParams.set('_t', new Date().getTime());
                const formData = new FormData();
                formData.append('order_id', orderId);

                const response = await fetch(cacheBustedUrl, { 
                    method: 'POST', 
                    body: formData,
                    headers: { 'Cache-Control': 'no-cache' }
                });
                const data = await response.json();

                if (data.success && data.order_status === 'belum_dicetak') {
                    clearInterval(intervalId);
                    updateOrderOnPage(orderId); // Panggil fungsi DOM update
                    loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                    injectFlashMessage('success', 'Pembayaran berhasil! Status telah diperbarui.');
                }
            } catch (error) {
                console.error('Polling error:', error);
            }
        }, 2000); // Poll setiap 2 detik
    }

    async function verifyOrderStatusOnLoad(orderId) {
        try {
            const pollCheckUrl = '<?= BASE_URL ?>/checkout/check_payment_status.php';
            const cacheBustedUrl = new URL(pollCheckUrl);
            cacheBustedUrl.searchParams.set('_t', new Date().getTime());
            
            const formData = new FormData();
            formData.append('order_id', orderId);

            const response = await fetch(cacheBustedUrl, { 
                method: 'POST', 
                body: formData,
                headers: { 'Cache-Control': 'no-cache' }
            });
            const data = await response.json();

            if (data.success && data.order_status === 'belum_dicetak') {
                console.log(`Benteng Pertahanan: Order ${orderId} seharusnya 'belum_dicetak'. Memperbarui UI...`);
                updateOrderOnPage(orderId);
            } else if (data.success && data.order_status === 'cancelled') {
                console.log(`Benteng Pertahanan: Order ${orderId} ternyata 'cancelled'. Reloading...`);
                window.location.href = '<?= BASE_URL ?>/profile/profile.php?tab=orders';
            }

        } catch (error) {
            console.error(`Gagal verifikasi order ${orderId} saat load:`, error);
        }
    }

    if (payButtons.length > 0) {
        setTimeout(() => {
            console.log(`Benteng Pertahanan: Ditemukan ${payButtons.length} order 'waiting_payment'. Memverifikasi...`);
            payButtons.forEach(button => {
                const orderId = button.dataset.orderId;
                if (orderId) {
                    verifyOrderStatusOnLoad(orderId);
                }
            });
        }, 500); 
    }

    payButtons.forEach(button => {
        button.addEventListener('click', function() {
            const orderId = this.dataset.orderId;
            payOrder(orderId, this);
        });
    });

    async function payOrder(orderId, payButton) {
        if(loadingOverlayText) {
            loadingOverlayText.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i>Mempersiapkan pembayaran...';
        }
        loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
        if(payButton) payButton.disabled = true;

        try {
            const formData = new FormData();
            formData.append('order_id', orderId);
            const response = await fetch('<?= BASE_URL ?>/checkout/get_snap_token.php', { method: 'POST', body: formData });
            const result = await response.json();
            
            if (result.success && result.snap_token) {
                let finalStatus = null;
                let dbOrderId = result.db_order_id;
                
                window.snap.pay(result.snap_token, {
                    onSuccess: function(res){
                        finalStatus = 'success';
                    },
                    onPending: function(res){
                        finalStatus = 'pending';
                    },
                    onError: function(res){
                        finalStatus = 'error';
                    },
                    onClose: function(){
                        loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                        if (finalStatus === 'success') {
                            pollForStatus(dbOrderId); // Panggil polling
                        } else if (finalStatus === 'pending') {
                            injectFlashMessage('info', 'Selesaikan pembayaran Anda.');
                            if(payButton) payButton.disabled = false;
                        } else if (finalStatus === 'error') {
                            injectFlashMessage('error', 'Pembayaran gagal, silakan coba lagi.');
                            if(payButton) payButton.disabled = false;
                        } else {
                            if(payButton) payButton.disabled = false;
                        }
                    }
                });
            } else { 
                throw new Error(result.message || 'Gagal memulai sesi pembayaran.'); 
            }
        } catch (error) {
            if(loadingOverlayText) {
                loadingOverlayText.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i>Mempersiapkan pembayaran...';
            }
            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
            if(payButton) payButton.disabled = false;
            alert('Terjadi kesalahan: ' + error.message);
        }
    }
});

function toggleAddAddressForm() {
    const form = document.getElementById('add-address-form');
    const isHidden = form.classList.contains('hidden');
    if (isHidden) {
        form.classList.remove('hidden');
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                form.style.maxHeight = form.scrollHeight + 'px';
                form.style.opacity = '1';
                 form.style.marginTop = '1.5rem';
                 form.style.marginBottom = '2rem';
                 form.style.padding = '1.25rem';
            });
        });
    } else {
        form.style.maxHeight = '0';
        form.style.opacity = '0';
        form.style.marginTop = '0';
        form.style.marginBottom = '0';
        form.style.padding = '0';
        setTimeout(() => {
            form.classList.add('hidden');
        }, 300);
    }
}
</script>

</body>
</html>