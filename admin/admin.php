<?php
// File: admin/admin.php
include '../config/config.php';
include '../sistem/sistem.php';
check_admin();

load_settings($conn); 

// Direktori untuk upload gambar
define('UPLOAD_DIR_PRODUK', '../assets/images/produk/');
define('UPLOAD_DIR_BANNER', '../assets/images/banner/');
define('UPLOAD_DIR_SETTINGS', '../assets/images/settings/');
define('UPLOAD_DIR_PAYMENT', '../assets/images/payment/');
define('UPLOAD_DIR_KATEGORI', '../assets/images/kategori/'); 

// Pastikan direktori ada
if (!is_dir(UPLOAD_DIR_PRODUK)) mkdir(UPLOAD_DIR_PRODUK, 0777, true);
if (!is_dir(UPLOAD_DIR_BANNER)) mkdir(UPLOAD_DIR_BANNER, 0777, true);
if (!is_dir(UPLOAD_DIR_SETTINGS)) mkdir(UPLOAD_DIR_SETTINGS, 0777, true);
if (!is_dir(UPLOAD_DIR_PAYMENT)) mkdir(UPLOAD_DIR_PAYMENT, 0777, true);
if (!is_dir(UPLOAD_DIR_KATEGORI)) mkdir(UPLOAD_DIR_KATEGORI, 0777, true);


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
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_ext, $allowed_ext)) {
            $new_file_name = uniqid('img_') . '-' . time() . '.' . $file_ext;
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

// --- LOGIKA AKSI PESANAN TERPUSAT ---
if (isset($_POST['action']) && in_array($_POST['action'], ['approve_payment', 'reject_payment', 'process_order', 'ship_order', 'complete_order'])) {
    
    $action = $_POST['action'];
    $redirect_url = '/admin/admin.php?' . ($_POST['active_query_string'] ?? 'page=pesanan');
    
    $order_ids = [];
    if (isset($_POST['order_id'])) {
        $order_ids[] = (int)$_POST['order_id'];
    } elseif (isset($_POST['selected_orders']) && is_array($_POST['selected_orders'])) {
        $order_ids = array_map('intval', $_POST['selected_orders']);
    }

    if (empty($order_ids)) {
        set_flashdata('error', 'Tidak ada pesanan yang dipilih.');
        redirect($redirect_url);
    }

    $new_status = '';
    $success_message = '';
    $error_message = 'Gagal memperbarui pesanan.';
    $should_restock = false;
    
    switch ($action) {
        case 'approve_payment':
            $new_status = 'belum_dicetak';
            $success_message = 'Pembayaran berhasil disetujui.';
            break;
        case 'reject_payment':
            $new_status = 'cancelled';
            $success_message = 'Pembayaran berhasil ditolak.';
            $should_restock = true;
            break;
        case 'process_order':
            $new_status = 'processed';
            $success_message = 'Pesanan berhasil diproses.';
            break;
        case 'ship_order':
            $new_status = 'shipped';
            $success_message = 'Pesanan berhasil dikirim.';
            break;
        case 'complete_order':
            $new_status = 'completed';
            $success_message = 'Pesanan berhasil diselesaikan.';
            break;
        default:
            set_flashdata('error', 'Aksi tidak dikenal.');
            redirect($redirect_url);
    }
    
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types = str_repeat('i', count($order_ids));
    
    $conn->begin_transaction();
    try {
        if ($should_restock) {
            $stmt_restock = $conn->prepare("
                UPDATE products p
                JOIN order_items oi ON p.id = oi.product_id
                SET p.stock = p.stock + oi.quantity
                WHERE oi.order_id = ?
            ");
            foreach ($order_ids as $order_id) {
                $stmt_restock->bind_param("i", $order_id);
                $stmt_restock->execute();
            }
            $stmt_restock->close();
        }

        $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id IN ($placeholders)");
        $stmt->bind_param("s" . $types, $new_status, ...$order_ids);
        
        if ($stmt->execute()) {
            $count = $stmt->affected_rows;
            foreach($order_ids as $order_id) {
                $result = $conn->query("SELECT user_id, order_number FROM orders WHERE id = $order_id");
                $order = $result->fetch_assoc();
                if ($order) {
                    $notif_message = "Status pesanan #{$order['order_number']} diperbarui menjadi " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                    send_notification($conn, $order['user_id'], $notif_message);
                }
            }
            $conn->commit();
            set_flashdata('success', ($count > 1 ? "$count pesanan " : "Pesanan ") . $success_message);
        } else {
            $conn->rollback();
            set_flashdata('error', $error_message . ' Error DB: ' . $conn->error);
        }
        $stmt->close();
    } catch (Exception $e) {
        $conn->rollback();
        set_flashdata('error', $error_message . ' Error: ' . $e->getMessage());
    }
    redirect($redirect_url);
}

// --- LOGIKA CRUD PRODUK ---
if (isset($_POST['save_product'])) {
    $product_id = (int)$_POST['product_id'];
    $name = sanitize_input($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $price = (float)$_POST['price'];
    $new_stock = (int)$_POST['stock'];
    $description = sanitize_input($_POST['description']);
    $image_name = null;
    $is_new_image_uploaded = false;
    
    $limit_type = $_POST['limit_type'] ?? 'unlimited';
    $purchase_limit_input = (int)($_POST['purchase_limit'] ?? 0);
    $purchase_limit = ($limit_type === 'limited' && $purchase_limit_input > 0) ? $purchase_limit_input : 0;
    
    $stock_changed = false;

    if ($product_id > 0) {
        $stmt_old_prod = $conn->prepare("SELECT stock FROM products WHERE id = ?");
        $stmt_old_prod->bind_param("i", $product_id);
        $stmt_old_prod->execute();
        $old_prod = $stmt_old_prod->get_result()->fetch_assoc();
        $stmt_old_prod->close();
        if ($old_prod) {
            // PERBAIKAN: Cek jika stok LAMA tidak sama dengan stok BARU
            if ($new_stock != (int)$old_prod['stock']) { 
                $stock_changed = true;
            }
        }
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploaded_name = upload_image_file($_FILES['image'], UPLOAD_DIR_PRODUK);
        if ($uploaded_name) {
            $image_name = $uploaded_name;
            $is_new_image_uploaded = true;
        } else {
            set_flashdata('error', 'Gagal mengupload gambar.');
            redirect('/admin/admin.php?page=produk');
        }
    }

    if ($product_id > 0) {
        $sql = "UPDATE products SET name=?, category_id=?, price=?, stock=?, description=?, purchase_limit=?";
        $types = "sidisi"; 
        $params = [$name, $category_id, $price, $new_stock, $description, $purchase_limit];

        if ($is_new_image_uploaded) {
            $stmt_old = $conn->prepare("SELECT image FROM products WHERE id = ?");
            $stmt_old->bind_param("i", $product_id);
            $stmt_old->execute();
            if ($row = $stmt_old->get_result()->fetch_assoc()) {
                if ($row['image'] && file_exists(UPLOAD_DIR_PRODUK . $row['image'])) {
                    unlink(UPLOAD_DIR_PRODUK . $row['image']);
                }
            }
            $stmt_old->close();
            
            $sql .= ", image=?";
            $types .= "s";
            $params[] = $image_name;
        }
        
        if ($stock_changed) {
            $sql .= ", last_stock_reset = NOW(), stock_cycle_id = stock_cycle_id + 1";
        }

        $sql .= " WHERE id=?";
        $types .= "i"; 
        $params[] = $product_id;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            set_flashdata('success', 'Produk berhasil diperbarui.');
        } else {
            set_flashdata('error', 'Gagal memperbarui produk: ' . $conn->error);
        }
        $stmt->close();
    } else {
        if (!$is_new_image_uploaded) {
            set_flashdata('error', 'Gambar produk wajib diisi.');
            redirect('/admin/admin.php?page=produk&action=add');
        }
        $sql = "INSERT INTO products (name, category_id, price, stock, description, image, purchase_limit, last_stock_reset, stock_cycle_id) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sidissi", $name, $category_id, $price, $new_stock, $description, $image_name, $purchase_limit); 
        if ($stmt->execute()) {
            set_flashdata('success', 'Produk baru berhasil ditambahkan.');
        } else {
            set_flashdata('error', 'Gagal menambah produk: ' . $conn->error);
        }
        $stmt->close();
    }
    redirect('/admin/admin.php?page=produk');
}


if (isset($_POST['delete_product'])) {
    $product_id = (int)$_POST['product_id'];
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
                unlink(UPLOAD_DIR_PRODUK . $row['image']);
            }
            set_flashdata('success', 'Produk berhasil dihapus.');
        } else {
            set_flashdata('error', 'Gagal menghapus produk.');
        }
        $stmt->close();
    }
    redirect('/admin/admin.php?page=produk');
}

if (isset($_POST['save_settings'])) {
    $settings_to_update = ['store_name', 'store_description', 'store_address', 'store_phone', 'store_email', 'store_facebook', 'store_tiktok'];
    foreach ($settings_to_update as $key) {
        if (isset($_POST[$key])) {
            update_or_insert_setting($conn, $key, sanitize_input($_POST[$key]));
        }
    }
    if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] === UPLOAD_ERR_OK) {
        $new_logo_name = upload_image_file($_FILES['store_logo'], UPLOAD_DIR_SETTINGS);
        if ($new_logo_name) {
            $old_logo = get_setting($conn, 'store_logo');
            if ($old_logo && file_exists(UPLOAD_DIR_SETTINGS . $old_logo)) {
                unlink(UPLOAD_DIR_SETTINGS . $old_logo);
            }
            update_or_insert_setting($conn, 'store_logo', $new_logo_name);
        }
    }
    set_flashdata('success', 'Pengaturan toko berhasil diperbarui.');
    redirect('/admin/admin.php?page=pengaturan_toko');
}


if (isset($_POST['save_kategori'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = sanitize_input($_POST['name']);
    if (!empty($name)) {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
        }
        $stmt->execute();
        $stmt->close();
    }
    redirect('/admin/admin.php?page=kategori');
}

if (isset($_POST['delete_kategori'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
    }
    redirect('/admin/admin.php?page=kategori');
}

if (isset($_POST['save_banner'])) {
    $id = (int)($_POST['id'] ?? 0);
    $title = sanitize_input($_POST['title']);
    $link_url = sanitize_input($_POST['link_url']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $new_image_name = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $new_image_name = upload_image_file($_FILES['image'], UPLOAD_DIR_BANNER);
    }
    
    if ($id > 0) {
        $sql = "UPDATE banners SET title = ?, link_url = ?, is_active = ?";
        $params = [$title, $link_url, $is_active];
        if($new_image_name) {
            $sql .= ", image = ?";
            $params[] = $new_image_name;
        }
        $sql .= " WHERE id = ?";
        $params[] = $id;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(str_repeat('s', count($params) -1) . 'i', ...$params);
    } else {
        $stmt = $conn->prepare("INSERT INTO banners (title, link_url, is_active, image) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $link_url, $is_active, $new_image_name);
    }
    $stmt->execute();
    redirect('/admin/admin.php?page=banner');
}

if (isset($_POST['delete_banner'])) {
    $id = (int)($_POST['id'] ?? 0);
    if($id > 0) {
        $stmt = $conn->prepare("DELETE FROM banners WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    redirect('/admin/admin.php?page=banner');
}

if (isset($_POST['save_payment_method'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = sanitize_input($_POST['name']);
    $details = sanitize_input($_POST['details']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (!empty($name) && !empty($details)) {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE payment_methods SET name = ?, details = ?, is_active = ? WHERE id = ?");
            $stmt->bind_param("ssii", $name, $details, $is_active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO payment_methods (name, details, is_active) VALUES (?, ?, ?)");
            $stmt->bind_param("ssi", $name, $details, $is_active);
        }
        $stmt->execute();
    }
    redirect('/admin/admin.php?page=pembayaran');
}

if (isset($_POST['delete_payment_method'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
    }
    redirect('/admin/admin.php?page=pembayaran');
}


$page_name = $_GET['page'] ?? 'dashboard';
$is_settings_submenu = in_array($page_name, ['pengaturan_toko', 'pengaturan_user']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f7f9; }
        .sidebar { min-width: 250px; }
        .submenu-active > div { max-height: 500px; opacity: 1; transition: all 0.3s ease-in-out; }
        .submenu-inactive > div { max-height: 0; opacity: 0; overflow: hidden; transition: all 0.3s ease-in-out; }
    </style>
</head>
<body>
    <div class="flex h-screen bg-gray-100">
        
        <!-- Sidebar -->
        <aside class="sidebar bg-gray-800 text-white flex flex-col">
            <div class="p-6 text-xl font-semibold border-b border-gray-700">Admin Warok Kite</div>
            <nav class="flex-grow p-4 space-y-2">
                <a href="?page=dashboard" class="flex items-center p-3 rounded-lg <?= $page_name == 'dashboard' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Dashboard</a>
                <a href="?page=pesanan" class="flex items-center p-3 rounded-lg <?= $page_name == 'pesanan' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Pesanan</a>
                <a href="?page=produk" class="flex items-center p-3 rounded-lg <?= $page_name == 'produk' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Produk</a>
                <a href="?page=kategori" class="flex items-center p-3 rounded-lg <?= $page_name == 'kategori' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Kategori</a>
                <a href="?page=banner" class="flex items-center p-3 rounded-lg <?= $page_name == 'banner' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Banner</a>
                <a href="?page=pembayaran" class="flex items-center p-3 rounded-lg <?= $page_name == 'pembayaran' ? 'bg-indigo-600 font-bold' : 'hover:bg-gray-700' ?>">Pembayaran</a>
                
                <!-- PERBAIKAN: Mengembalikan menu Pengaturan yang hilang -->
                <div id="settings-menu" class="submenu-inactive">
                    <button type="button" class="flex items-center justify-between w-full p-3 rounded-lg text-left <?= $is_settings_submenu ? 'bg-indigo-700 font-bold' : 'hover:bg-gray-700' ?>" onclick="toggleSettingsMenu()">
                        <span><i class="fas fa-cog mr-2"></i> Pengaturan</span>
                        <svg id="settings-arrow" class="w-4 h-4 transition-transform duration-300 transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                    </button>
                    <div class="mt-1 ml-4 space-y-1 overflow-hidden">
                        <a href="?page=pengaturan_toko" class="block p-2 text-sm rounded-lg <?= $page_name == 'pengaturan_toko' ? 'bg-indigo-500 font-semibold' : 'hover:bg-gray-600' ?>">Pengaturan Toko</a>
                        <a href="?page=pengaturan_user" class="block p-2 text-sm rounded-lg <?= $page_name == 'pengaturan_user' ? 'bg-indigo-500 font-semibold' : 'hover:bg-gray-600' ?>">Pengguna</a>
                    </div>
                </div>

            </nav>
            <div class="p-4 border-t border-gray-700">
                 <a href="<?= BASE_URL ?>/login/logout.php" class="block w-full text-center py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Logout</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-6 overflow-y-auto">
            <?php flash_message(); ?>
            <h1 class="text-3xl font-bold mb-6 text-gray-800">
                <?php
                    $page_title_map = [
                        'dashboard' => 'Dashboard', 'pesanan' => 'Manajemen Pesanan', 'produk' => 'Manajemen Produk',
                        'kategori' => 'Manajemen Kategori', 'banner' => 'Manajemen Banner', 'pembayaran' => 'Metode Pembayaran',
                        'pengaturan_toko' => 'Pengaturan Toko', 'pengaturan_user' => 'Manajemen Pengguna'
                    ];
                    echo $page_title_map[$page_name] ?? 'Halaman Tidak Ditemukan';
                ?>
            </h1>
            <?php
                $allowed_pages = array_keys($page_title_map);
                $file_map = [
                    'dashboard' => 'dashboard.php', 'pesanan' => 'pesanan/pesanan.php', 'produk' => 'produk/produk.php',
                    'kategori' => 'kategori/kategori.php', 'banner' => 'banner/banner.php', 'pembayaran' => 'pembayaran/pembayaran.php',
                    'pengaturan_toko' => 'pengaturan/pengaturan.php', 'pengaturan_user' => 'user/user.php',
                ];
                if (in_array($page_name, $allowed_pages)) {
                    $include_file = $file_map[$page_name];
                    if (file_exists($include_file)) {
                        define('IS_ADMIN_PAGE', true);
                        include $include_file;
                    } else {
                        echo '<div class="bg-red-100 text-red-700 p-4 rounded-lg">File tidak ditemukan: ' . htmlspecialchars($include_file) . '</div>';
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
            const arrow = document.getElementById('settings-arrow');
            menu.classList.toggle('submenu-active');
            menu.classList.toggle('submenu-inactive');
            arrow.classList.toggle('rotate-90');
        }

        // PERBAIKAN: Pastikan menu terbuka jika halaman aktif
        document.addEventListener('DOMContentLoaded', function() {
            const menu = document.getElementById('settings-menu');
            const is_settings_page = <?= json_encode($is_settings_submenu) ?>;
            if (is_settings_page) {
                menu.classList.remove('submenu-inactive');
                menu.classList.add('submenu-active');
                document.getElementById('settings-arrow').classList.add('rotate-90');
            }
        });
    </script>
</body>
</html>