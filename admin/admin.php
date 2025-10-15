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
define('UPLOAD_DIR_KATEGORI', '../assets/images/kategori/');
define('UPLOAD_DIR_BANNER', '../assets/images/banner/');

// --- PERBAIKAN: Pastikan folder upload ada ---
// Cek dan buat folder jika belum ada untuk menghindari error saat upload gambar.
// Izin 0777 digunakan agar web server (XAMPP) pasti bisa menulis file ke dalamnya.
if (!is_dir(UPLOAD_DIR_PRODUK)) {
    mkdir(UPLOAD_DIR_PRODUK, 0777, true);
}
if (!is_dir(UPLOAD_DIR_BANNER)) {
    mkdir(UPLOAD_DIR_BANNER, 0777, true);
}
// ---------------------------------------------

// --- CRUD KATEGORI ---
if (isset($_POST['save_kategori'])) {
    $id = sanitize_input($_POST['id']);
    $name = sanitize_input($_POST['name']);
    
    // Logika upload gambar (opsional)
    // ... (bisa ditambahkan jika kategori pakai gambar)

    if (empty($id)) { // Tambah baru
        $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->bind_param("s", $name);
    } else { // Update
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

// --- CRUD PRODUK ---
if (isset($_POST['save_produk'])) {
    $id = sanitize_input($_POST['id']);
    $name = sanitize_input($_POST['name']);
    $category_id = sanitize_input($_POST['category_id']);
    $price = sanitize_input($_POST['price']);
    $stock = sanitize_input($_POST['stock']);
    $description = sanitize_input($_POST['description']);
    $image_path = sanitize_input($_POST['current_image']);

    // Handle file upload
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $image_name = uniqid() . '-' . basename($_FILES["image"]["name"]);
        $target_file = UPLOAD_DIR_PRODUK . $image_name;
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
            // Hapus gambar lama jika ada saat update
            if (!empty($image_path) && file_exists(UPLOAD_DIR_PRODUK . $image_path)) {
                unlink(UPLOAD_DIR_PRODUK . $image_path);
            }
            $image_path = $image_name;
        }
    }

    if (empty($id)) { // Tambah produk
        $stmt = $conn->prepare("INSERT INTO products (name, category_id, price, stock, description, image) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("siisss", $name, $category_id, $price, $stock, $description, $image_path);
    } else { // Update produk
        $stmt = $conn->prepare("UPDATE products SET name=?, category_id=?, price=?, stock=?, description=?, image=? WHERE id=?");
        $stmt->bind_param("siisssi", $name, $category_id, $price, $stock, $description, $image_path, $id);
    }

    if ($stmt->execute()) {
        set_flash_message('success', 'Produk berhasil disimpan.');
    } else {
        set_flash_message('error', 'Gagal menyimpan produk.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=produk');
}
if (isset($_POST['delete_produk'])) {
    $id = sanitize_input($_POST['id']);
    // Hapus juga gambar dari server
    $res = $conn->query("SELECT image FROM products WHERE id = $id");
    if($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if(!empty($row['image']) && file_exists(UPLOAD_DIR_PRODUK . $row['image'])) {
            unlink(UPLOAD_DIR_PRODUK . $row['image']);
        }
    }

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        set_flash_message('success', 'Produk berhasil dihapus.');
    } else {
        set_flash_message('error', 'Gagal menghapus produk.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=produk');
}

// --- CRUD USER ---
if (isset($_POST['update_user_role'])) {
    $id = sanitize_input($_POST['id']);
    $role = sanitize_input($_POST['role']);
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $role, $id);
     if ($stmt->execute()) {
        set_flash_message('success', 'Role pengguna berhasil diubah.');
    } else {
        set_flash_message('error', 'Gagal mengubah role pengguna.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=user');
}
if (isset($_POST['delete_user'])) {
    $id = sanitize_input($_POST['id']);
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        set_flash_message('success', 'Pengguna berhasil dihapus.');
    } else {
        set_flash_message('error', 'Gagal menghapus pengguna.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=user');
}

// --- CRUD BANNER ---
if (isset($_POST['save_banner'])) {
    $id = sanitize_input($_POST['id']);
    $title = sanitize_input($_POST['title']);
    $link_url = sanitize_input($_POST['link_url']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $image_path = sanitize_input($_POST['current_image']);

    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $image_name = uniqid() . '-' . basename($_FILES["image"]["name"]);
        $target_file = UPLOAD_DIR_BANNER . $image_name;
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
             if (!empty($image_path) && file_exists(UPLOAD_DIR_BANNER . $image_path)) {
                unlink(UPLOAD_DIR_BANNER . $image_path);
            }
            $image_path = $image_name;
        }
    }

    if (empty($id)) {
        $stmt = $conn->prepare("INSERT INTO banners (title, link_url, is_active, image) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $link_url, $is_active, $image_path);
    } else {
        $stmt = $conn->prepare("UPDATE banners SET title=?, link_url=?, is_active=?, image=? WHERE id=?");
        $stmt->bind_param("ssisi", $title, $link_url, $is_active, $image_path, $id);
    }
     if ($stmt->execute()) {
        set_flash_message('success', 'Banner berhasil disimpan.');
    } else {
        set_flash_message('error', 'Gagal menyimpan banner.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=banner');
}
if (isset($_POST['delete_banner'])) {
    $id = sanitize_input($_POST['id']);
    $res = $conn->query("SELECT image FROM banners WHERE id = $id");
     if($res->num_rows > 0) {
        $row = $res->fetch_assoc();
        if(!empty($row['image']) && file_exists(UPLOAD_DIR_BANNER . $row['image'])) {
            unlink(UPLOAD_DIR_BANNER . $row['image']);
        }
    }
    $stmt = $conn->prepare("DELETE FROM banners WHERE id = ?");
    $stmt->bind_param("i", $id);
     if ($stmt->execute()) {
        set_flash_message('success', 'Banner berhasil dihapus.');
    } else {
        set_flash_message('error', 'Gagal menghapus banner.');
    }
    $stmt->close();
    redirect('/admin/admin.php?page=banner');
}

// ... (logika pesanan yang sudah ada) ...
if (isset($_POST['update_status'])) {
    $order_id = sanitize_input($_POST['order_id']);
    $status = sanitize_input($_POST['status']);
    $current_page = sanitize_input($_POST['current_page']);
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $order_id);
    if ($stmt->execute()) {
        $user_id_res = $conn->query("SELECT user_id FROM orders WHERE id = $order_id")->fetch_assoc();
        $user_id = $user_id_res['user_id'];
        $message = "Status pesanan #WK{$order_id} Anda telah diperbarui menjadi: " . ucfirst(str_replace('_', ' ', $status));
        $conn->query("INSERT INTO notifications (user_id, message) VALUES ($user_id, '$message')");
        set_flash_message('update_success', 'Status pesanan berhasil diperbarui.');
    } else {
        set_flash_message('update_error', 'Gagal memperbarui status pesanan.');
    }
    $stmt->close();
    redirect($current_page);
}
if (isset($_POST['cetak_resi'])) {
    $order_id = sanitize_input($_POST['order_id']);
    $current_page = sanitize_input($_POST['current_page']);
    $new_status = 'proses_pengemasan';
    $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $order_id);
    if ($stmt->execute()) {
        $user_id_res = $conn->query("SELECT user_id FROM orders WHERE id = $order_id")->fetch_assoc();
        $user_id = $user_id_res['user_id'];
        $message = "Pesanan #WK{$order_id} Anda sedang kami siapkan untuk dikemas.";
        $conn->query("INSERT INTO notifications (user_id, message) VALUES ($user_id, '$message')");
        set_flash_message('update_success', 'Status diubah ke "Proses Pengemasan". Silakan cetak resi.');
    } else {
        set_flash_message('update_error', 'Gagal memproses cetak resi.');
    }
    $stmt->close();
    redirect($current_page);
}


// --- Navigasi Halaman Admin ---
$page = $_GET['page'] ?? 'dashboard';
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
            <nav class="flex-1 p-2 space-y-2">
                <a href="?page=dashboard" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'dashboard' ? 'bg-indigo-600' : '' ?>">Dashboard</a>
                <a href="?page=pesanan" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'pesanan' ? 'bg-indigo-600' : '' ?>">Pesanan</a>
                <a href="?page=produk" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'produk' ? 'bg-indigo-600' : '' ?>">Produk</a>
                <a href="?page=kategori" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'kategori' ? 'bg-indigo-600' : '' ?>">Kategori</a>
                <a href="?page=user" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'user' ? 'bg-indigo-600' : '' ?>">Users</a>
                <a href="?page=banner" class="flex items-center px-4 py-2 rounded-md hover:bg-gray-700 <?= $page == 'banner' ? 'bg-indigo-600' : '' ?>">Banner</a>
            </nav>
            <div class="p-4 border-t border-gray-700">
                <a href="<?= BASE_URL ?>/" class="block text-center w-full px-4 py-2 rounded-md bg-indigo-600 hover:bg-indigo-700">Kembali ke Toko</a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-8 overflow-y-auto ml-64">
             <h1 class="text-3xl font-bold text-gray-800 mb-6">
                <?php 
                    $page_title = 'Dashboard';
                    switch($page) {
                        case 'pesanan': $page_title = 'Manajemen Pesanan'; break;
                        case 'produk': $page_title = 'Manajemen Produk'; break;
                        case 'kategori': $page_title = 'Manajemen Kategori'; break;
                        case 'user': $page_title = 'Manajemen Pengguna'; break;
                        case 'banner': $page_title = 'Manajemen Banner'; break;
                    }
                    echo $page_title;
                ?>
            </h1>

            <!-- Konten dinamis -->
            <?php
                $allowed_pages = ['dashboard', 'pesanan', 'produk', 'kategori', 'user', 'banner'];
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