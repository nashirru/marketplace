<?php
// File: admin/admin.php
require_once '../config/config.php';
require_once '../sistem/sistem.php';
check_admin();

load_settings($conn); 

// Direktori untuk upload gambar
define('UPLOAD_DIR_PRODUK', '../assets/images/produk/');
define('UPLOAD_DIR_BANNER', '../assets/images/banner/');
define('UPLOAD_DIR_SETTINGS', '../assets/images/settings/');
define('UPLOAD_DIR_PAYMENT', '../assets/images/payment/');

// Pastikan direktori ada
if (!is_dir(UPLOAD_DIR_PRODUK)) mkdir(UPLOAD_DIR_PRODUK, 0777, true);
if (!is_dir(UPLOAD_DIR_BANNER)) mkdir(UPLOAD_DIR_BANNER, 0777, true);
if (!is_dir(UPLOAD_DIR_SETTINGS)) mkdir(UPLOAD_DIR_SETTINGS, 0777, true);
if (!is_dir(UPLOAD_DIR_PAYMENT)) mkdir(UPLOAD_DIR_PAYMENT, 0777, true);

// Fungsi helper untuk notifikasi
function send_notification($conn, $user_id, $message) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $message);
    $stmt->execute();
    $stmt->close();
}

/**
 * Fungsi untuk mengupload file gambar.
 */
function upload_image_file($file_data, $upload_dir) {
    if ($file_data['error'] === UPLOAD_ERR_OK) {
        $file_tmp = $file_data['tmp_name'];
        $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (in_array($file_ext, $allowed_ext)) {
            $new_file_name = uniqid() . '-' . time() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_file_name;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                return $new_file_name;
            }
        }
    }
    return false;
}

/**
 * Menyimpan atau memperbarui pasangan key-value di tabel settings.
 */
function update_or_insert_setting($conn, $key, $value) {
    $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->bind_param("sss", $key, $value, $value);
    
    $result = $stmt->execute(); 
    $stmt->close();
    
    return $result; 
}

// ===============================================
// --- LOGIKA PEMROSESAN AKSI ---
// ===============================================

$redirect_url = BASE_URL . '/admin/admin.php?page=pesanan';
if (isset($_POST['active_status_filter']) && !empty($_POST['active_status_filter'])) {
    $redirect_url .= '&status=' . urlencode($_POST['active_status_filter']);
}

// --- LOGIKA AKSI PESANAN (Setujui, Tolak, Update Status, Aksi Massal) ---
if (isset($_POST['action'])) {
    $order_ids = [];
    // Aksi massal
    if (isset($_POST['selected_orders']) && is_array($_POST['selected_orders'])) {
        $order_ids = array_map('intval', $_POST['selected_orders']);
    } 
    // Aksi individual
    elseif (isset($_POST['order_id'])) {
        $order_ids[] = (int)$_POST['order_id'];
    }

    if (!empty($order_ids)) {
        $action = $_POST['action'];
        $new_status = '';
        $message = '';

        switch ($action) {
            case 'approve_payment':
                $new_status = 'belum_dicetak';
                $message = 'Pembayaran disetujui.';
                break;
            case 'reject_payment':
                $new_status = 'waiting_payment';
                $message = 'Pembayaran ditolak. Mohon upload ulang bukti yang benar.';
                break;
            case 'process_order':
                $new_status = 'processed';
                $message = 'Pesanan Anda sedang diproses.';
                break;
            case 'ship_order':
                $new_status = 'shipped';
                $message = 'Pesanan Anda telah dikirim.';
                break;
            case 'complete_order':
                $new_status = 'completed';
                $message = 'Pesanan Anda telah selesai.';
                break;
        }

        if (!empty($new_status)) {
            $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
            $types = str_repeat('i', count($order_ids));
            
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id IN ($placeholders)");
            $stmt->bind_param("s" . $types, $new_status, ...$order_ids);

            if ($stmt->execute()) {
                set_flashdata('success', count($order_ids) . ' pesanan berhasil diperbarui.');
                // Kirim notifikasi ke setiap user
                $stmt_user = $conn->prepare("SELECT user_id, order_number FROM orders WHERE id = ?");
                foreach ($order_ids as $order_id) {
                    $stmt_user->bind_param("i", $order_id);
                    $stmt_user->execute();
                    $order = $stmt_user->get_result()->fetch_assoc();
                    if ($order) {
                        send_notification($conn, $order['user_id'], "Status pesanan #{$order['order_number']} diperbarui: " . $message);
                    }
                }
                $stmt_user->close();
            } else {
                set_flashdata('error', 'Gagal memperbarui pesanan.');
            }
            $stmt->close();
        }
    }
    redirect($redirect_url);
}

// --- LOGIKA SIMPAN PENGATURAN TOKO ---
if (isset($_POST['save_settings'])) {
    $success = true;
    
    // 1. Ambil dan bersihkan data teks
    $store_name = sanitize_input($_POST['store_name'] ?? '');
    $store_description = sanitize_input($_POST['store_description'] ?? '');
    $store_address = sanitize_input($_POST['store_address'] ?? '');
    $store_phone = sanitize_input($_POST['store_phone'] ?? '');
    $store_email = sanitize_input($_POST['store_email'] ?? '');
    $store_facebook = sanitize_input($_POST['store_facebook'] ?? '');
    $store_tiktok = sanitize_input($_POST['store_tiktok'] ?? ''); 
    
    // 2. Update data teks
    if (!update_or_insert_setting($conn, 'store_name', $store_name)) $success = false;
    if (!update_or_insert_setting($conn, 'store_description', $store_description)) $success = false;
    if (!update_or_insert_setting($conn, 'store_address', $store_address)) $success = false;
    if (!update_or_insert_setting($conn, 'store_phone', $store_phone)) $success = false;
    if (!update_or_insert_setting($conn, 'store_email', $store_email)) $success = false;
    if (!update_or_insert_setting($conn, 'store_facebook', $store_facebook)) $success = false;
    if (!update_or_insert_setting($conn, 'store_tiktok', $store_tiktok)) $success = false; 
    
    // 3. Handle upload Logo
    if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded_name = upload_image_file($_FILES['store_logo'], UPLOAD_DIR_SETTINGS);

        if ($uploaded_name) {
            $old_logo = get_setting($conn, 'store_logo');
            if ($old_logo && file_exists(UPLOAD_DIR_SETTINGS . $old_logo)) {
                @unlink(UPLOAD_DIR_SETTINGS . $old_logo);
            }
            if (!update_or_insert_setting($conn, 'store_logo', $uploaded_name)) {
                 $success = false; 
            }
        } else {
            set_flashdata('error', 'Gagal mengupload logo. Pastikan format file benar (jpg, jpeg, png, gif).');
            redirect(BASE_URL . '/admin/admin.php?page=pengaturan_toko');
        }
    }
    
    load_settings($conn); 
    
    if ($success) {
        set_flashdata('success', 'Pengaturan Toko berhasil diperbarui.');
    } else {
        set_flashdata('error', 'Beberapa pengaturan gagal diperbarui. Cek koneksi database.');
    }
    
    redirect(BASE_URL . '/admin/admin.php?page=pengaturan_toko');
}


// --- LOGIKA MANAJEMEN BANNER (TAMBAH/EDIT) ---
if (isset($_POST['save_banner'])) {
    $banner_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $title = sanitize_input($_POST['title']);
    $link_url = sanitize_input($_POST['link_url'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($title)) {
        set_flashdata('error', 'Judul banner wajib diisi.');
        redirect(BASE_URL . '/admin/admin.php?page=banner&action=' . ($banner_id ? 'edit&id='.$banner_id : 'add'));
    }

    $image_name = null;
    $is_new_image_uploaded = false;

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded_name = upload_image_file($_FILES['image'], UPLOAD_DIR_BANNER);

        if ($uploaded_name) {
            $image_name = $uploaded_name;
            $is_new_image_uploaded = true;
        } else {
            set_flashdata('error', 'Gagal mengupload gambar banner.');
             redirect(BASE_URL . '/admin/admin.php?page=banner&action=' . ($banner_id ? 'edit&id='.$banner_id : 'add'));
        }
    }

    if ($banner_id > 0) { // EDIT BANNER
        $sql = "UPDATE banners SET title = ?, link_url = ?, is_active = ?";
        $types = "ssi";
        $params = [&$title, &$link_url, &$is_active];
        
        if ($is_new_image_uploaded) {
            // Hapus gambar lama
            $stmt_old = $conn->prepare("SELECT image FROM banners WHERE id = ?");
            $stmt_old->bind_param("i", $banner_id);
            $stmt_old->execute();
            $result_old = $stmt_old->get_result();
            if ($row = $result_old->fetch_assoc()) {
                if ($row['image'] && file_exists(UPLOAD_DIR_BANNER . $row['image'])) {
                    @unlink(UPLOAD_DIR_BANNER . $row['image']);
                }
            }
            $stmt_old->close();

            $sql .= ", image = ?";
            $types .= "s";
            $params[] = &$image_name;
        }

        $sql .= " WHERE id = ?";
        $types .= "i";
        $params[] = &$banner_id;
        
        $stmt = $conn->prepare($sql);
        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $params)); 

        if ($stmt->execute()) {
            set_flashdata('success', 'Banner berhasil diperbarui.');
        } else {
            set_flashdata('error', 'Gagal memperbarui banner: ' . $conn->error);
        }
        $stmt->close();

    } else { // TAMBAH BANNER BARU
        
        if (!$is_new_image_uploaded) {
             set_flashdata('error', 'Gagal menambahkan banner. Gambar wajib diisi.');
             redirect(BASE_URL . '/admin/admin.php?page=banner&action=add');
        }

        $sql = "INSERT INTO banners (title, image, link_url, is_active) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $title, $image_name, $link_url, $is_active);

        if ($stmt->execute()) {
            set_flashdata('success', 'Banner baru berhasil ditambahkan.');
        } else {
            if($image_name && file_exists(UPLOAD_DIR_BANNER . $image_name)) {
                @unlink(UPLOAD_DIR_BANNER . $image_name);
            }
            set_flashdata('error', 'Gagal menambahkan banner: ' . $conn->error);
        }
        $stmt->close();
    }
    
    redirect(BASE_URL . '/admin/admin.php?page=banner');
}

// --- LOGIKA HAPUS BANNER ---
if (isset($_POST['delete_banner'])) {
    $banner_id = (int)$_POST['id'];

    if ($banner_id > 0) {
        $stmt_img = $conn->prepare("SELECT image FROM banners WHERE id = ?");
        $stmt_img->bind_param("i", $banner_id);
        $stmt_img->execute();
        $row = $stmt_img->get_result()->fetch_assoc();
        $stmt_img->close();

        $stmt = $conn->prepare("DELETE FROM banners WHERE id = ?");
        $stmt->bind_param("i", $banner_id);

        if ($stmt->execute()) {
            if ($row && $row['image'] && file_exists(UPLOAD_DIR_BANNER . $row['image'])) {
                @unlink(UPLOAD_DIR_BANNER . $row['image']);
            }
            set_flashdata('success', 'Banner berhasil dihapus.');
        } else {
            set_flashdata('error', 'Gagal menghapus banner: ' . $conn->error);
        }
        $stmt->close();
    } else {
        set_flashdata('error', 'ID Banner tidak valid.');
    }
    
    redirect(BASE_URL . '/admin/admin.php?page=banner');
}

// --- LOGIKA MANAJEMEN PRODUK (TAMBAH/EDIT) ---
if (isset($_POST['save_product'])) {
    $product_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = sanitize_input($_POST['name']);
    $description = sanitize_input($_POST['description']);
    $price = (float)$_POST['price'];
    $stock = (int)$_POST['stock'];
    $category_id = (int)$_POST['category_id'];
    $purchase_limit = isset($_POST['purchase_limit_active']) ? (int)$_POST['purchase_limit'] : null;

    if (empty($name) || empty($price) || empty($stock) || empty($category_id)) {
        set_flashdata('error', 'Semua field wajib diisi (kecuali gambar saat edit).');
        redirect(BASE_URL . '/admin/admin.php?page=produk&action=' . ($product_id ? 'edit&id='.$product_id : 'add'));
    }

    $image_name = null;
    $is_new_image_uploaded = false;

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded_name = upload_image_file($_FILES['image'], UPLOAD_DIR_PRODUK);
        if ($uploaded_name) {
            $image_name = $uploaded_name;
            $is_new_image_uploaded = true;
        } else {
            set_flashdata('error', 'Gagal mengupload gambar produk.');
            redirect(BASE_URL . '/admin/admin.php?page=produk&action=' . ($product_id ? 'edit&id='.$product_id : 'add'));
        }
    }

    $conn->begin_transaction();
    try {
        if ($product_id > 0) { // EDIT PRODUK
            // ✅ FITUR BARU: Logika reset limit saat restock
            $stmt_current = $conn->prepare("SELECT stock, image FROM products WHERE id = ?");
            $stmt_current->bind_param("i", $product_id);
            $stmt_current->execute();
            $current_product = $stmt_current->get_result()->fetch_assoc();
            $current_stock = $current_product['stock'];
            $stmt_current->close();
    
            // Jika stok baru lebih besar dari stok lama (restock)
            if ($stock > $current_stock) {
                $stmt_reset = $conn->prepare("DELETE FROM user_purchase_records WHERE product_id = ?");
                $stmt_reset->bind_param("i", $product_id);
                $stmt_reset->execute();
                $stmt_reset->close();
            }

            $sql = "UPDATE products SET name=?, description=?, price=?, stock=?, category_id=?, purchase_limit=?";
            // ✅ PERBAIKAN: Tipe data 'd' untuk price(decimal) dan 'i' untuk stock, category_id. 's' untuk purchase_limit (bisa null)
            $types = "sdiisi"; 
            $params = [&$name, &$description, &$price, &$stock, &$category_id, &$purchase_limit];
    
            if ($is_new_image_uploaded) {
                if ($current_product['image'] && file_exists(UPLOAD_DIR_PRODUK . $current_product['image'])) {
                    @unlink(UPLOAD_DIR_PRODUK . $current_product['image']);
                }
                $sql .= ", image=?";
                $types .= "s";
                $params[] = &$image_name;
            }
    
            $sql .= " WHERE id=?";
            $types .= "i";
            $params[] = &$product_id;
    
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
    
        } else { // TAMBAH PRODUK BARU
            if (!$is_new_image_uploaded) {
                set_flashdata('error', 'Gambar produk wajib diisi untuk produk baru.');
                redirect(BASE_URL . '/admin/admin.php?page=produk&action=add');
            }
            $sql = "INSERT INTO products (name, description, price, stock, category_id, purchase_limit, image) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            // ✅ PERBAIKAN: Tipe data yang benar
            $stmt->bind_param("sdiisss", $name, $description, $price, $stock, $category_id, $purchase_limit, $image_name);
        }
    
        if ($stmt->execute()) {
            $conn->commit();
            set_flashdata('success', 'Produk berhasil disimpan.');
        } else {
            throw new Exception('Gagal menyimpan produk ke database.');
        }
        $stmt->close();

    } catch (Exception $e) {
        $conn->rollback();
        set_flashdata('error', $e->getMessage());
        if ($is_new_image_uploaded && $image_name && file_exists(UPLOAD_DIR_PRODUK . $image_name)) {
            @unlink(UPLOAD_DIR_PRODUK . $image_name);
        }
    }
    
    redirect(BASE_URL . '/admin/admin.php?page=produk');
}

// --- LOGIKA HAPUS PRODUK ---
if (isset($_POST['delete_product'])) {
    $product_id = (int)$_POST['id'];
    if ($product_id > 0) {
        $stmt_img = $conn->prepare("SELECT image FROM products WHERE id = ?");
        $stmt_img->bind_param("i", $product_id);
        $stmt_img->execute();
        $row = $stmt_img->get_result()->fetch_assoc();
        $stmt_img->close();

        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param("i", $product_id);

        if ($stmt->execute()) {
            if ($row && $row['image'] && file_exists(UPLOAD_DIR_PRODUK . $row['image'])) {
                @unlink(UPLOAD_DIR_PRODUK . $row['image']);
            }
            set_flashdata('success', 'Produk berhasil dihapus.');
        } else {
            set_flashdata('error', 'Gagal menghapus produk: ' . $conn->error);
        }
        $stmt->close();
    }
    redirect(BASE_URL . '/admin/admin.php?page=produk');
}


// Ambil parameter halaman
$page = $_GET['page'] ?? 'dashboard';
$is_settings_submenu = in_array($page, ['pengaturan_toko', 'pengaturan_user']);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= get_setting($conn, 'store_name') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .sidebar { min-width: 250px; }
        .submenu-active > div {
            max-height: 500px;
            opacity: 1;
            transition: max-height 0.3s ease-in-out, opacity 0.3s ease-in;
        }
        .submenu-inactive > div {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-in-out, opacity 0.3s ease-out;
        }
    </style>
</head>
<body>
    <div class="flex h-screen bg-gray-100">
        
        <!-- Sidebar -->
        <aside class="sidebar bg-gray-800 text-white flex flex-col">
            <div class="p-6 text-xl font-semibold border-b border-gray-700">
                Admin <?= get_setting($conn, 'store_name') ?>
            </div>
            <nav class="flex-grow p-4 space-y-2">
                <a href="?page=dashboard" class="flex items-center p-3 rounded-lg <?= $page == 'dashboard' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                    <i class="fas fa-tachometer-alt w-6 text-center"></i><span class="ml-3">Dashboard</span>
                </a>
                <a href="?page=pesanan" class="flex items-center p-3 rounded-lg <?= $page == 'pesanan' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                    <i class="fas fa-box-open w-6 text-center"></i><span class="ml-3">Pesanan</span>
                </a>
                <a href="?page=produk" class="flex items-center p-3 rounded-lg <?= $page == 'produk' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                    <i class="fas fa-tags w-6 text-center"></i><span class="ml-3">Produk</span>
                </a>
                <a href="?page=kategori" class="flex items-center p-3 rounded-lg <?= $page == 'kategori' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                     <i class="fas fa-folder-open w-6 text-center"></i><span class="ml-3">Kategori</span>
                </a>
                <a href="?page=banner" class="flex items-center p-3 rounded-lg <?= $page == 'banner' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                    <i class="fas fa-images w-6 text-center"></i><span class="ml-3">Banner</span>
                </a>
                <a href="?page=pembayaran" class="flex items-center p-3 rounded-lg <?= $page == 'pembayaran' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">
                    <i class="fas fa-credit-card w-6 text-center"></i><span class="ml-3">Pembayaran</span>
                </a>
                
                <div id="settings-menu" class="<?= $is_settings_submenu ? 'submenu-active' : 'submenu-inactive' ?>">
                    <button type="button" class="flex items-center justify-between w-full p-3 rounded-lg text-left <?= $is_settings_submenu ? 'bg-indigo-700 font-bold' : 'hover:bg-gray-700' ?>" onclick="toggleSettingsMenu()">
                        <span><i class="fas fa-cogs w-6 text-center"></i><span class="ml-3">Pengaturan</span></span>
                        <svg class="w-4 h-4 transition-transform duration-300 transform <?= $is_settings_submenu ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </button>
                    <div class="mt-1 ml-4 space-y-1">
                        <a href="?page=pengaturan_toko" class="block p-2 text-sm rounded-lg <?= $page == 'pengaturan_toko' ? 'bg-indigo-500 font-semibold' : 'hover:bg-gray-600' ?>">
                            Pengaturan Toko
                        </a>
                        <a href="?page=pengaturan_user" class="block p-2 text-sm rounded-lg <?= $page == 'pengaturan_user' ? 'bg-indigo-500 font-semibold' : 'hover:bg-gray-600' ?>">
                            Pengguna
                        </a>
                    </div>
                </div>
            </nav>
            <div class="p-4 border-t border-gray-700">
                 <a href="<?= BASE_URL ?>/login/logout.php" class="block w-full text-center py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-sign-out-alt mr-2"></i>Logout
                 </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 overflow-y-auto">
            
            <?php flash_message(); ?>
            
            <?php
                $page_title_map = [
                    'dashboard' => 'Dashboard',
                    'pesanan' => 'Manajemen Pesanan',
                    'produk' => 'Manajemen Produk',
                    'kategori' => 'Manajemen Kategori',
                    'banner' => 'Manajemen Banner',
                    'pembayaran' => 'Metode Pembayaran',
                    'pengaturan_toko' => 'Pengaturan Toko',
                    'pengaturan_user' => 'Manajemen Pengguna'
                ];
            ?>
             <h1 class="text-3xl font-bold mb-6 text-gray-800">
                <?= $page_title_map[$page] ?? 'Halaman Tidak Ditemukan' ?>
            </h1>

            <?php
                $allowed_pages = ['dashboard', 'pesanan', 'produk', 'kategori', 'banner', 'pembayaran', 'pengaturan_toko', 'pengaturan_user'];
                
                $file_map = [
                    'dashboard' => 'dashboard.php',
                    'pesanan' => 'pesanan/pesanan.php',
                    'produk' => 'produk/produk.php',
                    'kategori' => 'kategori/kategori.php',
                    'banner' => 'banner/banner.php',
                    'pembayaran' => 'pembayaran/pembayaran.php',
                    'pengaturan_toko' => 'pengaturan/pengaturan.php',
                    'pengaturan_user' => 'user/user.php',
                ];

                if (in_array($page, $allowed_pages)) {
                    $include_file = $file_map[$page];
                    
                    if (!defined('BASE_PATH')) {
                        define('BASE_PATH', dirname(__DIR__) . '/');
                    }
                    
                    $full_path_file = BASE_PATH . 'admin/' . $include_file;
                    
                    if (file_exists($full_path_file)) {
                        include $full_path_file;
                    } else {
                        echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg">File tidak ditemukan: ' . htmlspecialchars($full_path_file) . '</div>';
                    }
                } else {
                     echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg">Halaman tidak valid.</div>';
                }
            ?>
        </main>
    </div>
    
    <script>
        function toggleSettingsMenu() {
            const menu = document.getElementById('settings-menu');
            menu.classList.toggle('submenu-active');
            menu.classList.toggle('submenu-inactive');
        }
    </script>
</body>
</html>