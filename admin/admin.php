<?php
// File: admin/admin.php
// VERSI FULL: FIX HARGA (DECIMAL SAFE) & FITUR RESET LIMIT & PAGINASI
// Programmer IQ 180 Edition

include '../config/config.php';
include '../sistem/sistem.php'; // Wajib ada compressImage() di sini
check_admin();

// Definisikan konstanta IS_ADMIN_PAGE SEBELUM memanggil load_settings
define('IS_ADMIN_PAGE', true);
load_settings($conn); 

// --- KONFIGURASI DIREKTORI UPLOAD ---
define('UPLOAD_DIR_PRODUK', '../assets/images/produk/');
define('UPLOAD_DIR_BANNER', '../assets/images/banner/');
define('UPLOAD_DIR_SETTINGS', '../assets/images/settings/');
define('UPLOAD_DIR_KATEGORI', '../assets/images/kategori/'); 
define('UPLOAD_DIR_IMPORTS', 'uploads/imports/');

// Pastikan semua direktori ada
$dirs = [UPLOAD_DIR_PRODUK, UPLOAD_DIR_BANNER, UPLOAD_DIR_SETTINGS, UPLOAD_DIR_KATEGORI, UPLOAD_DIR_IMPORTS, 'rekap', 'import'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

// --- HELPER FUNCTIONS ---

// Cek kolom opsional agar query tetap jalan di DB versi lama.
function column_exists($conn, $table, $column) {
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    $column = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$column);
    if ($table === '' || $column === '') return false;

    // NOTE: MariaDB tidak mendukung placeholder '?' untuk statement SHOW pada prepared statement.
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'";
    $res = $conn->query($sql);
    if (!$res) return false;
    return ($res->num_rows > 0);
}
// Fungsi upload standar (untuk non-kompresi / fallback)
function upload_image_file($file_data, $upload_dir) {
    if ($file_data['error'] === UPLOAD_ERR_OK) {
        $file_ext = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_ext, $allowed_ext)) {
            $new_file_name = uniqid('img_') . '-' . time() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_file_name;
            if (move_uploaded_file($file_data['tmp_name'], $upload_path)) {
                return $new_file_name;
            }
        }
    }
    return false;
}

// Fungsi Helper untuk membuat URL Redirect yang Membawa State Sebelumnya
function build_return_url_produk() {
    $q = urlencode($_POST['return_q'] ?? '');
    $cat = urlencode($_POST['return_category'] ?? 0);
    $status = urlencode($_POST['return_status'] ?? 'active');
    $p = urlencode($_POST['return_page'] ?? 1);
    $limit = urlencode($_POST['return_limit'] ?? 10); // NEW: Support dynamic limit
    
    return "/admin/admin.php?page=produk&q=$q&category=$cat&status=$status&p=$p&limit=$limit";
}

// =================================================================================
// LOGIKA UTAMA: PENYIMPANAN PRODUK & VARIASI
// =================================================================================
if (isset($_POST['save_product'])) {
    
    // 1. Ambil & Sanitasi Data Dasar
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $name = sanitize_input($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $description = $_POST['description']; // Deskripsi boleh HTML
    // Ambil stok saat ini untuk mendeteksi restock (auto reset limit).
    $current_stock_db = null;
    if ($product_id > 0) {
        $stmt_old = $conn->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
        $stmt_old->bind_param("i", $product_id);
        $stmt_old->execute();
        $row_old = $stmt_old->get_result()->fetch_assoc();
        $current_stock_db = (int)($row_old['stock'] ?? 0);
        $stmt_old->close();
    }
    
    // Logika Limit Pembelian
    $limit_type = $_POST['limit_type'] ?? 'unlimited';
    $purchase_limit = ($limit_type == 'limited') ? (int)$_POST['purchase_limit'] : 0;

    // Logika Flag Variasi (PENTING: Default 0 jika tidak dikirim)
    $has_variation = (isset($_POST['has_variation']) && $_POST['has_variation'] == '1') ? 1 : 0;

    // Validasi Wajib
    if (empty($name) || empty($category_id)) {
        set_flashdata('error', 'Nama Produk dan Kategori wajib diisi!');
        $q = urlencode($_POST['return_q'] ?? '');
        $cat = urlencode($_POST['return_category'] ?? 0);
        $status = urlencode($_POST['return_status'] ?? 'active');
        $p = urlencode($_POST['return_page'] ?? 1);
        $limit = urlencode($_POST['return_limit'] ?? 10);
        redirect("/admin/admin.php?page=produk&action=" . ($product_id ? 'edit&id='.$product_id : 'add') . "&q=$q&category=$cat&status=$status&p=$p&limit=$limit");
    }

    // 2. Handle Upload Gambar Utama (Sampul)
    $image_name = '';
    
    // Jika Edit, ambil gambar lama dulu
    if ($product_id > 0) {
        $q_old = $conn->query("SELECT image FROM products WHERE id = $product_id");
        if ($q_old->num_rows > 0) {
            $image_name = $q_old->fetch_assoc()['image'];
        }
    }

    // Proses Upload Gambar Utama dengan Kompresi
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($file_ext, $allowed)) {
            $new_filename = uniqid('cover_') . '.' . $file_ext;
            $destination = UPLOAD_DIR_PRODUK . $new_filename;
            
            // Gunakan fungsi compressImage dari sistem.php / image_compressor.php
            if (function_exists('compressImage') && compressImage($_FILES['image']['tmp_name'], $destination, 80)) {
                // Hapus gambar lama jika ada dan file baru sukses
                if (!empty($image_name) && file_exists(UPLOAD_DIR_PRODUK . $image_name)) {
                    unlink(UPLOAD_DIR_PRODUK . $image_name);
                }
                $image_name = $new_filename;
            } else {
                // Fallback jika compressImage gagal atau tidak ada
                if (move_uploaded_file($_FILES['image']['tmp_name'], $destination)) {
                     if (!empty($image_name) && file_exists(UPLOAD_DIR_PRODUK . $image_name)) unlink(UPLOAD_DIR_PRODUK . $image_name);
                     $image_name = $new_filename;
                } else {
                    set_flashdata('error', 'Gagal mengupload gambar sampul.');
                    redirect(build_return_url_produk());
                }
            }
        }
    } elseif ($product_id == 0 && empty($image_name)) {
        // Jika produk baru dan tidak ada gambar
        set_flashdata('error', 'Gambar sampul wajib diupload untuk produk baru.');
        $q = urlencode($_POST['return_q'] ?? '');
        $cat = urlencode($_POST['return_category'] ?? 0);
        $status = urlencode($_POST['return_status'] ?? 'active');
        $p = urlencode($_POST['return_page'] ?? 1);
        $limit = urlencode($_POST['return_limit'] ?? 10);
        redirect("/admin/admin.php?page=produk&action=add&q=$q&category=$cat&status=$status&p=$p&limit=$limit");
    }

    // 3. Tentukan Harga & Stok Awal (Untuk Tabel Utama)
    $final_price = 0;
    $final_stock = 0;

    if ($has_variation == 0) {
        // Mode Produk Standar
        // PERBAIKAN: Tidak menggunakan str_replace agar nilai desimal (20000.00) tidak rusak
        $final_price = $_POST['price']; 
        $final_stock = (int)$_POST['stock'];
    }

    // 4. Mulai Transaksi Database
    $conn->begin_transaction();

    try {
        // INSERT atau UPDATE Data Utama Produk
        if ($product_id > 0) {
            // Update
            $stmt = $conn->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, stock=?, purchase_limit=?, is_active=1, image=?, has_variation=? WHERE id=?");
            $stmt->bind_param("issdiisii", $category_id, $name, $description, $final_price, $final_stock, $purchase_limit, $image_name, $has_variation, $product_id);
            if (!$stmt->execute()) throw new Exception("Gagal update produk utama: " . $stmt->error);
            // Auto reset limit saat stok diubah (tanpa perlu klik tombol Reset Limit).
            if ($has_variation == 0 && $purchase_limit > 0 && $current_stock_db !== null && (int)$current_stock_db !== (int)$final_stock) {
                $has_last_stock_reset = column_exists($conn, 'products', 'last_stock_reset');
                $sql_reset = $has_last_stock_reset
                    ? "UPDATE products SET stock_cycle_id = stock_cycle_id + 1, last_stock_reset = NOW() WHERE id = ?"
                    : "UPDATE products SET stock_cycle_id = stock_cycle_id + 1 WHERE id = ?";
                $stmt_reset = $conn->prepare($sql_reset);
                $stmt_reset->bind_param("i", $product_id);
                if (!$stmt_reset->execute()) throw new Exception("Gagal auto reset limit: " . $stmt_reset->error);
                $stmt_reset->close();
            }
            $current_product_id = $product_id;
            $stmt->close();
        } else {
            // Insert Baru
            $stmt = $conn->prepare("INSERT INTO products (category_id, name, description, price, stock, purchase_limit, is_active, image, has_variation) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmt->bind_param("issdiisi", $category_id, $name, $description, $final_price, $final_stock, $purchase_limit, $image_name, $has_variation);
            if (!$stmt->execute()) throw new Exception("Gagal simpan produk baru: " . $stmt->error);
            $current_product_id = $conn->insert_id;
            $stmt->close();
        }

        // 5. HANDLER LOGIKA VARIASI
        if ($has_variation == 1) {
            $var_names = $_POST['variation_name'] ?? [];
            $var_prices = $_POST['variation_price'] ?? [];
            $var_stocks = $_POST['variation_stock'] ?? [];
            $var_ids = $_POST['variation_id'] ?? []; // ID variasi (untuk edit)
            $existing_imgs = $_POST['existing_variation_image'] ?? [];

            $min_price_calculated = null;
            $total_stock_calculated = 0;
            
            // Ambil ID variasi yang sudah ada di DB untuk produk ini
            $existing_db_ids = [];
            if ($product_id > 0) {
                $q_ids = $conn->query("SELECT id FROM product_variations WHERE product_id = $current_product_id");
                while ($r_id = $q_ids->fetch_assoc()) $existing_db_ids[] = $r_id['id'];
            }
            
            $processed_ids = [];

            // Loop setiap input variasi
            for ($i = 0; $i < count($var_names); $i++) {
                if (empty(trim($var_names[$i]))) continue;

                $v_name = sanitize_input($var_names[$i]);
                // PERBAIKAN: Tidak menggunakan str_replace agar nilai desimal tidak rusak
                $v_price = (float)$var_prices[$i];
                $v_stock = (int)$var_stocks[$i];
                $v_id = isset($var_ids[$i]) ? (int)$var_ids[$i] : 0;
                $v_image = $existing_imgs[$i] ?? '';

                // Kalkulasi Min Price & Total Stock
                if ($min_price_calculated === null || $v_price < $min_price_calculated) {
                    $min_price_calculated = $v_price;
                }
                $total_stock_calculated += $v_stock;

                // Handle Upload Gambar Variasi
                if (isset($_FILES['variation_image']['name'][$i]) && $_FILES['variation_image']['error'][$i] == 0) {
                    $var_ext = strtolower(pathinfo($_FILES['variation_image']['name'][$i], PATHINFO_EXTENSION));
                    if (in_array($var_ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $new_var_fname = uniqid('var_' . $current_product_id . '_') . '.' . $var_ext;
                        $var_dest = UPLOAD_DIR_PRODUK . $new_var_fname;
                        
                        // Kompresi Variasi
                        if (function_exists('compressImage') && compressImage($_FILES['variation_image']['tmp_name'][$i], $var_dest, 75)) {
                            // Hapus gambar lama
                            if (!empty($v_image) && file_exists(UPLOAD_DIR_PRODUK . $v_image)) {
                                unlink(UPLOAD_DIR_PRODUK . $v_image);
                            }
                            $v_image = $new_var_fname;
                        } else {
                             // Fallback
                             if (move_uploaded_file($_FILES['variation_image']['tmp_name'][$i], $var_dest)) {
                                 if (!empty($v_image) && file_exists(UPLOAD_DIR_PRODUK . $v_image)) unlink(UPLOAD_DIR_PRODUK . $v_image);
                                 $v_image = $new_var_fname;
                             }
                        }
                    }
                }

                // Database Operation: Insert / Update Variasi
                if ($v_id > 0 && in_array($v_id, $existing_db_ids)) {
                    // Update
                    $stmt_v = $conn->prepare("UPDATE product_variations SET name=?, price=?, stock=?, image=? WHERE id=? AND product_id=?");
                    $stmt_v->bind_param("sdisii", $v_name, $v_price, $v_stock, $v_image, $v_id, $current_product_id);
                    $stmt_v->execute();
                    $stmt_v->close();
                    $processed_ids[] = $v_id;
                } else {
                    // Insert
                    $stmt_v = $conn->prepare("INSERT INTO product_variations (product_id, name, price, stock, image) VALUES (?, ?, ?, ?, ?)");
                    $stmt_v->bind_param("isdis", $current_product_id, $v_name, $v_price, $v_stock, $v_image);
                    $stmt_v->execute();
                    $stmt_v->close();
                    $processed_ids[] = $conn->insert_id;
                }
            }

            // Hapus variasi yang dihilangkan user dari form
            $ids_to_delete = array_diff($existing_db_ids, $processed_ids);
            if (!empty($ids_to_delete)) {
                $ids_str = implode(',', $ids_to_delete);
                // Hapus file gambar fisiknya
                $res_del = $conn->query("SELECT image FROM product_variations WHERE id IN ($ids_str)");
                while ($row_del = $res_del->fetch_assoc()) {
                    if ($row_del['image'] && file_exists(UPLOAD_DIR_PRODUK . $row_del['image'])) {
                        unlink(UPLOAD_DIR_PRODUK . $row_del['image']);
                    }
                }
                $conn->query("DELETE FROM product_variations WHERE id IN ($ids_str)");
            }

            // UPDATE PRODUK UTAMA dengan Total Stok & Harga Terendah dari Variasi
            $final_price_update = $min_price_calculated ?? 0;
            $conn->query("UPDATE products SET price = $final_price_update, stock = $total_stock_calculated WHERE id = $current_product_id");
            // Auto reset limit saat stok (hasil variasi) berubah.
            if ($product_id > 0 && $purchase_limit > 0 && $current_stock_db !== null && (int)$current_stock_db !== (int)$total_stock_calculated) {
                $has_last_stock_reset = column_exists($conn, 'products', 'last_stock_reset');
                $sql_reset = $has_last_stock_reset
                    ? "UPDATE products SET stock_cycle_id = stock_cycle_id + 1, last_stock_reset = NOW() WHERE id = ?"
                    : "UPDATE products SET stock_cycle_id = stock_cycle_id + 1 WHERE id = ?";
                $stmt_reset = $conn->prepare($sql_reset);
                $stmt_reset->bind_param("i", $current_product_id);
                if (!$stmt_reset->execute()) throw new Exception("Gagal auto reset limit (variasi): " . $stmt_reset->error);
                $stmt_reset->close();
            }

        } else {
            // JIKA VARIASI DIMATIKAN
            if ($product_id > 0) {
                $res_del = $conn->query("SELECT image FROM product_variations WHERE product_id = $product_id");
                while ($row_del = $res_del->fetch_assoc()) {
                    if ($row_del['image'] && file_exists(UPLOAD_DIR_PRODUK . $row_del['image'])) {
                        unlink(UPLOAD_DIR_PRODUK . $row_del['image']);
                    }
                }
                $conn->query("DELETE FROM product_variations WHERE product_id = $product_id");
            }
        }

        $conn->commit();
        set_flashdata('success', 'Produk berhasil disimpan dengan konfigurasi variasi terbaru.');

    } catch (Exception $e) {
        $conn->rollback();
        set_flashdata('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
    }

    redirect(build_return_url_produk());
}

// --- LOGIKA DELETE PRODUK (SOFT DELETE) ---
if (isset($_POST['delete_product'])) {
    $product_id = (int)$_POST['product_id'];
    if ($product_id > 0) {
        $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            set_flashdata('success', 'Produk berhasil dipindahkan ke Non-Aktif (Diarsipkan).');
        } else {
            set_flashdata('error', 'Gagal menonaktifkan produk: ' . $conn->error);
        }
        $stmt->close();
    }
    redirect(build_return_url_produk());
}

// --- LOGIKA TOGGLE STATUS PRODUK ---
if (isset($_POST['action']) && $_POST['action'] === 'toggle_product_status') {
    $product_id = (int)($_POST['product_id'] ?? 0);
    $new_status = (int)($_POST['new_status'] ?? 0); 
    
    if ($product_id > 0 && ($new_status === 0 || $new_status === 1)) {
        $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $product_id);
        if ($stmt->execute()) {
            set_flashdata('success', 'Status produk berhasil diperbarui.');
        } else {
            set_flashdata('error', 'Gagal update status: ' . $conn->error);
        }
        $stmt->close();
    }
    redirect(build_return_url_produk());
}

// --- LOGIKA RESET LIMIT PEMBELIAN (PENTING: BAGIAN INI HILANG SEBELUMNYA) ---
if (isset($_POST['reset_limit'])) {
    $product_id = (int)$_POST['product_id'];
    if ($product_id > 0) {
        // Increment stock_cycle_id agar history pembelian user dianggap kadaluarsa untuk produk ini
        // last_stock_reset bersifat opsional (DB lama mungkin belum punya kolom ini).
        $has_last_stock_reset = column_exists($conn, 'products', 'last_stock_reset');
        $sql = $has_last_stock_reset
            ? "UPDATE products SET stock_cycle_id = stock_cycle_id + 1, last_stock_reset = NOW() WHERE id = ?"
            : "UPDATE products SET stock_cycle_id = stock_cycle_id + 1 WHERE id = ?";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            set_flashdata('success', 'Limit pembelian user untuk produk ini berhasil di-reset.');
        } else {
            set_flashdata('error', 'Gagal mereset limit: ' . $conn->error);
        }
        $stmt->close();
    }
    // Redirect kembali ke tabel produk
    redirect(build_return_url_produk());
}

// --- LOGIKA BULK STOCK UPDATE ---
if (isset($_POST['bulk_stock_update'])) {
    $product_ids_map = $_POST['product_id'] ?? [];
    $stock_values_map = $_POST['stock_value'] ?? [];
    $update_count = 0;
    $valid_updates = [];

    foreach ($product_ids_map as $product_id_key => $id_value) {
        $id = (int)$id_value;
        $stock_to_add = (int)($stock_values_map[$product_id_key] ?? 0); 
        if ($id > 0 && $stock_to_add > 0) $valid_updates[$id] = $stock_to_add;
    }

    if (!empty($valid_updates)) {
        $conn->begin_transaction();
        try {
            // last_stock_reset bersifat opsional (DB lama mungkin belum punya kolom ini).
            $has_last_stock_reset = column_exists($conn, 'products', 'last_stock_reset');
            $sql = $has_last_stock_reset
                ? "UPDATE products SET stock = stock + ?, stock_cycle_id = stock_cycle_id + 1, last_stock_reset = NOW() WHERE id = ?"
                : "UPDATE products SET stock = stock + ?, stock_cycle_id = stock_cycle_id + 1 WHERE id = ?";
            $stmt = $conn->prepare($sql);
            foreach ($valid_updates as $product_id => $stock_to_add) {
                $stmt->bind_param("ii", $stock_to_add, $product_id);
                $stmt->execute();
                $update_count++;
            }
            $conn->commit();
            set_flashdata('success', "Berhasil menambahkan stok pada $update_count produk.");
            $stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            set_flashdata('error', 'Gagal update stok massal.');
        }
    } else {
        set_flashdata('warning', 'Tidak ada data stok valid yang dipilih.');
    }
    redirect('/admin/admin.php?page=input_stok');
}

// --- LOGIKA SETTINGS TOKO ---
if (isset($_POST['save_settings'])) {
    $keys = ['store_name', 'store_description', 'store_address', 'store_phone', 'store_email', 'store_facebook', 'store_tiktok'];
    foreach ($keys as $key) {
        if (isset($_POST[$key])) update_or_insert_setting($conn, $key, sanitize_input($_POST[$key]));
    }
    if (isset($_FILES['store_logo']) && $_FILES['store_logo']['error'] === UPLOAD_ERR_OK) {
        $new_logo = upload_image_file($_FILES['store_logo'], UPLOAD_DIR_SETTINGS);
        if ($new_logo) {
            $old = get_setting($conn, 'store_logo');
            if ($old && file_exists(UPLOAD_DIR_SETTINGS . $old)) unlink(UPLOAD_DIR_SETTINGS . $old);
            update_or_insert_setting($conn, 'store_logo', $new_logo);
        }
    }
    set_flashdata('success', 'Pengaturan toko diperbarui.');
    redirect('/admin/admin.php?page=pengaturan_toko');
}

// --- LOGIKA MAINTENANCE MODE ---
if (isset($_POST['save_maintenance_settings'])) {
    $mode = sanitize_input($_POST['maintenance_mode'] ?? 'off'); 
    $msg = sanitize_input($_POST['maintenance_message']);
    if (empty($msg)) $msg = 'Situs sedang maintenance.';
    update_or_insert_setting($conn, 'maintenance_mode', $mode);
    update_or_insert_setting($conn, 'maintenance_message', $msg);
    load_settings($conn); 
    set_flashdata('success', 'Pengaturan Maintenance diperbarui.');
    redirect('/admin/admin.php?page=mode_maintenance');
}

// --- LOGIKA KATEGORI ---
if (isset($_POST['save_kategori'])) {
    $id = (int)($_POST['id'] ?? 0);
    $name = sanitize_input($_POST['name']);
    if (!empty($name)) {
        if ($id > 0) {
            $conn->query("UPDATE categories SET name = '$name' WHERE id = $id");
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            $stmt->execute();
        }
    }
    redirect('/admin/admin.php?page=kategori');
}
if (isset($_POST['delete_kategori'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $conn->query("DELETE FROM categories WHERE id = $id");
    redirect('/admin/admin.php?page=kategori');
}

// --- LOGIKA BANNER ---
if (isset($_POST['save_banner'])) {
    $id = (int)($_POST['id'] ?? 0);
    $title = sanitize_input($_POST['title']);
    $link = sanitize_input($_POST['link_url']);
    $active = isset($_POST['is_active']) ? 1 : 0;
    $img = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $img = upload_image_file($_FILES['image'], UPLOAD_DIR_BANNER);
    }
    
    if ($id > 0) {
        $sql = "UPDATE banners SET title='$title', link_url='$link', is_active=$active";
        if($img) $sql .= ", image='$img'";
        $sql .= " WHERE id=$id";
        $conn->query($sql);
    } else {
        $stmt = $conn->prepare("INSERT INTO banners (title, link_url, is_active, image) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $title, $link, $active, $img);
        $stmt->execute();
    }
    redirect('/admin/admin.php?page=banner');
}
if (isset($_POST['delete_banner'])) {
    $id = (int)($_POST['id'] ?? 0);
    if($id > 0) $conn->query("DELETE FROM banners WHERE id = $id");
    redirect('/admin/admin.php?page=banner');
}

// --- LOGIKA FAQ ---
if (isset($_POST['save_faq'])) {
    $id = (int)($_POST['id'] ?? 0);
    $q = sanitize_input($_POST['question']);
    $a = sanitize_input($_POST['answer']);
    $sort = (int)($_POST['sort_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($q) && !empty($a)) {
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE faq SET question=?, answer=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->bind_param("ssiii", $q, $a, $sort, $active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO faq (question, answer, sort_order, is_active) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssii", $q, $a, $sort, $active);
        }
        $stmt->execute();
        set_flashdata('success', 'FAQ disimpan.');
    }
    redirect('/admin/admin.php?page=faq');
}
if (isset($_POST['delete_faq'])) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $conn->query("DELETE FROM faq WHERE id = $id");
        set_flashdata('success', 'FAQ dihapus.');
    }
    redirect('/admin/admin.php?page=faq');
}

// --- LOGIKA IMPORT RESI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_import') {
    if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
        include 'import/import_processor.php';
    } else {
        set_flashdata('error', 'Gagal upload file import.');
        redirect('admin.php?page=import_resi');
    }
    exit;
}

// --- LOGIKA MANUAL MATCH RESI ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_match') {
    $resi = sanitize_input($_POST['tracking_number'] ?? '');
    $oid = (int)($_POST['order_id'] ?? 0);

    if (!empty($resi) && $oid > 0) {
        $conn->begin_transaction();
        try {
            $order = $conn->query("SELECT tracking_number FROM orders WHERE id = $oid")->fetch_assoc();
            if ($order) {
                $cur = $order['tracking_number'];
                $list = array_map('trim', array_filter(explode(',', $cur ?? '')));
                if (!in_array($resi, $list)) {
                    $new = empty($cur) ? $resi : $cur . ',' . $resi;
                    $stmt = $conn->prepare("UPDATE orders SET tracking_number=?, status='shipped' WHERE id=?");
                    $stmt->bind_param("si", $new, $oid);
                    $stmt->execute();
                    $conn->commit();
                    set_flashdata('success', "Resi $resi berhasil ditambahkan.");
                } else {
                    set_flashdata('warning', "Resi sudah ada.");
                }
            }
        } catch (Exception $e) {
            $conn->rollback();
            set_flashdata('error', 'Error: ' . $e->getMessage());
        }
    }
    redirect('/admin/admin.php?page=database_resi');
}


// =================================================================================
// TAMPILAN HALAMAN ADMIN (VIEW)
// =================================================================================
$page_name = $_GET['page'] ?? 'dashboard';

// Judul Halaman Map
$titles = [
    'dashboard' => 'Dashboard', 'pesanan' => 'Manajemen Pesanan', 'log_pembatalan' => 'Log Pembatalan',
    'import_resi' => 'Import Resi', 'database_resi' => 'Database Resi', 'pantau_resi' => 'Pantau Resi',
    'produk' => 'Manajemen Produk', 'input_stok' => 'Input Stok Cepat', 'kategori' => 'Kategori',
    'banner' => 'Banner', 'faq' => 'FAQ', 'rekap' => 'Rekap Laporan',
    'pengaturan_toko' => 'Pengaturan Toko', 'pengaturan_user' => 'Pengguna', 'mode_maintenance' => 'Maintenance'
];
$current_title = $titles[$page_name] ?? 'Halaman Admin';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= $current_title ?></title>
    <?php
    $logo_name = get_setting($conn, 'store_logo');
    $favicon_path = BASE_URL . '/assets/images/settings/' . ($logo_name ?: 'default_logo.png');
    ?>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($favicon_path) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($favicon_path) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .sidebar { min-width: 260px; }
        /* Transisi halus */
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    </style>
</head>
<body class="bg-gray-100 text-gray-800">

    <div class="flex min-h-screen relative">
        <!-- Mobile Toggle -->
        <button id="sidebar-toggle" class="fixed top-4 left-4 z-50 p-2 bg-indigo-700 text-white rounded md:hidden shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Sidebar -->
        <aside id="admin-sidebar" class="sidebar bg-slate-900 text-slate-100 flex flex-col w-64 fixed inset-y-0 left-0 z-40 transform -translate-x-full md:translate-x-0 transition-transform duration-300 shadow-xl">
            <div class="p-6 border-b border-slate-700 bg-slate-900">
                <h1 class="text-xl font-bold tracking-wide text-white"><i class="fas fa-user-shield mr-2"></i> Admin Panel</h1>
            </div>
            
            <nav class="flex-grow p-4 space-y-4 overflow-y-auto custom-scrollbar">
                <a href="?page=dashboard" class="flex items-center px-4 py-3 rounded-lg <?= $page_name=='dashboard'?'bg-indigo-600 shadow-lg text-white':'hover:bg-slate-800 text-slate-300' ?>">
                    <i class="fas fa-home w-6"></i> <span class="font-medium">Dashboard</span>
                </a>

                <!-- Group: Pesanan -->
                <div>
                    <h3 class="px-4 text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Transaksi</h3>
                    <div class="space-y-1">
                        <a href="?page=pesanan" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='pesanan'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-shopping-cart w-6"></i> Pesanan
                        </a>
                        <a href="?page=log_pembatalan" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='log_pembatalan'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-history w-6"></i> Log Pembatalan
                        </a>
                    </div>
                </div>

                <!-- Group: Resi -->
                <div>
                    <h3 class="px-4 text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Pengiriman</h3>
                    <div class="space-y-1">
                        <a href="?page=import_resi" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='import_resi'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-file-import w-6"></i> Import Resi
                        </a>
                        <a href="?page=database_resi" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='database_resi'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-database w-6"></i> Database Resi
                        </a>
                    </div>
                </div>

                <!-- Group: Katalog -->
                <div>
                    <h3 class="px-4 text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Katalog</h3>
                    <div class="space-y-1">
                        <a href="?page=produk" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='produk'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-box w-6"></i> Produk
                        </a>
                        <a href="?page=input_stok" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='input_stok'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-cubes w-6"></i> Input Stok Cepat
                        </a>
                        <a href="?page=kategori" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='kategori'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-tags w-6"></i> Kategori
                        </a>
                        <a href="?page=banner" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='banner'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-images w-6"></i> Banner
                        </a>
                    </div>
                </div>

                <!-- Group: Sistem -->
                <div>
                    <h3 class="px-4 text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Sistem</h3>
                    <div class="space-y-1">
                        <a href="?page=pengaturan_toko" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='pengaturan_toko'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-cog w-6"></i> Pengaturan Toko
                        </a>
                        <a href="?page=rekap" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='rekap'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-chart-line w-6"></i> Laporan
                        </a>
                        <a href="?page=mode_maintenance" class="flex items-center px-4 py-2 rounded-lg text-sm <?= $page_name=='mode_maintenance'?'bg-slate-800 text-indigo-400':'hover:bg-slate-800 text-slate-400' ?>">
                            <i class="fas fa-tools w-6"></i> Maintenance
                        </a>
                    </div>
                </div>
            </nav>

            <div class="p-4 bg-slate-800 border-t border-slate-700">
                <a href="<?= BASE_URL ?>/login/logout.php" class="flex items-center justify-center w-full px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded transition shadow">
                    <i class="fas fa-sign-out-alt mr-2"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 md:ml-64 p-4 md:p-8 pt-16 md:pt-8 overflow-y-auto">
            <?php flash_message(); ?>
            
            <header class="flex justify-between items-center mb-8 fade-in">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800"><?= $current_title ?></h2>
                    <p class="text-sm text-gray-500 mt-1">Selamat datang kembali, Administrator.</p>
                </div>
                <div class="hidden md:block">
                    <span class="bg-indigo-100 text-indigo-800 px-3 py-1 rounded-full text-xs font-semibold">
                        <?= date('d F Y') ?>
                    </span>
                </div>
            </header>

            <div class="fade-in bg-white rounded-xl shadow-sm border border-gray-100 min-h-[500px] p-6">
                <?php
                $files = [
                    'dashboard' => 'dashboard.php', 
                    'pesanan' => 'pesanan/pesanan.php', 
                    'log_pembatalan' => 'log_pembatalan/log_pembatalan.php',
                    'import_resi' => 'import/import_resi.php', 
                    'database_resi' => 'import/database_resi.php', 
                    'pantau_resi' => 'import/pantau_resi.php', 
                    'produk' => 'produk/produk.php',
                    'input_stok' => 'produk/input_stok.php', 
                    'kategori' => 'kategori/kategori.php', 
                    'banner' => 'banner/banner.php', 
                    'faq' => 'faq/faq.php', 
                    'rekap' => 'rekap/rekap.php',
                    'pengaturan_toko' => 'pengaturan/pengaturan.php', 
                    'pengaturan_user' => 'user/user.php',
                    'mode_maintenance' => 'pengaturan/maintenance.php',
                ];

                if (array_key_exists($page_name, $files)) {
                    $f = $files[$page_name];
                    if (file_exists($f)) include $f;
                    else echo "<div class='text-center py-20 text-gray-400'><i class='fas fa-exclamation-triangle text-4xl mb-4'></i><br>File $f belum dibuat.</div>";
                } else {
                    echo "<div class='text-center py-20 text-gray-400'>Halaman tidak ditemukan.</div>";
                }
                ?>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Toggle Sidebar Mobile
        const btn = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('admin-sidebar');
        btn.addEventListener('click', () => {
            sidebar.classList.toggle('-translate-x-full');
        });
    </script>
</body>
</html>





