<?php
// File: profile/profile.php - Halaman Profil Pengguna

// Pemuatan File Inti
require_once '../config/config.php'; 
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

// Cek Login: Pastikan user sudah login
check_login(); 

// Memuat pengaturan toko ke cache
load_settings($conn);

// Judul halaman
$store_name = get_setting($conn, 'store_name') ?? 'Marketplace';

// Ambil ID pengguna dari session
$user_id = $_SESSION['user_id'] ?? 0;

// =========================================================
// LOGIKA UPDATE PROFIL & ALAMAT
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Logika Update Nama Pengguna
    if (isset($_POST['update_profile'])) {
        $name = sanitize_input($_POST['name']);
        if (!empty($name) && $user_id > 0) {
            $stmt = $conn->prepare("UPDATE users SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $user_id);
            if ($stmt->execute()) {
                $_SESSION['user_name'] = $name; // Update session juga
                set_flashdata('success', 'Nama berhasil diperbarui.');
            } else {
                set_flashdata('error', 'Gagal memperbarui nama.');
            }
            $stmt->close();
        }
        redirect('/profile/profile.php?tab=account');
    }
    
    // Logika Simpan Alamat (Tambah/Edit)
    if (isset($_POST['save_address'])) {
        $address_id = (int)($_POST['address_id'] ?? 0);
        $full_name = sanitize_input($_POST['full_name']);
        $phone_number = sanitize_input($_POST['phone_number']);
        $province = sanitize_input($_POST['province']);
        $city = sanitize_input($_POST['city']);
        $subdistrict = sanitize_input($_POST['subdistrict']);
        $postal_code = sanitize_input($_POST['postal_code']);
        $address_line_1 = sanitize_input($_POST['address_line_1']);
        $address_line_2 = sanitize_input($_POST['address_line_2'] ?? '');
        $is_default = isset($_POST['is_default']) ? 1 : 0;

        if ($address_id > 0) { // Proses Edit
            $stmt = $conn->prepare("UPDATE user_addresses SET full_name=?, phone_number=?, province=?, city=?, subdistrict=?, postal_code=?, address_line_1=?, address_line_2=?, is_default=? WHERE id=? AND user_id=?");
            $stmt->bind_param("ssssssssiii", $full_name, $phone_number, $province, $city, $subdistrict, $postal_code, $address_line_1, $address_line_2, $is_default, $address_id, $user_id);
        } else { // Proses Tambah
            $stmt = $conn->prepare("INSERT INTO user_addresses (user_id, full_name, phone_number, province, city, subdistrict, postal_code, address_line_1, address_line_2, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssi", $user_id, $full_name, $phone_number, $province, $city, $subdistrict, $postal_code, $address_line_1, $address_line_2, $is_default);
        }

        if ($stmt->execute()) {
            if ($is_default == 1) {
                $new_address_id = ($address_id > 0) ? $address_id : $conn->insert_id;
                $stmt_default = $conn->prepare("UPDATE user_addresses SET is_default = 0 WHERE user_id = ? AND id != ?");
                $stmt_default->bind_param("ii", $user_id, $new_address_id);
                $stmt_default->execute();
                $stmt_default->close();
            }
            set_flashdata('success', 'Alamat berhasil disimpan.');
        } else {
            set_flashdata('error', 'Gagal menyimpan alamat.');
        }
        $stmt->close();
        redirect('/profile/profile.php?tab=addresses');
    }
}


// =========================================================
// PENGAMBILAN DATA UNTUK TAMPILAN
// =========================================================
$user_data = [];
$stmt_user = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
$stmt_user->bind_param("i", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();
if ($result_user) {
    $user_data = $result_user->fetch_assoc();
}
$stmt_user->close();
$user_name = $user_data['name'] ?? 'Guest'; 
$user_email = $user_data['email'] ?? '';   

$orders = [];
$stmt_orders = $conn->prepare("
    SELECT o.id, o.order_number, o.total, o.status, o.created_at, o.order_hash
    FROM orders o
    WHERE o.user_id = ? ORDER BY o.created_at DESC 
");
if ($user_id > 0) {
    $stmt_orders->bind_param("i", $user_id);
    $stmt_orders->execute();
    $result_orders = $stmt_orders->get_result();
    if ($result_orders) {
        while($row = $result_orders->fetch_assoc()) {
            $orders[] = $row;
        }
    }
    $stmt_orders->close();
}

$addresses = get_user_addresses($conn, $user_id);
$active_tab = $_GET['tab'] ?? 'orders';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil - <?= htmlspecialchars($store_name) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f7f9fb; }
        .tab-button.active { border-bottom-color: #4f46e5; color: #4f46e5; font-weight: 600; }
    </style>
</head>
<body>
    <?php navbar($conn); ?>

    <main class="container mx-auto px-4 py-12 min-h-screen max-w-5xl">
        <h1 class="text-3xl font-bold text-gray-800 mb-8">Halaman Saya</h1>
        
        <?php flash_message(); ?>

        <div class="border-b border-gray-200 mb-8">
            <nav class="flex space-x-4 -mb-px">
                <a href="?tab=orders" class="tab-button <?= $active_tab == 'orders' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-4 border-b-2 border-transparent transition"><i class="fas fa-receipt mr-2"></i>Pesanan Saya</a>
                <a href="?tab=addresses" class="tab-button <?= $active_tab == 'addresses' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-4 border-b-2 border-transparent transition"><i class="fas fa-map-marker-alt mr-2"></i>Alamat Saya</a>
                <a href="?tab=account" class="tab-button <?= $active_tab == 'account' ? 'active' : 'text-gray-500 hover:text-gray-700' ?> py-2 px-4 border-b-2 border-transparent transition"><i class="fas fa-user-circle mr-2"></i>Akun Saya</a>
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
                                    <span class="text-xs font-medium px-3 py-1 rounded-full 
                                        <?php 
                                            $status_classes = ['completed' => 'bg-green-100 text-green-800', 'shipped' => 'bg-blue-100 text-blue-800', 'processed' => 'bg-purple-100 text-purple-800', 'belum_dicetak' => 'bg-cyan-100 text-cyan-800', 'waiting_approval' => 'bg-yellow-100 text-yellow-800', 'waiting_payment' => 'bg-orange-100 text-orange-800', 'cancelled' => 'bg-red-100 text-red-800'];
                                            echo $status_classes[$order['status']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                    ">
                                        <?= ucwords(str_replace('_', ' ', $order['status'])) ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-3 mb-4">
                                <?php
                                    $order_items = [];
                                    $stmt_items = $conn->prepare("SELECT oi.quantity, p.name, p.image FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
                                    $stmt_items->bind_param("i", $order['id']);
                                    $stmt_items->execute();
                                    $result_items = $stmt_items->get_result();
                                    while ($item_row = $result_items->fetch_assoc()) {
                                        $order_items[] = $item_row;
                                    }
                                    $stmt_items->close();
                                    
                                    foreach ($order_items as $item):
                                ?>
                                    <div class="flex items-center gap-3">
                                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" class="w-12 h-12 rounded-md object-cover border">
                                        <div class="flex-grow">
                                            <p class="text-sm font-medium text-gray-800"><?= htmlspecialchars($item['name']) ?></p>
                                            <p class="text-xs text-gray-500">Jumlah: <?= $item['quantity'] ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                
                                <div class="flex flex-wrap justify-between items-center gap-3 pt-3 border-t">
                                    <div>
                                        <p class="text-xs text-gray-600">Total Belanja</p>
                                        <p class="text-base sm:text-lg font-bold text-indigo-700"><?= format_rupiah($order['total']) ?></p> 
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <?php if ($order['status'] == 'waiting_payment'): ?>
                                            <!-- ✅ PERBAIKAN: Menggunakan order_hash -->
                                            <a href="<?= BASE_URL ?>/checkout/upload.php?hash=<?= $order['order_hash'] ?>" class="text-sm text-white bg-green-600 hover:bg-green-700 px-3 py-1.5 rounded-md transition shadow">
                                                Upload Bukti Bayar
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= BASE_URL ?>/checkout/invoice.php?hash=<?= $order['order_hash'] ?>" class="text-sm text-indigo-600 border border-indigo-200 hover:bg-indigo-50 px-3 py-1.5 rounded-md transition">
                                            Lihat Invoice
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-10 bg-white rounded-xl shadow-lg"><p class="text-gray-500">Anda belum memiliki riwayat pesanan.</p></div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab == 'addresses'): ?>
                <div>
                    <div class="flex justify-between items-center mb-6">
                        <h2 class="text-2xl font-semibold text-indigo-600">Alamat Tersimpan</h2>
                        <a href="?tab=addresses&action=add" class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 transition"><i class="fas fa-plus mr-2"></i>Tambah Alamat</a>
                    </div>
                    <?php if (isset($_GET['action']) && in_array($_GET['action'], ['add', 'edit'])): 
                        $address_to_edit = null;
                        if ($_GET['action'] == 'edit' && isset($_GET['id'])) {
                            foreach($addresses as $addr) {
                                if ($addr['id'] == (int)$_GET['id']) {
                                    $address_to_edit = $addr;
                                    break;
                                }
                            }
                        }
                    ?>
                        <div class="bg-white p-8 rounded-xl shadow-lg mt-6">
                            <h3 class="text-xl font-semibold mb-4"><?= $address_to_edit ? 'Edit Alamat' : 'Tambah Alamat Baru' ?></h3>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="address_id" value="<?= $address_to_edit['id'] ?? 0 ?>">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div><label class="block text-sm font-medium text-gray-700">Nama Penerima</label><input type="text" name="full_name" required value="<?= htmlspecialchars($address_to_edit['full_name'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium text-gray-700">Nomor Telepon</label><input type="text" name="phone_number" required value="<?= htmlspecialchars($address_to_edit['phone_number'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium text-gray-700">Provinsi</label><input type="text" name="province" required value="<?= htmlspecialchars($address_to_edit['province'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium text-gray-700">Kota/Kabupaten</label><input type="text" name="city" required value="<?= htmlspecialchars($address_to_edit['city'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium text-gray-700">Kecamatan</label><input type="text" name="subdistrict" required value="<?= htmlspecialchars($address_to_edit['subdistrict'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                    <div><label class="block text-sm font-medium text-gray-700">Kode Pos</label><input type="text" name="postal_code" required value="<?= htmlspecialchars($address_to_edit['postal_code'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                </div>
                                <div><label class="block text-sm font-medium text-gray-700">Alamat Lengkap</label><textarea name="address_line_1" required class="mt-1 w-full p-2 border rounded-md"><?= htmlspecialchars($address_to_edit['address_line_1'] ?? '') ?></textarea></div>
                                <div><label class="block text-sm font-medium text-gray-700">Detail Tambahan (Opsional)</label><input type="text" name="address_line_2" value="<?= htmlspecialchars($address_to_edit['address_line_2'] ?? '') ?>" class="mt-1 w-full p-2 border rounded-md"></div>
                                <div><label class="flex items-center"><input type="checkbox" name="is_default" value="1" <?= ($address_to_edit['is_default'] ?? 0) ? 'checked' : '' ?> class="h-4 w-4 text-indigo-600 border-gray-300 rounded"> <span class="ml-2 text-sm">Jadikan Alamat Utama</span></label></div>
                                <div class="flex gap-4 pt-4 border-t"><button type="submit" name="save_address" class="px-4 py-2 bg-indigo-600 text-white rounded-md">Simpan Alamat</button><a href="?tab=addresses" class="text-gray-600 py-2">Batal</a></div>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                        <?php if (!empty($addresses)): ?>
                            <?php foreach($addresses as $address): ?>
                                <div class="bg-white p-4 rounded-lg shadow-md flex justify-between items-start">
                                    <div>
                                        <p class="font-bold"><?= htmlspecialchars($address['full_name']) ?> <?= $address['is_default'] ? '<span class="text-xs bg-green-200 text-green-800 px-2 py-1 rounded-full ml-2">Utama</span>' : '' ?></p>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($address['phone_number']) ?></p>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($address['address_line_1']) ?>, <?= htmlspecialchars($address['subdistrict']) ?>, <?= htmlspecialchars($address['city']) ?></p>
                                    </div>
                                    <a href="?tab=addresses&action=edit&id=<?= $address['id'] ?>" class="text-sm text-indigo-600 hover:underline">Ubah</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-center text-gray-500 py-8 bg-white rounded-lg shadow-md">Anda belum memiliki alamat tersimpan.</p>
                        <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($active_tab == 'account'): ?>
                <div class="bg-white p-8 rounded-xl shadow-lg max-w-lg mx-auto">
                    <h2 class="text-2xl font-semibold text-indigo-600 mb-6 border-b pb-2">Informasi Akun</h2>
                    <form method="POST" class="space-y-4">
                        <div><label class="block text-sm font-medium text-gray-700">Nama Lengkap</label><input type="text" name="name" value="<?= htmlspecialchars($user_name) ?>" required class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2"></div>
                        <div><label class="block text-sm font-medium text-gray-700">Email</label><input type="email" value="<?= htmlspecialchars($user_email) ?>" disabled class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm p-2 bg-gray-100"></div>
                        <div class="flex items-center gap-4 pt-4 border-t"><button type="submit" name="update_profile" class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700">Simpan Perubahan</button><a href="<?= BASE_URL ?>/login/logout.php" class="text-gray-600 py-2">Keluar</a></div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <?php footer($conn); ?>
</body>
</html>