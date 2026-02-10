<?php
// File: api/api-produk.php
// Programmer IQ 180: Ultimate Product Management API
// Fixes: Kategori Dropdown, Insert Database Ghosting, Image Upload Robustness

require_once 'api_helper.php';

// Validasi Akses
api_check_admin();

// Setup Direktori
define('UPLOAD_DIR_PRODUK', __DIR__ . '/../assets/images/produk/');

// Pastikan folder ada dan writable
if (!is_dir(UPLOAD_DIR_PRODUK)) {
    if (!mkdir(UPLOAD_DIR_PRODUK, 0755, true)) {
        send_response(false, 'Gagal membuat direktori upload produk.', [], 500);
    }
}

// Router Action
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handle_list_products($conn);
            break;
        case 'list_categories':
            handle_list_categories($conn);
            break;
        case 'detail':
            handle_detail_product($conn);
            break;
        case 'save':
            handle_save_product($conn);
            break;
        case 'delete':
            handle_delete_product($conn);
            break;
        case 'toggle_status':
            handle_toggle_status($conn);
            break;
        default:
            send_response(false, "Action '$action' tidak ditemukan.", [], 400);
    }
} catch (Exception $e) {
    // Tangkap unhandled exception
    send_response(false, 'Unexpected Error: ' . $e->getMessage(), [], 500);
}

// =================================================================================
// 1. HANDLER: LIST KATEGORI (FIXED)
// =================================================================================
function handle_list_categories($conn) {
    // Debugging: Pastikan query benar-benar jalan
    try {
        $data = [];
        $query = "SELECT id, name FROM categories ORDER BY name ASC";
        $result = $conn->query($query);
        
        if (!$result) {
            throw new Exception("Query Error: " . $conn->error);
        }
        
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => (int)$row['id'],
                'name' => htmlspecialchars_decode($row['name']) // Decode jika ada karakter spesial
            ];
        }
        
        // Selalu return array, meskipun kosong
        send_response(true, 'Data kategori berhasil diambil.', $data);
        
    } catch (Exception $e) {
        send_response(false, 'Gagal load kategori: ' . $e->getMessage(), [], 500);
    }
}

// =================================================================================
// 2. HANDLER: LIST PRODUK (PAGINATION & SEARCH)
// =================================================================================
function handle_list_products($conn) {
    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = max(1, (int)($_GET['limit'] ?? 10));
    $offset = ($page - 1) * $limit;
    
    $search = $_GET['q'] ?? '';
    $cat_id = (int)($_GET['category'] ?? 0);
    
    // Bangun Query Kondisional
    $where = ["p.is_active IN (0, 1)"]; // Default tampilkan aktif & non-aktif (kecuali soft delete flag lain)
    $params = [];
    $types = "";
    
    if (!empty($search)) {
        $where[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm; 
        $params[] = $searchTerm;
        $types .= "ss";
    }
    
    if ($cat_id > 0) {
        $where[] = "p.category_id = ?";
        $params[] = $cat_id;
        $types .= "i";
    }
    
    $whereSQL = implode(" AND ", $where);
    
    // Hitung Total Data
    $countQuery = "SELECT COUNT(*) as total FROM products p WHERE $whereSQL";
    $stmtCount = $conn->prepare($countQuery);
    if (!empty($params)) {
        $stmtCount->bind_param($types, ...$params);
    }
    $stmtCount->execute();
    $total = $stmtCount->get_result()->fetch_assoc()['total'];
    $stmtCount->close();
    
    // Ambil Data Produk
    // Joins dengan categories untuk nama kategori
    $dataQuery = "
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE $whereSQL 
        ORDER BY p.created_at DESC 
        LIMIT ? OFFSET ?
    ";
    
    $stmt = $conn->prepare($dataQuery);
    
    // Tambahkan limit & offset ke params
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['price'] = (float)$row['price'];
        $row['stock'] = (int)$row['stock'];
        $row['has_variation'] = (bool)$row['has_variation'];
        $row['image_url'] = !empty($row['image']) ? BASE_URL . '/assets/images/produk/' . $row['image'] : 'https://via.placeholder.com/150';
        
        // Logika Variasi untuk Display Harga
        if ($row['has_variation']) {
            $vSum = $conn->query("SELECT MIN(price) as min_p, MAX(price) as max_p, SUM(stock) as total_s FROM product_variations WHERE product_id = {$row['id']}")->fetch_assoc();
            $row['variation_summary'] = [
                'min_price' => (float)($vSum['min_p'] ?? 0),
                'max_price' => (float)($vSum['max_p'] ?? 0),
                'total_stock' => (int)($vSum['total_s'] ?? 0)
            ];
            // Override stock display dengan total variasi
            $row['stock'] = $row['variation_summary']['total_stock'];
        }
        
        $products[] = $row;
    }
    
    send_response(true, "List produk halaman $page", [
        'products' => $products,
        'pagination' => [
            'total_items' => (int)$total,
            'total_pages' => ceil($total / $limit),
            'current_page' => $page
        ]
    ]);
}

// =================================================================================
// 3. HANDLER: SAVE PRODUK (CREATE & UPDATE - THE CORE FIX)
// =================================================================================
function handle_save_product($conn) {
    // 1. Validasi Method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_response(false, 'Method Not Allowed', [], 405);
    }

    // 2. Ambil & Sanitasi Input
    $id = (int)($_POST['product_id'] ?? 0);
    $name = api_sanitize($_POST['name'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $description = $_POST['description'] ?? ''; // Deskripsi boleh HTML safe, sanitize basic
    
    // Handle Format Uang (Hapus titik/koma ribuan)
    $price_raw = $_POST['price'] ?? '0';
    $price = (float)str_replace(['.', ','], '', $price_raw);
    
    $stock = (int)($_POST['stock'] ?? 0);
    
    // Limit Logic
    $limit_type = $_POST['limit_type'] ?? 'unlimited';
    $purchase_limit = ($limit_type === 'limited') ? (int)($_POST['purchase_limit'] ?? 0) : 0;
    
    $has_variation = (isset($_POST['has_variation']) && $_POST['has_variation'] == '1') ? 1 : 0;

    // 3. Validasi Wajib
    $errors = [];
    if (empty($name)) $errors[] = "Nama produk wajib diisi.";
    if ($category_id <= 0) $errors[] = "Kategori wajib dipilih.";
    if (!$has_variation && $price <= 0) $errors[] = "Harga produk wajib diisi (jika bukan variasi).";

    if (!empty($errors)) {
        send_response(false, implode(" ", $errors), $errors, 400);
    }

    // 4. Mulai Transaksi Database (PENTING AGAR DATA KONSISTEN)
    $conn->begin_transaction();

    try {
        // --- A. Handle Image Upload ---
        $image_filename = null;
        
        // Ambil gambar lama jika ini edit
        if ($id > 0) {
            $old_data = $conn->query("SELECT image FROM products WHERE id = $id")->fetch_assoc();
            $image_filename = $old_data['image'] ?? null;
        }

        // Proses Upload Baru
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['image'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];
            
            if (!in_array($ext, $allowed)) {
                throw new Exception("Format gambar utama tidak valid (Gunakan: jpg, png, webp).");
            }
            
            // Generate nama file unik
            $new_filename = 'prod_' . time() . '_' . uniqid() . '.' . $ext;
            $target_path = UPLOAD_DIR_PRODUK . $new_filename;
            
            // Kompresi atau Move
            $upload_success = false;
            if (function_exists('compressImage')) {
                // Gunakan kompresor IQ 180 kamu
                $upload_success = compressImage($file['tmp_name'], $target_path, 80);
            } else {
                // Fallback standard move
                $upload_success = move_uploaded_file($file['tmp_name'], $target_path);
            }
            
            if (!$upload_success) {
                // Cek permission folder
                if (!is_writable(UPLOAD_DIR_PRODUK)) {
                    throw new Exception("Server Error: Folder upload tidak writable.");
                }
                throw new Exception("Gagal mengupload/kompresi gambar utama.");
            }
            
            // Hapus gambar lama jika ada dan berbeda
            if ($image_filename && file_exists(UPLOAD_DIR_PRODUK . $image_filename)) {
                @unlink(UPLOAD_DIR_PRODUK . $image_filename);
            }
            
            $image_filename = $new_filename;
            
        } elseif ($id === 0 && empty($image_filename)) {
             // Jika Insert Baru & Tidak ada file upload
             throw new Exception("Foto sampul produk wajib diupload untuk produk baru.");
        }

        // --- B. Insert / Update Product Data ---
        
        // Pastikan null handling untuk query
        $final_image = $image_filename;
        $active_status = 1; // Default active saat create/update

        // Perbaiki Query untuk mencakup kolom default DB yang NOT NULL
        // DB Schema: stock_cycle_id default 1, user_purchase_limit default 0. 
        // Kita tidak perlu insert kolom itu, biarkan MySQL handle defaultnya.
        // Tapi kita harus pastikan kolom yang kita insert tidak salah tipe.

        if ($id > 0) {
            // MODE UPDATE
            $sql = "UPDATE products SET 
                    category_id = ?, 
                    name = ?, 
                    description = ?, 
                    price = ?, 
                    stock = ?, 
                    purchase_limit = ?, 
                    image = ?, 
                    has_variation = ? 
                    WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            // Types: i (cat), s (name), s (desc), d (price), i (stock), i (limit), s (img), i (has_var), i (id)
            $stmt->bind_param("issdiisii", 
                $category_id, $name, $description, $price, $stock, 
                $purchase_limit, $final_image, $has_variation, $id
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Database Update Error: " . $stmt->error);
            }
            $stmt->close();
            $product_id = $id;
            $msg_success = "Produk berhasil diperbarui.";

        } else {
            // MODE INSERT
            $sql = "INSERT INTO products 
                    (category_id, name, description, price, stock, purchase_limit, image, has_variation, is_active, user_purchase_limit, stock_cycle_id) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, 1)";
            
            $stmt = $conn->prepare($sql);
            // Types: i, s, s, d, i, i, s, i
            $stmt->bind_param("issdiisi", 
                $category_id, $name, $description, $price, $stock, 
                $purchase_limit, $final_image, $has_variation
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Database Insert Error: " . $stmt->error);
            }
            $product_id = $conn->insert_id; // Ambil ID produk baru
            $stmt->close();
            $msg_success = "Produk baru berhasil ditambahkan.";
        }

        // --- C. Handle Variations ---
        
        if ($has_variation) {
            $v_names = $_POST['variation_name'] ?? [];
            $v_prices = $_POST['variation_price'] ?? [];
            $v_stocks = $_POST['variation_stock'] ?? [];
            $v_ids = $_POST['variation_id'] ?? [];
            $v_existing_imgs = $_POST['existing_variation_image'] ?? [];
            
            // Validasi data variasi minimal 1
            if (empty($v_names)) {
                throw new Exception("Jika mode variasi aktif, minimal harus ada 1 variasi.");
            }

            $processed_ids = []; // Untuk melacak ID mana yang dipertahankan (sisanya dihapus)
            
            // Variabel akumulasi untuk update produk induk
            $accumulated_stock = 0;
            $min_price_var = null;

            foreach ($v_names as $i => $v_name) {
                if (empty($v_name)) continue;

                $vid = (int)($v_ids[$i] ?? 0);
                $vprice = (float)str_replace(['.', ','], '', $v_prices[$i]);
                $vstock = (int)$v_stocks[$i];
                
                // Cari harga terendah untuk display produk induk
                if ($min_price_var === null || $vprice < $min_price_var) {
                    $min_price_var = $vprice;
                }
                $accumulated_stock += $vstock;

                // Handle Image Variasi
                $v_img_name = $v_existing_imgs[$i] ?? null;
                
                if (isset($_FILES['variation_image']['name'][$i]) && $_FILES['variation_image']['error'][$i] === UPLOAD_ERR_OK) {
                    $vf_tmp = $_FILES['variation_image']['tmp_name'][$i];
                    $vf_name = $_FILES['variation_image']['name'][$i];
                    $vf_ext = strtolower(pathinfo($vf_name, PATHINFO_EXTENSION));
                    
                    $new_v_fname = 'var_' . $product_id . '_' . uniqid() . '.' . $vf_ext;
                    $v_target = UPLOAD_DIR_PRODUK . $new_v_fname;
                    
                    if (function_exists('compressImage')) {
                        if (compressImage($vf_tmp, $v_target, 80)) {
                            $v_img_name = $new_v_fname;
                        }
                    } else {
                         if (move_uploaded_file($vf_tmp, $v_target)) {
                            $v_img_name = $new_v_fname;
                         }
                    }
                }

                // Insert / Update Variasi
                if ($vid > 0) {
                    // Cek kepemilikan
                    $stmtCheck = $conn->prepare("SELECT id FROM product_variations WHERE id = ? AND product_id = ?");
                    $stmtCheck->bind_param("ii", $vid, $product_id);
                    $stmtCheck->execute();
                    if ($stmtCheck->get_result()->num_rows > 0) {
                        // Update
                        $stmtUpd = $conn->prepare("UPDATE product_variations SET name=?, price=?, stock=?, image=? WHERE id=?");
                        $stmtUpd->bind_param("sdisi", $v_name, $vprice, $vstock, $v_img_name, $vid);
                        $stmtUpd->execute();
                        $processed_ids[] = $vid;
                    }
                    $stmtCheck->close();
                } else {
                    // Insert
                    $stmtIns = $conn->prepare("INSERT INTO product_variations (product_id, name, price, stock, image) VALUES (?, ?, ?, ?, ?)");
                    $stmtIns->bind_param("isdis", $product_id, $v_name, $vprice, $vstock, $v_img_name);
                    $stmtIns->execute();
                    $processed_ids[] = $conn->insert_id;
                }
            }
            
            // Hapus variasi yang tidak ada di list (User menghapus baris di frontend)
            if ($id > 0) {
                // Ambil semua ID variasi yang ada di DB untuk produk ini
                $currentVars = $conn->query("SELECT id, image FROM product_variations WHERE product_id = $product_id");
                while ($cv = $currentVars->fetch_assoc()) {
                    if (!in_array($cv['id'], $processed_ids)) {
                        // Hapus File Gambar
                        if ($cv['image'] && file_exists(UPLOAD_DIR_PRODUK . $cv['image'])) {
                            @unlink(UPLOAD_DIR_PRODUK . $cv['image']);
                        }
                        // Hapus Data DB
                        $conn->query("DELETE FROM product_variations WHERE id = " . $cv['id']);
                    }
                }
            }

            // Update Stok & Harga Produk Induk berdasarkan Agregasi Variasi
            $final_price_master = $min_price_var ?? 0;
            $conn->query("UPDATE products SET stock = $accumulated_stock, price = $final_price_master WHERE id = $product_id");

        } else {
            // Jika Mode Variasi OFF, pastikan tabel variasi bersih untuk produk ini
            if ($id > 0) {
                $delVars = $conn->query("SELECT image FROM product_variations WHERE product_id = $id");
                while ($dv = $delVars->fetch_assoc()) {
                    if ($dv['image'] && file_exists(UPLOAD_DIR_PRODUK . $dv['image'])) {
                        @unlink(UPLOAD_DIR_PRODUK . $dv['image']);
                    }
                }
                $conn->query("DELETE FROM product_variations WHERE product_id = $id");
            }
        }

        // --- COMMIT TRANSAKSI ---
        $conn->commit();
        
        send_response(true, $msg_success);

    } catch (Exception $e) {
        // --- ROLLBACK JIKA ERROR ---
        $conn->rollback();
        
        // Hapus file utama yang terlanjur ke-upload jika insert baru gagal
        if ($id === 0 && isset($new_filename) && file_exists(UPLOAD_DIR_PRODUK . $new_filename)) {
            @unlink(UPLOAD_DIR_PRODUK . $new_filename);
        }
        
        send_response(false, 'Gagal Menyimpan: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()], 500);
    }
}

// =================================================================================
// 4. HANDLER: DETAIL PRODUK (GET)
// =================================================================================
function handle_detail_product($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_response(false, 'ID Invalid', [], 400);

    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();
    
    if (!$prod) send_response(false, 'Produk tidak ditemukan', [], 404);
    
    // Format Data
    $prod['image_url'] = !empty($prod['image']) ? BASE_URL . '/assets/images/produk/' . $prod['image'] : null;
    $prod['price'] = (float)$prod['price'];
    $prod['stock'] = (int)$prod['stock'];
    
    // Ambil Variasi
    $variations = [];
    if ($prod['has_variation']) {
        $vRes = $conn->query("SELECT * FROM product_variations WHERE product_id = $id ORDER BY id ASC");
        while ($v = $vRes->fetch_assoc()) {
            $v['image_url'] = !empty($v['image']) ? BASE_URL . '/assets/images/produk/' . $v['image'] : null;
            $variations[] = $v;
        }
    }
    
    send_response(true, 'Found', [
        'product' => $prod,
        'variations' => $variations
    ]);
}

// =================================================================================
// 5. HANDLER: DELETE PRODUK (SOFT DELETE)
// =================================================================================
function handle_delete_product($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['product_id'] ?? 0);
    
    if ($id <= 0) send_response(false, 'ID Invalid', [], 400);
    
    // Soft Delete: Set is_active = 0 (Sesuai schema kamu yang tidak punya deleted_at)
    // Atau jika kamu mau hard delete, ganti jadi DELETE FROM
    
    $stmt = $conn->prepare("UPDATE products SET is_active = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        send_response(true, 'Produk berhasil dinonaktifkan (Soft Delete).');
    } else {
        send_response(false, 'Database Error: ' . $conn->error, [], 500);
    }
}

// =================================================================================
// 6. HANDLER: TOGGLE STATUS
// =================================================================================
function handle_toggle_status($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['product_id'] ?? 0);
    $status = isset($input['new_status']) ? (int)$input['new_status'] : -1;
    
    if ($id <= 0 || $status < 0) send_response(false, 'Invalid Params', [], 400);
    
    $stmt = $conn->prepare("UPDATE products SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $status, $id);
    
    if ($stmt->execute()) {
        send_response(true, 'Status produk diperbarui.');
    } else {
        send_response(false, 'Error: ' . $conn->error);
    }
}
?>