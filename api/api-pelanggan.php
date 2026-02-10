<?php
/**
 * API PELANGGAN v1.0 (Analytics Edition)
 * =================================================================================
 * File: api/api-pelanggan.php
 * Deskripsi: Mengelola data user, history belanja, dan produk favorit.
 * =================================================================================
 */

require_once 'api_helper.php';

// Validasi Admin
api_check_admin();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            handle_list_users($conn);
            break;
        case 'detail':
            handle_detail_user($conn);
            break;
        case 'delete':
            handle_delete_user($conn);
            break;
        default:
            send_response(false, "Action '$action' tidak valid.", [], 400);
    }
} catch (Exception $e) {
    send_response(false, 'Server Error: ' . $e->getMessage(), [], 500);
}

// =================================================================================
// 1. HANDLER: LIST USERS (With Stats)
// =================================================================================
function handle_list_users($conn) {
    // Parameter Pagination & Search
    $page = max(1, (int)($_GET['p'] ?? 1));
    $limit = max(1, (int)($_GET['limit'] ?? 10));
    $offset = ($page - 1) * $limit;
    $search = $_GET['q'] ?? '';
    $sort = $_GET['sort'] ?? 'newest'; // newest, oldest, highest_spend, most_orders

    // Build WHERE
    $where = ["role = 'user'"]; // Hanya user biasa
    $params = [];
    $types = "";

    if (!empty($search)) {
        $where[] = "(name LIKE ? OR email LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term;
        $types .= "ss";
    }
    $whereSQL = implode(" AND ", $where);

    // Count Total
    $stmtCount = $conn->prepare("SELECT COUNT(*) as total FROM users WHERE $whereSQL");
    if (!empty($params)) $stmtCount->bind_param($types, ...$params);
    $stmtCount->execute();
    $total_records = $stmtCount->get_result()->fetch_assoc()['total'];
    $stmtCount->close();

    // Sorting Logic
    $orderBy = "u.created_at DESC";
    if ($sort === 'oldest') $orderBy = "u.created_at ASC";
    if ($sort === 'highest_spend') $orderBy = "total_spent DESC";
    if ($sort === 'most_orders') $orderBy = "total_orders DESC";

    // Main Query (Complex Join for Analytics)
    // Menghitung Total Order Completed & Total Uang Dihabiskan
    $sql = "
        SELECT 
            u.id, u.name, u.email, u.created_at,
            COUNT(CASE WHEN o.status = 'completed' THEN 1 END) as total_orders,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) as total_spent,
            MAX(o.created_at) as last_order_date
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        WHERE $whereSQL
        GROUP BY u.id
        ORDER BY $orderBy
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($sql);
    
    // Bind Params (Search params + Limit + Offset)
    $final_params = $params;
    $final_params[] = $limit;
    $final_params[] = $offset;
    $final_types = $types . "ii";
    
    $stmt->bind_param($final_types, ...$final_params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $row['total_spent'] = (float)$row['total_spent'];
        $row['total_orders'] = (int)$row['total_orders'];
        // Generate Avatar Text
        $row['initials'] = strtoupper(substr($row['name'], 0, 1));
        $users[] = $row;
    }

    send_response(true, "Data user loaded", [
        'users' => $users,
        'pagination' => [
            'total_records' => $total_records,
            'total_pages' => ceil($total_records / $limit),
            'current_page' => $page
        ]
    ]);
}

// =================================================================================
// 2. HANDLER: USER DETAIL (Deep Dive)
// =================================================================================
function handle_detail_user($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) send_response(false, 'ID tidak valid', [], 400);

    // A. Info Dasar & Stats
    $sqlBasic = "
        SELECT 
            u.*,
            COUNT(o.id) as all_orders_count,
            COUNT(CASE WHEN o.status = 'completed' THEN 1 END) as success_orders,
            COUNT(CASE WHEN o.status = 'cancelled' THEN 1 END) as cancelled_orders,
            COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) as lifetime_value
        FROM users u
        LEFT JOIN orders o ON u.id = o.user_id
        WHERE u.id = ?
        GROUP BY u.id
    ";
    $stmt = $conn->prepare($sqlBasic);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) send_response(false, 'User tidak ditemukan', [], 404);

    // B. Daftar Alamat
    $sqlAddr = "SELECT * FROM user_addresses WHERE user_id = ? ORDER BY is_default DESC";
    $stmtAddr = $conn->prepare($sqlAddr);
    $stmtAddr->bind_param("i", $id);
    $stmtAddr->execute();
    $addresses = $stmtAddr->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtAddr->close();

    // C. Top Produk yang Dibeli (Checkout Terbanyak)
    // Join: Order Items -> Orders (Filter Completed) -> Products
    $sqlTopProd = "
        SELECT 
            p.name, p.image, 
            SUM(oi.quantity) as total_qty,
            MAX(o.created_at) as last_bought
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE o.user_id = ? AND o.status = 'completed'
        GROUP BY p.id
        ORDER BY total_qty DESC
        LIMIT 5
    ";
    $stmtProd = $conn->prepare($sqlTopProd);
    $stmtProd->bind_param("i", $id);
    $stmtProd->execute();
    $top_products = [];
    $resProd = $stmtProd->get_result();
    while($row = $resProd->fetch_assoc()) {
        $row['image_url'] = !empty($row['image']) ? "../assets/images/produk/" . $row['image'] : null;
        $top_products[] = $row;
    }
    $stmtProd->close();

    send_response(true, "Detail user loaded", [
        'profile' => $user,
        'addresses' => $addresses,
        'top_products' => $top_products
    ]);
}

// =================================================================================
// 3. HANDLER: DELETE USER
// =================================================================================
function handle_delete_user($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) send_response(false, 'ID tidak valid', [], 400);

    // Cek Role (Jangan hapus sesama admin via API ini untuk keamanan)
    $check = $conn->query("SELECT role FROM users WHERE id = $id")->fetch_assoc();
    if ($check && $check['role'] === 'admin') {
        send_response(false, 'Tidak dapat menghapus akun Administrator.', [], 403);
    }

    // Eksekusi Hapus (Cascade akan menangani order/alamat jika disetting di DB)
    // Jika tidak cascade, manual delete tabel relasi
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $conn->commit();
            send_response(true, 'Pengguna berhasil dihapus.');
        } else {
            throw new Exception($stmt->error);
        }
    } catch (Exception $e) {
        $conn->rollback();
        send_response(false, 'Gagal menghapus: ' . $e->getMessage(), [], 500);
    }
}
?>