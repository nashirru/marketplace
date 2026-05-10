<?php
// ================================================================================================
// File: profile/profile.php
// VERSI: IQ 180 V4.0 - ROBUST SQL & ERROR HANDLER
// AUTHOR: SYSTEM (IQ 180)
// DATE: 2026-01-25
//
// CHANGELOG & FIXES:
// 1. [CRITICAL FIX] Memperbaiki potensi 'Unknown column' dengan mengubah SELECT o.* menjadi
//    seleksi kolom eksplisit sesuai skema database 'publi.sql'.
// 2. [PERFORMANCE] Menghapus 'SELECT *' yang boros memori.
// 3. [CONSISTENCY] Menyelaraskan nama kolom dengan tabel 'orders' (midtrans_transaction_id).
// 4. [SECURITY] Transaction lock (FOR UPDATE) diperkuat pada logika pembatalan.
// 5. [UX] Peningkatan feedback error pada JavaScript untuk debugging Midtrans.
// ================================================================================================

date_default_timezone_set('Asia/Jakarta');

// Memuat dependensi utama sistem
// Pastikan path ini sesuai dengan struktur direktori Anda
require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';
require_once '../midtrans/config_midtrans.php'; 

// Cek autentikasi user sebelum memuat halaman
check_login();

// Inisialisasi variabel user dari session
$user_id = $_SESSION['user_id'];

// Ambil data user terbaru dari database untuk memastikan validitas
$user_data = get_user_by_id($conn, $user_id);

// Tentukan tab aktif, default ke 'orders'
$active_tab = $_GET['tab'] ?? 'orders';

// ================================================================================================
// 1. LOGIKA PEMBATALAN PESANAN (SERVER-SIDE TRANSACTION)
// ================================================================================================
// Menggunakan MySQL Transaction untuk menjamin integritas data stok saat pembatalan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    $order_id_to_cancel = (int)($_POST['order_id'] ?? 0);
    $order_number_to_cancel = "N/A"; 

    if ($order_id_to_cancel > 0) {
        // Mulai Transaksi Database
        $conn->begin_transaction();
        try {
            // [IQ 180] Menggunakan 'FOR UPDATE' untuk mengunci baris (Row Locking)
            // Ini mencegah Race Condition jika user menekan tombol batal berulang kali dengan cepat
            $stmt_check = $conn->prepare("
                SELECT status, order_number 
                FROM orders 
                WHERE id = ? AND user_id = ? 
                FOR UPDATE
            ");
            $stmt_check->bind_param("ii", $order_id_to_cancel, $user_id);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $order_data_check = $result_check->fetch_assoc();
            $stmt_check->close();

            // Validasi keberadaan pesanan
            if (!$order_data_check) {
                throw new Exception("Pesanan tidak ditemukan atau akses ditolak.");
            }

            // Validasi status pesanan (hanya 'waiting_payment' yang bisa dibatalkan user)
            if ($order_data_check['status'] !== 'waiting_payment') {
                throw new Exception("Pesanan ini tidak dapat dibatalkan karena status sudah berubah.");
            }
            
            $order_number_to_cancel = $order_data_check['order_number'];

            // Ambil item dalam pesanan untuk proses pengembalian stok (Restock)
            // Kita mengambil product_id, quantity, dan variation_id
            $stmt_items = $conn->prepare("
                SELECT product_id, quantity, variation_id 
                FROM order_items 
                WHERE order_id = ?
            ");
            $stmt_items->bind_param("i", $order_id_to_cancel);
            $stmt_items->execute();
            $items = $stmt_items->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt_items->close();
            
            // Persiapkan statement update stok untuk efisiensi dalam loop
            $stmt_restock_prod = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $stmt_restock_var = $conn->prepare("UPDATE product_variations SET stock = stock + ? WHERE id = ?");

            foreach ($items as $item) {
                // Jika item punya variasi, restock hanya variasi.
                $variation_id = isset($item['variation_id']) ? (int)$item['variation_id'] : 0;
                if ($variation_id > 0) {
                    $stmt_restock_var->bind_param("ii", $item['quantity'], $variation_id);
                    $stmt_restock_var->execute();
                } else {
                    $stmt_restock_prod->bind_param("ii", $item['quantity'], $item['product_id']);
                    $stmt_restock_prod->execute();
                }
            }
            
            // Tutup statement restock
            $stmt_restock_prod->close();
            $stmt_restock_var->close();
            
            // Update status pesanan menjadi 'cancelled'
            $cancel_reason_user = "Dibatalkan oleh pelanggan (Self Service)";
            $stmt_cancel = $conn->prepare("
                UPDATE orders 
                SET status = 'cancelled', cancel_reason = ? 
                WHERE id = ? AND user_id = ?
            ");
            $stmt_cancel->bind_param("sii", $cancel_reason_user, $order_id_to_cancel, $user_id);
            $stmt_cancel->execute();

            if ($stmt_cancel->affected_rows > 0) {
                // Buat notifikasi sistem
                $message = "Anda telah membatalkan pesanan #{$order_number_to_cancel}.";
                create_notification($conn, $user_id, $message);
                
                // Commit transaksi jika semua sukses
                $conn->commit();
                set_flashdata('success', 'Pesanan berhasil dibatalkan dan stok telah dikembalikan.');
            } else {
                throw new Exception("Gagal mengupdate status pesanan. Silakan coba lagi.");
            }
            $stmt_cancel->close();

        } catch (Exception $e) {
            // Rollback jika terjadi error apapun selama proses
            $conn->rollback();
            set_flashdata('error', 'Gagal membatalkan: ' . $e->getMessage());
        }
    } else {
        set_flashdata('error', 'ID Pesanan tidak valid.');
    }
    // Redirect kembali ke tab orders
    redirect('/profile/profile.php?tab=orders');
}

// ================================================================================================
// 2. LOGIKA PENYELESAIAN PESANAN (USER CONFIRMATION)
// ================================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'complete_order') {
    $order_id_to_complete = (int)($_POST['order_id'] ?? 0);
    
    if ($order_id_to_complete > 0) {
        // Update hanya jika status saat ini adalah 'shipped'
        $stmt = $conn->prepare("UPDATE orders SET status = 'completed' WHERE id = ? AND user_id = ? AND status = 'shipped'");
        $stmt->bind_param("ii", $order_id_to_complete, $user_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            set_flashdata('success', 'Terima kasih! Pesanan telah ditandai sebagai selesai.');
            
            // Ambil nomor pesanan untuk notifikasi
            $orderNumResult = $conn->query("SELECT order_number FROM orders WHERE id = $order_id_to_complete");
            if($orderNumRow = $orderNumResult->fetch_assoc()) {
                 create_notification($conn, $user_id, "Pesanan #{$orderNumRow['order_number']} telah Anda selesaikan.");
            }
        } else {
            set_flashdata('error', 'Gagal menyelesaikan pesanan. Pastikan status pesanan sudah dikirim.');
        }
        $stmt->close();
    }
    redirect('/profile/profile.php?tab=orders');
}

// ================================================================================================
// 3. DATA FETCHING (SAFE & OPTIMIZED MODE)
// ================================================================================================
$orders = [];
$addresses = [];
$notifications = [];
$shipment_details = []; 

// Konfigurasi Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$total_pages = 1;

if ($active_tab === 'orders') {
    // --- LANGKAH 1: Hitung Total Pesanan (Optimized Count) ---
    $stmt_count = $conn->prepare("SELECT COUNT(id) as total FROM orders WHERE user_id = ?");
    $stmt_count->bind_param("i", $user_id);
    $stmt_count->execute();
    $total_orders = $stmt_count->get_result()->fetch_assoc()['total'];
    $total_pages = ceil($total_orders / $limit);
    $stmt_count->close();

    // --- LANGKAH 2: Ambil ID Pesanan (Efficient Pagination) ---
    // Kita hanya mengambil ID terlebih dahulu untuk pagination yang efisien
    $stmt_ids = $conn->prepare("
        SELECT id 
        FROM orders 
        WHERE user_id = ? 
        ORDER BY created_at DESC, id DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt_ids->bind_param("iii", $user_id, $limit, $offset);
    $stmt_ids->execute();
    $result_ids = $stmt_ids->get_result();
    $order_ids = [];
    while($row = $result_ids->fetch_assoc()) {
        $order_ids[] = $row['id'];
    }
    $stmt_ids->close();

    // --- LANGKAH 3: Ambil Detail Pesanan (EXPLICIT COLUMN SELECTION) ---
    // [IQ 180 FIX] Menggunakan nama kolom eksplisit untuk menghindari error "Unknown Column"
    // dan memastikan konsistensi dengan skema database 'orders'.
    if (!empty($order_ids)) {
        $ids_placeholder = implode(',', array_fill(0, count($order_ids), '?'));
        
        // Perhatikan: Kita tidak menggunakan o.* untuk keamanan.
        // Kita memetakan kolom sesuai tabel 'orders' di SQL.
        $sql_details = "
            SELECT 
                o.id, 
                o.order_number, 
                o.total, 
                o.status, 
                o.cancel_reason, 
                o.created_at, 
                o.full_name, 
                o.phone_number, 
                o.address_line_1, 
                o.city, 
                o.province, 
                o.subdistrict, 
                o.postal_code, 
                o.order_hash,
                o.midtrans_transaction_id, /* Pastikan ini diambil jika ada debugging */
                o.midtrans_payment_type,
                o.tracking_number,
                
                /* Kolom dari Order Items */
                oi.product_id, 
                oi.quantity, 
                oi.price AS item_price, 
                
                /* Kolom dari Produk */
                p.name AS product_name, 
                p.image AS product_image,
                
                /* Kolom dari Variasi */
                pv.name AS variation_name
            FROM orders o
            LEFT JOIN order_items oi ON o.id = oi.order_id
            LEFT JOIN products p ON oi.product_id = p.id
            LEFT JOIN product_variations pv ON oi.variation_id = pv.id
            WHERE o.id IN ($ids_placeholder)
            ORDER BY o.created_at DESC, o.id DESC
        ";
        
        $stmt_orders = $conn->prepare($sql_details);
        
        // Bind parameter dinamis
        $types = str_repeat('i', count($order_ids));
        $stmt_orders->bind_param($types, ...$order_ids);
        $stmt_orders->execute();
        $result_orders = $stmt_orders->get_result();
        
        $order_items_grouped = [];
        
        // Grouping logic (PHP-side join handling)
        while ($row = $result_orders->fetch_assoc()) {
            $order_id = $row['id'];
            
            // Inisialisasi Header Pesanan jika belum ada
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
                        'subdistrict' => $row['subdistrict'] ?? '',
                        'postal_code' => $row['postal_code'],
                        'phone_number' => $row['phone_number'],
                        'order_hash' => $row['order_hash'],
                        'tracking_number' => $row['tracking_number']
                    ], 'items' => []
                ];
            }
            
            // Masukkan Item ke dalam grup pesanan
            if ($row['product_id']) {
                $order_items_grouped[$order_id]['items'][] = [
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'variation_name' => $row['variation_name'], // Nama variasi dari tabel PV
                    'product_image' => $row['product_image'],
                    'quantity' => $row['quantity'],
                    'item_price' => $row['item_price']
                ];
            }
        }
        $orders = array_values($order_items_grouped);
        $stmt_orders->close();
    }
} elseif ($active_tab === 'addresses') {
    // Fungsi ini diasumsikan ada di sistem.php atau partial.php
    $addresses = get_user_addresses($conn, $user_id);
} elseif ($active_tab === 'notifications') {
    // Ambil notifikasi
    $stmt_notif = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC");
    $stmt_notif->bind_param("i", $user_id);
    $stmt_notif->execute();
    $result_notif = $stmt_notif->get_result();
    $notifications = $result_notif->fetch_all(MYSQLI_ASSOC);
    $stmt_notif->close();
} elseif ($active_tab === 'tracking') {
    // Logika Tracking yang Kompleks: Map Order ke Resi ke Data Tracking
    $all_user_resis_flat = []; 
    $order_to_resi_map = []; 
    
    // Ambil semua order yang punya resi
    $stmt_orders_resi = $conn->prepare("
        SELECT order_number, tracking_number 
        FROM orders 
        WHERE user_id = ? AND (tracking_number IS NOT NULL AND tracking_number != '') 
        ORDER BY created_at DESC
    ");
    $stmt_orders_resi->bind_param("i", $user_id);
    $stmt_orders_resi->execute();
    $orders_with_resi = $stmt_orders_resi->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_orders_resi->close();

    // Flatten resi (karena satu order bisa multi resi dipisah koma)
    if (!empty($orders_with_resi)) {
        foreach ($orders_with_resi as $order) {
            $resis = explode(',', $order['tracking_number']);
            foreach ($resis as $resi) {
                $c_resi = trim($resi);
                if (!empty($c_resi)) {
                    $all_user_resis_flat[] = $c_resi;
                    $order_to_resi_map[$c_resi] = $order['order_number'];
                }
            }
        }
    }
    
    // Ambil detail pengiriman dari tabel imported_shipments
    $unique_resis = array_unique($all_user_resis_flat);
    if (!empty($unique_resis)) {
        $placeholders = implode(',', array_fill(0, count($unique_resis), '?'));
        $types = str_repeat('s', count($unique_resis));
        
        $stmt_tracking = $conn->prepare("
            SELECT tracking_number, shipping_cost, shipment_date 
            FROM imported_shipments 
            WHERE tracking_number IN ($placeholders)
        ");
        $stmt_tracking->bind_param($types, ...$unique_resis);
        $stmt_tracking->execute();
        $result_tracking = $stmt_tracking->get_result();
        
        while ($row = $result_tracking->fetch_assoc()) {
            $shipment_details[] = [
                'resi' => $row['tracking_number'],
                'order_number' => $order_to_resi_map[$row['tracking_number']] ?? 'N/A',
                'shipping_cost' => $row['shipping_cost'], 
                'shipment_date' => $row['shipment_date'], 
            ];
        }
        $stmt_tracking->close();
    }
}

$page_title = "Profil Saya";
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title, $conn); ?>
<!-- Integrasi Midtrans Snap JS dengan Timestamp Cache Buster -->
<script src="<?= htmlspecialchars(midtrans_snap_js_url(), ENT_QUOTES, 'UTF-8') ?>" data-client-key="<?= htmlspecialchars(\Midtrans\Config::$clientKey); ?>"></script>

<body class="bg-white text-gray-800">

<!-- 2. UI LOADING OVERLAY (High Fidelity) -->
<div id="loading-overlay" class="fixed inset-0 bg-black/80 z-[9999] flex justify-center items-center text-white transition-opacity duration-300 opacity-0 pointer-events-none backdrop-blur-sm">
    <div class="flex flex-col items-center animate-pulse">
        <i class="fas fa-circle-notch fa-spin text-5xl text-red-600 mb-4 filter drop-shadow-lg"></i>
        <p class="font-bold text-lg tracking-wide" id="loading-text">Memproses Transaksi...</p>
        <p class="text-xs text-gray-400 mt-2">Mohon jangan tutup halaman ini</p>
    </div>
</div>

<!-- Modal Alert Custom (Error Handler UI) -->
<div id="error-modal" class="fixed inset-0 z-[10000] hidden flex items-center justify-center bg-black/60 backdrop-blur-md transition-all duration-300">
    <div class="bg-white rounded-xl shadow-2xl p-6 max-w-lg w-full mx-4 transform transition-all scale-100 border border-red-100">
        <div class="flex items-center gap-4 mb-4 border-b border-gray-100 pb-4">
            <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-xl text-red-600"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900">Terjadi Kesalahan Sistem</h3>
                <p class="text-xs text-gray-500">Kode Error: SYSTEM_EXCEPTION</p>
            </div>
        </div>
        
        <div class="bg-gray-50 p-4 rounded-lg border border-gray-200 mb-6">
            <p class="text-xs font-mono text-gray-700 break-words whitespace-pre-wrap" id="error-modal-message">
                <!-- Pesan error akan diinject via JS -->
            </p>
        </div>
        
        <div class="flex justify-end gap-3">
             <button onclick="document.getElementById('error-modal').classList.add('hidden')" class="px-5 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm font-bold">Tutup</button>
             <button onclick="location.reload()" class="px-5 py-2.5 bg-red-700 text-white rounded-lg hover:bg-red-800 transition text-sm font-bold shadow-lg">Refresh Halaman</button>
        </div>
    </div>
</div>

<?php navbar($conn); ?>

<main class="container mx-auto px-4 py-8 max-w-6xl min-h-screen">
    <!-- Flash Message Container -->
    <div id="flash-message-container" class="sticky top-4 z-40"><?php flash_message(); ?></div>

    <!-- 3. PROFILE HEADER DESIGN (Modern & Clean) -->
    <div class="flex flex-col md:flex-row items-center justify-between mb-8 gap-6 p-6 bg-white rounded-2xl border border-gray-100 shadow-sm">
        <div class="flex items-center gap-5 w-full md:w-auto">
            <div class="relative">
                <div class="w-20 h-20 rounded-full bg-gradient-to-br from-red-50 to-red-100 flex items-center justify-center text-red-700 text-3xl font-extrabold shadow-inner border border-red-200">
                    <?= strtoupper(substr($user_data['name'], 0, 1)) ?>
                </div>
                <div class="absolute bottom-0 right-0 w-6 h-6 bg-green-500 border-2 border-white rounded-full" title="Online"></div>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Halo, <?= htmlspecialchars(explode(' ', $user_data['name'])[0]) ?>!</h1>
                <p class="text-sm text-gray-500 font-medium"><?= htmlspecialchars($user_data['email']) ?></p>
                <div class="mt-2 flex gap-2">
                    <span class="px-2 py-0.5 bg-gray-100 text-gray-600 text-[10px] font-bold uppercase rounded border border-gray-200">Member</span>
                </div>
            </div>
        </div>
        
        <!-- Tab Navigation (Responsive Scrollable) -->
        <nav class="flex overflow-x-auto pb-2 md:pb-0 gap-2 w-full md:w-auto scrollbar-hide">
            <?php
            $tabs = [
                'orders' => ['icon' => 'fa-box', 'label' => 'Pesanan'],
                'tracking' => ['icon' => 'fa-truck', 'label' => 'Lacak'],
                'addresses' => ['icon' => 'fa-map-marker-alt', 'label' => 'Alamat'],
                'notifications' => ['icon' => 'fa-bell', 'label' => 'Notif'],
                'settings' => ['icon' => 'fa-cog', 'label' => 'Akun'],
            ];
            foreach ($tabs as $key => $tab):
                $isActive = $active_tab == $key;
                $activeClass = $isActive 
                    ? 'bg-red-700 text-white shadow-lg shadow-red-200 ring-2 ring-red-100 border-transparent' 
                    : 'bg-white text-gray-600 hover:bg-gray-50 hover:text-red-600 border border-gray-200';
            ?>
                <a href="?tab=<?= $key ?>" class="flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-bold transition-all whitespace-nowrap border <?= $activeClass ?>">
                    <i class="fas <?= $tab['icon'] ?>"></i>
                    <span><?= $tab['label'] ?></span>
                </a>
            <?php endforeach; ?>
            <a href="<?= BASE_URL ?>/login/logout.php" class="flex items-center gap-2 px-5 py-2.5 rounded-full text-sm font-bold bg-red-50 text-red-600 hover:bg-red-100 transition-all whitespace-nowrap ml-auto md:ml-2 border border-red-100">
                <i class="fas fa-sign-out-alt"></i> Keluar
            </a>
        </nav>
    </div>

    <!-- 4. CONTENT SECTIONS CONTAINER -->
    <section class="min-h-[500px] transition-all duration-500">
        
        <!-- === TAB: ORDERS (Logic Intensive) === -->
        <?php if ($active_tab === 'orders'): ?>
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-900">Riwayat Pesanan</h2>
                <?php if (!empty($orders)): ?>
                    <span class="text-xs font-semibold bg-gray-100 text-gray-600 px-3 py-1 rounded-full"><?= $total_orders ?> Transaksi</span>
                <?php endif; ?>
            </div>

            <?php if (empty($orders)): ?>
                <!-- Empty State -->
                <div class="flex flex-col items-center justify-center py-20 border-2 border-dashed border-gray-200 rounded-2xl bg-gray-50">
                    <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mb-4 text-gray-300 shadow-sm">
                        <i class="fas fa-shopping-bag text-4xl"></i>
                    </div>
                    <p class="text-gray-500 font-bold text-lg">Belum ada pesanan.</p>
                    <p class="text-gray-400 text-sm mb-6">Yuk, mulai belanja produk favoritmu!</p>
                    <a href="<?=BASE_URL?>/" class="px-6 py-3 bg-red-700 text-white rounded-lg font-bold hover:bg-red-800 transition shadow-lg shadow-red-200">
                        Mulai Belanja <i class="fas fa-arrow-right ml-2"></i>
                    </a>
                </div>
            <?php else: ?>
                <!-- Order List -->
                <div class="space-y-6">
                    <?php foreach ($orders as $order_group):
                        $order = $order_group['details'];
                        $items = $order_group['items'];
                        
                        // Mapping Status dengan Warna & Label
                        $status_map = [
                            'waiting_payment' => ['label' => 'Menunggu Pembayaran', 'class' => 'bg-orange-50 text-orange-700 border-orange-200 ring-orange-100'],
                            'waiting_approval' => ['label' => 'Menunggu Konfirmasi', 'class' => 'bg-blue-50 text-blue-700 border-blue-200 ring-blue-100'],
                            'belum_dicetak'    => ['label' => 'Diproses', 'class' => 'bg-teal-50 text-teal-700 border-teal-200 ring-teal-100'],
                            'processed'        => ['label' => 'Dikemas', 'class' => 'bg-indigo-50 text-indigo-700 border-indigo-200 ring-indigo-100'],
                            'shipped'          => ['label' => 'Dikirim', 'class' => 'bg-purple-50 text-purple-700 border-purple-200 ring-purple-100'],
                            'completed'        => ['label' => 'Selesai', 'class' => 'bg-green-50 text-green-700 border-green-200 ring-green-100'],
                            'cancelled'        => ['label' => 'Dibatalkan', 'class' => 'bg-red-50 text-red-700 border-red-200 ring-red-100'],
                        ];
                        
                        $st = $status_map[$order['status']] ?? ['label' => $order['status'], 'class' => 'bg-gray-100 text-gray-700 border-gray-200'];
                    ?>
                    
                    <div id="order-block-<?= $order['id'] ?>" class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:shadow-md transition-all duration-300 relative group">
                        <!-- Card Header -->
                        <div class="bg-gray-50/50 px-6 py-4 flex flex-wrap justify-between items-center gap-4 border-b border-gray-100">
                            <div class="flex flex-col gap-1">
                                <div class="flex items-center gap-3">
                                    <span class="bg-gray-800 text-white text-[10px] font-bold px-2 py-0.5 rounded uppercase tracking-wider">Order</span>
                                    <p class="text-sm font-bold text-gray-900 font-mono">#<?= htmlspecialchars($order['order_number']) ?></p>
                                </div>
                                <p class="text-xs text-gray-500 flex items-center gap-1">
                                    <i class="far fa-clock"></i>
                                    <?= (new DateTime($order['created_at']))->format('d M Y, H:i') ?> WIB
                                </p>
                            </div>
                            <div class="text-right">
                                <span id="order-status-<?= $order['id'] ?>" class="px-3 py-1 rounded-full text-xs font-bold border ring-1 ring-inset <?= $st['class'] ?>">
                                    <?= $st['label'] ?>
                                </span>
                                <p class="text-lg font-bold text-gray-900 mt-1"><?= format_rupiah($order['total']) ?></p>
                            </div>
                        </div>

                        <!-- Info Penerima & Alamat -->
                        <div class="px-6 py-4 bg-white border-b border-gray-50 text-sm text-gray-600 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-gray-400"><i class="fas fa-user"></i></div>
                                <div>
                                    <span class="font-bold text-gray-800 block"><?= htmlspecialchars($order['full_name']) ?></span>
                                    <span class="text-gray-500 text-xs"><?= htmlspecialchars($order['phone_number']) ?></span>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 text-gray-400"><i class="fas fa-map-marked-alt"></i></div>
                                <p class="line-clamp-2 text-xs leading-relaxed" title="<?= htmlspecialchars($order['address_line_1']) ?>">
                                    <?= htmlspecialchars($order['address_line_1']) ?><br>
                                    <?= htmlspecialchars($order['subdistrict'] ?? '') ?>, <?= htmlspecialchars($order['city']) ?> <?= htmlspecialchars($order['postal_code']) ?>
                                </p>
                            </div>
                        </div>

                        <!-- Card Body (Items) -->
                        <div class="p-6 space-y-4">
                            <?php foreach ($items as $index => $item): ?>
                                <div class="flex gap-4 group-hover:bg-gray-50/50 p-2 rounded-lg transition-colors -mx-2 <?= $index >= 2 ? 'hidden extra-item-' . $order['id'] : '' ?>">
                                    <div class="w-16 h-16 flex-shrink-0 bg-white rounded-lg overflow-hidden border border-gray-200 p-1">
                                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['product_image']) ?>" class="w-full h-full object-cover rounded" onerror="this.src='<?=BASE_URL?>/assets/images/no-image.png'">
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-bold text-gray-800 line-clamp-1"><?= htmlspecialchars($item['product_name']) ?></h4>
                                        <!-- Menampilkan Variasi dengan Benar -->
                                        <?php if (!empty($item['variation_name'])): ?>
                                            <div class="mt-1">
                                                <span class="text-[10px] bg-gray-200 text-gray-700 px-2 py-0.5 rounded border border-gray-300 font-medium">
                                                    <?= htmlspecialchars($item['variation_name']) ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex justify-between items-end mt-2">
                                            <p class="text-xs text-gray-500 font-medium"><?= $item['quantity'] ?> x <?= format_rupiah($item['item_price']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($items) > 2): ?>
                                <button onclick="toggleExtraItems(<?= $order['id'] ?>)" id="toggle-btn-<?= $order['id'] ?>" class="w-full py-2 text-xs text-center text-gray-500 hover:text-red-700 font-medium border-t border-dashed border-gray-200">
                                    + <?= count($items) - 2 ?> produk lainnya
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Card Actions (Dynamic Controls) -->
                        <?php if (in_array($order['status'], ['waiting_payment', 'shipped'])): ?>
                        <div id="order-controls-<?= $order['id'] ?>" class="px-6 py-4 bg-gray-50 flex flex-wrap justify-end gap-3 border-t border-gray-100">
                             
                             <?php if ($order['status'] === 'waiting_payment'): ?>
                                <form method="POST" onsubmit="return confirm('Apakah Anda yakin ingin membatalkan pesanan ini? Stok akan dikembalikan.')" class="cancel-form">
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <button type="submit" class="px-5 py-2.5 rounded-lg text-sm font-bold text-gray-500 hover:text-red-600 hover:bg-white border border-transparent hover:border-red-200 transition">
                                        Batalkan
                                    </button>
                                </form>
                                <!-- TOMBOL BAYAR (Trigger JS) -->
                                <button 
                                    data-order-id="<?= $order['id'] ?>" 
                                    class="pay-now-button px-6 py-2.5 rounded-lg bg-red-700 text-white text-sm font-bold hover:bg-red-800 shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all flex items-center gap-2">
                                    <i class="fas fa-credit-card"></i> Bayar Sekarang
                                </button>
                                
                            <?php elseif ($order['status'] === 'shipped'): ?>
                                <div class="flex items-center gap-3">
                                    <?php if(!empty($order['tracking_number'])): ?>
                                    <a href="?tab=tracking" class="text-sm font-medium text-blue-600 hover:underline mr-2">Lacak Paket</a>
                                    <?php endif; ?>
                                    
                                    <form method="POST" onsubmit="return confirm('Pastikan Anda telah menerima paket dengan baik sebelum menyelesaikan pesanan.')" class="complete-form">
                                        <input type="hidden" name="action" value="complete_order">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="px-6 py-2.5 rounded-lg bg-green-600 text-white text-sm font-bold hover:bg-green-700 shadow-md transition flex items-center gap-2">
                                            <i class="fas fa-check-circle"></i> Pesanan Diterima
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Helper Text for Cancelled -->
                        <?php if ($order['status'] === 'cancelled'): ?>
                            <div class="px-6 py-3 bg-red-50 border-t border-red-100">
                                <p class="text-xs text-red-600 font-medium"><i class="fas fa-info-circle mr-1"></i> Alasan: <?= htmlspecialchars($order['cancel_reason'] ?? 'Tidak disebutkan') ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- PAGINATION CONTROL -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-10 flex justify-center items-center gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?tab=orders&page=<?= $page - 1 ?>" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700 transition shadow-sm">
                            <i class="fas fa-chevron-left text-xs"></i>
                        </a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == 1 || $i == $total_pages || ($i >= $page - 1 && $i <= $page + 1)): ?>
                            <a href="?tab=orders&page=<?= $i ?>" class="w-10 h-10 flex items-center justify-center rounded-full text-sm font-bold transition shadow-sm <?= $i == $page ? 'bg-red-700 text-white border-red-700 transform scale-110' : 'bg-white border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700' ?>">
                                <?= $i ?>
                            </a>
                        <?php elseif ($i == $page - 2 || $i == $page + 2): ?>
                            <span class="text-gray-400">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?tab=orders&page=<?= $page + 1 ?>" class="w-10 h-10 flex items-center justify-center rounded-full bg-white border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700 transition shadow-sm">
                            <i class="fas fa-chevron-right text-xs"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <?php endif; ?>

        <!-- === TAB: TRACKING === -->
        <?php elseif ($active_tab === 'tracking'): ?>
            <h2 class="text-xl font-bold text-gray-900 mb-6 flex items-center gap-2"><i class="fas fa-search-location text-red-700"></i> Lacak Pengiriman</h2>
            <?php if (empty($shipment_details)): ?>
                 <div class="text-center py-12 bg-gray-50 rounded-2xl border border-gray-200 border-dashed">
                    <i class="fas fa-truck text-3xl text-gray-300 mb-3"></i>
                    <p class="text-gray-500 font-medium">Belum ada data resi yang tersedia.</p>
                </div>
            <?php else: ?>
                <div class="grid gap-4">
                <?php foreach ($shipment_details as $detail): ?>
                    <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 hover:border-red-200 transition">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-bold uppercase">RESI</span>
                                <span class="text-sm font-bold text-gray-400">#<?= htmlspecialchars($detail['order_number']) ?></span>
                            </div>
                            <p class="text-2xl font-mono font-bold text-gray-800 tracking-wider mt-2"><?= htmlspecialchars($detail['resi']) ?></p>
                            <p class="text-xs text-gray-500 mt-1">Estimasi Ongkir: <?= format_rupiah($detail['shipping_cost']) ?></p>
                        </div>
                        <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                             <button onclick="copyToClipboard('<?= htmlspecialchars($detail['resi']) ?>')" class="flex-1 sm:flex-none px-4 py-2 text-xs font-bold text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition border border-gray-200">
                                <i class="far fa-copy mr-1"></i> Salin
                             </button>
                             <a href="https://jet.co.id/track?tracking_id=<?= urlencode($detail['resi']) ?>" target="_blank" class="flex-1 sm:flex-none px-5 py-2 text-xs font-bold text-white bg-red-600 rounded-lg hover:bg-red-700 transition shadow-md text-center">
                                Lacak Paket <i class="fas fa-external-link-alt ml-1"></i>
                             </a>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <!-- === TAB: ADDRESSES === -->
        <?php elseif ($active_tab === 'addresses'): ?>
            <div class="flex justify-between items-center mb-6">
                 <h2 class="text-xl font-bold text-gray-900">Daftar Alamat</h2>
                 <button onclick="toggleAddAddressForm()" class="text-sm font-bold text-white bg-red-700 px-5 py-2.5 rounded-lg hover:bg-red-800 transition shadow-md flex items-center gap-2">
                    <i class="fas fa-plus"></i> Tambah Alamat
                 </button>
            </div>
            
            <!-- Simplified Add Form -->
            <div id="add-address-form" class="hidden mb-8 p-6 bg-white rounded-xl border border-red-100 shadow-lg relative overflow-hidden">
                <div class="absolute top-0 left-0 w-1 h-full bg-red-600"></div>
                <h3 class="font-bold text-gray-800 mb-4">Alamat Baru</h3>
                <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <input type="hidden" name="action" value="save_address">
                    <input type="hidden" name="address_id" value="0">
                    
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-500 uppercase">Nama Penerima</label>
                        <input type="text" name="full_name" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-200 focus:border-red-500 transition outline-none" required>
                    </div>
                    <div class="space-y-1">
                        <label class="text-xs font-bold text-gray-500 uppercase">No. Telepon</label>
                        <input type="tel" name="phone_number" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-200 focus:border-red-500 transition outline-none" required>
                    </div>
                    
                    <input type="text" name="province" placeholder="Provinsi" class="p-3 border rounded-lg focus:ring-red-500 focus:border-red-500 outline-none" required>
                    <input type="text" name="city" placeholder="Kota" class="p-3 border rounded-lg focus:ring-red-500 focus:border-red-500 outline-none" required>
                    <input type="text" name="subdistrict" placeholder="Kecamatan" class="p-3 border rounded-lg focus:ring-red-500 focus:border-red-500 outline-none">
                    <input type="text" name="postal_code" placeholder="Kode Pos" class="p-3 border rounded-lg focus:ring-red-500 focus:border-red-500 outline-none">
                    
                    <textarea name="address_line_1" rows="2" placeholder="Detail Alamat (Jalan, No. Rumah, RT/RW)" class="md:col-span-2 p-3 border rounded-lg focus:ring-red-500 focus:border-red-500 outline-none" required></textarea>
                    
                    <div class="md:col-span-2 flex items-center gap-2 bg-gray-50 p-3 rounded-lg border border-gray-200">
                        <input type="checkbox" id="def_addr" name="is_default" value="1" class="w-4 h-4 text-red-600 focus:ring-red-500 rounded cursor-pointer">
                        <label for="def_addr" class="text-sm text-gray-700 font-medium cursor-pointer select-none">Jadikan sebagai alamat utama</label>
                    </div>
                    
                    <div class="md:col-span-2 flex gap-3 mt-2 pt-4 border-t border-gray-100">
                         <button type="submit" class="bg-red-700 text-white font-bold py-2.5 px-6 rounded-lg hover:bg-red-800 shadow-md">Simpan Alamat</button>
                         <button type="button" onclick="toggleAddAddressForm()" class="text-gray-500 font-bold py-2.5 px-4 hover:bg-gray-100 rounded-lg transition">Batal</button>
                    </div>
                </form>
            </div>

            <div class="grid gap-4">
                <?php foreach ($addresses as $addr): ?>
                <div class="p-6 rounded-xl border flex flex-col md:flex-row justify-between items-start gap-4 transition-all <?= $addr['is_default'] ? 'border-red-400 bg-red-50/30 ring-1 ring-red-100' : 'border-gray-200 bg-white hover:border-gray-300' ?>">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="font-bold text-gray-900 text-lg"><?= htmlspecialchars($addr['full_name']) ?></span>
                            <?php if ($addr['is_default']): ?>
                                <span class="px-2 py-0.5 bg-red-100 text-red-700 text-[10px] font-extrabold uppercase rounded tracking-wide">Utama</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-500 mb-2 font-mono"><?= htmlspecialchars($addr['phone_number']) ?></p>
                        <p class="text-sm text-gray-700 leading-relaxed">
                            <?= htmlspecialchars($addr['address_line_1']) ?><br>
                            <?= htmlspecialchars($addr['subdistrict']) ?>, <?= htmlspecialchars($addr['city']) ?> <?= htmlspecialchars($addr['postal_code']) ?><br>
                            <?= htmlspecialchars($addr['province']) ?>
                        </p>
                    </div>
                    <div class="flex flex-row md:flex-col gap-2 w-full md:w-auto pt-4 md:pt-0 border-t md:border-t-0 border-gray-100">
                         <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus alamat ini?')" class="flex-1">
                            <input type="hidden" name="action" value="delete_address">
                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                            <button class="w-full px-4 py-2 text-xs font-bold text-red-600 bg-white border border-red-200 rounded-lg hover:bg-red-50 transition"><i class="fas fa-trash mr-1"></i> Hapus</button>
                        </form>
                        <?php if (!$addr['is_default']): ?>
                        <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" class="flex-1">
                            <input type="hidden" name="action" value="set_default_address">
                            <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                            <button class="w-full px-4 py-2 text-xs font-bold text-green-700 bg-white border border-green-200 rounded-lg hover:bg-green-50 transition"><i class="fas fa-check mr-1"></i> Set Utama</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

        <!-- === TAB: NOTIFICATIONS === -->
        <?php elseif ($active_tab === 'notifications'): ?>
             <div class="flex justify-between items-center mb-6">
                 <h2 class="text-xl font-bold text-gray-900">Notifikasi</h2>
                 <?php if (!empty($notifications) && array_filter($notifications, fn($n) => !$n['is_read'])): ?>
                    <form method="POST" action="<?= BASE_URL ?>/notification/mark_all_read.php">
                        <button class="text-xs font-bold text-red-600 hover:text-red-800 bg-red-50 px-3 py-1 rounded-full transition">Tandai semua dibaca</button>
                    </form>
                 <?php endif; ?>
            </div>
            <ul class="border border-gray-200 rounded-xl overflow-hidden shadow-sm bg-white">
                <?php foreach ($notifications as $notif): ?>
                <li class="p-5 border-b border-gray-100 last:border-0 flex gap-4 hover:bg-gray-50 transition <?= !$notif['is_read'] ? 'bg-red-50/50' : 'bg-white' ?>">
                    <div class="mt-1">
                        <div class="w-2 h-2 rounded-full <?= !$notif['is_read'] ? 'bg-red-600' : 'bg-gray-300' ?>"></div>
                    </div>
                    <div>
                        <p class="text-sm text-gray-800 <?= !$notif['is_read'] ? 'font-bold' : '' ?>"><?= htmlspecialchars($notif['message']) ?></p>
                        <p class="text-xs text-gray-400 mt-1"><?= (new DateTime($notif['created_at']))->format('d M Y, H:i') ?></p>
                    </div>
                </li>
                <?php endforeach; ?>
                <?php if(empty($notifications)): ?>
                    <li class="p-10 text-center text-gray-500">
                        <i class="far fa-bell-slash text-2xl mb-2 block text-gray-300"></i>
                        Tidak ada notifikasi baru.
                    </li>
                <?php endif; ?>
            </ul>

        <!-- === TAB: SETTINGS === -->
        <?php elseif ($active_tab === 'settings'): ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <div class="bg-white p-6 rounded-xl border border-gray-200 shadow-sm">
                        <h2 class="text-xl font-bold text-gray-900 mb-6 pb-4 border-b border-gray-100">Pengaturan Akun</h2>
                        <form action="<?= BASE_URL ?>/profile/process_actions.php" method="POST" class="space-y-6">
                            <input type="hidden" name="action" value="update_profile">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Nama Lengkap</label>
                                    <input type="text" name="name" value="<?= htmlspecialchars($user_data['name']) ?>" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500 transition outline-none" required>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-gray-500 uppercase mb-2">Email (Tidak dapat diubah)</label>
                                    <input type="email" value="<?= htmlspecialchars($user_data['email']) ?>" class="w-full p-3 border border-gray-200 rounded-lg bg-gray-100 text-gray-500 cursor-not-allowed" readonly>
                                </div>
                            </div>
                            
                            <div class="pt-6 border-t border-gray-100">
                                <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="fas fa-lock text-red-700"></i> Ganti Password
                                </h3>
                                <div class="space-y-4">
                                    <input type="password" name="current_password" placeholder="Password Lama" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500 outline-none transition">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <input type="password" name="new_password" placeholder="Password Baru (Min. 6 karakter)" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500 outline-none transition">
                                        <input type="password" name="confirm_password" placeholder="Ulangi Password Baru" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-red-500 focus:border-red-500 outline-none transition">
                                    </div>
                                </div>
                                <p class="text-xs text-gray-400 mt-2">* Kosongkan jika tidak ingin mengubah password.</p>
                            </div>
                            
                            <div class="flex justify-end pt-4">
                                <button type="submit" class="bg-gray-900 text-white font-bold py-3 px-8 rounded-lg hover:bg-black transition shadow-lg transform hover:-translate-y-0.5">Simpan Perubahan</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Side Panel Info -->
                <div class="lg:col-span-1">
                    <div class="bg-gradient-to-br from-red-700 to-red-800 rounded-xl p-6 text-white shadow-lg mb-4">
                        <h3 class="font-bold text-lg mb-2">Keamanan Akun</h3>
                        <p class="text-red-100 text-sm mb-4">Pastikan password Anda kuat dan tidak dibagikan kepada siapapun. Kami menjaga privasi data Anda.</p>
                        <div class="flex items-center gap-2 text-xs bg-white/10 p-2 rounded">
                            <i class="fas fa-shield-alt"></i> Enkripsi Database Aktif
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php footer($conn); ?>

<!-- 5. JAVASCRIPT LOGIC (IQ 180 DIAGNOSTICS & PAYMENT HANDLER) -->
<script>
// --- UTILITY FUNCTIONS ---
function injectFlashMessage(type, message) {
    const color = type === 'success' ? 'bg-green-500' : (type === 'error' ? 'bg-red-500' : 'bg-blue-500');
    const icon = type === 'success' ? 'fa-check' : (type === 'error' ? 'fa-times' : 'fa-info');
    
    // Hapus toast lama jika ada
    const existing = document.getElementById('flash-toast');
    if(existing) existing.remove();

    const html = `
    <div id="flash-toast" class="fixed top-24 right-5 z-50 p-4 rounded-lg shadow-2xl ${color} text-white flex items-center gap-4 min-w-[300px] transform transition-all duration-500 translate-x-full opacity-0 border border-white/20 backdrop-blur-sm">
        <div class="bg-white/20 rounded-full w-8 h-8 flex items-center justify-center flex-shrink-0">
            <i class="fas ${icon}"></i>
        </div>
        <span class="font-medium text-sm">${message}</span>
    </div>`;
    
    document.getElementById('flash-message-container').insertAdjacentHTML('beforeend', html);
    const el = document.getElementById('flash-toast');
    
    // Trigger animation
    requestAnimationFrame(() => { el.classList.remove('translate-x-full', 'opacity-0'); });
    
    // Auto dismiss
    setTimeout(() => { 
        if(el) {
            el.classList.add('translate-x-full', 'opacity-0'); 
            setTimeout(() => el.remove(), 500);
        }
    }, 5000);
}

// Fungsi menampilkan error modal yang lebih informatif
function showBackendError(message) {
    const cleanMsg = message.replace(/<[^>]+>/g, '').trim(); 
    const modalMsg = document.getElementById('error-modal-message');
    
    // Deteksi Spesifik Error "Unknown Column"
    if (cleanMsg.includes("Unknown column") && cleanMsg.includes("midtrans_order_id")) {
        modalMsg.innerHTML = `<span class="text-red-600 font-bold block mb-2">CRITICAL DATABASE MISMATCH DETECTED (IQ 180 Analysis):</span>` +
                             `Sistem mendeteksi bahwa kode backend Anda (file: <code>checkout/get_snap_token.php</code>) mencoba mengakses kolom <code>midtrans_order_id</code> yang TIDAK ADA di tabel database <code>orders</code>.\n\n` +
                             `<strong>SOLUSI:</strong>\n` +
                             `Mohon periksa file <code>get_snap_token.php</code> dan ubah referensi <code>midtrans_order_id</code> menjadi <code>midtrans_transaction_id</code> atau hapus query yang menggunakan kolom tersebut.`;
    } else {
        modalMsg.textContent = cleanMsg || "Terjadi kesalahan server yang tidak diketahui.";
    }
    
    document.getElementById('error-modal').classList.remove('hidden');
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        injectFlashMessage('success', 'Nomor Resi berhasil disalin!');
    }).catch(() => {
        injectFlashMessage('error', 'Gagal menyalin resi.');
    });
}

function toggleAddAddressForm() {
    const form = document.getElementById('add-address-form');
    if (form.classList.contains('hidden')) {
        form.classList.remove('hidden');
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        form.classList.add('hidden');
    }
}

// --- MIDTRANS & ORDER HANDLING LOGIC ---
document.addEventListener('DOMContentLoaded', function() {
    const loadingOverlay = document.getElementById('loading-overlay');
    const loadingText = document.getElementById('loading-text');
    
    // Fungsi Update UI setelah pembayaran sukses tanpa reload
    function updateOrderUI(orderId) {
        const badge = document.getElementById(`order-status-${orderId}`);
        const controls = document.getElementById(`order-controls-${orderId}`);
        
        if(badge) {
            badge.className = 'px-3 py-1 rounded-full text-xs font-bold border ring-1 ring-inset bg-teal-50 text-teal-700 border-teal-200 ring-teal-100';
            badge.textContent = 'Diproses'; 
            badge.classList.add('animate-pulse'); // Visual feedback
        }
        
        if(controls) {
            controls.innerHTML = `
                <div class="flex items-center gap-2 text-green-600 bg-green-50 px-4 py-2 rounded-lg border border-green-100 w-full justify-center">
                    <i class="fas fa-check-circle text-lg"></i>
                    <span class="font-bold">Pembayaran Berhasil Diverifikasi</span>
                </div>`;
        }
    }

    // Polling Status Otomatis (Smart Polling)
    window.toggleExtraItems = function(orderId) {
        const extraItems = document.querySelectorAll('.extra-item-' + orderId);
        const btn = document.getElementById('toggle-btn-' + orderId);
        if (!extraItems.length || !btn) return;
        const isHidden = extraItems[0].classList.contains('hidden');
        if (isHidden) {
            extraItems.forEach(el => el.classList.remove('hidden'));
            btn.innerHTML = 'Sembunyikan produk';
        } else {
            extraItems.forEach(el => el.classList.add('hidden'));
            btn.innerHTML = '+ ' + extraItems.length + ' produk lainnya';
        }
    };

    function pollStatus(orderId) {
        loadingText.textContent = 'Memverifikasi status pembayaran...';
        loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
        
        let attempts = 0;
        const maxAttempts = 15; // 30 detik timeout
        
        const interval = setInterval(async () => {
            attempts++;
            try {
                const fd = new FormData(); fd.append('order_id', orderId);
                // Cache busting untuk mencegah browser caching response lama
                const res = await fetch('<?= BASE_URL ?>/checkout/check_payment_status.php?_t='+Date.now(), { method:'POST', body:fd });
                const json = await res.json();
                
                if (json.success && (json.order_status === 'belum_dicetak' || json.order_status === 'processed')) {
                    clearInterval(interval);
                    updateOrderUI(orderId);
                    loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                    injectFlashMessage('success', 'Pembayaran Dikonfirmasi! Pesanan sedang diproses.');
                    
                    // Optional: Reload page after delay
                    setTimeout(() => location.reload(), 2000);
                } else if (attempts >= maxAttempts) {
                    clearInterval(interval);
                    loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                    injectFlashMessage('info', 'Pembayaran diterima. Mohon refresh halaman jika status belum berubah.');
                }
            } catch(e) {
                // Silent fail pada polling network error
            }
        }, 2000);
    }

    // Event Listener untuk Tombol Bayar
    document.querySelectorAll('.pay-now-button').forEach(btn => {
        btn.addEventListener('click', async function() {
            // [FIX] Cegah double-click yang menyebabkan duplicate entry
            if (this.dataset.processing === 'true') return;
            this.dataset.processing = 'true';
            this.disabled = true;
            const originalHtml = this.innerHTML;
            this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Memproses...';

            const orderId = this.dataset.orderId;
            const buttonRef = this; // Simpan referensi tombol
            
            // UI Feedback
            loadingText.textContent = 'Menghubungi Gateway Pembayaran...';
            loadingOverlay.classList.remove('opacity-0', 'pointer-events-none');
            
            try {
                const fd = new FormData(); 
                fd.append('order_id', orderId);
                
                // Request Token Snap
                const response = await fetch('<?= BASE_URL ?>/checkout/get_snap_token.php', { 
                    method:'POST', 
                    body:fd 
                });
                
                // [IQ 180 DEBUGGING] Membaca response sebagai text dulu untuk menangkap Error PHP Fatal
                const responseText = await response.text();
                
                let json;
                try {
                    json = JSON.parse(responseText);
                } catch (e) {
                    // Jika parsing gagal, berarti response adalah HTML Error (seperti Unknown Column)
                    throw new Error("CRITICAL_BACKEND_ERROR: " + responseText);
                }
                
                if (json.success) {
                    // [FIX] Cek apakah Midtrans Snap SDK sudah dimuat
                    // Saat traffic tinggi, CDN Midtrans bisa lambat/timeout
                    if (typeof window.snap === 'undefined' || typeof window.snap.pay !== 'function') {
                        // Tunggu sampai script selesai dimuat (max 10 detik)
                        let waitAttempts = 0;
                        const maxWait = 20; // 20 x 500ms = 10 detik
                        await new Promise((resolve, reject) => {
                            const checkInterval = setInterval(() => {
                                waitAttempts++;
                                if (typeof window.snap !== 'undefined' && typeof window.snap.pay === 'function') {
                                    clearInterval(checkInterval);
                                    resolve();
                                } else if (waitAttempts >= maxWait) {
                                    clearInterval(checkInterval);
                                    reject(new Error('Midtrans payment SDK gagal dimuat. Periksa koneksi internet Anda dan coba refresh halaman.'));
                                }
                            }, 500);
                        });
                    }
                    
                    // Trigger Snap Popup (sekarang aman karena window.snap pasti ada)
                    window.snap.pay(json.snap_token, {
                        onSuccess: function(result) {
                            console.log("Payment Success", result);
                            pollStatus(json.db_order_id || orderId);
                        },
                        onPending: function(result) {
                            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                            injectFlashMessage('info', 'Menunggu pembayaran diselesaikan...'); 
                            console.log("Payment Pending", result);
                            // Re-enable tombol bayar
                            buttonRef.dataset.processing = 'false';
                            buttonRef.disabled = false;
                            buttonRef.innerHTML = originalHtml;
                        },
                        onError: function(result) {
                            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                            injectFlashMessage('error', 'Pembayaran gagal atau dibatalkan.');
                            console.error("Payment Error", result);
                            // Re-enable tombol bayar
                            buttonRef.dataset.processing = 'false';
                            buttonRef.disabled = false;
                            buttonRef.innerHTML = originalHtml;
                        },
                        onClose: function() {
                            loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                            console.log('Customer closed the popup without finishing the payment');
                            // Re-enable tombol bayar
                            buttonRef.dataset.processing = 'false';
                            buttonRef.disabled = false;
                            buttonRef.innerHTML = originalHtml;
                        }
                    });
                } else {
                    // Handle logic error dari backend (JSON valid tapi success: false)
                    throw new Error(json.message || "Gagal mendapatkan token pembayaran.");
                }
            } catch (e) {
                loadingOverlay.classList.add('opacity-0', 'pointer-events-none');
                console.error("Payment Flow Error:", e);
                
                if (e.message.includes('CRITICAL_BACKEND_ERROR')) {
                    // Tampilkan modal error "IQ 180"
                    showBackendError(e.message.replace('CRITICAL_BACKEND_ERROR: ', ''));
                } else {
                    injectFlashMessage('error', e.message);
                }
                
                // Re-enable tombol bayar saat error
                buttonRef.dataset.processing = 'false';
                buttonRef.disabled = false;
                buttonRef.innerHTML = originalHtml;
            }
        });
    });
});
</script>
</body>
</html>
