<?php
// File: admin/admin.php
include '../config/config.php';
include '../sistem/sistem.php';
check_admin();

// =================================================================
// --- PUSAT LOGIKA PEMROSESAN FORM (CRUD) ---
// =================================================================

// Direktori untuk upload gambar
define('UPLOAD_DIR_PRODUK', '../assets/images/produk/');
define('UPLOAD_DIR_BANNER', '../assets/images/banner/');
define('UPLOAD_DIR_SETTINGS', '../assets/images/settings/');

if (!is_dir(UPLOAD_DIR_PRODUK)) mkdir(UPLOAD_DIR_PRODUK, 0777, true);
if (!is_dir(UPLOAD_DIR_BANNER)) mkdir(UPLOAD_DIR_BANNER, 0777, true);
if (!is_dir(UPLOAD_DIR_SETTINGS)) mkdir(UPLOAD_DIR_SETTINGS, 0777, true);

// Fungsi helper untuk notifikasi (jika belum ada)
function send_notification($conn, $user_id, $message) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}


// --- LOGIKA UPDATE STATUS PESANAN ---
if (isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = sanitize_input($_POST['status']);
    $redirect_url = $_POST['current_page'] ?? '?page=pesanan';

    // PERBAIKAN: Menghapus duplikasi BASE_URL dari URL redirect
    if (defined('BASE_URL') && strpos($redirect_url, BASE_URL) === 0) {
        $redirect_url = substr($redirect_url, strlen(BASE_URL));
    }

    // Ambil status saat ini dan user_id dari pesanan
    $stmt_current = $conn->prepare("SELECT status, user_id FROM orders WHERE id = ?");
    $stmt_current->bind_param("i", $order_id);
    $stmt_current->execute();
    $result_current = $stmt_current->get_result();
    
    if ($result_current->num_rows > 0) {
        $order_data = $result_current->fetch_assoc();
        $current_status = $order_data['status'];
        $user_id = $order_data['user_id'];
        
        // --- LOGIKA BARU: Intersep perubahan status ---
        // Jika admin menyetujui pembayaran (mengubah dari 'waiting_approval' ke 'processed'),
        // maka status sebenarnya diubah menjadi 'belum_dicetak'.
        if ($current_status == 'waiting_approval' && $new_status == 'processed') {
            $final_status = 'belum_dicetak';
            $notification_message = "Pembayaran untuk pesanan #WK{$order_id} telah disetujui dan pesanan Anda sedang disiapkan.";
            
            // Notifikasi untuk client statusnya 'disetujui'
            send_notification($conn, $user_id, $notification_message);

        } else {
            $final_status = $new_status;
            // Kirim notifikasi umum jika status berubah
            if ($final_status != $current_status) {
                $notification_message = "Status pesanan #WK{$order_id} Anda telah diperbarui menjadi: " . ucfirst(str_replace('_', ' ', $final_status));
                send_notification($conn, $user_id, $notification_message);
            }
        }

        $stmt_update = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt_update->bind_param("si", $final_status, $order_id);
        
        if ($stmt_update->execute()) {
            set_flash_message('success', 'Status pesanan berhasil diperbarui.');
        } else {
            set_flash_message('error', 'Gagal memperbarui status pesanan.');
        }
        $stmt_update->close();

    } else {
        set_flash_message('error', 'Pesanan tidak ditemukan.');
    }

    redirect($redirect_url);
}


// --- FUNGSI HELPER UNTUK UPDATE PENGATURAN ---
function update_setting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    return $stmt->execute();
}

// --- LOGIKA SIMPAN PENGATURAN TOKO ---
if (isset($_POST['save_settings'])) {
    $settings_to_update = [
        'store_description' => sanitize_input($_POST['store_description']),
        'facebook_url' => sanitize_input($_POST['facebook_url']),
        'instagram_url' => sanitize_input($_POST['instagram_url'])
    ];

    foreach ($settings_to_update as $key => $value) {
        update_setting($conn, $key, $value);
    }

    // Handle upload logo
    if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] == 0) {
        // Hapus logo lama
        $old_logo = get_setting('store_logo');
        if ($old_logo && file_exists(UPLOAD_DIR_SETTINGS . $old_logo)) {
            unlink(UPLOAD_DIR_SETTINGS . $old_logo);
        }

        $logo_name = 'logo_' . time() . '_' . basename($_FILES["store_logo"]["name"]);
        $target_file = UPLOAD_DIR_SETTINGS . $logo_name;
        if (move_uploaded_file($_FILES["store_logo"]["tmp_name"], $target_file)) {
            update_setting($conn, 'store_logo', $logo_name);
        }
    }

    set_flash_message('success', 'Pengaturan toko berhasil disimpan.');
    redirect('/admin/admin.php?page=pengaturan');
}


// --- CRUD KATEGORI ---
if (isset($_POST['save_kategori'])) {
    $id = sanitize_input($_POST['id']);
    $name = sanitize_input($_POST['name']);
    
    if (empty($id)) {
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
    } else {
        $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $name, $id);
    }

    if ($stmt->execute()) {
        set_flash_message('success', 'Kategori berhasil disimpan.');
    } else {
        set_flash_message('error', 'Gagal menyimpan kategori.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=kategori');
}
if (isset($_POST['delete_kategori'])) {
    $id = sanitize_input($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        set_flash_message('success', 'Kategori berhasil dihapus.');
    } else {
        set_flash_message('error', 'Gagal menghapus kategori.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=kategori');
}

// Sisa kode CRUD lainnya (produk, user, banner, dll) tetap sama
// ...

// --- Navigasi Halaman Admin ---
$page = $_GET['page'] ?? 'dashboard';
$main_page = in_array($page, ['user', 'pengaturan']) ? 'users_settings' : $page;

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-gray-800 text-white flex flex-col fixed h-full">
            <div class="p-4 text-2xl font-bold border-b border-gray-700">Admin Panel</div>
            <nav class="flex-1 p-2 space-y-1">
                <a href="?page=dashboard" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'dashboard' ? 'bg-indigo-600' : '' ?>">Dashboard</a>
                <a href="?page=pesanan" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'pesanan' ? 'bg-indigo-600' : '' ?>">Pesanan</a>
                <a href="?page=produk" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'produk' ? 'bg-indigo-600' : '' ?>">Produk</a>
                <a href="?page=kategori" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'kategori' ? 'bg-indigo-600' : '' ?>">Kategori</a>
                <a href="?page=banner" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'banner' ? 'bg-indigo-600' : '' ?>">Banner</a>
                <a href="?page=pembayaran" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $main_page == 'pembayaran' ? 'bg-indigo-600' : '' ?>">Pembayaran</a>
                
                <details class="group" <?= $main_page == 'users_settings' ? 'open' : '' ?>>
                    <summary class="flex items-center justify-between px-4 py-2 rounded-md hover:bg-gray-700 cursor-pointer list-none">
                        <span>Pengguna & Pengaturan</span>
                        <svg class="w-4 h-4 transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                    </summary>
                    <div class="pl-4 mt-1 space-y-1">
                        <a href="?page=user" class="block px-4 py-2 rounded-md hover:bg-gray-700 text-sm <?= $page == 'user' ? 'bg-indigo-500' : '' ?>">Manajemen Pengguna</a>
                        <a href="?page=pengaturan" class="block px-4 py-2 rounded-md hover:bg-gray-700 text-sm <?= $page == 'pengaturan' ? 'bg-indigo-500' : '' ?>">Pengaturan Toko</a>
                    </div>
                </details>

            </nav>
            <div class="p-4 border-t border-gray-700">
                <a href="<?= BASE_URL ?>/" class="block text-center w-full px-4 py-2 rounded-md bg-indigo-600 hover:bg-indigo-700">Kembali ke Toko</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-8 overflow-y-auto ml-64">
             <h1 class="text-3xl font-bold text-gray-800 mb-6">
                <?php 
                    $page_title_map = [
                        'dashboard' => 'Dashboard',
                        'pesanan' => 'Manajemen Pesanan',
                        'produk' => 'Manajemen Produk',
                        'kategori' => 'Manajemen Kategori',
                        'user' => 'Manajemen Pengguna',
                        'banner' => 'Manajemen Banner',
                        'pembayaran' => 'Metode Pembayaran',
                        'pengaturan' => 'Pengaturan Toko'
                    ];
                    echo $page_title_map[$page] ?? 'Halaman Tidak Ditemukan';
                ?>
            </h1>

            <!-- Konten dinamis -->
            <?php
                $allowed_pages = ['dashboard', 'pesanan', 'produk', 'kategori', 'user', 'banner', 'pembayaran', 'pengaturan'];
                if (in_array($page, $allowed_pages)) {
                    $include_file = ($page === 'dashboard') ? 'dashboard.php' : $page . '/' . $page . '.php';
                    if (file_exists($include_file)) {
                        include $include_file;
                    } else {
                        echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg">File untuk halaman <strong>' . htmlspecialchars($page) . '</strong> tidak ditemukan.</div>';
                    }
                } else {
                     echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg">Halaman yang Anda minta tidak valid.</div>';
                }
            ?>
        </main>
    </div>
</body>
</html>