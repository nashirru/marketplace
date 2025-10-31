<?php
// File: cart/cart.php
// VERSI FIXED: Validasi limit yang konsisten (termasuk pending order DAN GUEST)

require_once '../config/config.php';
require_once '../sistem/sistem.php';
require_once '../partial/partial.php';

$user_id = $_SESSION['user_id'] ?? 0;

// ============================================================
// ✅ PERBAIKAN #1: VALIDASI OTOMATIS SAAT LOAD KERANJANG (TERMASUK GUEST)
// ============================================================
$cart_items_raw = get_cart_items_for_display($conn, $user_id);
$cart_was_updated = false;
$update_messages = [];

foreach ($cart_items_raw as $item) {
    $current_qty_in_cart = $item['quantity'];
    $max_allowed_in_cart = (int)$item['stock'];
    $purchase_limit = (int)($item['purchase_limit'] ?? 0); // Ambil limit

    // Cek limit jika ada
    if ($purchase_limit > 0) {
        if ($user_id > 0) {
            // --- Logika untuk USER LOGIN ---
            $already_bought = get_user_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
            $pending_bought = get_user_pending_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
            
            $total_committed = $already_bought + $pending_bought;
            $remaining_quota = max(0, $purchase_limit - $total_committed);
            
            $max_allowed_in_cart = min($max_allowed_in_cart, $remaining_quota);
        } else {
            // --- ✅ Logika untuk GUEST ---
            $max_allowed_in_cart = min($max_allowed_in_cart, $purchase_limit);
        }
    }

    // Jika kuantitas di keranjang melebihi batas (stok ATAU limit)
    if ($current_qty_in_cart > $max_allowed_in_cart) {
        $cart_was_updated = true;

        if ($max_allowed_in_cart > 0) {
            $update_messages[] = "Jumlah '" . htmlspecialchars($item['name']) . "' disesuaikan menjadi $max_allowed_in_cart karena melebihi sisa kuota/stok Anda.";
            
            if ($user_id > 0) {
                $stmt = $conn->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("iii", $max_allowed_in_cart, $user_id, $item['product_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $_SESSION['cart'][$item['product_id']]['quantity'] = $max_allowed_in_cart;
            }
        } else {
            $update_messages[] = "'" . htmlspecialchars($item['name']) . "' dihapus dari keranjang karena Anda telah mencapai batas pembelian/pemesanan atau stok habis.";
            
            if ($user_id > 0) {
                $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
                $stmt->bind_param("ii", $user_id, $item['product_id']);
                $stmt->execute();
                $stmt->close();
            } else {
                unset($_SESSION['cart'][$item['product_id']]);
            }
        }
    }
}

if ($cart_was_updated) {
    set_flashdata('info', implode('<br>', $update_messages));
    redirect('/cart/cart.php');
}

// =================================================================
// ✅ PERBAIKAN #2: LOGIKA UPDATE AJAX (TERMASUK GUEST)
// =================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    
    function send_json_response($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    if ($product_id <= 0 || $action !== 'update') {
        send_json_response(['success' => false, 'message' => 'Data tidak valid.']);
    }

    $quantity = (int)($_POST['quantity'] ?? 1);
    if ($quantity < 0) $quantity = 0;

    $stmt_product = $conn->prepare("SELECT price, stock, name, purchase_limit, stock_cycle_id FROM products WHERE id = ?");
    $stmt_product->bind_param("i", $product_id);
    $stmt_product->execute();
    $product = $stmt_product->get_result()->fetch_assoc();
    $stmt_product->close();

    if (!$product) {
        send_json_response(['success' => false, 'message' => 'Produk tidak ditemukan.']);
    }

    $max_stock = (int)$product['stock'];
    $purchase_limit = (int)$product['purchase_limit'];

    $max_allowed = $max_stock;
    
    // Cek limit jika ada
    if ($purchase_limit > 0) {
        if ($user_id > 0) {
            // --- Logika untuk USER LOGIN ---
            $already_bought = get_user_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
            $pending_bought = get_user_pending_purchase_count($conn, $user_id, $product_id, $product['stock_cycle_id']);
            
            $total_committed = $already_bought + $pending_bought;
            $remaining_quota = max(0, $purchase_limit - $total_committed);
            
            $max_allowed = min($max_stock, $remaining_quota);
        } else {
            // --- ✅ Logika untuk GUEST ---
            $max_allowed = min($max_stock, $purchase_limit);
        }
    }


    if ($quantity > $max_allowed) {
        $quantity = $max_allowed;
        // Kirim pesan jika kuantitas disesuaikan
        if ($quantity == 0) {
             send_json_response([
                'success' => true,
                'newQuantity' => 0,
                'message' => 'Anda telah mencapai batas. Item dihapus.'
             ]);
        }
    }
    
    if ($user_id > 0) { 
        if ($quantity > 0) {
            $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = ?");
            $stmt->bind_param("iiii", $user_id, $product_id, $quantity, $quantity);
        } else {
            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
        }
        $stmt->execute();
        $stmt->close();
    } else { 
        if ($quantity > 0) {
            if (isset($_SESSION['cart'][$product_id])) $_SESSION['cart'][$product_id]['quantity'] = $quantity;
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
    }

    $cart_data_for_calc = get_cart_items_for_calculation($conn, $user_id);
    $total_price = array_reduce($cart_data_for_calc, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
    $total_items = array_reduce($cart_data_for_calc, fn($sum, $item) => $sum + $item['quantity'], 0);

    send_json_response([
        'success' => true,
        'newSubtotalFormatted' => format_rupiah($product['price'] * $quantity),
        'newGrandTotalFormatted' => format_rupiah($total_price),
        'newCartCount' => $total_items,
        'newQuantity' => $quantity
    ]);
}

// Handle form non-ajax (tombol hapus)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax'])) {
    if (($_POST['action'] ?? '') === 'remove' && ($_POST['product_id'] ?? 0) > 0) {
        $product_id = (int)$_POST['product_id'];
        if ($user_id > 0) {
            $stmt = $conn->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->bind_param("ii", $user_id, $product_id);
            $stmt->execute();
            $stmt->close();
        } else {
            unset($_SESSION['cart'][$product_id]);
        }
        set_flashdata('success', 'Produk berhasil dihapus dari keranjang.');
    }
    redirect('/cart/cart.php');
}

// =================================================================

$cart_items = get_cart_items_for_display($conn, $user_id);
$total_price = array_reduce($cart_items, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
$page_title = "Keranjang Belanja";
?>

<!DOCTYPE html>
<html lang="id">
<?php page_head($page_title . ' - ' . get_setting($conn, 'store_name'), $conn); ?>
<body class="bg-gray-100">

    <?php navbar($conn) ?>
    
    <main class="container mx-auto px-4 py-8">
        <?php flash_message(); ?>

        <h1 class="text-3xl font-bold text-gray-800 mb-6">Keranjang Belanja Anda</h1>
        
        <?php if (empty($cart_items)): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-shopping-cart text-5xl text-gray-300 mb-4"></i>
                <h2 class="text-2xl font-semibold text-gray-700">Keranjang Anda Kosong</h2>
                <p class="text-gray-500 mt-2">Sepertinya Anda belum menambahkan produk apapun.</p>
                <a href="<?= BASE_URL ?>/" class="mt-6 inline-block bg-indigo-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-indigo-700 transition-colors">Mulai Belanja</a>
            </div>
        <?php else: ?>
            <div class="flex flex-col lg:flex-row gap-8">
                <div class="lg:w-2/3">
                    <div class="bg-white rounded-lg shadow-md">
                        <div class="hidden md:flex bg-gray-50 rounded-t-lg p-4 font-semibold text-gray-600">
                            <div class="w-2/5">Produk</div>
                            <div class="w-1/5 text-center">Harga</div>
                            <div class="w-1/5 text-center">Jumlah</div>
                            <div class="w-1/5 text-right">Subtotal</div>
                        </div>

                        <?php 
                        foreach ($cart_items as $item): 
                            // ============================================================
                            // ✅ PERBAIKAN #3: HTML INPUT MAX ATTRIBUTE (TERMASUK GUEST)
                            // ============================================================
                            $limit_text = '';
                            $max_qty_input = (int)$item['stock'];
                            $purchase_limit = (int)($item['purchase_limit'] ?? 0); // Ambil limit

                            if ($purchase_limit > 0) {
                                if ($user_id > 0) {
                                    // --- Logika untuk USER LOGIN ---
                                    $already_bought = get_user_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
                                    $pending_bought = get_user_pending_purchase_count($conn, $user_id, $item['product_id'], $item['stock_cycle_id']);
                                    $total_committed = $already_bought + $pending_bought;
                                    $remaining_quota = max(0, $purchase_limit - $total_committed);
                                    
                                    $limit_text = "Limit: {$purchase_limit}. Sisa kuota Anda: {$remaining_quota}";
                                    $max_qty_input = min((int)$item['stock'], $remaining_quota);
                                } else {
                                    // --- ✅ Logika untuk GUEST ---
                                    $limit_text = "Limit pembelian: {$purchase_limit} buah";
                                    $max_qty_input = min((int)$item['stock'], $purchase_limit);
                                }
                            }
                            // ============================================================
                        ?>
                            <div class="p-4 border-b flex flex-col md:flex-row items-center gap-4">
                                <div class="w-full md:w-2/5 flex items-center">
                                    <img src="<?= BASE_URL ?>/assets/images/produk/<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" class="w-20 h-20 object-cover rounded-md mr-4">
                                    <div>
                                        <a href="<?= BASE_URL ?>/product/product.php?id=<?= encode_id($item['product_id']) ?>" class="font-semibold text-gray-800 hover:text-indigo-600"><?= htmlspecialchars($item['name']) ?></a>
                                        <p class="text-sm text-gray-500">Stok: <?= $item['stock'] ?></p>
                                        <?php if (!empty($limit_text)): ?>
                                            <p class="text-xs text-red-500 font-medium mt-1"><i class="fas fa-exclamation-circle"></i> <?= $limit_text ?></p>
                                        <?php endif; ?>
                                        <form method="POST" class="mt-2">
                                            <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                            <input type="hidden" name="action" value="remove">
                                            <button type="submit" class="text-red-500 hover:text-red-700 text-sm font-semibold" title="Hapus Item"><i class="fas fa-trash-alt mr-1"></i> Hapus</button>
                                        </form>
                                    </div>
                                </div>
                                <div class="w-full md:w-1/5 text-left md:text-center"><span class="md:hidden font-semibold">Harga: </span><?= format_rupiah($item['price']) ?></div>
                                <div class="w-full md:w-1/5 flex items-center justify-start md:justify-center">
                                    <div class="flex items-center border border-gray-300 rounded-md">
                                        <button class="quantity-change-btn p-2 text-gray-600 hover:bg-gray-100 rounded-l-md" data-product-id="<?= $item['product_id'] ?>" data-change="-1">-</button>
                                        <input type="number" class="quantity-input w-12 text-center border-l border-r" 
                                            value="<?= $item['quantity'] ?>" min="0" max="<?= $max_qty_input ?>" data-product-id="<?= $item['product_id'] ?>">
                                        <button class="quantity-change-btn p-2 text-gray-600 hover:bg-gray-100 rounded-r-md" data-product-id="<?= $item['product_id'] ?>" data-change="1">+</button>
                                    </div>
                                </div>
                                <div class="w-full md:w-1/5 text-right font-bold"><span class="md:hidden">Subtotal: </span><span id="subtotal-<?= $item['product_id'] ?>"><?= format_rupiah($item['price'] * $item['quantity']) ?></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="lg:w-1/3 mt-8 lg:mt-0">
                    <div class="bg-white rounded-lg shadow-md p-6 sticky top-8">
                        <h2 class="text-xl font-bold border-b pb-4 mb-4">Ringkasan Belanja</h2>
                        <div class="flex justify-between mb-2"><span class="text-gray-600">Subtotal</span><span class="font-semibold" id="summary-subtotal"><?= format_rupiah($total_price) ?></span></div>
                        <div class="flex justify-between mb-4"><span class="text-gray-600">Ongkir</span><span class="font-semibold">Akan dihitung</span></div>
                        <div class="border-t pt-4 flex justify-between items-center"><span class="text-gray-800 font-bold text-lg">Total</span><span class="font-bold text-xl text-indigo-600" id="summary-total"><?= format_rupiah($total_price) ?></span></div>
                        <a href="<?= BASE_URL ?>/checkout/checkout.php" class="mt-6 w-full text-center block bg-indigo-600 text-white font-bold py-3 px-4 rounded-lg hover:bg-indigo-700">Lanjutkan ke Checkout</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <?php footer($conn) ?>

    <script>
        function debounce(func, delay = 400) {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => { func.apply(this, args); }, delay);
            };
        }

        async function updateCart(productId, quantity) {
            const formData = new FormData();
            formData.append('product_id', productId);
            formData.append('quantity', quantity);
            formData.append('action', 'update');
            formData.append('ajax', '1');

            try {
                const response = await fetch('', { method: 'POST', body: formData });
                if (!response.ok) throw new Error('Network response was not ok.');
                const result = await response.json();
                
                if (result.success) {
                    if (result.newQuantity == 0) {
                        window.location.reload(); // Muat ulang jika item jadi 0
                        return;
                    }
                    // Update nilai di halaman
                    const subtotalEl = document.getElementById(`subtotal-${productId}`);
                    if(subtotalEl) subtotalEl.textContent = result.newSubtotalFormatted;
                    
                    document.getElementById('summary-subtotal').textContent = result.newGrandTotalFormatted;
                    document.getElementById('summary-total').textContent = result.newGrandTotalFormatted;
                    
                    const cartBadge = document.getElementById('cart-count-badge');
                    if (cartBadge) {
                        cartBadge.textContent = result.newCartCount > 0 ? result.newCartCount : '';
                        cartBadge.style.display = result.newCartCount > 0 ? 'inline-flex' : 'none';
                    }
                    
                    // (PERBAIKAN) Jika kuantitas disesuaikan oleh server (misal: > max)
                    const inputEl = document.querySelector(`.quantity-input[data-product-id="${productId}"]`);
                    if(inputEl && inputEl.value != result.newQuantity) {
                        inputEl.value = result.newQuantity;
                        // Muat ulang halaman adalah cara teraman untuk sinkronisasi
                        window.location.reload();
                    }

                } else {
                    alert(result.message || 'Gagal memperbarui keranjang.');
                    // Muat ulang untuk sinkronisasi
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error updating cart:', error);
                alert('Terjadi kesalahan. Halaman akan dimuat ulang.');
                window.location.reload();
            }
        }

        const debouncedUpdateCart = debounce(updateCart);

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.quantity-change-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const productId = this.dataset.productId;
                    const change = parseInt(this.dataset.change);
                    const input = document.querySelector(`.quantity-input[data-product-id="${productId}"]`);
                    
                    if (input) {
                        let oldValue = parseInt(input.value);
                        let newValue = oldValue + change;
                        const maxAllowed = parseInt(input.max);
                        const minAllowed = parseInt(input.min);

                        if (newValue < minAllowed) newValue = minAllowed;
                        if (newValue > maxAllowed) {
                             newValue = maxAllowed;
                             // Beri tahu user mereka mentok
                             alert("Anda telah mencapai sisa kuota/stok maksimum untuk produk ini.");
                        }
                        
                        if (oldValue !== newValue) {
                            input.value = newValue;
                            debouncedUpdateCart(productId, newValue);
                        }
                    }
                });
            });

            document.querySelectorAll('.quantity-input').forEach(input => {
                input.addEventListener('input', function() {
                    const productId = this.dataset.productId;
                    let quantity = parseInt(this.value);
                    const maxAllowed = parseInt(this.max);
                    const minAllowed = parseInt(this.min);

                    if (isNaN(quantity)) return;
                    if (quantity > maxAllowed) {
                        this.value = maxAllowed;
                        alert("Anda telah mencapai sisa kuota/stok maksimum untuk produk ini.");
                    }
                    if (quantity < minAllowed) {
                         this.value = minAllowed;
                    }
                    
                    debouncedUpdateCart(productId, this.value);
                });
            });
        });
    </script>
</body>
</html>