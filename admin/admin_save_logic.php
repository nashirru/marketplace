<?php
// File: admin/admin_save_logic.php (Atau kode untuk dimasukkan ke admin.php)
// INI ADALAH LOGIKA BACKEND UNTUK MENANGANI FORM DI ATAS.
// Silakan copy-paste kode di dalam case 'save_product' pada file admin.php Anda.

if ($action == 'save_product') {
    $product_id = $_POST['product_id'] ?? '';
    $name = sanitize_input($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $description = $_POST['description']; // Deskripsi mungkin mengandung HTML safe
    
    // Logika Purchase Limit
    $limit_type = $_POST['limit_type'];
    $purchase_limit = ($limit_type == 'limited') ? (int)$_POST['purchase_limit'] : 0; // 0 = unlimited di DB

    // Cek Variasi Flag
    $has_variation = isset($_POST['has_variation']) && $_POST['has_variation'] == '1' ? 1 : 0;
    
    // Validasi Dasar
    if (empty($name) || empty($category_id)) {
        set_flashdata('error', 'Nama dan Kategori wajib diisi.');
        redirect('/admin/admin.php?page=produk&action=' . ($product_id ? 'edit&id='.$product_id : 'add'));
    }

    // --- HANDLING GAMBAR UTAMA (SAMPUL) ---
    $image_name = '';
    // Jika edit, ambil gambar lama dulu
    if (!empty($product_id)) {
        $old_prod = get_product_by_id($conn, $product_id);
        $image_name = $old_prod['image'];
    }

    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $upload_dir = __DIR__ . '/../assets/images/produk/';
        $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($file_ext, $allowed)) {
            // Generate nama file unik
            $new_filename = uniqid('prod_') . '.' . $file_ext;
            $destination = $upload_dir . $new_filename;
            
            // GUNAKAN FUNGSI KOMPRESI ANDA
            if (compressImage($_FILES['image']['tmp_name'], $destination, 80)) {
                // Hapus gambar lama jika ada
                if (!empty($image_name) && file_exists($upload_dir . $image_name)) {
                    unlink($upload_dir . $image_name);
                }
                $image_name = $new_filename;
            } else {
                set_flashdata('error', 'Gagal mengompres gambar utama.');
                redirect('/admin/admin.php?page=produk');
            }
        }
    }

    // --- MENENTUKAN HARGA & STOK UTAMA ---
    // Jika variasi aktif, harga utama = harga terendah variasi, stok = total stok variasi
    // Jika tidak, ambil dari input form standar
    $final_price = 0;
    $final_stock = 0;

    if (!$has_variation) {
        $final_price = str_replace(['.', ','], '', $_POST['price']);
        $final_stock = (int)$_POST['stock'];
    }

    // Mulai Transaksi Database (PENTING untuk integritas data variasi)
    $conn->begin_transaction();

    try {
        if (!empty($product_id)) {
            // UPDATE EXISTING PRODUCT
            $stmt = $conn->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, stock=?, purchase_limit=?, is_active=1, image=?, has_variation=? WHERE id=?");
            $stmt->bind_param("issdiisisi", $category_id, $name, $description, $final_price, $final_stock, $purchase_limit, $image_name, $has_variation, $product_id);
            $stmt->execute();
            $current_id = $product_id;
        } else {
            // INSERT NEW PRODUCT
            $stmt = $conn->prepare("INSERT INTO products (category_id, name, description, price, stock, purchase_limit, is_active, image, has_variation) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)");
            $stmt->bind_param("issdiisi", $category_id, $name, $description, $final_price, $final_stock, $purchase_limit, $image_name, $has_variation);
            $stmt->execute();
            $current_id = $conn->insert_id;
        }
        $stmt->close();

        // --- LOGIKA PENYIMPANAN VARIASI ---
        if ($has_variation) {
            $var_names = $_POST['variation_name'] ?? [];
            $var_prices = $_POST['variation_price'] ?? [];
            $var_stocks = $_POST['variation_stock'] ?? [];
            $var_ids = $_POST['variation_id'] ?? []; // ID untuk update
            $existing_imgs = $_POST['existing_variation_image'] ?? [];

            $min_price = null;
            $total_stock_calculated = 0;
            
            // Ambil ID variasi yang ada di DB untuk produk ini (untuk handle penghapusan)
            $existing_var_ids_db = [];
            if (!empty($product_id)) {
                $q_ids = $conn->query("SELECT id FROM product_variations WHERE product_id = $product_id");
                while($row_id = $q_ids->fetch_assoc()) $existing_var_ids_db[] = $row_id['id'];
            }
            
            $kept_var_ids = [];

            // Loop setiap input variasi (Maksimal 9 handled by UI, but loop is safe)
            for ($i = 0; $i < count($var_names); $i++) {
                if (empty($var_names[$i])) continue; // Skip empty names

                $v_id = $var_ids[$i] ?? null;
                $v_name = sanitize_input($var_names[$i]);
                $v_price = (float)$var_prices[$i];
                $v_stock = (int)$var_stocks[$i];
                $v_image = $existing_imgs[$i] ?? '';

                // Hitung Min Price & Total Stock
                if ($min_price === null || $v_price < $min_price) $min_price = $v_price;
                $total_stock_calculated += $v_stock;

                // Handle Image Upload Per Variasi
                if (isset($_FILES['variation_image']['name'][$i]) && $_FILES['variation_image']['error'][$i] == 0) {
                    $u_dir_var = __DIR__ . '/../assets/images/produk/';
                    $f_ext_var = strtolower(pathinfo($_FILES['variation_image']['name'][$i], PATHINFO_EXTENSION));
                    
                    if (in_array($f_ext_var, ['jpg', 'jpeg', 'png', 'webp'])) {
                        $new_fname_var = uniqid('var_'.$current_id.'_') . '.' . $f_ext_var;
                        $dest_var = $u_dir_var . $new_fname_var;
                        
                        if (compressImage($_FILES['variation_image']['tmp_name'][$i], $dest_var, 80)) {
                            // Hapus gambar variasi lama jika diganti
                            if (!empty($v_image) && file_exists($u_dir_var . $v_image)) {
                                unlink($u_dir_var . $v_image);
                            }
                            $v_image = $new_fname_var;
                        }
                    }
                }

                if (!empty($v_id) && in_array($v_id, $existing_var_ids_db)) {
                    // Update Variasi
                    $stmt_v = $conn->prepare("UPDATE product_variations SET name=?, price=?, stock=?, image=? WHERE id=? AND product_id=?");
                    $stmt_v->bind_param("sdisii", $v_name, $v_price, $v_stock, $v_image, $v_id, $current_id);
                    $stmt_v->execute();
                    $kept_var_ids[] = $v_id;
                } else {
                    // Insert Variasi Baru
                    $stmt_v = $conn->prepare("INSERT INTO product_variations (product_id, name, price, stock, image) VALUES (?, ?, ?, ?, ?)");
                    $stmt_v->bind_param("isdis", $current_id, $v_name, $v_price, $v_stock, $v_image);
                    $stmt_v->execute();
                    $kept_var_ids[] = $conn->insert_id;
                }
            }

            // Hapus variasi yang tidak ada di form (User menghapus baris di UI)
            if (!empty($product_id)) {
                $ids_to_delete = array_diff($existing_var_ids_db, $kept_var_ids);
                if (!empty($ids_to_delete)) {
                    $ids_str = implode(',', $ids_to_delete);
                    // Hapus gambar fisiknya dulu (opsional, good practice)
                    $res_img = $conn->query("SELECT image FROM product_variations WHERE id IN ($ids_str)");
                    while($row_img = $res_img->fetch_assoc()) {
                        if($row_img['image'] && file_exists(__DIR__ . '/../assets/images/produk/' . $row_img['image'])) {
                            unlink(__DIR__ . '/../assets/images/produk/' . $row_img['image']);
                        }
                    }
                    $conn->query("DELETE FROM product_variations WHERE id IN ($ids_str)");
                }
            }

            // Update Produk Utama dengan Total Stok & Harga Terendah
            $final_price_update = $min_price ?? 0;
            $conn->query("UPDATE products SET price = $final_price_update, stock = $total_stock_calculated WHERE id = $current_id");
            
        } else {
            // Jika mode variasi dimatikan pada produk yang tadinya punya variasi
            // Hapus semua data variasi
            if (!empty($product_id)) {
                $conn->query("DELETE FROM product_variations WHERE product_id = $product_id");
            }
        }

        $conn->commit();
        set_flashdata('success', 'Produk berhasil disimpan dengan konfigurasi variasi terbaru.');
        redirect('/admin/admin.php?page=produk');

    } catch (Exception $e) {
        $conn->rollback();
        set_flashdata('error', 'Terjadi kesalahan sistem: ' . $e->getMessage());
        redirect('/admin/admin.php?page=produk');
    }
}
?>