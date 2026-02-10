<?php
// File: api/api-kategori.php
// Programmer IQ 180: API Manajemen Kategori
// Menangani CRUD Kategori dengan response JSON standar

require_once 'api_helper.php';

// Validasi Keamanan
api_check_admin();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        handle_list($conn);
        break;
    case 'detail':
        handle_detail($conn);
        break;
    case 'save':
        handle_save($conn);
        break;
    case 'delete':
        handle_delete($conn);
        break;
    default:
        send_response(false, 'Action tidak dikenal.', [], 400);
}

// 1. LIST KATEGORI
function handle_list($conn) {
    try {
        $data = [];
        $result = $conn->query("SELECT * FROM categories ORDER BY name ASC");
        
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            // Hitung jumlah produk per kategori (Opsional, untuk info tambahan di frontend)
            $count_query = $conn->query("SELECT COUNT(id) as total FROM products WHERE category_id = " . $row['id']);
            $row['product_count'] = (int)$count_query->fetch_assoc()['total'];
            $data[] = $row;
        }

        send_response(true, 'Data kategori berhasil diambil.', $data);
    } catch (Exception $e) {
        send_response(false, 'Error: ' . $e->getMessage(), [], 500);
    }
}

// 2. DETAIL KATEGORI (Untuk Edit)
function handle_detail($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_response(false, 'ID tidak valid', [], 400);

    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        send_response(true, 'Detail ditemukan', $row);
    } else {
        send_response(false, 'Kategori tidak ditemukan', [], 404);
    }
}

// 3. SAVE (CREATE / UPDATE)
function handle_save($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') send_response(false, 'Method denied', [], 405);

    $id = (int)($_POST['id'] ?? 0);
    $name = api_sanitize($_POST['name'] ?? '');

    if (empty($name)) {
        send_response(false, 'Nama kategori wajib diisi.', [], 400);
    }

    try {
        if ($id > 0) {
            // Update
            $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
            $stmt->bind_param("si", $name, $id);
            if ($stmt->execute()) {
                send_response(true, 'Kategori berhasil diperbarui.');
            } else {
                throw new Exception($stmt->error);
            }
        } else {
            // Create
            $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                send_response(true, 'Kategori baru berhasil ditambahkan.');
            } else {
                throw new Exception($stmt->error);
            }
        }
    } catch (Exception $e) {
        send_response(false, 'Database Error: ' . $e->getMessage(), [], 500);
    }
}

// 4. DELETE
function handle_delete($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) send_response(false, 'ID tidak valid', [], 400);

    try {
        // Cek apakah ada produk yang menggunakan kategori ini
        $check = $conn->query("SELECT COUNT(id) as total FROM products WHERE category_id = $id")->fetch_assoc();
        if ($check['total'] > 0) {
            send_response(false, "Gagal hapus! Masih ada {$check['total']} produk dalam kategori ini.", [], 400);
        }

        $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            send_response(true, 'Kategori berhasil dihapus.');
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        send_response(false, 'Gagal menghapus: ' . $e->getMessage(), [], 500);
    }
}
?>