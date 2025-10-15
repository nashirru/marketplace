<?php
// File: cart/cart.php
include '../config/config.php';
include '../sistem/sistem.php';

$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$action = $_POST['action'] ?? $_GET['action'] ?? 'view';

// --- LOGIKA TAMBAH PRODUK KE KERANJANG ---
if ($action == 'add' && isset($_POST['product_id'])) {
    $product_id = sanitize_input($_POST['product_id']);
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;

    $stock_res = $conn->query("SELECT stock FROM products WHERE id = $product_id");
    if ($stock_res->num_rows > 0) {
        $stock = $stock_res->fetch_assoc()['stock'];

        if ($quantity > 0 && $stock > 0) {
            if ($is_logged_in) {
                // Logika untuk user yang sudah login (Database)
                $stmt = $conn->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $user_id, $product_id);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $existing_item = $result->fetch_assoc();
                    $new_quantity = $existing_item['quantity'] + $quantity;
                    $new_quantity = ($new_quantity > $stock) ? $stock : $new_quantity;
                    
                    $update_stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                    $update_stmt->bind_param("iii", $new_quantity, $user_id, $product_id);
                    $update_stmt->execute();
                } else {
                    $quantity = ($quantity > $stock) ? $stock : $quantity;
                    $insert_stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                    $insert_stmt->bind_param("iii", $user_id, $product_id, $quantity);
                    $insert_stmt->execute();
                }
            } else {
                // Logika untuk user guest (Session)
                if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
                if (isset($_SESSION['cart'][$product_id])) {
                    $new_quantity = $_SESSION['cart'][$product_id] + $quantity;
                    $_SESSION['cart'][$product_id] = ($new_quantity > $stock) ? $stock : $new_quantity;
                } else {
                    $_SESSION['cart'][$product_id] = ($quantity > $stock) ? $stock : $quantity;
                }
            }
            set_flash_message('success', 'Produk berhasil ditambahkan ke keranjang.');
        } else {
            set_flash_message('error', 'Gagal menambahkan produk, stok tidak mencukupi.');
        }
    } else {
        set_flash_message('error', 'Produk tidak valid.');
    }
    
    $redirect_url = $_SERVER['HTTP_REFERER'] ?? BASE_URL;
    header('Location: ' . $redirect_url);
    exit;
}

// --- LOGIKA UPDATE & HAPUS PRODUK DARI KERANJANG ---
if (($action == 'update' || $action == 'remove') && isset($_POST['product_id'])) {
    $product_id = sanitize_input($_POST['product_id']);
    
    if ($is_logged_in) {
        if ($action == 'update') {
            $quantity = (int)$_POST['quantity'];
            $stock_res = $conn->query("SELECT stock FROM products WHERE id = $product_id");
            $stock = $stock_res->fetch_assoc()['stock'];
            if($quantity > 0 && $quantity <= $stock) {
                 $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                 $stmt->bind_param("iii", $quantity, $user_id, $product_id);
                 $stmt->execute();
            } else { 
                 $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                 $stmt->bind_param("ii", $user_id, $product_id);
                 $stmt->execute();
            }
        } elseif ($action == 'remove') {
            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
        }
    } else {
        if ($action == 'update') {
            $quantity = (int)$_POST['quantity'];
            $stock_res = $conn->query("SELECT stock FROM products WHERE id = $product_id");
            $stock = $stock_res->fetch_assoc()['stock'];
            if ($quantity > 0 && $quantity <= $stock) {
                $_SESSION['cart'][$product_id] = $quantity;
            } else {
                unset($_SESSION['cart'][$product_id]);
            }
        } elseif ($action == 'remove') {
            unset($_SESSION['cart'][$product_id]);
        }
    }
    redirect('/cart/cart.php');
    exit;
}

// --- AMBIL DATA KERANJANG UNTUK DITAMPILKAN ---
$cart_items = [];
$total_price = 0;

if ($is_logged_in) {
    $stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.image, p.stock, c.quantity FROM cart c JOIN products p ON c.product_id = p.id WHERE c.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cart_items[] = $row;
        $total_price += $row['price'] * $row['quantity'];
    }
} else {
    if (!empty($_SESSION['cart'])) {
        $product_ids_string = implode(',', array_map('intval', array_keys($_SESSION['cart'])));
        if ($product_ids_string) {
            $result = $conn->query("SELECT id, name, price, image, stock FROM products WHERE id IN ($product_ids_string)");
            while ($row = $result->fetch_assoc()) {
                $row['quantity'] = $_SESSION['cart'][$row['id']];
                $cart_items[] = $row;
                $total_price += $row['price'] * $row['quantity'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keranjang Belanja - Warok Kite</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50">

    <?php include '../partial/partial.php'; ?>
    <?= navbar($conn) ?>

    <main class="container mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-800 mb-6">Keranjang Belanja Anda</h1>
        
        <?php if (empty($cart_items)): ?>
            <div class="bg-white p-8 rounded-lg shadow-md text-center">
                <p class="text-gray-600">Keranjang Anda masih kosong.</p>
                <a href="<?= BASE_URL ?>/" class="mt-4 inline-block px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Mulai Belanja</a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow-md">
                    <!-- Daftar Item -->
                    <?php foreach ($cart_items as $item): ?>
                    <div class="flex items-center border-b py-4 last:border-b-0">
                        <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-20 h-20 rounded object-cover">
                        <div class="flex-1 ml-4">
                            <h3 class="font-semibold text-gray-800"><?= htmlspecialchars($item['name']) ?></h3>
                            <p class="text-indigo-600 font-bold"><?= format_rupiah($item['price']) ?></p>
                        </div>
                        <div class="flex items-center gap-4">
                            <form action="<?= BASE_URL ?>/cart/cart.php" method="POST">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                <input type="number" name="quantity" value="<?= $item['quantity'] ?>" min="1" max="<?= $item['stock'] ?>" class="w-20 border-gray-300 rounded-md text-center" onchange="this.form.submit()">
                            </form>
                             <form action="<?= BASE_URL ?>/cart/cart.php" method="POST">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?= $item['id'] ?>">
                                <button type="submit" class="text-red-500 hover:text-red-700">&times;</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Ringkasan Belanja -->
                <div class="bg-white p-6 rounded-lg shadow-md h-fit">
                    <h2 class="text-xl font-bold border-b pb-4 mb-4">Ringkasan Belanja</h2>
                    <div class="flex justify-between mb-2">
                        <span>Subtotal</span>
                        <span><?= format_rupiah($total_price) ?></span>
                    </div>
                    <div class="flex justify-between font-bold text-lg border-t pt-4 mt-4">
                        <span>Total</span>
                        <span><?= format_rupiah($total_price) ?></span>
                    </div>
                    <a href="<?= BASE_URL ?>/checkout/checkout.php" class="mt-6 block w-full text-center px-6 py-3 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700">
                        Lanjut ke Pembayaran
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?= footer() ?>
</body>
</html>